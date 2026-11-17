<?php
// Shared helpers for the public site and the admin portal.
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA', ROOT . '/data');
define('PAGES', DATA . '/pages');
define('BACKUPS', DATA . '/backups');
define('UPLOADS', ROOT . '/uploads');

function base_url(): string {
    // Folder the site lives in, e.g. "/" or "/site/". Works for page.php and admin/*.php.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/page.php');
    $dir = rtrim(dirname($script), '/');
    if (substr($dir, -6) === '/admin') $dir = substr($dir, 0, -6);
    return $dir . '/';
}

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function read_json(string $file, $default = []) {
    if (!is_file($file)) return $default;
    $d = json_decode((string)file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}

// Writes JSON atomically and keeps the previous version in data/backups.
function write_json(string $file, array $data): bool {
    if (is_file($file)) backup_file($file);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $file);
}

function backup_file(string $file): void {
    if (!is_dir(BACKUPS)) @mkdir(BACKUPS, 0755, true);
    $rel = substr($file, strlen(DATA) + 1);                 // e.g. pages/index.json
    $key = str_replace(['/', '.json'], ['__', ''], $rel);    // pages__index
    @copy($file, BACKUPS . '/' . $key . '@' . date('Ymd-His') . '.json');
    // keep the newest 30 versions per file
    $all = glob(BACKUPS . '/' . $key . '@*.json') ?: [];
    rsort($all);
    foreach (array_slice($all, 30) as $old) @unlink($old);
}

function site(): array {
    static $s = null;
    if ($s === null) $s = read_json(DATA . '/site.json');
    return $s;
}

function valid_slug(string $slug): bool {
    return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $slug);
}

function page_file(string $slug): string {
    return PAGES . '/' . $slug . '.json';
}

function load_page(string $slug): ?array {
    if (!valid_slug($slug) || !is_file(page_file($slug))) return null;
    return read_json(page_file($slug), null);
}

function all_pages(): array {
    $out = [];
    foreach (glob(PAGES . '/*.json') ?: [] as $f) {
        $slug = basename($f, '.json');
        $p = read_json($f);
        $out[$slug] = $p;
    }
    $order = ['index', 'companies', 'philosophy', 'leadership', 'careers', 'faq', 'contact'];
    uksort($out, function ($a, $b) use ($order) {
        $a = (string)$a; $b = (string)$b; $ia = array_search($a, $order, true); $ib = array_search($b, $order, true);
        $ia = $ia === false ? ($a === '404' ? 999 : 100) : $ia;
        $ib = $ib === false ? ($b === '404' ? 999 : 100) : $ib;
        return $ia === $ib ? strcmp((string)$a, (string)$b) : $ia <=> $ib;
    });
    return $out;
}

function page_url(string $slug): string {
    return $slug . '.html';
}

// Uploaded images, newest first, as site-relative paths' file names.
function media_files(): array {
    $out = [];
    foreach (is_dir(UPLOADS) ? (scandir(UPLOADS) ?: []) : [] as $f) {
        if (preg_match('/\.(jpe?g|png|gif|webp|svg|ico)$/i', $f) && is_file(UPLOADS . '/' . $f)) $out[$f] = filemtime(UPLOADS . '/' . $f);
    }
    arsort($out);
    return array_map(fn($f) => UPLOADS . '/' . $f, array_keys($out));
}

// ---------- sessions and auth ----------
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('cg_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => base_url(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function admin_config(): ?array {
    $f = DATA . '/config.php';
    if (!is_file($f)) return null;
    $c = include $f;
    return is_array($c) ? $c : null;
}

function save_admin_config(array $c): bool {
    $php = "<?php\n// Admin login. Do not share this file.\nreturn " . var_export($c, true) . ";\n";
    $f = DATA . '/config.php';
    $tmp = $f . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false) return false;
    $ok = rename($tmp, $f);
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    return $ok;
}

function is_admin(): bool {
    start_session();
    return !empty($_SESSION['admin']) && ($_SESSION['ua'] ?? '') === ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function check_csrf(): bool {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($t) && hash_equals(csrf_token(), $t);
}
