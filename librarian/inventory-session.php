<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/inventory.php';
$user = require_role('librarian', 'admin');
$pdo = db(); $id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM inventory_sessions WHERE id = ?'); $st->execute([$id]); $sess = $st->fetch();
if (!$sess) { flash('danger', 'Inventory session not found.'); redirect('librarian/inventory.php'); }
$self = 'librarian/inventory-session.php?id=' . $id;

if (is_post()) {
    csrf_require(); $act = $_POST['action'] ?? '';
    if ($act === 'complete' && $sess['status'] === 'in_progress') {
        $state = inventory_state($sess);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE inventory_sessions SET status='completed', completed_at=NOW() WHERE id=?")->execute([$id]);
        $ins = $pdo->prepare("INSERT INTO inventory_logs (copy_id, session_id, action, notes, user_id) VALUES (?,?, 'missing', 'Not found during inventory', ?)");
        foreach ($state['missing'] as $m) $ins->execute([$m['id'], $id, $user['id']]);
        $pdo->commit();
        log_activity('inventory_complete', "Inventory '{$sess['title']}' completed: {$state['scanned']}/{$state['expected']} found, " . count($state['missing']) . ' missing', 'inventory_sessions', $id);
        flash('success', 'Inventory completed.');
    } elseif ($act === 'cancel' && $sess['status'] === 'in_progress') {
        $pdo->prepare("UPDATE inventory_sessions SET status='cancelled', completed_at=NOW() WHERE id=?")->execute([$id]);
        log_activity('inventory_cancel', "Inventory '{$sess['title']}' cancelled", 'inventory_sessions', $id); flash('info', 'Session cancelled.');
    } elseif ($act === 'mark_lost') {
        $cid = (int)($_POST['copy_id'] ?? 0);
        $r = report_incident('lost', $cid, "Missing during inventory session #$id ({$sess['title']})", 0.0, (int)$user['id']);
        if ($r['ok']) $pdo->prepare("INSERT INTO inventory_logs (copy_id, session_id, action, old_value, new_value, notes, user_id) VALUES (?,?, 'adjustment', 'available', 'lost', 'Marked lost after inventory', ?)")->execute([$cid, $id, $user['id']]);
        flash($r['ok'] ? 'success' : 'danger', $r['ok'] ? $r['message'] : $r['error']);
    }
    redirect($self);
}
$state = inventory_state($sess);
$active = $sess['status'] === 'in_progress';
$pageTitle = 'Inventory: ' . $sess['title'];
if ($active) $extraScripts = ['https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js', url('assets/js/scanner.js')];
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <?php if ($active): ?><div class="col-lg-5"><?php $manualHint = 'Manual entry: book QR code or accession no.'; include __DIR__ . '/../includes/scanner_card.php'; ?>
    <div class="d-flex gap-2 mt-3">
      <form method="post" class="flex-fill" data-confirm="Complete this inventory? Books not scanned will be recorded as missing."><?= csrf_field() ?><input type="hidden" name="action" value="complete"><button class="btn btn-success w-100" type="submit"><i class="bi bi-check2-circle"></i> Complete</button></form>
      <form method="post" data-confirm="Cancel this session?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-secondary" type="submit">Cancel</button></form></div></div><?php endif; ?>
  <div class="<?= $active ? 'col-lg-7' : 'col-12' ?>">
    <div class="row g-2 mb-3" id="sumRow"></div>
    <div class="card p-3 mb-3"><h3 class="h6">Missing / unaccounted for <span class="text-muted small" id="missCount"></span></h3><div id="missBox"></div></div>
    <div class="card p-3"><h3 class="h6">Unexpected or unregistered</h3><div id="unexBox"></div></div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var esc = (typeof SL !== 'undefined') ? SL.esc : function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var ACTIVE = <?= $active ? 'true' : 'false' ?>, SID = <?= (int)$id ?>, CSRF = <?= json_encode(csrf_token()) ?>, STATE = <?= json_encode($state) ?>;
  function render(s) {
    document.getElementById('sumRow').innerHTML = [['Expected', s.expected, 'primary'], ['Scanned', s.scanned, 'success'], ['Missing', s.missing.length, 'danger'], ['Unexpected', s.unexpected.length, 'warning']]
      .map(function (x) { return '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="fs-4 fw-bold text-' + x[2] + '">' + x[1] + '</div><div class="small text-muted">' + x[0] + '</div></div></div>'; }).join('');
    document.getElementById('missCount').textContent = '(' + s.missing.length + ')';
    document.getElementById('missBox').innerHTML = s.missing.length ? s.missing.map(function (m) {
      return '<div class="scan-item"><div><div class="fw-semibold">' + esc(m.title) + '</div><div class="small text-muted">' + esc(m.accession_number) + (m.shelf_location ? ' · ' + esc(m.shelf_location) : '') + '</div></div>' +
        (ACTIVE ? '' : '<form method="post"><input type="hidden" name="csrf_token" value="' + esc(CSRF) + '"><input type="hidden" name="action" value="mark_lost"><input type="hidden" name="copy_id" value="' + m.id + '"><button class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Mark this copy as lost?\')">Mark lost</button></form>') + '</div>';
    }).join('') : '<div class="text-muted small">' + (s.expected ? 'Nothing missing.' : 'No expected copies for this filter.') + '</div>';
    document.getElementById('unexBox').innerHTML = s.unexpected.length ? s.unexpected.map(function (u) {
      return '<div class="scan-item"><div><div class="fw-semibold">' + esc(u.title || u.code) + '</div><div class="small text-muted">' + esc(u.reason) + '</div></div></div>';
    }).join('') : '<div class="text-muted small">None.</div>';
  }
  render(STATE);
  if (ACTIVE) SL.bindScanner(async function (code) {
    var r = await SL.api('inv_scan', { session_id: SID, code: code });
    if (r.state) render(r.state);
    if (!r.ok) { SL.status(r.error, 'warning'); return; }
    SL.status((r.registered ? 'Scanned: ' : 'Unregistered code: ') + r.message, r.registered ? 'success' : 'danger');
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php';