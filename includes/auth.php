<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

function current_user(): ?array { return $_SESSION['user'] ?? null; }

function login_throttled(string $username): bool
{
    $n = db_count("SELECT COUNT(*) FROM login_attempts
                   WHERE username = ? AND ip_address = ? AND success = 0
                   AND attempted_at > (NOW() - INTERVAL " . (int)LOGIN_LOCKOUT_MINUTES . " MINUTE)",
                  [$username, client_ip()]);
    return $n >= MAX_LOGIN_ATTEMPTS;
}

/** @return string|null error message, or null on success */
function attempt_login(string $username, string $password): ?string
{
    $generic = 'Invalid username or password.';
    if ($username === '' || $password === '') return $generic;
    if (login_throttled($username)) {
        log_activity('login_blocked', "Login blocked (too many attempts) for '$username'", null, null, null);
        return 'Too many failed attempts. Please try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.';
    }
    $st = db()->prepare('SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ?');
    $st->execute([$username]);
    $u = $st->fetch();

    $ok = $u && password_verify($password, $u['password_hash']);
    db()->prepare('INSERT INTO login_attempts (username, ip_address, success) VALUES (?,?,?)')
        ->execute([$username, client_ip(), $ok ? 1 : 0]);

    if (!$ok) { log_activity('login_failed', "Failed login for '$username'", null, null, $u['id'] ?? null); return $generic; }
    if ($u['status'] !== 'active') return 'This account is deactivated. Contact the administrator.';

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role']];
    $_SESSION['last_activity'] = time();
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
    db()->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ?')->execute([$username, client_ip()]);
    log_activity('login', "User '{$u['username']}' logged in", 'users', (int)$u['id'], (int)$u['id']);
    return null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) { flash('warning', 'Please log in to continue.'); redirect('login.php'); }
    if (time() - ($_SESSION['last_activity'] ?? 0) > SESSION_LIFETIME) {
        logout_user(false); session_start(); flash('warning', 'Your session expired. Please log in again.'); redirect('login.php');
    }
    // Re-check account is still active on every request (server-side authority)
    $st = db()->prepare('SELECT status FROM users WHERE id = ?'); $st->execute([$u['id']]);
    if ($st->fetchColumn() !== 'active') { logout_user(false); session_start(); flash('danger', 'Account is no longer active.'); redirect('login.php'); }
    $_SESSION['last_activity'] = time();
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        $pageTitle = 'Access denied';
        include __DIR__ . '/header.php';
        echo '<div class="text-center py-5"><i class="bi bi-shield-lock display-3 text-danger"></i><h4 class="mt-3">Access denied</h4>
              <p class="text-muted">You do not have permission to view this page.</p>
              <a class="btn btn-primary" href="' . e(url(role_home($u['role']))) . '">Back to my dashboard</a></div>';
        include __DIR__ . '/footer.php';
        exit;
    }
    return $u;
}

function logout_user(bool $log = true): void
{
    if ($log && current_user()) log_activity('logout', "User '" . $_SESSION['user']['username'] . "' logged out");
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}