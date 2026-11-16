<?php
// Expects: $pageTitle (string), optional $bare (true = no app chrome, used by login/install)
$bare = $bare ?? false;
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($pageTitle ?? 'Dashboard') ?> · <?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
  <style>
    /* School seal watermark + logos. Kept here so they work even if style.css is cached or outdated. */
    .sl-watermark{position:fixed!important;top:50%;left:50%;transform:translate(-50%,-50%);width:min(70vw,520px)!important;max-width:none;height:auto!important;opacity:.07!important;pointer-events:none;user-select:none;z-index:2000}
    @media(min-width:992px){.sl-watermark{left:calc(50% + 125px)}}
    .sl-logo{height:34px!important;width:auto!important;max-width:56px!important;object-fit:contain;flex-shrink:0}
    .id-card .hd .sl-logo{height:30px!important;background:#fff;border-radius:8px;padding:2px 4px}
    .auth-seal{display:block;width:150px;max-width:60%;height:auto;margin:0 auto 1rem}
    [data-qr],.sl-reader{position:relative;z-index:2001}
    @media print{.sl-watermark{display:block!important;left:50%!important;width:62%!important;opacity:.10!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}}
  </style>
</head>
<body class="<?= $bare ? 'sl-bare' : 'sl-app' ?>">
<?php if (!$bare && $user): ?>
  <img class="sl-watermark" src="<?= e(school_logo_url()) ?>" alt="" aria-hidden="true">
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="sl-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="sl-main">
      <div class="sl-page-head"><h1 class="h4 mb-0"><?= e($pageTitle ?? '') ?></h1></div>
<?php endif; ?>