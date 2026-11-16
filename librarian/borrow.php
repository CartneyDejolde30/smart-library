<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('librarian', 'admin');
$pageTitle = 'Borrow Book';
$extraScripts = ['https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js', url('assets/js/scanner.js')];
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="alert alert-light border py-2 small mb-2"><i class="bi bi-1-circle me-1"></i> <strong id="stepLabel">Scan the student's QR code</strong></div>
    <?php include __DIR__ . '/../includes/scanner_card.php'; ?>
  </div>
  <div class="col-lg-7">
    <div class="card p-3 mb-3" id="stuBox"></div>
    <div class="card p-3" id="bookBox"></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (id) { return document.getElementById(id); }, esc = SL.esc;
  var student = null, copies = [], token = newToken(), sending = false, done = null;

  function newToken() { var a = new Uint8Array(16); crypto.getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }

  function render() {
    $('stepLabel').textContent = done ? 'Transaction complete' : (student ? 'Scan the book QR code(s)' : "Scan the student's QR code");
    if (!student) {
      $('stuBox').innerHTML = '<div class="empty-state py-3"><i class="bi bi-person-badge"></i><p class="mb-0 mt-2">No student scanned yet.</p></div>';
    } else {
      var badge = student.eligible ? '<span class="badge text-bg-success">Eligible</span>' : '<span class="badge text-bg-danger">Not eligible</span>';
      $('stuBox').innerHTML = '<div class="d-flex justify-content-between align-items-start gap-2"><div><div class="fw-bold">' + esc(student.name) + '</div>' +
        '<div class="small text-muted">ID ' + esc(student.student_id) + ' · ' + esc(student.grade) + '</div>' +
        '<div class="mt-1">' + badge + ' <span class="badge text-bg-light border">Borrowed ' + student.active + ' / ' + student.max + '</span></div></div>' +
        '<button class="btn btn-sm btn-light" id="resetBtn">Change</button></div>' +
        (student.reasons.length ? '<ul class="text-danger small mb-0 mt-2">' + student.reasons.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>' : '');
      $('resetBtn').onclick = reset;
    }
    if (done) {
      $('bookBox').innerHTML = '<div class="text-center py-3"><i class="bi bi-check-circle-fill text-success display-4"></i><h5 class="mt-2">Borrowing recorded</h5>' +
        '<div class="text-muted">' + esc(done.txn) + '</div><div class="mt-1">Due date: <strong>' + esc(done.dueText) + '</strong></div>' +
        (done.duplicate ? '<div class="small text-warning mt-1">This transaction had already been saved (duplicate submit ignored).</div>' : '') +
        '<button class="btn btn-primary mt-3" id="newBtn"><i class="bi bi-plus-lg"></i> New transaction</button></div>';
      $('newBtn').onclick = reset; return;
    }
    var items = copies.map(function (c, i) {
      return '<div class="scan-item"><div><div class="fw-semibold">' + esc(c.title) + '</div><div class="small text-muted">' + esc(c.accession) + ' · ' + esc(c.author) + '</div></div>' +
        '<button class="btn btn-sm btn-outline-danger" data-rm="' + i + '"><i class="bi bi-x-lg"></i></button></div>';
    }).join('');
    $('bookBox').innerHTML = '<h3 class="h6">Books to borrow' + (student ? ' (' + copies.length + ' / ' + student.remaining + ' allowed)' : '') + '</h3>' +
      (items || '<div class="empty-state py-3"><i class="bi bi-book"></i><p class="mb-0 mt-2">' + (student ? 'Scan a book QR code to add it.' : 'Scan a student first.') + '</p></div>') +
      '<button class="btn btn-success w-100 mt-2" id="confirmBtn" ' + ((student && student.eligible && copies.length && !sending) ? '' : 'disabled') + '><i class="bi bi-check2-circle"></i> Confirm borrowing' + (copies.length ? ' (' + copies.length + ')' : '') + '</button>';
    document.querySelectorAll('[data-rm]').forEach(function (b) { b.onclick = function () { copies.splice(+b.dataset.rm, 1); render(); }; });
    $('confirmBtn').onclick = confirmBorrow;
  }
  function reset() { student = null; copies = []; done = null; token = newToken(); render(); SL.status('Ready. Scan the next student.', 'info'); }

  async function onCode(code) {
    if (done) { SL.status('Transaction finished. Press “New transaction” to continue.', 'warning'); return; }
    if (!student) {
      var r = await SL.api('student', { code: code });
      if (!r.ok) { SL.status(r.error, 'danger'); return; }
      student = r.student; copies = []; render();
      SL.status(student.eligible ? 'Student found. Now scan the book(s).' : 'Student is not eligible to borrow.', student.eligible ? 'success' : 'danger');
      return;
    }
    if (!student.eligible) { SL.status('This student is not eligible to borrow.', 'danger'); return; }
    if (copies.length >= student.remaining) { SL.status('Borrowing limit reached for this student.', 'warning'); return; }
    var c = await SL.api('copy', { code: code });
    if (!c.ok) { SL.status(c.error, 'danger'); return; }
    if (copies.some(function (x) { return x.id === c.copy.id; })) { SL.status('That copy is already in the list.', 'warning'); return; }
    copies.push(c.copy); render(); SL.status('Added: ' + c.copy.title, 'success');
  }
  async function confirmBorrow() {
    if (sending) return; sending = true; render();
    var r = await SL.api('borrow', { student_id: student.id, copy_ids: copies.map(function (c) { return c.id; }), token: token });
    sending = false;
    if (!r.ok) { SL.status(r.error, 'danger'); render(); return; }
    done = { txn: r.txn, dueText: new Date(r.due + 'T00:00:00').toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }), duplicate: !!r.duplicate };
    render(); SL.status('Borrowing saved.', 'success');
  }
  render();
  SL.bindScanner(onCode);
});
</script>
<?php include __DIR__ . '/../includes/footer.php';