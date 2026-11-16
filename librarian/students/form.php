<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/qr.php';
$user = require_role('librarian', 'admin');
$pdo = db(); $id = (int)($_GET['id'] ?? 0); $errors = [];
$stu = null;
if ($id) { $st = $pdo->prepare('SELECT * FROM students WHERE id=? AND is_archived=0'); $st->execute([$id]); $stu = $st->fetch();
  if (!$stu) { flash('danger', 'Student not found.'); redirect('librarian/students/index.php'); } }
$keys = ['student_id', 'first_name', 'middle_name', 'last_name', 'gender', 'grade_level', 'section', 'contact_number', 'email', 'address'];
$f = $stu ?: array_fill_keys($keys, '');
if (is_post()) {
    csrf_require();
    foreach ($keys as $k) $f[$k] = clean($_POST[$k] ?? '');
    $pw = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9\-_.\/]{3,30}$/', $f['student_id'])) $errors[] = 'Student ID must be 3-30 characters (letters, numbers, - _ . /).';
    if ($f['first_name'] === '' || $f['last_name'] === '') $errors[] = 'First and last name are required.';
    if (!in_array($f['gender'], ['Male', 'Female', 'Other'], true)) $errors[] = 'Select a gender.';
    if ($f['grade_level'] === '') $errors[] = 'Year level is required.';
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if ($f['contact_number'] !== '' && !preg_match('/^[0-9+\-\s()]{7,30}$/', $f['contact_number'])) $errors[] = 'Contact number is not valid.';
    $d = $pdo->prepare('SELECT id FROM students WHERE student_id = ? AND id <> ?'); $d->execute([$f['student_id'], $id]);
    if ($d->fetch()) $errors[] = 'A student with this Student ID is already registered.';
    if ($pw !== '' && ($e = password_error($pw))) $errors[] = $e;
    $photo = null;
    try { $photo = save_image_upload($_FILES['photo'] ?? [], 'students'); } catch (RuntimeException $ex) { $errors[] = $ex->getMessage(); }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $vals = [$f['student_id'], $f['first_name'], $f['middle_name'] ?: null, $f['last_name'], $f['gender'], $f['grade_level'], $f['section'] ?: null, $f['contact_number'] ?: null, $f['email'] ?: null, $f['address'] ?: null];
            if ($id) {
                $pdo->prepare('UPDATE students SET student_id=?, first_name=?, middle_name=?, last_name=?, gender=?, grade_level=?, section=?, contact_number=?, email=?, address=? WHERE id=?')->execute([...$vals, $id]);
                if ($photo) $pdo->prepare('UPDATE students SET photo=? WHERE id=?')->execute([$photo, $id]);
                log_activity('student_update', "Student {$f['student_id']} updated", 'students', $id);
            } else {
                $pdo->prepare('INSERT INTO students (student_id, first_name, middle_name, last_name, gender, grade_level, section, contact_number, email, address, qr_code_id, photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([...$vals, unique_code('STU', 'students'), $photo]);
                $id = (int)$pdo->lastInsertId();
                log_activity('student_register', "Student {$f['student_id']} registered", 'students', $id);
            }
            // optional portal account: username = student ID
            if ($pw !== '') {
                $cur = $pdo->prepare('SELECT user_id FROM students WHERE id=?'); $cur->execute([$id]); $uid = $cur->fetchColumn();
                $full = $f['first_name'] . ' ' . $f['last_name'];
                if ($uid) { $pdo->prepare('UPDATE users SET password_hash=?, full_name=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $full, $uid]); log_activity('student_password_reset', "Portal password set for {$f['student_id']}", 'students', $id); }
                else {
                    $x = $pdo->prepare('SELECT id FROM users WHERE username=?'); $x->execute([$f['student_id']]);
                    if ($x->fetch()) throw new RuntimeException('A user account named "' . $f['student_id'] . '" already exists.');
                    $pdo->prepare('INSERT INTO users (role_id, username, password_hash, full_name, contact_number) VALUES (3,?,?,?,?)')->execute([$f['student_id'], password_hash($pw, PASSWORD_DEFAULT), $full, $f['contact_number'] ?: null]);
                    $pdo->prepare('UPDATE students SET user_id=? WHERE id=?')->execute([$pdo->lastInsertId(), $id]);
                    log_activity('student_account_create', "Portal account created for {$f['student_id']}", 'students', $id);
                }
            }
            $pdo->commit();
            flash('success', $stu ? 'Student updated.' : 'Student registered with a unique QR code.');
            redirect('librarian/students/view.php?id=' . $id);
        } catch (RuntimeException $ex) { $pdo->rollBack(); $errors[] = $ex->getMessage();
        } catch (PDOException $ex) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log($ex->getMessage()); $errors[] = 'A database error occurred. Nothing was saved.'; }
    }
}
$pageTitle = $stu ? 'Edit Student' : 'Register Student';
include __DIR__ . '/../../includes/header.php';
$in = fn(string $k, string $label, string $extra = '', string $col = 'col-12 col-md-4') => print('<div class="' . $col . '"><label class="form-label">' . $label . '</label><input class="form-control" name="' . $k . '" value="' . e($f[$k]) . '" ' . $extra . '></div>');
$years = setting_list('year_levels', ['1st Year', '2nd Year', '3rd Year', '4th Year']); $courses = setting_list('courses');
$pick = function (string $k, string $label, array $opts, string $col, string $hint) use (&$f) {
    $cur = (string)($f[$k] ?? '');
    if (!$opts) { print('<div class="' . $col . '"><label class="form-label">' . $label . '</label><input class="form-control" name="' . $k . '" value="' . e($cur) . '" placeholder="' . e($hint) . '"></div>'); return; }
    if ($cur !== '' && !in_array($cur, $opts, true)) array_unshift($opts, $cur);   // keep an existing value that is no longer in the list
    $h = '<div class="' . $col . '"><label class="form-label">' . $label . '</label><select class="form-select" name="' . $k . '"><option value="">Select…</option>';
    foreach ($opts as $o) $h .= '<option ' . ($cur === $o ? 'selected' : '') . '>' . e($o) . '</option>';
    print($h . '</select></div>');
};
?>
<div class="card p-3 p-md-4" style="max-width:900px">
<?php foreach ($errors as $er): ?><div class="alert alert-danger py-2"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" enctype="multipart/form-data" novalidate><?= csrf_field() ?><div class="row g-3">
  <?php $in('student_id', 'Student ID *', 'required'); $in('first_name', 'First name *', 'required'); $in('middle_name', 'Middle name'); $in('last_name', 'Last name *', 'required'); ?>
  <div class="col-12 col-md-4"><label class="form-label">Gender *</label><select class="form-select" name="gender"><option value="">Select…</option><?php foreach (['Male', 'Female', 'Other'] as $g): ?><option <?= $f['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></div>
  <?php $pick('grade_level', 'Year level *', $years, 'col-6 col-md-4', 'e.g. 1st Year'); $pick('section', 'Course', $courses, 'col-6 col-md-4', 'e.g. BSIT'); $in('contact_number', 'Contact number', 'inputmode="tel"', 'col-12 col-md-4'); $in('email', 'Email', 'type="email"', 'col-12 col-md-8'); $in('address', 'Address', '', 'col-12'); ?>
  <div class="col-12 col-md-6"><label class="form-label">Profile photo <small class="text-muted">(optional, max 2 MB)</small></label><input class="form-control" type="file" name="photo" accept="image/jpeg,image/png,image/webp"></div>
  <div class="col-12 col-md-6"><label class="form-label">Portal password <small class="text-muted">(optional; username will be the Student ID)</small></label><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?= $stu && $stu['user_id'] ? 'Leave blank to keep current' : 'Leave blank for no portal login' ?>"></div>
</div>
<div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit"><i class="bi bi-check2"></i> Save</button><a class="btn btn-light" href="<?= e(url($stu ? 'librarian/students/view.php?id=' . $id : 'librarian/students/index.php')) ?>">Cancel</a></div>
</form></div>
<?php include __DIR__ . '/../../includes/footer.php';