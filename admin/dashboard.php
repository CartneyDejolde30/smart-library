<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('admin');
$pageTitle = 'Dashboard';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/dashboard_basic.php';
include __DIR__ . '/../includes/footer.php';