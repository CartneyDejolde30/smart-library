<?php
declare(strict_types=1);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $p = ''): string { return BASE_URL . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }
function clean($v): string { return trim(preg_replace('/\s+/u', ' ', (string)$v)); }
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function old(string $k, string $default = ''): string { return e($_POST[$k] ?? $default); }

function flash(string $type, string $msg): void { $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg]; }
function get_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function role_home(string $role): string
{
    return match ($role) {
        'admin' => 'admin/dashboard.php',
        'librarian' => 'librarian/dashboard.php',
        default => 'student/dashboard.php',
    };
}

/** Audit trail. Never throws: logging must not break the user's action. */
function log_activity(string $action, string $desc, ?string $table = null, ?int $id = null, ?int $uid = null): void
{
    try {
        $uid ??= $_SESSION['user']['id'] ?? null;
        db()->prepare('INSERT INTO activity_logs (user_id, action, description, related_table, related_id, ip_address)
                       VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $action, mb_substr($desc, 0, 500), $table, $id, client_ip()]);
    } catch (Throwable $e) { error_log('log_activity: ' . $e->getMessage()); }
}

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        try { $cache = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_KEY_PAIR); }
        catch (Throwable $e) { $cache = []; }
    }
    return (string)($cache[$key] ?? $default);
}

function db_count(string $sql, array $params = []): int
{
    $st = db()->prepare($sql); $st->execute($params);
    return (int)$st->fetchColumn();
}

/** Returns an error string or null if the password meets policy. */
function password_error(string $pw): ?string
{
    if (strlen($pw) < 10) return 'Password must be at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Password must contain letters and numbers.';
    return null;
}

/** Bootstrap badge for a copy status. Overdue is derived from the due date, never stored. */
function status_badge(string $status, ?string $due = null): string
{
    if ($status === 'borrowed' && $due !== null && $due < date('Y-m-d')) { $status = 'overdue'; }
    $map = ['available' => 'success', 'borrowed' => 'primary', 'overdue' => 'danger', 'lost' => 'dark', 'damaged' => 'warning',
            'active' => 'success', 'inactive' => 'secondary'];
    return '<span class="badge text-bg-' . ($map[$status] ?? 'secondary') . '">' . e(ucfirst($status)) . '</span>';
}

/** Validates and stores an uploaded image. Returns stored filename, or null if none uploaded. Throws RuntimeException. */
function save_image_upload(array $f, string $dir): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Photo upload failed.');
    if ($f['size'] > 5 * 1024 * 1024) throw new RuntimeException('Photo must be 5 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Photo must be a JPG, PNG or WEBP image.');
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $target = BASE_PATH . '/uploads/' . $dir;
    if (!is_dir($target)) mkdir($target, 0775, true);
    if (!move_uploaded_file($f['tmp_name'], $target . '/' . $name)) throw new RuntimeException('Could not save the photo.');
    return $name;
}

/** A setting that holds a list, one item per line (e.g. courses, year levels). */
function setting_list(string $key, array $default = []): array
{
    $raw = setting($key, '');
    $items = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
    return $items ?: $default;
}

/** URL of the school seal: the logo uploaded in Settings if any, otherwise the bundled Southway seal. */
function school_logo_url(): string
{
    $f = basename(setting('school_logo', ''));
    if ($f !== '' && is_file(BASE_PATH . '/uploads/school/' . $f)) return url('uploads/school/' . $f);
    return url('assets/images/school-logo.png');
}