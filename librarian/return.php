<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('librarian', 'admin');
$pageTitle = 'Return Book';
$manualHint = 'Manual entry: book QR code or accession no.';
$extraScripts = ['https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js', url('assets/js/scanner.js')];
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="alert alert-light border py-2 small mb-2"><i class="bi bi-qr-code-scan me-1"></i> <strong>Scan the book being returned</strong></div>
    <?php include __DIR__ . '/../includes/scanner_card.php'; ?>
  </div>
  <div class="col-lg-7"><div class="card p-3" id="loanBox"></div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (id) { return document.getElementById(id); }, esc = SL.esc;
  var IS_ADMIN = <?= $user['role'] === 'admin' ? 'true' : 'false' ?>;
  var loan = null, sending = false;

  function empty() { $('loanBox').innerHTML = '<div class="empty-state py-4"><i class="bi bi-box-arrow-in-down-left"></i><p class="mb-0 mt-2">Scan a borrowed book to start the return.</p></div>'; }

  function show() {
    var late = loan.days_overdue > 0;
    $('loanBox').innerHTML =
      '<h3 class="h6">' + esc(loan.title) + '</h3><div class="small text-muted mb-2">' + esc(loan.author) + ' · ' + esc(loan.accession) + '</div>' +
      '<dl class="row small mb-2"><dt class="col-4">Borrower</dt><dd class="col-8">' + esc(loan.student) + ' (' + esc(loan.sno) + ')<br><span class="text-muted">' + esc(loan.grade) + '</span></dd>' +
      '<dt class="col-4">Borrowed</dt><dd class="col-8">' + esc(loan.borrowed_at) + '</dd>' +
      '<dt class="col-4">Due</dt><dd class="col-8">' + esc(loan.due) + '</dd>' +
      '<dt class="col-4">Status</dt><dd class="col-8">' + (late ? '<span class="badge text-bg-danger">Overdue by ' + loan.days_overdue + ' day(s)</span>' : '<span class="badge text-bg-success">On time</span>') + '</dd>' +
      '<dt class="col-4">Penalty</dt><dd class="col-8"><strong class="' + (late ? 'text-danger' : '') + '">' + SL.money(loan.penalty) + '</strong></dd></dl>' +
      '<div class="row g-2"><div class="col-12 col-md-5"><label class="form-label small mb-1">Condition</label><select id="cond" class="form-select"><option value="good">Good</option><option value="damaged">Damaged</option></select></div>' +
      '<div class="col-12 col-md-7"><label class="form-label small mb-1">Remarks</label><input id="remarks" class="form-control" maxlength="255" placeholder="Optional"></div>' +
      (IS_ADMIN && loan.penalty > 0 ? '<div class="col-12 col-md-5"><label class="form-label small mb-1">Adjust penalty (admin)</label><input id="adj" type="number" step="0.01" min="0" max="' + loan.penalty + '" class="form-control" placeholder="' + SL.money(loan.penalty) + '"></div>' +
        '<div class="col-12 col-md-7"><label class="form-label small mb-1">Reason for adjustment</label><input id="reason" class="form-control" maxlength="200"></div>' : '') +
      '</div><div class="d-flex gap-2 mt-3"><button class="btn btn-success flex-fill" id="okBtn"><i class="bi bi-check2-circle"></i> Confirm return</button><button class="btn btn-light" id="cancelBtn">Cancel</button></div>';
    $('cancelBtn').onclick = function () { loan = null; empty(); SL.status('Cancelled. Scan the next book.', 'info'); };
    $('okBtn').onclick = confirmReturn;
  }

  async function onCode(code) {
    if (loan) { SL.status('Finish or cancel the current return first.', 'warning'); return; }
    var r = await SL.api('loan', { code: code });
    if (!r.ok) { SL.status(r.error, 'danger'); return; }
    loan = r.loan; show(); SL.status('Loan found. Review and confirm.', 'success');
  }

  async function confirmReturn() {
    if (sending) return; sending = true; $('okBtn').disabled = true;
    var adj = $('adj') ? $('adj').value : '';
    var r = await SL.api('return', { item_id: loan.item_id, condition: $('cond').value, remarks: $('remarks').value, adjusted_amount: adj, reason: $('reason') ? $('reason').value : '' });
    sending = false;
    if (!r.ok) { SL.status(r.error, 'danger'); if ($('okBtn')) $('okBtn').disabled = false; return; }
    $('loanBox').innerHTML = '<div class="text-center py-3"><i class="bi bi-check-circle-fill text-success display-4"></i><h5 class="mt-2">Return recorded</h5>' +
      '<div>' + esc(r.title) + '</div><div class="text-muted small">from ' + esc(r.student) + '</div>' +
      (r.days_overdue > 0 ? '<div class="mt-2">Overdue ' + r.days_overdue + ' day(s) · Penalty <strong>' + SL.money(r.penalty) + '</strong></div>' : '') +
      (r.copy_status === 'damaged' ? '<div class="mt-2 text-warning"><i class="bi bi-exclamation-triangle"></i> Copy marked as damaged.</div>' : '') +
      '<button class="btn btn-primary mt-3" id="nextBtn">Scan next book</button></div>';
    $('nextBtn').onclick = function () { loan = null; empty(); SL.status('Ready. Scan the next book.', 'info'); };
    loan = null; SL.status('Return saved.', 'success');
  }
  empty();
  SL.bindScanner(onCode);
});
</script>
<?php include __DIR__ . '/../includes/footer.php';