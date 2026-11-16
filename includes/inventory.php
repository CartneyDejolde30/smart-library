<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/** Expected (available, matching filters), scanned, missing and unexpected copies for an inventory session. */
function inventory_state(array $session): array
{
    $pdo = db();
    $sql = "SELECT c.id, c.accession_number, c.qr_code_id, c.shelf_location, c.status, b.title FROM book_copies c JOIN books b ON b.id = c.book_id
            WHERE c.is_archived = 0 AND c.status = 'available'"; $p = [];
    if ($session['shelf_filter'] !== null && $session['shelf_filter'] !== '') { $sql .= ' AND c.shelf_location = ?'; $p[] = $session['shelf_filter']; }
    if ($session['category_filter']) { $sql .= ' AND b.category_id = ?'; $p[] = $session['category_filter']; }
    $e = $pdo->prepare($sql . ' ORDER BY c.shelf_location, b.title'); $e->execute($p);
    $expected = array_column($e->fetchAll(), null, 'id');

    $s = $pdo->prepare('SELECT sc.*, c.accession_number, c.status AS cstatus, c.shelf_location, b.title FROM inventory_scans sc
                        LEFT JOIN book_copies c ON c.id = sc.copy_id LEFT JOIN books b ON b.id = c.book_id WHERE sc.session_id = ? ORDER BY sc.id DESC');
    $s->execute([$session['id']]); $scans = $s->fetchAll();

    $scannedIds = []; $unexpected = [];
    foreach ($scans as $sc) {
        if ($sc['copy_id'] && isset($expected[$sc['copy_id']])) $scannedIds[$sc['copy_id']] = true;
        else $unexpected[] = ['code' => $sc['scanned_code'], 'title' => $sc['title'], 'accession' => $sc['accession_number'],
                              'reason' => $sc['copy_id'] ? "Status is '{$sc['cstatus']}' or outside this filter" : 'Unregistered QR code'];
    }
    $missing = array_values(array_filter($expected, fn($c) => !isset($scannedIds[$c['id']])));
    return ['expected' => count($expected), 'scanned' => count($scannedIds), 'missing' => $missing, 'unexpected' => $unexpected, 'total_scans' => count($scans)];
}