<?php
// Loads the logged-in student's record. Students only ever see their own data.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/borrowing.php';
$user = require_role('student');
$st = db()->prepare('SELECT * FROM students WHERE user_id = ? AND is_archived = 0'); $st->execute([$user['id']]);
$me = $st->fetch();
if (!$me) {
    $pageTitle = 'My Account'; include __DIR__ . '/header.php';
    echo '<div class="empty-state"><i class="bi bi-person-x"></i><p class="mt-2">Your login is not linked to a student record. Please see the librarian.</p></div>';
    include __DIR__ . '/footer.php'; exit;
}