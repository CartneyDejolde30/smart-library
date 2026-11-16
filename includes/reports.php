<?php
declare(strict_types=1);

/** Report catalogue: key => [title, default range (today|week|month), builder(o) => [sql, params], usesDates, adminOnly].
 *  Column aliases in each SELECT become the column headings (and the searchable columns). */
function report_catalog(): array
{
    $cap = (int)setting('max_overdue_days', '0');
    $rate = sprintf('%.4F', (float)setting('penalty_per_day', '0'));
    $stu = "CONCAT(s.first_name,' ',s.last_name)";
    $loan = "FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN students s ON s.id=bt.student_id
             JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id JOIN users u ON u.id=bt.processed_by";
    $days = 'DATEDIFF(CURDATE(), bi.due_date)';
    $penalty = $cap > 0 ? "LEAST($days, $cap)" : $days;
    $byDay = "SELECT d `Date`, SUM(b) `Books borrowed`, SUM(r) `Books returned` FROM (
        SELECT DATE(bt.borrowed_at) d, 1 b, 0 r FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id WHERE DATE(bt.borrowed_at) BETWEEN ? AND ?
        UNION ALL SELECT DATE(returned_at), 0, 1 FROM returns WHERE DATE(returned_at) BETWEEN ? AND ?) t GROUP BY d ORDER BY d";
    $incident = fn(string $t) => "SELECT l.date_reported `Date reported`, b.title `Book`, c.accession_number `Accession`, COALESCE(s.student_id,'') `Student ID`, COALESCE($stu,'') `Borrower`,
        l.replacement_fee `Replacement fee`, l.resolution_status `Resolution`, COALESCE(l.description,'') `Description` FROM $t l JOIN book_copies c ON c.id=l.copy_id JOIN books b ON b.id=c.book_id
        LEFT JOIN students s ON s.id=l.student_id WHERE l.date_reported BETWEEN ? AND ? ORDER BY l.date_reported DESC";
    $copies = fn(string $w) => "SELECT c.accession_number `Accession`, b.title `Title`, b.author `Author`, COALESCE(cat.name,'') `Category`, COALESCE(c.shelf_location,'') `Shelf`, c.status `Status`
        FROM book_copies c JOIN books b ON b.id=c.book_id LEFT JOIN book_categories cat ON cat.id=b.category_id WHERE c.is_archived=0 $w ORDER BY b.title, c.accession_number";
    $sub = fn(string $w) => "(SELECT COUNT(*) FROM borrowing_items bi JOIN borrowing_transactions bt ON bt.id=bi.transaction_id WHERE bt.student_id=s.id $w)";

    return [
        'daily_borrowed' => ['Daily Borrowed Books', 'today', fn($o) => ["SELECT bt.transaction_no `Transaction`, DATE_FORMAT(bt.borrowed_at,'%Y-%m-%d %H:%i') `Borrowed at`, s.student_id `Student ID`, $stu `Student`, b.title `Book`, c.accession_number `Accession`, bi.due_date `Due date`, u.full_name `Processed by` $loan WHERE DATE(bt.borrowed_at) BETWEEN ? AND ? ORDER BY bt.borrowed_at", [$o['from'], $o['to']]], true, false],
        'daily_returned' => ['Daily Returned Books', 'today', fn($o) => ["SELECT bt.transaction_no `Transaction`, DATE_FORMAT(r.returned_at,'%Y-%m-%d %H:%i') `Returned at`, s.student_id `Student ID`, $stu `Student`, b.title `Book`, c.accession_number `Accession`, bi.due_date `Due date`, r.days_overdue `Days overdue`, r.condition_on_return `Condition`, u.full_name `Processed by` FROM returns r JOIN borrowing_items bi ON bi.id=r.item_id JOIN borrowing_transactions bt ON bt.id=bi.transaction_id JOIN students s ON s.id=bt.student_id JOIN book_copies c ON c.id=bi.copy_id JOIN books b ON b.id=c.book_id JOIN users u ON u.id=r.processed_by WHERE DATE(r.returned_at) BETWEEN ? AND ? ORDER BY r.returned_at", [$o['from'], $o['to']]], true, false],
        'weekly' => ['Weekly Borrowing Report', 'week', fn($o) => [$byDay, [$o['from'], $o['to'], $o['from'], $o['to']]], true, false],
        'monthly' => ['Monthly Borrowing Report', 'month', fn($o) => [$byDay, [$o['from'], $o['to'], $o['from'], $o['to']]], true, false],
        'overdue' => ['Overdue Books Report (as of today)', 'month', fn($o) => ["SELECT s.student_id `Student ID`, $stu `Student`, s.grade_level `Year Level`, b.title `Book`, c.accession_number `Accession`, bi.due_date `Due date`, $days `Days late`, ROUND($penalty * $rate, 2) `Penalty` $loan WHERE bi.status='borrowed' AND bi.due_date < CURDATE() ORDER BY bi.due_date", []], false, false],
        'lost' => ['Lost Books Report', 'month', fn($o) => [$incident('lost_books'), [$o['from'], $o['to']]], true, false],
        'damaged' => ['Damaged Books Report', 'month', fn($o) => [$incident('damaged_books'), [$o['from'], $o['to']]], true, false],
        'inventory' => ['Complete Library Inventory', 'month', fn($o) => [$copies(''), []], false, false],
        'available' => ['Available Books Report', 'month', fn($o) => [$copies("AND c.status='available'"), []], false, false],
        'borrower_history' => ['Borrower Transaction History', 'month', fn($o) => ["SELECT s.student_id `Student ID`, $stu `Student`, bt.transaction_no `Transaction`, b.title `Book`, c.accession_number `Accession`, DATE(bt.borrowed_at) `Borrowed`, bi.due_date `Due date`, COALESCE(DATE(bi.returned_at),'') `Returned`, bi.status `Status` $loan WHERE DATE(bt.borrowed_at) BETWEEN ? AND ? ORDER BY bt.borrowed_at DESC", [$o['from'], $o['to']]], true, false],
        'most_borrowed' => ['Most Borrowed Books', 'month', fn($o) => ["SELECT b.title `Book`, b.author `Author`, COUNT(*) `Times borrowed` $loan WHERE DATE(bt.borrowed_at) BETWEEN ? AND ? GROUP BY b.id, b.title, b.author ORDER BY COUNT(*) DESC, b.title", [$o['from'], $o['to']]], true, false],
        'student_summary' => ['Student Borrowing Summary', 'month', fn($o) => ["SELECT s.student_id `Student ID`, $stu `Student`, s.grade_level `Year Level`, COALESCE(s.section,'') `Course`, " . $sub('AND DATE(bt.borrowed_at) BETWEEN ? AND ?') . " `Borrowed in range`, " . $sub("AND bi.status='borrowed'") . " `Currently borrowed`, " . $sub("AND bi.status='borrowed' AND bi.due_date < CURDATE()") . " `Overdue`, (SELECT COALESCE(SUM(COALESCE(adjusted_amount, amount)),0) FROM penalties WHERE student_id=s.id AND status='unpaid') `Unpaid penalties` FROM students s WHERE s.is_archived=0 ORDER BY s.last_name, s.first_name", [$o['from'], $o['to']]], true, false],
        'personnel' => ['Library Personnel Activity', 'month', fn($o) => ["SELECT u.full_name `Name`, u.username `Username`, r.name `Role`, (SELECT COUNT(*) FROM borrowing_transactions bt WHERE bt.processed_by=u.id AND DATE(bt.borrowed_at) BETWEEN ? AND ?) `Borrow transactions`, (SELECT COUNT(*) FROM returns rt WHERE rt.processed_by=u.id AND DATE(rt.returned_at) BETWEEN ? AND ?) `Returns processed`, (SELECT COUNT(*) FROM activity_logs a WHERE a.user_id=u.id AND DATE(a.created_at) BETWEEN ? AND ?) `Logged actions`, COALESCE(u.last_login_at,'') `Last login` FROM users u JOIN roles r ON r.id=u.role_id WHERE u.role_id IN (1,2) ORDER BY u.full_name", [$o['from'], $o['to'], $o['from'], $o['to'], $o['from'], $o['to']]], true, true],
    ];
}

function report_default_range(string $kind): array
{
    $today = date('Y-m-d');
    return match ($kind) { 'today' => [$today, $today], 'week' => [date('Y-m-d', strtotime('-6 days')), $today], default => [date('Y-m-01'), $today] };
}

/** Runs a report; optional free-text filter matches any column. */
function run_report(array $def, array $o): array
{
    [$sql, $params] = $def[2]($o);
    if ($o['q'] !== '') {
        $probe = db()->prepare("SELECT * FROM ($sql) x LIMIT 0"); $probe->execute($params);
        $cols = []; for ($i = 0; $i < $probe->columnCount(); $i++) $cols[] = 'x.`' . $probe->getColumnMeta($i)['name'] . '`';
        $sql = "SELECT * FROM ($sql) x WHERE CONCAT_WS(' ', " . implode(',', $cols) . ') LIKE ?';
        $params[] = '%' . $o['q'] . '%';
    }
    $st = db()->prepare($sql); $st->execute($params);
    return $st->fetchAll();
}