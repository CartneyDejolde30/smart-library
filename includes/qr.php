<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/** Opaque, random, unique QR identifier. Contains no personal data. */
function unique_code(string $prefix, string $table): string
{
    if (!in_array($table, ['book_copies', 'students'], true)) throw new InvalidArgumentException('bad table');
    $st = db()->prepare("SELECT 1 FROM $table WHERE qr_code_id = ?");
    do { $c = $prefix . '-' . strtoupper(bin2hex(random_bytes(5))); $st->execute([$c]); } while ($st->fetchColumn());
    return $c;
}

/** Creates $qty physical copies with unique accession numbers + QR ids. Call inside a transaction. @return int[] ids */
function create_copies(int $bookId, int $qty, ?string $shelf): array
{
    $pdo = db(); $ids = [];
    $ins = $pdo->prepare('INSERT INTO book_copies (book_id, accession_number, qr_code_id, shelf_location) VALUES (?,?,?,?)');
    $upd = $pdo->prepare('UPDATE book_copies SET accession_number = ? WHERE id = ?');
    for ($i = 0; $i < $qty; $i++) {
        $qr = unique_code('BK', 'book_copies');
        $ins->execute([$bookId, 'TMP-' . $qr, $qr, $shelf ?: null]);
        $id = (int)$pdo->lastInsertId();
        $upd->execute([sprintf('ACC-%s-%05d', date('Y'), $id), $id]);   // derived from the unique PK => never collides
        $ids[] = $id;
    }
    return $ids;
}