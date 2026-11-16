<?php
// Menu items are listed by role. An item is shown ONLY if its target file exists,
// so navigation never contains dead links while later phases are being added.
$menus = [
  'admin' => [
    ['Dashboard','bi-speedometer2','admin/dashboard.php'],
    ['Books','bi-book','librarian/books/index.php'],
    ['Students','bi-people','librarian/students/index.php'],
    ['Categories','bi-tags','admin/categories/index.php'],
    ['Borrow Book','bi-box-arrow-up-right','librarian/borrow.php'],
    ['Return Book','bi-box-arrow-in-down-left','librarian/return.php'],
    ['Borrowed Books','bi-journal-check','librarian/borrowed.php'],
    ['Scanner','bi-qr-code-scan','librarian/scanner.php'],
    ['Transactions','bi-clock-history','admin/transactions/index.php'],
    ['Overdue','bi-exclamation-triangle','librarian/overdue.php'],
    ['Lost / Damaged','bi-x-octagon','librarian/lost-books.php'],
    ['Penalties','bi-cash-coin','librarian/penalties.php'],
    ['Inventory','bi-boxes','librarian/inventory.php'],
    ['Reports','bi-bar-chart-line','admin/reports/index.php'],
    ['Users','bi-person-gear','admin/users/index.php'],
    ['Activity Logs','bi-journal-text','admin/logs/index.php'],
    ['Settings','bi-gear','admin/settings/index.php'],
    ['Backup & Restore','bi-database','admin/settings/backup.php'],
  ],
  'librarian' => [
    ['Dashboard','bi-speedometer2','librarian/dashboard.php'],
    ['Borrow Book','bi-box-arrow-up-right','librarian/borrow.php'],
    ['Return Book','bi-box-arrow-in-down-left','librarian/return.php'],
    ['Borrowed Books','bi-journal-check','librarian/borrowed.php'],
    ['Scanner','bi-qr-code-scan','librarian/scanner.php'],
    ['Books','bi-book','librarian/books/index.php'],
    ['Students','bi-people','librarian/students/index.php'],
    ['Overdue','bi-exclamation-triangle','librarian/overdue.php'],
    ['Lost / Damaged','bi-x-octagon','librarian/lost-books.php'],
    ['Penalties','bi-cash-coin','librarian/penalties.php'],
    ['Inventory','bi-boxes','librarian/inventory.php'],
    ['Reports','bi-bar-chart-line','librarian/reports/index.php'],
  ],
  'student' => [
    ['Dashboard','bi-speedometer2','student/dashboard.php'],
    ['My Profile','bi-person','student/profile.php'],
    ['My QR Card','bi-qr-code','student/qr-card.php'],
    ['Borrowed Books','bi-book-half','student/borrowed-books.php'],
    ['History','bi-clock-history','student/history.php'],
  ],
];
$items = array_filter($menus[$user['role']] ?? [], fn($m) => is_file(BASE_PATH . '/' . $m[2]));
if (is_file(BASE_PATH . '/notifications.php')) $items[] = ['Notifications', 'bi-bell', 'notifications.php'];
$current = $_SERVER['SCRIPT_NAME'] ?? '';
$isActive = function (string $path) use ($current): bool {
  $u = url($path);
  return $current === $u || (basename($path) === 'index.php' && str_starts_with($current, dirname($u) . '/'));
};
?>
<aside class="offcanvas-lg offcanvas-start sl-sidebar" tabindex="-1" id="sidebar" aria-label="Main navigation">
  <div class="offcanvas-header d-lg-none">
    <span class="fw-semibold text-white"><i class="bi bi-qr-code-scan me-2"></i><?= e(APP_NAME) ?></span>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body flex-column p-0">
    <div class="sl-brand d-none d-lg-flex"><i class="bi bi-qr-code-scan me-2"></i><?= e(APP_NAME) ?></div>
    <nav class="nav flex-column px-2 py-2 flex-grow-1">
      <?php foreach ($items as [$label, $icon, $path]): ?>
        <a class="nav-link sl-link <?= $isActive($path) ? 'active' : '' ?>" href="<?= e(url($path)) ?>">
          <i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="p-2 border-top border-secondary-subtle">
      <a class="nav-link sl-link" href="<?= e(url('logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a>
    </div>
  </div>
</aside>