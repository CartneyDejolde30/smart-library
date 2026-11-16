<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function find_student(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM students WHERE is_archived = 0 AND (qr_code_id = ? OR student_id = ?) LIMIT 1');
    $st->execute([$code, $code]);
    return $st->fetch() ?: null;
}

function find_copy(string $code): ?array
{
    $st = db()->prepare('SELECT c.*, b.title, b.author FROM book_copies c JOIN books b ON b.id = c.book_id
                         WHERE c.is_archived = 0 AND (c.qr_code_id = ? OR c.accession_number = ?) LIMIT 1');
    $st->execute([$code, $code]);
    return $st->fetch() ?: null;
}

/** Overdue days and penalty for a due date. Uses settings: penalty_per_day, max_overdue_days (cap on penalised days, 0 = no cap). */
function calc_overdue(string $due, ?string $asOf = null): array
{
    $d = new DateTimeImmutable($due);
    $n = new DateTimeImmutable($asOf ?: date('Y-m-d'));
    $days = $n > $d ? (int)$d->diff($n)->days : 0;
    $cap = (int)setting('max_overdue_days', '0');
    $billable = $cap > 0 ? min($days, $cap) : $days;
    return ['days' => $days, 'penalty' => round($billable * (float)setting('penalty_per_day', '0'), 2)];
}

function borrow_eligibility(array $student): array
{
    $max = (int)setting('max_books_per_student', '3');
    $q = db()->prepare("SELECT COUNT(*) AS active, COALESCE(SUM(bi.due_date < CURDATE()),0) AS overdue
        FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id = bi.transaction_id
        WHERE bt.student_id = ? AND bi.status = 'borrowed'");
    $q->execute([$student['id']]); $r = $q->fetch();
    $p = db()->prepare("SELECT COALESCE(SUM(COALESCE(adjusted_amount, amount)),0) FROM penalties WHERE student_id = ? AND status = 'unpaid'");
    $p->execute([$student['id']]); $unpaid = (float)$p->fetchColumn();
    $active = (int)$r['active']; $overdue = (int)$r['overdue']; $reasons = [];
    if ($student['account_status'] !== 'active') $reasons[] = 'Borrower account is inactive.';
    if ($active >= $max) $reasons[] = "Borrowing limit reached ($max book(s)).";
    if (setting('block_borrow_if_overdue', '1') === '1' && $overdue > 0) $reasons[] = "Student has $overdue overdue book(s).";
    if (setting('block_borrow_if_unpaid_penalty', '1') === '1' && $unpaid > 0) $reasons[] = 'Student has unpaid penalties of ' . number_format($unpaid, 2) . '.';
    return ['ok' => !$reasons, 'reasons' => $reasons, 'active' => $active, 'max' => $max, 'remaining' => max(0, $max - $active), 'overdue' => $overdue, 'unpaid' => $unpaid];
}

function notify(?int $userId, ?int $roleId, string $type, string $title, string $msg, ?string $link = null): void
{
    try {
        db()->prepare('INSERT INTO notifications (user_id, target_role_id, type, title, message, link) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $roleId, $type, $title, mb_substr($msg, 0, 500), $link]);
    } catch (Throwable $e) { error_log('notify: ' . $e->getMessage()); }
}

/** Atomic multi-book borrow. @return array{ok:bool,error?:string,txn?:string,due?:string,duplicate?:bool} */
function process_borrow(int $studentId, array $copyIds, int $userId, string $token): array
{
    $pdo = db();
    $copyIds = array_values(array_unique(array_map('intval', $copyIds)));
    if (!$copyIds) return ['ok' => false, 'error' => 'No books selected.'];
    if (!preg_match('/^[A-Za-z0-9\-]{16,64}$/', $token)) return ['ok' => false, 'error' => 'Invalid transaction token. Refresh the page.'];
    $hash = hash('sha256', $token);

    $existing = function () use ($pdo, $hash) {
        $x = $pdo->prepare('SELECT bt.transaction_no, MIN(bi.due_date) AS due FROM borrowing_transactions bt JOIN borrowing_items bi ON bi.transaction_id = bt.id WHERE bt.idempotency_token = ? GROUP BY bt.id');
        $x->execute([$hash]); return $x->fetch();
    };
    if ($dup = $existing()) return ['ok' => true, 'txn' => $dup['transaction_no'], 'due' => $dup['due'], 'duplicate' => true];

    try {
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT * FROM students WHERE id = ? AND is_archived = 0 FOR UPDATE'); $s->execute([$studentId]);
        $student = $s->fetch();
        if (!$student) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Student not found.']; }
        $el = borrow_eligibility($student);
        if (!$el['ok']) { $pdo->rollBack(); return ['ok' => false, 'error' => implode(' ', $el['reasons'])]; }
        if (count($copyIds) > $el['remaining']) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Too many books: this student can borrow ' . $el['remaining'] . ' more.']; }

        $due = date('Y-m-d', strtotime('+' . max(1, (int)setting('borrow_duration_days', '7')) . ' day'));
        $txn = 'TXN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $pdo->prepare('INSERT INTO borrowing_transactions (transaction_no, student_id, processed_by, idempotency_token) VALUES (?,?,?,?)')->execute([$txn, $studentId, $userId, $hash]);
        $txId = (int)$pdo->lastInsertId();

        $lock = $pdo->prepare('SELECT c.*, b.title FROM book_copies c JOIN books b ON b.id = c.book_id WHERE c.id = ? AND c.is_archived = 0 FOR UPDATE');
        $ins = $pdo->prepare('INSERT INTO borrowing_items (transaction_id, copy_id, due_date) VALUES (?,?,?)');
        $upd = $pdo->prepare("UPDATE book_copies SET status = 'borrowed' WHERE id = ?");
        $titles = [];
        foreach ($copyIds as $cid) {
            $lock->execute([$cid]); $c = $lock->fetch();
            if (!$c) { $pdo->rollBack(); return ['ok' => false, 'error' => 'A selected copy no longer exists.']; }
            if ($c['status'] !== 'available') { $pdo->rollBack(); return ['ok' => false, 'error' => "{$c['accession_number']} is not available (status: {$c['status']})."]; }
            $ins->execute([$txId, $cid, $due]); $upd->execute([$cid]); $titles[] = $c['title'];
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($e->errorInfo[1] ?? 0) == 1062) {
            if ($dup = $existing()) return ['ok' => true, 'txn' => $dup['transaction_no'], 'due' => $dup['due'], 'duplicate' => true];
            return ['ok' => false, 'error' => 'A selected copy was just borrowed in another transaction. Rescan and try again.'];
        }
        error_log('process_borrow: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Database error. Nothing was saved.'];
    }
    $name = $student['first_name'] . ' ' . $student['last_name'];
    log_activity('borrow', "$txn: " . count($copyIds) . " book(s) borrowed by {$student['student_id']} ($name)", 'borrowing_transactions', $txId, $userId);
    if ($student['user_id']) notify((int)$student['user_id'], null, 'borrow', 'Books borrowed', implode(', ', $titles) . " — due " . date('M j, Y', strtotime($due)), 'student/borrowed-books.php');
    notify(null, 2, 'borrow', 'New borrowing', "$name borrowed " . count($copyIds) . ' book(s) (' . $txn . ')');
    return ['ok' => true, 'txn' => $txn, 'due' => $due];
}

/** Atomic return. Penalty adjustment is honoured only for admins and is audited. */
function process_return(int $itemId, int $userId, string $condition, string $remarks, ?float $adjAmount, string $adjReason, bool $isAdmin): array
{
    $pdo = db();
    if (!in_array($condition, ['good', 'damaged'], true)) return ['ok' => false, 'error' => 'Invalid condition.'];
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT bi.*, bt.student_id, c.accession_number, b.title, s.user_id AS suser, s.first_name, s.last_name, s.student_id AS sno
            FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id = bi.transaction_id JOIN book_copies c ON c.id = bi.copy_id
            JOIN books b ON b.id = c.book_id JOIN students s ON s.id = bt.student_id WHERE bi.id = ? FOR UPDATE");
        $q->execute([$itemId]); $it = $q->fetch();
        if (!$it || $it['status'] !== 'borrowed') { $pdo->rollBack(); return ['ok' => false, 'error' => 'This book is not currently borrowed (it may already have been returned).']; }

        $calc = calc_overdue($it['due_date']); $amount = $calc['penalty']; $effective = $amount; $adjusted = null;
        if ($isAdmin && $adjAmount !== null && abs($adjAmount - $amount) > 0.004) {
            if ($adjAmount < 0 || $adjAmount > $amount) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Adjusted penalty must be between 0 and ' . number_format($amount, 2) . '.']; }
            if (mb_strlen(trim($adjReason)) < 3) { $pdo->rollBack(); return ['ok' => false, 'error' => 'A reason is required to adjust a penalty.']; }
            $adjusted = round($adjAmount, 2); $effective = $adjusted;
        }
        $pdo->prepare('INSERT INTO returns (item_id, days_overdue, condition_on_return, processed_by, remarks) VALUES (?,?,?,?,?)')
            ->execute([$itemId, $calc['days'], $condition, $userId, $remarks ?: null]);
        $pdo->prepare("UPDATE borrowing_items SET status = 'returned', returned_at = NOW() WHERE id = ?")->execute([$itemId]);
        $newStatus = $condition === 'damaged' ? 'damaged' : 'available';
        $pdo->prepare('UPDATE book_copies SET status = ? WHERE id = ?')->execute([$newStatus, $it['copy_id']]);
        if ($condition === 'damaged') {
            $pdo->prepare('INSERT INTO damaged_books (copy_id, student_id, item_id, date_reported, description, reported_by) VALUES (?,?,?,CURDATE(),?,?)')
                ->execute([$it['copy_id'], $it['student_id'], $itemId, $remarks ?: 'Reported damaged on return', $userId]);
        }
        if ($amount > 0) {
            $pdo->prepare('INSERT INTO penalties (student_id, item_id, type, amount, adjusted_amount, adjustment_reason, adjusted_by, status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$it['student_id'], $itemId, 'overdue', $amount, $adjusted, $adjusted !== null ? trim($adjReason) : null, $adjusted !== null ? $userId : null, $effective <= 0 ? 'waived' : 'unpaid']);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (($e->errorInfo[1] ?? 0) == 1062) return ['ok' => false, 'error' => 'This book was already returned.'];
        error_log('process_return: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Database error. Nothing was saved.'];
    }
    $name = $it['first_name'] . ' ' . $it['last_name'];
    log_activity('return', "{$it['accession_number']} returned by {$it['sno']} ($name); {$calc['days']} day(s) overdue; condition $condition", 'borrowing_items', $itemId, $userId);
    if ($adjusted !== null) log_activity('penalty_adjust', "Penalty for item #$itemId adjusted from " . number_format($amount, 2) . ' to ' . number_format($adjusted, 2) . '. Reason: ' . trim($adjReason), 'penalties', $itemId, $userId);
    if ($condition === 'damaged') log_activity('book_damaged', "{$it['accession_number']} marked damaged on return", 'book_copies', (int)$it['copy_id'], $userId);
    if ($it['suser']) notify((int)$it['suser'], null, 'return', 'Book returned', $it['title'] . ' was returned.' . ($effective > 0 ? ' Penalty: ' . number_format($effective, 2) : ''), 'student/history.php');
    if ($effective > 0 && $it['suser']) notify((int)$it['suser'], null, 'penalty', 'Penalty recorded', 'Overdue penalty of ' . number_format($effective, 2) . ' for ' . $it['title'], 'student/dashboard.php');
    notify(null, 2, 'return', 'Book returned', "{$it['title']} returned by $name");
    return ['ok' => true, 'title' => $it['title'], 'student' => $name, 'days_overdue' => $calc['days'], 'penalty' => $effective, 'copy_status' => $newStatus];
}

/** SQL fragment + params selecting the notifications a user may see. Students see only their own. */
function notif_scope(array $user): array
{
    return match ($user['role']) {
        'admin' => ['(n.user_id = ? OR n.target_role_id IN (1,2))', [$user['id']]],
        'librarian' => ['(n.user_id = ? OR n.target_role_id = 2)', [$user['id']]],
        default => ['n.user_id = ?', [$user['id']]],
    };
}

function unread_notifications(array $user): int
{
    [$w, $p] = notif_scope($user);
    return db_count("SELECT COUNT(*) FROM notifications n WHERE $w AND n.is_read = 0", $p);
}

/** Creates overdue / outstanding-penalty notifications (deduplicated). Throttled to once per hour per session. */
function sync_overdue_notifications(): void
{
    if (time() - ($_SESSION['overdue_sync'] ?? 0) < 3600) return;
    $_SESSION['overdue_sync'] = time();
    try {
        $pdo = db();
        $rows = $pdo->query("SELECT s.user_id, b.title, bi.due_date FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id
            JOIN students s ON s.id=bt.student_id JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id
            WHERE bi.status='borrowed' AND bi.due_date < CURDATE() AND s.user_id IS NOT NULL LIMIT 300")->fetchAll();
        $chk = $pdo->prepare("SELECT 1 FROM notifications WHERE user_id=? AND type='overdue' AND message=?");
        foreach ($rows as $r) {
            $msg = $r['title'] . ' was due on ' . date('M j, Y', strtotime($r['due_date'])) . '. Please return it; penalties apply.';
            $chk->execute([$r['user_id'], $msg]);
            if (!$chk->fetchColumn()) notify((int)$r['user_id'], null, 'overdue', 'Book overdue', $msg, 'student/borrowed-books.php');
        }
        $n = db_count("SELECT COUNT(*) FROM borrowing_items WHERE status='borrowed' AND due_date < CURDATE()");
        if ($n > 0) {
            $msg = "$n overdue book(s) as of " . date('M j, Y');
            $chk = $pdo->prepare("SELECT 1 FROM notifications WHERE target_role_id=2 AND type='overdue' AND message=?"); $chk->execute([$msg]);
            if (!$chk->fetchColumn()) notify(null, 2, 'overdue', 'Overdue books', $msg, 'librarian/overdue.php');
        }
        $pen = $pdo->query("SELECT s.user_id, SUM(COALESCE(p.adjusted_amount,p.amount)) t FROM penalties p JOIN students s ON s.id=p.student_id WHERE p.status='unpaid' AND s.user_id IS NOT NULL GROUP BY s.user_id")->fetchAll();
        foreach ($pen as $r) {
            $msg = 'You have outstanding penalties of ' . number_format((float)$r['t'], 2) . '.';
            $chk = $pdo->prepare("SELECT 1 FROM notifications WHERE user_id=? AND type='penalty_due' AND message=?"); $chk->execute([$r['user_id'], $msg]);
            if (!$chk->fetchColumn()) notify((int)$r['user_id'], null, 'penalty_due', 'Outstanding penalty', $msg, 'student/borrowed-books.php');
        }
    } catch (Throwable $e) { error_log('sync_overdue_notifications: ' . $e->getMessage()); }
}