<?php
require_once __DIR__ . '/../../includes/auth.php';
$user = require_role('librarian', 'admin');
$ids = array_values(array_filter(array_map('intval', array_merge([(int)($_GET['id'] ?? 0)], (array)($_GET['ids'] ?? [])))));
if (!$ids) { flash('warning', 'No student selected.'); redirect('librarian/students/index.php'); }
$in = implode(',', array_fill(0, count($ids), '?'));
$st = db()->prepare("SELECT * FROM students WHERE is_archived=0 AND id IN ($in) ORDER BY last_name, first_name"); $st->execute($ids); $rows = $st->fetchAll();
$pageTitle = 'Student QR ID Card';
$extraScripts = ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', url('assets/js/qr.js')];
include __DIR__ . '/../../includes/header.php';
?>
<div class="no-print mb-3 d-flex gap-2"><button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  <?php if (count($rows) === 1): ?><button class="btn btn-outline-secondary" data-qr-download="#cardqr0" data-name="<?= e($rows[0]['student_id']) ?>"><i class="bi bi-download"></i> Download QR</button><?php endif; ?>
  <button class="btn btn-light" onclick="history.back()">Back</button></div>
<?php if (!$rows): ?><div class="empty-state"><i class="bi bi-person-vcard"></i><p class="mt-2">Student not found.</p></div><?php endif; ?>
<div class="d-flex flex-wrap gap-3">
<?php foreach ($rows as $i => $s): ?>
  <div class="id-card"><div class="hd d-flex align-items-center gap-2"><img class="sl-logo" src="<?= e(school_logo_url()) ?>" alt=""><div><?= e(setting('school_name', APP_NAME)) ?><div class="small fw-normal opacity-75"><?= e(setting('library_name')) ?> · Library ID</div></div></div>
    <div class="p-3 d-flex gap-3 align-items-center">
      <div class="flex-grow-1">
        <?php if ($s['photo']): ?><img class="ph mb-2" src="<?= e(url('uploads/students/' . $s['photo'])) ?>" alt=""><?php endif; ?>
        <div class="fw-bold"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
        <div class="small text-muted">ID: <?= e($s['student_id']) ?></div>
        <div class="small text-muted"><?= e($s['grade_level'] . ($s['section'] ? ' – ' . $s['section'] : '')) ?></div></div>
      <div class="text-center"><div class="p-1 bg-white" id="cardqr<?= $i ?>" data-qr="<?= e($s['qr_code_id']) ?>" data-size="110"></div><div class="font-monospace" style="font-size:.6rem"><?= e($s['qr_code_id']) ?></div></div>
    </div></div>
<?php endforeach; ?></div>
<?php include __DIR__ . '/../../includes/footer.php';