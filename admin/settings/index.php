<?php
require_once __DIR__ . '/../../includes/auth.php';
$user = require_role('admin');
$pdo = db(); $errors = [];
$fields = [ // key => [label, type, min, max]
  'school_name' => ['School name', 'text'], 'library_name' => ['Library name', 'text'], 'library_contact' => ['Library contact information', 'text'],
  'library_address' => ['Library address', 'text'], 'operating_hours' => ['Operating hours', 'text'], 'academic_year' => ['Academic year', 'text'],
  'max_books_per_student' => ['Maximum books per student', 'int', 1, 50], 'borrow_duration_days' => ['Default borrowing duration (days)', 'int', 1, 365],
  'penalty_per_day' => ['Penalty per overdue day', 'dec', 0, 100000], 'max_overdue_days' => ['Maximum penalised overdue days (0 = no cap)', 'int', 0, 3650],
];
$cur = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$v = $cur;
if (is_post()) {
    csrf_require();
    foreach ($fields as $k => $f) {
        $val = clean($_POST[$k] ?? '');
        if ($f[1] === 'int' && (!ctype_digit($val) || (int)$val < $f[2] || (int)$val > $f[3])) $errors[] = "{$f[0]} must be a whole number between {$f[2]} and {$f[3]}.";
        if ($f[1] === 'dec' && (!is_numeric($val) || (float)$val < $f[2] || (float)$val > $f[3])) $errors[] = "{$f[0]} must be a number between {$f[2]} and {$f[3]}.";
        if ($f[1] === 'text' && mb_strlen($val) > 255) $errors[] = "{$f[0]} is too long.";
        $v[$k] = $val;
    }
    $v['borrower_rules'] = trim((string)($_POST['borrower_rules'] ?? ''));
    foreach (['courses', 'year_levels'] as $lk) {
        $lines = array_values(array_unique(array_filter(array_map('clean', preg_split('/\R/', (string)($_POST[$lk] ?? ''))))));
        $v[$lk] = implode("\n", $lines);
    }
    $v['block_borrow_if_overdue'] = isset($_POST['block_borrow_if_overdue']) ? '1' : '0';
    $v['block_borrow_if_unpaid_penalty'] = isset($_POST['block_borrow_if_unpaid_penalty']) ? '1' : '0';
    if (in_array($v['school_name'], [''], true)) $errors[] = 'School name is required.';
    $logo = null; $deleteOld = null;
    try { $logo = save_image_upload($_FILES['logo'] ?? [], 'school'); if ($logo) { $v['school_logo'] = $logo; $deleteOld = $cur['school_logo'] ?? null; } } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    if (!empty($_POST['remove_logo']) && !$logo) { $deleteOld = $cur['school_logo'] ?? null; $v['school_logo'] = ''; }   // back to the default seal
    if (!$errors) {
        $up = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)');
        $changed = [];
        foreach ($v as $k => $val) { if (($cur[$k] ?? null) !== $val) { $up->execute([$k, $val, $user['id']]); $changed[] = $k; } }
        if ($deleteOld && basename($deleteOld) !== '' && is_file(BASE_PATH . '/uploads/school/' . basename($deleteOld))) @unlink(BASE_PATH . '/uploads/school/' . basename($deleteOld));
        if ($changed) log_activity('settings_update', 'Settings changed: ' . implode(', ', $changed), 'system_settings');
        flash('success', $changed ? 'Settings saved.' : 'No changes.'); redirect('admin/settings/index.php');
    }
}
$pageTitle = 'System Settings';
include __DIR__ . '/../../includes/header.php';
?>
<div class="card p-3 p-md-4" style="max-width:900px">
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" novalidate><?= csrf_field() ?><div class="row g-3">
  <?php foreach ($fields as $k => $f): ?><div class="col-12 col-md-6"><label class="form-label"><?= e($f[0]) ?></label><input class="form-control" name="<?= e($k) ?>" value="<?= e($v[$k] ?? '') ?>" <?= $f[1] !== 'text' ? 'inputmode="decimal"' : '' ?>></div><?php endforeach; ?>
  <div class="col-12"><label class="form-label">Borrower account rules</label><textarea class="form-control" name="borrower_rules" rows="2"><?= e($v['borrower_rules'] ?? '') ?></textarea></div>
  <div class="col-12 col-md-6"><label class="form-label">Courses <small class="text-muted">(one per line, e.g. BSIT)</small></label><textarea class="form-control" name="courses" rows="5"><?= e($v['courses'] ?? '') ?></textarea></div>
  <div class="col-12 col-md-6"><label class="form-label">Year levels <small class="text-muted">(one per line)</small></label><textarea class="form-control" name="year_levels" rows="5"><?= e($v['year_levels'] ?? "1st Year\n2nd Year\n3rd Year\n4th Year") ?></textarea></div>
  <div class="col-12 col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="block_borrow_if_overdue" id="b1" <?= ($v['block_borrow_if_overdue'] ?? '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="b1">Block borrowing when the student has overdue books</label></div></div>
  <div class="col-12 col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="block_borrow_if_unpaid_penalty" id="b2" <?= ($v['block_borrow_if_unpaid_penalty'] ?? '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="b2">Block borrowing when the student has unpaid penalties</label></div></div>
  <div class="col-12"><label class="form-label">School logo <small class="text-muted">(JPG/PNG/WEBP, max 5 MB)</small></label><input class="form-control" type="file" name="logo" accept="image/jpeg,image/png,image/webp">
    <?php if (!empty($v['school_logo'])): ?><img class="mt-2 rounded border d-block" style="height:64px" src="<?= e(url('uploads/school/' . $v['school_logo'])) ?>" alt="Logo">
      <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_logo" id="rmlogo"><label class="form-check-label" for="rmlogo">Remove this logo (go back to the default Southway seal)</label></div><?php endif; ?></div>
</div>
<div class="d-flex flex-wrap gap-2 mt-4"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save settings</button>
  <a class="btn btn-outline-secondary" href="<?= e(url('admin/categories/index.php')) ?>"><i class="bi bi-tags"></i> Book categories</a>
  <a class="btn btn-outline-secondary" href="<?= e(url('admin/settings/backup.php')) ?>"><i class="bi bi-database"></i> Backup &amp; restore</a></div>
</form></div>
<?php include __DIR__ . '/../../includes/footer.php';