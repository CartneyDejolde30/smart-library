<?php
declare(strict_types=1);
require_once __DIR__ . '/borrowing.php';

/** Report a copy as lost or damaged. Atomic. @return array{ok:bool,error?:string,message?:string} */
function report_incident(string $type, int $copyId, string $desc, float $fee, int $userId): array
{
    $pdo = db();
    if (!in_array($type, ['lost', 'damaged'], true)) return ['ok' => false, 'error' => 'Invalid type.'];
    if ($fee < 0 || $fee > 1000000) return ['ok' => false, 'error' => 'Replacement fee is not valid.'];
    try {
        $pdo->beginTransaction();
        $c = $pdo->prepare('SELECT c.*, b.title FROM book_copies c JOIN books b ON b.id = c.book_id WHERE c.id = ? AND c.is_archived = 0 FOR UPDATE');
        $c->execute([$copyId]); $copy = $c->fetch();
        if (!$copy) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Copy not found.']; }
        if ($copy['status'] === $type) { $pdo->rollBack(); return ['ok' => false, 'error' => "This copy is already marked $type."]; }

        $l = $pdo->prepare("SELECT bi.id AS item_id, bt.student_id, s.user_id AS suser, s.student_id AS sno, CONCAT(s.first_name,' ',s.last_name) AS sname
            FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id = bi.transaction_id JOIN students s ON s.id = bt.student_id
            WHERE bi.copy_id = ? AND bi.status = 'borrowed' FOR UPDATE");
        $l->execute([$copyId]); $loan = $l->fetch();

        if ($type === 'damaged' && $loan) { $pdo->rollBack(); return ['ok' => false, 'error' => 'This copy is currently borrowed. Process it under Return Book and choose "Damaged".']; }
        $sid = $loan['student_id'] ?? null; $item = $loan['item_id'] ?? null;

        $pdo->prepare('UPDATE book_copies SET status = ? WHERE id = ?')->execute([$type, $copyId]);
        if ($type === 'lost') {
            if ($item) $pdo->prepare("UPDATE borrowing_items SET status = 'lost' WHERE id = ?")->execute([$item]);
            $pdo->prepare('INSERT INTO lost_books (copy_id, student_id, item_id, date_reported, description, replacement_fee, reported_by) VALUES (?,?,?,CURDATE(),?,?,?)')
                ->execute([$copyId, $sid, $item, $desc ?: null, $fee, $userId]);
            if ($sid && $fee > 0) $pdo->prepare("INSERT INTO penalties (student_id, item_id, type, amount) VALUES (?,?, 'lost', ?)")->execute([$sid, $item, $fee]);
        } else {
            $pdo->prepare('INSERT INTO damaged_books (copy_id, student_id, item_id, date_reported, description, replacement_fee, reported_by) VALUES (?,?,?,CURDATE(),?,?,?)')
                ->execute([$copyId, $sid, $item, $desc ?: null, $fee, $userId]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('report_incident: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Database error. Nothing was saved.'];
    }
    log_activity("book_$type", "{$copy['accession_number']} ({$copy['title']}) reported $type" . ($loan ? " — borrower {$loan['sno']}" : ''), 'book_copies', $copyId, $userId);
    notify(null, 2, $type, ucfirst($type) . ' book', "{$copy['title']} ({$copy['accession_number']}) reported $type");
    if ($loan && !empty($loan['suser']) && $type === 'lost') notify((int)$loan['suser'], null, 'lost', 'Book reported lost', "{$copy['title']} was reported lost." . ($fee > 0 ? ' Replacement fee: ' . number_format($fee, 2) : ''), 'student/dashboard.php');
    return ['ok' => true, 'message' => "{$copy['title']} marked $type."];
}

/** Update the resolution status of a lost/damaged record. */
function resolve_incident(string $type, int $id, string $status, int $userId): array
{
    $table = $type === 'lost' ? 'lost_books' : 'damaged_books';
    $allowed = $type === 'lost' ? ['open', 'paid', 'replaced', 'written_off', 'found'] : ['open', 'repaired', 'paid', 'written_off'];
    if (!in_array($status, $allowed, true)) return ['ok' => false, 'error' => 'Invalid status.'];
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $r = $pdo->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE"); $r->execute([$id]); $rec = $r->fetch();
        if (!$rec) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Record not found.']; }
        $pdo->prepare("UPDATE $table SET resolution_status = ?, resolved_at = " . ($status === 'open' ? 'NULL' : 'NOW()') . " WHERE id = ?")->execute([$status, $id]);
        if (in_array($status, ['found', 'repaired'], true)) {
            $pdo->prepare("UPDATE book_copies SET status = 'available' WHERE id = ? AND status IN ('lost','damaged')")->execute([$rec['copy_id']]);
        }
        if ($status === 'paid' && $type === 'lost' && $rec['item_id']) {
            $pdo->prepare("UPDATE penalties SET status = 'paid', paid_at = NOW() WHERE item_id = ? AND type = 'lost' AND status = 'unpaid'")->execute([$rec['item_id']]);
        }
        $pdo->commit();
    } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log($e->getMessage()); return ['ok' => false, 'error' => 'Database error.']; }
    log_activity("{$type}_resolution", ucfirst($type) . " record #$id set to $status", $table, $id, $userId);
    return ['ok' => true];
}