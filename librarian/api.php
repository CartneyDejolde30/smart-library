<?php
// JSON endpoint for the scanner pages. POST only, CSRF-protected, librarian/admin only.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/borrowing.php';
require_once __DIR__ . '/../includes/inventory.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$u = current_user();
if (!$u || !in_array($u['role'], ['librarian', 'admin'], true)) out(['ok' => false, 'error' => 'Not authorized. Please log in again.'], 403);
if (db_count("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active'", [$u['id']]) < 1) out(['ok' => false, 'error' => 'Account inactive.'], 403);
if (!is_post()) out(['ok' => false, 'error' => 'POST required.'], 405);
if (!csrf_valid()) out(['ok' => false, 'error' => 'Session expired. Refresh the page.'], 419);

$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$action = (string)($in['action'] ?? '');
$code = clean($in['code'] ?? '');

function student_payload(array $s): array
{
    $el = borrow_eligibility($s);
    return ['id' => (int)$s['id'], 'student_id' => $s['student_id'], 'name' => trim($s['first_name'] . ' ' . $s['last_name']),
        'grade' => $s['grade_level'] . ($s['section'] ? ' – ' . $s['section'] : ''), 'status' => $s['account_status'],
        'eligible' => $el['ok'], 'reasons' => $el['reasons'], 'active' => $el['active'], 'max' => $el['max'], 'remaining' => $el['remaining'],
        'link' => url('librarian/students/view.php?id=' . $s['id'])];
}

try {
    switch ($action) {
        case 'student':
            if ($code === '') out(['ok' => false, 'error' => 'Empty code.']);
            $s = find_student($code);
            if (!$s) out(['ok' => false, 'error' => 'No student found for "' . $code . '".']);
            out(['ok' => true, 'student' => student_payload($s)]);

        case 'copy':
            $c = find_copy($code);
            if (!$c) out(['ok' => false, 'error' => 'No book copy found for "' . $code . '".']);
            if ($c['status'] !== 'available') out(['ok' => false, 'error' => "{$c['title']} ({$c['accession_number']}) is not available — status: {$c['status']}."]);
            out(['ok' => true, 'copy' => ['id' => (int)$c['id'], 'accession' => $c['accession_number'], 'qr' => $c['qr_code_id'], 'title' => $c['title'], 'author' => $c['author']]]);

        case 'borrow':
            $r = process_borrow((int)($in['student_id'] ?? 0), (array)($in['copy_ids'] ?? []), (int)$u['id'], (string)($in['token'] ?? ''));
            out($r);

        case 'loan':
            $c = find_copy($code);
            if (!$c) out(['ok' => false, 'error' => 'No book copy found for "' . $code . '".']);
            $q = db()->prepare("SELECT bi.id AS item_id, bi.due_date, bt.transaction_no, bt.borrowed_at, s.id AS sid, s.student_id AS sno, s.first_name, s.last_name, s.grade_level, s.section
                FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id = bi.transaction_id JOIN students s ON s.id = bt.student_id
                WHERE bi.copy_id = ? AND bi.status = 'borrowed'");
            $q->execute([$c['id']]); $l = $q->fetch();
            if (!$l) out(['ok' => false, 'error' => "{$c['title']} ({$c['accession_number']}) is not currently borrowed — status: {$c['status']}."]);
            $calc = calc_overdue($l['due_date']);
            out(['ok' => true, 'loan' => ['item_id' => (int)$l['item_id'], 'title' => $c['title'], 'author' => $c['author'], 'accession' => $c['accession_number'],
                'txn' => $l['transaction_no'], 'borrowed_at' => date('M j, Y', strtotime($l['borrowed_at'])), 'due' => date('M j, Y', strtotime($l['due_date'])),
                'student' => trim($l['first_name'] . ' ' . $l['last_name']), 'sno' => $l['sno'], 'grade' => $l['grade_level'] . ($l['section'] ? ' – ' . $l['section'] : ''),
                'days_overdue' => $calc['days'], 'penalty' => $calc['penalty']]]);

        case 'return':
            $adj = isset($in['adjusted_amount']) && $in['adjusted_amount'] !== '' ? (float)$in['adjusted_amount'] : null;
            out(process_return((int)($in['item_id'] ?? 0), (int)$u['id'], (string)($in['condition'] ?? 'good'), clean($in['remarks'] ?? ''), $adj, (string)($in['reason'] ?? ''), $u['role'] === 'admin'));

        case 'identify':
            if ($s = find_student($code)) out(['ok' => true, 'type' => 'student', 'student' => student_payload($s)]);
            if ($c = find_copy($code)) {
                $q = db()->prepare("SELECT bi.due_date, CONCAT(s.first_name,' ',s.last_name) AS sname, s.id AS sid FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN students s ON s.id=bt.student_id WHERE bi.copy_id=? AND bi.status='borrowed'");
                $q->execute([$c['id']]); $b = $q->fetch();
                $due = $b['due_date'] ?? null;
                $status = ($c['status'] === 'borrowed' && $due && $due < date('Y-m-d')) ? 'overdue' : $c['status'];
                out(['ok' => true, 'type' => 'book', 'book' => ['title' => $c['title'], 'author' => $c['author'], 'accession' => $c['accession_number'], 'status' => $status,
                    'shelf' => $c['shelf_location'], 'borrower' => $b['sname'] ?? null, 'due' => $due ? date('M j, Y', strtotime($due)) : null, 'link' => url('librarian/books/view.php?id=' . $c['book_id'])]]);
            }
            out(['ok' => false, 'error' => 'Unknown QR code: "' . $code . '".']);

        case 'inv_scan':
            $sq = db()->prepare('SELECT * FROM inventory_sessions WHERE id = ?'); $sq->execute([(int)($in['session_id'] ?? 0)]); $sess = $sq->fetch();
            if (!$sess || $sess['status'] !== 'in_progress') out(['ok' => false, 'error' => 'This inventory session is not active.']);
            if ($code === '') out(['ok' => false, 'error' => 'Empty code.']);
            $copy = find_copy($code);
            $stored = $copy ? $copy['qr_code_id'] : mb_substr($code, 0, 60);
            $dup = db_count('SELECT COUNT(*) FROM inventory_scans WHERE session_id = ? AND scanned_code = ?', [$sess['id'], $stored]);
            if ($dup) out(['ok' => false, 'error' => 'Already scanned in this session.', 'state' => inventory_state($sess)]);
            db()->prepare('INSERT INTO inventory_scans (session_id, copy_id, scanned_code, scanned_by) VALUES (?,?,?,?)')->execute([$sess['id'], $copy['id'] ?? null, $stored, $u['id']]);
            $state = inventory_state($sess);
            $msg = $copy ? ($copy['title'] . ' (' . $copy['accession_number'] . ')') : 'Unregistered QR code';
            out(['ok' => true, 'message' => $msg, 'registered' => (bool)$copy, 'state' => $state]);

        default:
            out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('api: ' . $e->getMessage());
    out(['ok' => false, 'error' => 'Server error. Please try again.'], 500);
}