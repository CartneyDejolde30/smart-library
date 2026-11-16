<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('librarian', 'admin');
$pageTitle = 'QR Scanner';
$manualHint = 'Manual entry: any QR code, accession no. or student ID';
$extraScripts = ['https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js', url('assets/js/scanner.js')];
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5"><?php include __DIR__ . '/../includes/scanner_card.php'; ?></div>
  <div class="col-lg-7"><div class="card p-3" id="resultBox"><div class="empty-state py-4"><i class="bi bi-qr-code-scan"></i><p class="mb-0 mt-2">Scan any book or student QR code to look it up.</p></div></div></div>
</div>
<div class="d-flex flex-wrap gap-2 mt-3">
  <a class="btn btn-outline-primary" href="<?= e(url('librarian/borrow.php')) ?>"><i class="bi bi-box-arrow-up-right"></i> Borrow</a>
  <a class="btn btn-outline-primary" href="<?= e(url('librarian/return.php')) ?>"><i class="bi bi-box-arrow-in-down-left"></i> Return</a>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var esc = SL.esc, box = document.getElementById('resultBox');
  var badge = function (s) { var m = { available: 'success', borrowed: 'primary', overdue: 'danger', lost: 'dark', damaged: 'warning' }; return '<span class="badge text-bg-' + (m[s] || 'secondary') + '">' + esc(s.charAt(0).toUpperCase() + s.slice(1)) + '</span>'; };
  SL.bindScanner(async function (code) {
    var r = await SL.api('identify', { code: code });
    if (!r.ok) { SL.status(r.error, 'danger'); return; }
    if (r.type === 'student') {
      var s = r.student;
      box.innerHTML = '<div class="small text-muted">Student</div><h5>' + esc(s.name) + '</h5><div class="text-muted small mb-2">ID ' + esc(s.student_id) + ' · ' + esc(s.grade) + '</div>' +
        '<div class="mb-2">' + (s.eligible ? '<span class="badge text-bg-success">Eligible to borrow</span>' : '<span class="badge text-bg-danger">Not eligible</span>') + ' <span class="badge text-bg-light border">Borrowed ' + s.active + ' / ' + s.max + '</span></div>' +
        (s.reasons.length ? '<ul class="small text-danger">' + s.reasons.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '') +
        '<a class="btn btn-sm btn-primary" href="' + esc(s.link) + '">Open profile</a>';
      SL.status('Student identified.', 'success');
    } else {
      var b = r.book;
      box.innerHTML = '<div class="small text-muted">Book copy</div><h5>' + esc(b.title) + '</h5><div class="text-muted small mb-2">' + esc(b.author) + ' · ' + esc(b.accession) + (b.shelf ? ' · Shelf ' + esc(b.shelf) : '') + '</div>' +
        '<div class="mb-2">' + badge(b.status) + '</div>' + (b.borrower ? '<div class="small">Borrowed by <strong>' + esc(b.borrower) + '</strong>, due ' + esc(b.due) + '</div>' : '') +
        '<a class="btn btn-sm btn-primary mt-2" href="' + esc(b.link) + '">Open book</a>';
      SL.status('Book identified.', 'success');
    }
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php';