<?php
require_once __DIR__ . '/borrowing.php';
sync_overdue_notifications();
$unread = unread_notifications($user);
?>
<header class="sl-topbar">
  <button class="btn btn-link text-white d-lg-none p-0 me-2 fs-3" type="button"
          data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open menu"><i class="bi bi-list"></i></button>
  <a class="sl-topbar-brand" href="<?= e(url(role_home($user['role']))) ?>"><img class="sl-logo me-2" src="<?= e(school_logo_url()) ?>" alt=""><?= e(setting('library_name', APP_NAME)) ?></a>
  <a class="btn btn-link text-white position-relative ms-auto me-1 fs-5 p-1" href="<?= e(url('notifications.php')) ?>" aria-label="Notifications"><i class="bi bi-bell"></i><?php if ($unread > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem"><?= $unread > 99 ? '99+' : (int)$unread ?></span><?php endif; ?></a>
  <div class="dropdown">
    <button class="btn btn-sm btn-outline-light rounded-pill px-3" data-bs-toggle="dropdown" aria-expanded="false">
      <i class="bi bi-person-circle me-1"></i><span class="d-none d-sm-inline"><?= e($user['full_name']) ?></span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
      <li><span class="dropdown-item-text small text-muted"><?= e(ucfirst($user['role'])) ?> · <?= e($user['username']) ?></span></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item" href="<?= e(url('logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
    </ul>
  </div>
</header>