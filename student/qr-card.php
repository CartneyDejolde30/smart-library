<?php
require_once __DIR__ . '/../includes/student_portal.php';
$pageTitle = 'My QR Card';
$extraScripts = ['https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', url('assets/js/qr.js')];
include __DIR__ . '/../includes/header.php';
?>
<div class="no-print mb-3 d-flex gap-2"><button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  <button class="btn btn-outline-secondary" data-qr-download="#myqr" data-name="<?= e($me['student_id']) ?>"><i class="bi bi-download"></i> Download QR</button></div>
<div class="id-card"><div class="hd d-flex align-items-center gap-2"><img class="sl-logo" src="<?= e(school_logo_url()) ?>" alt=""><div><?= e(setting('school_name', APP_NAME)) ?><div class="small fw-normal opacity-75"><?= e(setting('library_name')) ?> · Library ID</div></div></div>
  <div class="p-3 d-flex gap-3 align-items-center"><div class="flex-grow-1">
    <?php if ($me['photo']): ?><img class="ph mb-2" src="<?= e(url('uploads/students/' . $me['photo'])) ?>" alt=""><?php endif; ?>
    <div class="fw-bold"><?= e($me['first_name'] . ' ' . $me['last_name']) ?></div><div class="small text-muted">ID: <?= e($me['student_id']) ?></div>
    <div class="small text-muted"><?= e($me['grade_level'] . ($me['section'] ? ' – ' . $me['section'] : '')) ?></div></div>
    <div class="text-center"><div class="p-1 bg-white" id="myqr" data-qr="<?= e($me['qr_code_id']) ?>" data-size="120"></div><div class="font-monospace" style="font-size:.6rem"><?= e($me['qr_code_id']) ?></div></div></div></div>
<p class="text-muted small mt-3 no-print">Show this QR code to the librarian when borrowing books. Do not share it with others.</p>
<?php include __DIR__ . '/../includes/footer.php';