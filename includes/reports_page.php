<?php
// Expects $user (admin or librarian). Included by admin/reports/index.php and librarian/reports/index.php.
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/pagination.php';
$isAdmin = $user['role'] === 'admin';
$catalog = array_filter(report_catalog(), fn($d) => $isAdmin || !$d[4]);
$key = $_GET['report'] ?? ''; $def = $catalog[$key] ?? null;
$validDate = fn($v) => is_string($v) && ($d = DateTime::createFromFormat('Y-m-d', $v)) && $d->format('Y-m-d') === $v;
$rows = []; $o = ['from' => '', 'to' => '', 'q' => clean($_GET['q'] ?? '')]; $error = null;
if ($def) {
    [$o['from'], $o['to']] = report_default_range($def[1]);
    if ($validDate($_GET['from'] ?? '')) $o['from'] = $_GET['from'];
    if ($validDate($_GET['to'] ?? '')) $o['to'] = $_GET['to'];
    if ($o['from'] > $o['to']) { $error = 'The start date is after the end date.'; }
    else {
        try { $rows = run_report($def, $o); } catch (PDOException $e) { error_log($e->getMessage()); $error = 'The report could not be generated.'; }
    }
}
$generated = date('M j, Y g:i A');
$range = $def && $def[3] ? "{$o['from']} to {$o['to']}" : 'Current data';

if ($def && !$error && ($_GET['export'] ?? '') === 'csv') {
    log_activity('report_export', "Exported '{$def[0]}' to CSV ($range)");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $key . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$def[0]]); fputcsv($out, ['Period', $range]); fputcsv($out, ['Generated', $generated, 'by', $user['full_name']]); fputcsv($out, []);
    if ($rows) { fputcsv($out, array_keys($rows[0])); foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r)); }
    fclose($out); exit;
}
$printAll = $def && !$error && ($_GET['print'] ?? '') === '1';
$total = count($rows);
if ($printAll) { $page = 1; $pages = 1; $view = $rows; log_activity('report_print', "Printed '{$def[0]}' ($range)"); }
else { [$page, $pages, $off, $per] = paginate($total, 50); $view = array_slice($rows, $off, $per); }
$self = $_SERVER['SCRIPT_NAME'];
$qs = fn(array $x = []) => '?' . http_build_query(array_filter(array_merge(['report' => $key, 'from' => $o['from'], 'to' => $o['to'], 'q' => $o['q']], $x), fn($v) => $v !== ''));

$pageTitle = 'Reports';
include __DIR__ . '/header.php';
?>
<?php if (!$def): ?>
  <div class="row g-3"><?php foreach ($catalog as $k => $d): ?>
    <div class="col-12 col-md-6 col-xl-4"><a class="card p-3 text-decoration-none h-100" href="?report=<?= e($k) ?>"><div class="d-flex align-items-center gap-3"><div class="stat-icon"><i class="bi bi-file-earmark-bar-graph"></i></div><div><div class="fw-semibold"><?= e($d[0]) ?></div><div class="small text-muted"><?= $d[3] ? 'Date range · ' : '' ?>Print · CSV</div></div></div></a></div>
  <?php endforeach; ?></div>
<?php else: ?>
  <form class="card p-3 mb-3 no-print row g-2 mx-0" method="get"><div class="col-12 col-md-3"><select class="form-select" name="report" onchange="this.form.submit()"><?php foreach ($catalog as $k => $d): ?><option value="<?= e($k) ?>" <?= $k === $key ? 'selected' : '' ?>><?= e($d[0]) ?></option><?php endforeach; ?></select></div>
    <?php if ($def[3]): ?><div class="col-6 col-md-2"><input class="form-control" type="date" name="from" value="<?= e($o['from']) ?>"></div><div class="col-6 col-md-2"><input class="form-control" type="date" name="to" value="<?= e($o['to']) ?>"></div><?php endif; ?>
    <div class="col-12 col-md-<?= $def[3] ? 3 : 7 ?>"><input class="form-control" name="q" placeholder="Search within report…" value="<?= e($o['q']) ?>"></div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> Run</button></div></form>
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php else: ?>
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
    <div><h2 class="h5 mb-0"><?= e($def[0]) ?></h2><div class="small text-muted">Period: <?= e($range) ?> · Generated <?= e($generated) ?> by <?= e($user['full_name']) ?> · <?= $total ?> row(s)</div></div>
    <div class="d-flex gap-2 no-print"><a class="btn btn-outline-secondary btn-sm" href="<?= e($qs(['print' => '1'])) ?>"><i class="bi bi-printer"></i> Print all</a><a class="btn btn-outline-success btn-sm" href="<?= e($qs(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a></div></div>
  <div class="card p-3"><?php if (!$rows): ?><div class="empty-state"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">No data for this report and period.</p></div><?php else: ?>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><?php foreach (array_keys($rows[0]) as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
      <?php foreach ($view as $r): ?><tr><?php foreach ($r as $v): ?><td><?= e($v) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <?= $printAll ? '<script>window.addEventListener("load",function(){window.print();});</script>' : pager($page, $pages) ?>
<?php endif; endif; ?>
<?php include __DIR__ . '/footer.php';