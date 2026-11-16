<?php
require_once __DIR__ . '/../../includes/auth.php';
$user = require_role('admin');
$pdo = db();
$dir = BASE_PATH . '/storage/backups';
const TABLES = ['roles','users','login_attempts','password_resets','students','book_categories','books','book_copies','borrowing_transactions','borrowing_items','returns','penalties','lost_books','damaged_books','inventory_sessions','inventory_scans','inventory_logs','notifications','activity_logs','system_settings','backups'];
const FILE_RE = '/^smart_library_\d{8}_\d{6}(_pre_restore)?\.sql$/';

function make_backup(string $dir, string $suffix = ''): string
{
    $pdo = db(); if (!is_dir($dir)) mkdir($dir, 0775, true);
    $name = 'smart_library_' . date('Ymd_His') . $suffix . '.sql';
    $fh = fopen("$dir/$name", 'w'); if (!$fh) throw new RuntimeException('Cannot write to storage/backups.');
    fwrite($fh, "-- Smart Library backup\n-- Generated " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n");
    foreach (TABLES as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n" . str_replace(["\r", "\n"], ' ', $create) . ";\n");
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll() as $c) if (stripos($c['Extra'], 'GENERATED') === false) $cols[] = $c['Field'];
        $list = '`' . implode('`,`', $cols) . '`'; $batch = [];
        $rows = $pdo->query("SELECT $list FROM `$t`");
        while ($r = $rows->fetch(PDO::FETCH_NUM)) {
            $batch[] = '(' . implode(',', array_map(fn($x) => $x === null ? 'NULL' : str_replace(["\r", "\n"], ['\\r', '\\n'], $pdo->quote((string)$x)), $r)) . ')';
            if (count($batch) >= 100) { fwrite($fh, "INSERT INTO `$t` ($list) VALUES " . implode(',', $batch) . ";\n"); $batch = []; }
        }
        if ($batch) fwrite($fh, "INSERT INTO `$t` ($list) VALUES " . implode(',', $batch) . ";\n");
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n"); fclose($fh);
    return $name;
}

/** Validates the file line by line; returns list of statements or throws RuntimeException. */
function validate_backup(string $content): array
{
    $lines = preg_split('/\r?\n/', $content);
    if (!str_starts_with($lines[0] ?? '', '-- Smart Library backup')) throw new RuntimeException('This is not a Smart Library backup file.');
    $tbl = implode('|', TABLES); $stmts = [];
    foreach ($lines as $n => $line) {
        if ($line === '' || str_starts_with($line, '--')) continue;
        $ok = preg_match('/^(DROP TABLE IF EXISTS `(' . $tbl . ')`;|CREATE TABLE `(' . $tbl . ')` \(.*;|INSERT INTO `(' . $tbl . ')` \(.*;|SET FOREIGN_KEY_CHECKS=[01];)$/s', $line);
        $bare = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", "''", $line);          // strip string literals
        if (!$ok || substr_count($bare, ';') !== 1 || !str_ends_with($line, ';')) throw new RuntimeException('Unsafe or unrecognised statement on line ' . ($n + 1) . '. Restore cancelled.');
        $stmts[] = $line;
    }
    if (count($stmts) < 5) throw new RuntimeException('The backup file is empty or incomplete.');
    return $stmts;
}

if (is_post()) {
    csrf_require(); $act = $_POST['action'] ?? '';
    $file = basename((string)($_POST['file'] ?? ''));
    try {
        if ($act === 'backup') {
            $name = make_backup($dir);
            $pdo->prepare("INSERT INTO backups (filename, size_bytes, type, created_by) VALUES (?,?, 'backup', ?)")->execute([$name, filesize("$dir/$name"), $user['id']]);
            log_activity('backup_create', "Database backup $name created"); flash('success', "Backup created: $name");
        } elseif ($act === 'download' && preg_match(FILE_RE, $file) && is_file("$dir/$file")) {
            log_activity('backup_download', "Backup $file downloaded");
            header('Content-Type: application/sql'); header('Content-Disposition: attachment; filename="' . $file . '"'); header('Content-Length: ' . filesize("$dir/$file")); readfile("$dir/$file"); exit;
        } elseif ($act === 'delete' && preg_match(FILE_RE, $file) && is_file("$dir/$file")) {
            unlink("$dir/$file"); log_activity('backup_delete', "Backup $file deleted"); flash('success', 'Backup deleted.');
        } elseif ($act === 'restore') {
            if (($_POST['confirm_text'] ?? '') !== 'RESTORE') throw new RuntimeException('Type RESTORE in capital letters to confirm.');
            if (!empty($_FILES['upload']['tmp_name']) && $_FILES['upload']['error'] === UPLOAD_ERR_OK) {
                if ($_FILES['upload']['size'] > 100 * 1024 * 1024) throw new RuntimeException('File is too large (max 100 MB).');
                if (strtolower(pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION)) !== 'sql') throw new RuntimeException('Only .sql backup files are accepted.');
                $content = file_get_contents($_FILES['upload']['tmp_name']); $src = 'uploaded file';
            } elseif (preg_match(FILE_RE, $file) && is_file("$dir/$file")) { $content = file_get_contents("$dir/$file"); $src = $file; }
            else throw new RuntimeException('Choose a backup from the list or upload a .sql file.');
            $stmts = validate_backup($content);                       // 1) validate everything first
            $safety = make_backup($dir, '_pre_restore');               // 2) automatic safety copy of the current data
            try { foreach ($stmts as $s) $pdo->exec($s); }             // 3) restore
            finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
            log_activity('backup_restore', "Database restored from $src (safety copy: $safety)");
            flash('success', "Database restored. A safety copy of the previous data was saved as $safety.");
        }
    } catch (RuntimeException $ex) { flash('danger', $ex->getMessage());
    } catch (PDOException $ex) { error_log($ex->getMessage()); flash('danger', 'The operation failed: ' . $ex->getMessage() . ' If a restore was interrupted, restore the latest *_pre_restore.sql file.'); }
    redirect('admin/settings/backup.php');
}
$files = is_dir($dir) ? glob("$dir/smart_library_*.sql") : []; rsort($files);
$creators = $pdo->query('SELECT b.filename, u.full_name FROM backups b LEFT JOIN users u ON u.id=b.created_by')->fetchAll(PDO::FETCH_KEY_PAIR);
$pageTitle = 'Backup & Restore';
include __DIR__ . '/../../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card p-3"><div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0">Backup history</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-database-add"></i> Create backup now</button></form></div>
    <?php if (!$files): ?><div class="empty-state py-3"><i class="bi bi-database"></i><p class="mb-0 mt-2">No backups yet.</p></div><?php else: ?>
    <table class="table table-cards table-sm align-middle mb-0"><thead><tr><th>File</th><th>Size</th><th>Created</th><th></th></tr></thead><tbody>
    <?php foreach ($files as $f): $b = basename($f); ?><tr><td data-label="File" class="small"><?= e($b) ?><div class="text-muted"><?= e($creators[$b] ?? '') ?></div></td><td data-label="Size"><?= number_format(filesize($f) / 1024, 1) ?> KB</td><td data-label="Created"><?= e(date('M j, Y g:i A', filemtime($f))) ?></td>
      <td data-label="" class="text-end text-nowrap">
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="download"><input type="hidden" name="file" value="<?= e($b) ?>"><button class="btn btn-sm btn-outline-primary" title="Download"><i class="bi bi-download"></i></button></form>
        <form method="post" class="d-inline" data-confirm="Delete backup <?= e($b) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= e($b) ?>"><button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
  <div class="col-lg-5"><div class="card p-3 border-danger-subtle"><h2 class="h6 text-danger"><i class="bi bi-exclamation-octagon"></i> Restore database</h2>
    <p class="small text-muted">Restoring <strong>replaces all current data</strong>. The file is validated first and a safety backup of the current data is created automatically.</p>
    <form method="post" enctype="multipart/form-data" data-confirm="Replace ALL current data with the selected backup? This cannot be undone (a safety copy will be saved)."><?= csrf_field() ?><input type="hidden" name="action" value="restore">
      <label class="form-label small">Choose a saved backup</label><select class="form-select mb-2" name="file"><option value="">— none —</option><?php foreach ($files as $f): ?><option><?= e(basename($f)) ?></option><?php endforeach; ?></select>
      <label class="form-label small">…or upload a .sql backup</label><input class="form-control mb-2" type="file" name="upload" accept=".sql">
      <label class="form-label small">Type <strong>RESTORE</strong> to confirm</label><input class="form-control mb-3" name="confirm_text" autocomplete="off">
      <button class="btn btn-danger w-100" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php';