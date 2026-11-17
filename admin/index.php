<?php
// The Credence Group website admin portal.
declare(strict_types=1);
require dirname(__DIR__) . '/inc/core.php';

start_session();
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

const PROTECTED_PAGES = ['index', '404'];
const MAX_UPLOAD = 8 * 1024 * 1024;
const IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];

$a = (string)($_GET['a'] ?? 'pages');
$cfg = admin_config();
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

function go(string $q = '', ?string $msg = null, string $type = 'ok'): void {
    if ($msg !== null) $_SESSION['flash'] = [$type, $msg];
    header('Location: index.php' . ($q !== '' ? '?' . $q : ''));
    exit;
}
function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}
function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0'; }

function too_many_attempts(): bool {
    $f = DATA . '/login_attempts.json';
    $all = read_json($f);
    $k = hash('sha256', client_ip());
    $recent = array_filter($all[$k] ?? [], fn($t) => $t > time() - 900);
    return count($recent) >= 5;
}
function record_attempt(bool $ok): void {
    $f = DATA . '/login_attempts.json';
    $all = read_json($f);
    $k = hash('sha256', client_ip());
    foreach ($all as $ip => $ts) { $all[$ip] = array_values(array_filter($ts, fn($t) => $t > time() - 900)); if (!$all[$ip]) unset($all[$ip]); }
    if ($ok) unset($all[$k]); else $all[$k][] = time();
    file_put_contents($f, json_encode($all), LOCK_EX);
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(substr($s, 0, 60), '-');
}

function clean_str($v, int $max = 2000): string {
    return mb_substr(trim((string)$v), 0, $max);
}

// Keeps only the keys the site understands, so a bad form post can't break the site.
function clean_site(array $in): array {
    $s = site();
    $links = function ($arr, $withTab = false) {
        $out = [];
        foreach (is_array($arr) ? $arr : [] as $l) {
            if (!is_array($l)) continue;
            $row = ['label' => clean_str($l['label'] ?? '', 200), 'url' => clean_str($l['url'] ?? '', 500)];
            if ($withTab) $row['newTab'] = !empty($l['newTab']);
            if ($row['label'] !== '') $out[] = $row;
        }
        return $out;
    };
    $b = $in['brand'] ?? [];
    $s['brand'] = [
        'name' => clean_str($b['name'] ?? '', 200),
        'logo' => clean_str($b['logo'] ?? '', 500),
        'logoHeight' => max(16, min(160, (int)($b['logoHeight'] ?? 40))),
        'footerLogo' => clean_str($b['footerLogo'] ?? '', 500),
        'favicon' => clean_str($b['favicon'] ?? 'favicon.ico', 500),
    ];
    $c = $in['colors'] ?? [];
    foreach (['accent', 'accentText', 'dark', 'text'] as $k) {
        $v = clean_str($c[$k] ?? '', 9);
        $s['colors'][$k] = preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : ($s['colors'][$k] ?? '#000000');
    }
    $s['heroImage'] = clean_str($in['heroImage'] ?? '', 500);
    $s['menu'] = $links($in['menu'] ?? [], true);
    $mb = $in['menuButton'] ?? [];
    $s['menuButton'] = ['show' => !empty($mb['show']), 'label' => clean_str($mb['label'] ?? '', 100), 'url' => clean_str($mb['url'] ?? '', 500)];
    $fc = $in['footerCta'] ?? [];
    $s['footerCta'] = ['show' => !empty($fc['show']), 'title' => clean_str($fc['title'] ?? '', 300), 'text' => clean_str($fc['text'] ?? '', 600),
        'buttonLabel' => clean_str($fc['buttonLabel'] ?? '', 100), 'buttonUrl' => clean_str($fc['buttonUrl'] ?? '', 500)];
    $f = $in['footer'] ?? [];
    $cols = [];
    foreach (is_array($f['columns'] ?? null) ? $f['columns'] : [] as $col) {
        if (!is_array($col)) continue;
        $cols[] = ['title' => clean_str($col['title'] ?? '', 200), 'links' => $links($col['links'] ?? [])];
    }
    $s['footer'] = [
        'tagline' => clean_str($f['tagline'] ?? '', 300), 'email' => clean_str($f['email'] ?? '', 200),
        'phone' => clean_str($f['phone'] ?? '', 60), 'address' => clean_str($f['address'] ?? '', 300),
        'columns' => array_slice($cols, 0, 4), 'social' => $links($f['social'] ?? []),
        'copyright' => clean_str($f['copyright'] ?? '', 300), 'bottomRight' => clean_str($f['bottomRight'] ?? '', 300),
    ];
    $s['contact'] = ['formEmail' => clean_str($in['contact']['formEmail'] ?? '', 200)];
    $s['seo'] = ['headCode' => clean_str($in['seo']['headCode'] ?? '', 20000), 'footerCode' => clean_str($in['seo']['footerCode'] ?? '', 20000)];
    return $s;
}

function handle_upload(array $file): array {
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) return ['error' => 'Upload failed. Try a smaller file.'];
    if ($file['size'] > MAX_UPLOAD) return ['error' => 'File is larger than 8 MB.'];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, IMG_EXT, true)) return ['error' => 'Only image files are allowed (JPG, PNG, GIF, WEBP, SVG, ICO).'];
    if (!in_array($ext, ['svg', 'ico'], true) && @getimagesize($file['tmp_name']) === false) return ['error' => 'That file is not a valid image.'];
    if ($ext === 'svg') {
        $svg = (string)file_get_contents($file['tmp_name']);
        if (stripos($svg, '<svg') === false || preg_match('/<script|on[a-z]+\s*=|javascript:/i', $svg)) return ['error' => 'That SVG contains scripts and was blocked.'];
    }
    if (!is_dir(UPLOADS)) @mkdir(UPLOADS, 0755, true);
    $name = slugify(pathinfo((string)$file['name'], PATHINFO_FILENAME)) ?: 'image';
    $name = substr($name, 0, 40) . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOADS . '/' . $name)) return ['error' => 'Could not save the file on the server.'];
    @chmod(UPLOADS . '/' . $name, 0644);
    return ['url' => 'uploads/' . $name];
}

// ------------------------------------------------------------------ first run
if ($cfg === null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_csrf()) {
        $u = clean_str($_POST['user'] ?? '', 60);
        $p = (string)($_POST['pass'] ?? '');
        if ($u === '' || strlen($p) < 8 || $p !== ($_POST['pass2'] ?? '')) {
            $flash = ['err', 'Enter a username and a password of at least 8 characters, typed the same twice.'];
        } else {
            save_admin_config(['user' => $u, 'hash' => password_hash($p, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['admin'] = $u; $_SESSION['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            go('', 'Admin account created. Welcome!');
        }
    }
    $view = 'setup';
}
// ------------------------------------------------------------------ login / logout
elseif (!is_admin()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $a === 'login' && check_csrf()) {
        if (too_many_attempts()) {
            $flash = ['err', 'Too many attempts. Please wait 15 minutes and try again.'];
        } elseif (hash_equals(strtolower($cfg['user']), strtolower(clean_str($_POST['user'] ?? '', 60))) && password_verify((string)($_POST['pass'] ?? ''), $cfg['hash'])) {
            record_attempt(true);
            session_regenerate_id(true);
            $_SESSION['admin'] = $cfg['user']; $_SESSION['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
            go();
        } else {
            record_attempt(false);
            $flash = ['err', 'Wrong username or password.'];
        }
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') json_out(['error' => 'Your session expired. Log in again in a new tab, then save.'], 401);
    $view = 'login';
}
// ------------------------------------------------------------------ logged-in actions
else {
    $view = $a;
    if ($a === 'logout') { $_SESSION = []; session_destroy(); go(); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        if (!check_csrf()) { if ($ajax) json_out(['error' => 'Security token expired. Reload the page.'], 403); go('', 'Security token expired. Please try again.', 'err'); }

        switch ($a) {
            case 'save_body':
                $slug = (string)($_POST['slug'] ?? '');
                $p = load_page($slug);
                if ($p === null) json_out(['error' => 'Page not found.'], 404);
                $p['body'] = (string)($_POST['body'] ?? '');
                $p['updated'] = date('c');
                write_json(page_file($slug), $p) ? json_out(['ok' => true]) : json_out(['error' => 'Could not write to the server. Check folder permissions on /data.'], 500);

            case 'upload':
                $r = handle_upload($_FILES['file'] ?? []);
                if ($ajax) json_out($r, isset($r['error']) ? 400 : 200);
                go('a=media', $r['error'] ?? 'Image uploaded.', isset($r['error']) ? 'err' : 'ok');

            case 'media_delete':
                $n = basename((string)($_POST['file'] ?? ''));
                if ($n !== '' && $n[0] !== '.' && is_file(UPLOADS . '/' . $n)) @unlink(UPLOADS . '/' . $n);
                go('a=media', 'Image deleted.');

            case 'save_site':
                $in = json_decode((string)($_POST['site'] ?? ''), true);
                if (!is_array($in)) go('a=site', 'Nothing was saved, the form data was invalid.', 'err');
                write_json(DATA . '/site.json', clean_site($in));
                go('a=site&tab=' . urlencode((string)($_POST['tab'] ?? 'brand')), 'Site settings saved and live.');

            case 'page_create':
                $name = clean_str($_POST['name'] ?? '', 120);
                $slug = slugify((string)($_POST['slug'] ?? '') ?: $name);
                if ($name === '' || !valid_slug($slug)) go('a=pages', 'Give the page a name.', 'err');
                if (is_file(page_file($slug))) go('a=pages', 'A page with the address ' . $slug . '.html already exists.', 'err');
                $site = site();
                $body = '<section class="page-hero"><div class="container"><h1>' . e($name) . '</h1><p>A short introduction to this page.</p></div></section>'
                    . "\n" . '<section class="section"><div class="container narrow"><div class="prose"><p>Start writing here. Click any text to edit it.</p></div></div></section>';
                $copy = (string)($_POST['copy'] ?? '');
                if ($copy !== '' && ($src = load_page($copy))) $body = $src['body'] ?? $body;
                write_json(page_file($slug), ['name' => $name, 'title' => $name . ' | ' . ($site['brand']['name'] ?? ''), 'description' => '', 'image' => '', 'body' => $body, 'updated' => date('c')]);
                if (!empty($_POST['menu'])) { $site['menu'][] = ['label' => $name, 'url' => $slug . '.html', 'newTab' => false]; write_json(DATA . '/site.json', $site); }
                go('a=edit&slug=' . $slug, 'Page created. Click any text to start editing.');

            case 'page_meta':
                $slug = (string)($_POST['slug'] ?? '');
                $p = load_page($slug);
                if ($p === null) go('a=pages', 'Page not found.', 'err');
                $p['name'] = clean_str($_POST['name'] ?? $p['name'] ?? '', 120);
                $p['title'] = clean_str($_POST['title'] ?? '', 200);
                $p['description'] = clean_str($_POST['description'] ?? '', 500);
                $p['image'] = clean_str($_POST['image'] ?? '', 500);
                $p['hidden'] = !empty($_POST['hidden']) && !in_array($slug, PROTECTED_PAGES, true);
                $new = slugify((string)($_POST['newslug'] ?? $slug));
                if (!in_array($slug, PROTECTED_PAGES, true) && $new !== $slug && valid_slug($new)) {
                    if (is_file(page_file($new))) go('a=meta&slug=' . $slug, 'That page address is already used.', 'err');
                    write_json(page_file($new), $p);
                    backup_file(page_file($slug)); @unlink(page_file($slug));
                    // keep menu and footer links pointing at the renamed page
                    $site = site(); $old = $slug . '.html'; $nu = $new . '.html';
                    foreach ($site['menu'] as &$m) if (($m['url'] ?? '') === $old) $m['url'] = $nu; unset($m);
                    foreach ($site['footer']['columns'] as &$c) foreach ($c['links'] as &$l) if (($l['url'] ?? '') === $old) $l['url'] = $nu; unset($c, $l);
                    write_json(DATA . '/site.json', $site);
                    go('a=meta&slug=' . $new, 'Page settings saved. New address: ' . $nu);
                }
                write_json(page_file($slug), $p);
                go('a=meta&slug=' . $slug, 'Page settings saved and live.');

            case 'page_delete':
                $slug = (string)($_POST['slug'] ?? '');
                if (in_array($slug, PROTECTED_PAGES, true) || load_page($slug) === null) go('a=pages', 'This page cannot be deleted.', 'err');
                backup_file(page_file($slug)); @unlink(page_file($slug));
                $site = site(); $old = $slug . '.html';
                $site['menu'] = array_values(array_filter($site['menu'], fn($m) => ($m['url'] ?? '') !== $old));
                write_json(DATA . '/site.json', $site);
                go('a=pages', 'Page deleted. You can bring it back from History.');

            case 'restore':
                $n = basename((string)($_POST['file'] ?? ''));
                if (!preg_match('/^([a-z0-9_-]+)@(\d{8}-\d{6})\.json$/', $n, $m) || !is_file(BACKUPS . '/' . $n)) go('a=history', 'Backup not found.', 'err');
                $target = $m[1] === 'site' ? DATA . '/site.json' : (substr($m[1], 0, 7) === 'pages__' ? PAGES . '/' . substr($m[1], 7) . '.json' : '');
                $d = read_json(BACKUPS . '/' . $n, null);
                if ($target === '' || !is_array($d)) go('a=history', 'Backup could not be read.', 'err');
                write_json($target, $d);
                go('a=history', 'Restored. The live site now shows that version.');

            case 'password':
                if (!password_verify((string)($_POST['current'] ?? ''), $cfg['hash'])) go('a=account', 'Current password is wrong.', 'err');
                $p = (string)($_POST['pass'] ?? '');
                if (strlen($p) < 8 || $p !== ($_POST['pass2'] ?? '')) go('a=account', 'New password must be at least 8 characters and match.', 'err');
                $u = clean_str($_POST['user'] ?? $cfg['user'], 60) ?: $cfg['user'];
                save_admin_config(['user' => $u, 'hash' => password_hash($p, PASSWORD_DEFAULT)]);
                $_SESSION['admin'] = $u;
                go('a=account', 'Login details updated.');
        }
        go();
    }
}

// ------------------------------------------------------------------ views
if (in_array($view, ['edit', 'meta'], true) && load_page((string)($_GET['slug'] ?? '')) === null) go('a=pages', 'Page not found.', 'err');
if (!in_array($view, ['setup', 'login', 'pages', 'edit', 'meta', 'site', 'media', 'history', 'account'], true)) go();
$pages = $view === 'login' || $view === 'setup' ? [] : all_pages();
$siteName = site()['brand']['name'] ?? 'Website';
$csrf = csrf_token();
$v = @filemtime(__DIR__ . '/assets/admin.css') ?: 1;

function page_options(array $pages): string {
    $o = '';
    foreach ($pages as $s => $p) if ((string)$s !== '404') $o .= '<option value="' . e($s . '.html') . '">' . e($p['name'] ?? $s) . '</option>';
    return $o;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Admin · <?= e($siteName) ?></title>
<link rel="stylesheet" href="assets/admin.css?v=<?= $v ?>">
</head>
<body class="view-<?= e($view) ?>">
<?php if ($view === 'setup' || $view === 'login'): ?>
<div class="auth">
  <form method="post" action="index.php?a=<?= $view === 'login' ? 'login' : 'setup' ?>" class="card auth-card">
    <h1><?= e($siteName) ?></h1>
    <p class="muted"><?= $view === 'setup' ? 'Create the admin login for this website. Keep it somewhere safe.' : 'Sign in to edit the website.' ?></p>
    <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <label>Username<input name="user" required autocomplete="username" autofocus></label>
    <label>Password<input name="pass" type="password" required autocomplete="<?= $view === 'setup' ? 'new-password' : 'current-password' ?>" minlength="<?= $view === 'setup' ? 8 : 1 ?>"></label>
    <?php if ($view === 'setup'): ?><label>Confirm password<input name="pass2" type="password" required autocomplete="new-password" minlength="8"></label><?php endif; ?>
    <button class="btn primary block"><?= $view === 'setup' ? 'Create admin account' : 'Sign in' ?></button>
  </form>
</div>
<?php elseif ($view === 'edit'):
    $slug = (string)($_GET['slug'] ?? '');
    $p = load_page($slug); ?>
<div class="editor" id="editor" data-slug="<?= e($slug) ?>" data-csrf="<?= e($csrf) ?>">
  <div class="ed-bar">
    <a href="index.php" class="btn ghost">&larr; Pages</a>
    <strong class="ed-title"><?= e($p['name'] ?? $slug) ?></strong>
    <select id="pageJump" class="ed-jump" title="Switch page"><?php foreach ($pages as $s => $pp): ?><option value="<?= e($s) ?>"<?= (string)$s === $slug ? ' selected' : '' ?>><?= e($pp['name'] ?? $s) ?></option><?php endforeach; ?></select>
    <span class="grow"></span>
    <div class="seg"><button data-dev="desktop" class="on" title="Desktop view">Desktop</button><button data-dev="mobile" title="Mobile view">Mobile</button></div>
    <button class="btn ghost" id="undoBtn" title="Undo (Ctrl+Z)" disabled>Undo</button>
    <button class="btn ghost" id="codeBtn" title="Edit the page HTML directly">&lt;/&gt; HTML</button>
    <a class="btn ghost" href="index.php?a=meta&slug=<?= e($slug) ?>">Page settings</a>
    <a class="btn ghost" href="../<?= e($slug === 'index' ? '' : $slug . '.html') ?>" target="_blank" rel="noopener">View live</a>
    <span id="saveState" class="save-state">All changes saved</span>
    <button class="btn primary" id="saveBtn" disabled>Save &amp; publish</button>
  </div>
  <div class="ed-main">
    <div class="ed-canvas"><iframe id="frame" src="../page.php?slug=<?= e($slug) ?>&amp;cms_edit=1&amp;t=<?= time() ?>" title="Page editor"></iframe></div>
    <aside class="ed-side" id="side">
      <div id="sideBody"></div>
    </aside>
  </div>
  <div class="modal" id="codeModal" hidden>
    <div class="modal-box wide">
      <div class="modal-head"><strong>Page HTML</strong><span class="muted small">For advanced changes. Header and footer are edited in Site settings.</span></div>
      <textarea id="codeArea" spellcheck="false"></textarea>
      <div class="modal-foot"><button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="codeApply">Apply to page</button></div>
    </div>
  </div>
  <div class="modal" id="mediaModal" hidden>
    <div class="modal-box">
      <div class="modal-head"><strong>Choose an image</strong><label class="btn primary small upl">Upload new<input type="file" accept="image/*" id="mediaUpload" hidden></label></div>
      <div class="media-grid pick" id="mediaPick"></div>
      <div class="modal-foot"><label class="grow">Or paste an image link <input id="mediaUrl" placeholder="https://..."></label><button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="mediaUseUrl">Use link</button></div>
    </div>
  </div>
  <script>window.CMS_MEDIA=<?= json_encode(array_map(fn($f) => 'uploads/' . basename($f), media_files())) ?>;window.CMS_PAGES=<?= json_encode(array_map(fn($s, $p) => ['url' => $s . '.html', 'name' => $p['name'] ?? $s], array_keys($pages), $pages)) ?>;</script>
</div>
<script src="assets/editor.js?v=<?= @filemtime(__DIR__ . '/assets/editor.js') ?: 1 ?>"></script>
<?php else: ?>
<div class="layout">
  <nav class="side">
    <div class="side-brand"><?= e($siteName) ?><small>Website admin</small></div>
    <a href="index.php" class="<?= in_array($view, ['pages', 'meta'], true) ? 'on' : '' ?>">Pages</a>
    <a href="index.php?a=site&tab=brand" class="<?= $view === 'site' && ($_GET['tab'] ?? 'brand') === 'brand' ? 'on' : '' ?>">Logo &amp; colours</a>
    <a href="index.php?a=site&tab=header" class="<?= $view === 'site' && ($_GET['tab'] ?? '') === 'header' ? 'on' : '' ?>">Header &amp; menu</a>
    <a href="index.php?a=site&tab=footer" class="<?= $view === 'site' && ($_GET['tab'] ?? '') === 'footer' ? 'on' : '' ?>">Footer &amp; copyright</a>
    <a href="index.php?a=site&tab=other" class="<?= $view === 'site' && ($_GET['tab'] ?? '') === 'other' ? 'on' : '' ?>">Contact &amp; SEO</a>
    <a href="index.php?a=media" class="<?= $view === 'media' ? 'on' : '' ?>">Images</a>
    <a href="index.php?a=history" class="<?= $view === 'history' ? 'on' : '' ?>">History &amp; restore</a>
    <a href="index.php?a=account" class="<?= $view === 'account' ? 'on' : '' ?>">Account</a>
    <span class="grow"></span>
    <a href="../" target="_blank" rel="noopener">View website &nearr;</a>
    <a href="index.php?a=logout">Log out</a>
  </nav>
  <main class="content">
  <?php if ($flash): ?><div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>

  <?php if ($view === 'pages'): ?>
    <div class="head"><h1>Pages</h1><button class="btn primary" onclick="document.getElementById('newPage').hidden=false">+ Add page</button></div>
    <p class="muted">Click <b>Edit content</b> to change text, images, buttons and sections on a page. Changes go live when you press <b>Save &amp; publish</b>.</p>
    <div class="card list">
      <?php foreach ($pages as $s => $p): ?>
      <div class="row">
        <div class="grow"><strong><?= e($p['name'] ?? $s) ?></strong><?php if (!empty($p['hidden'])): ?> <span class="tag">Hidden</span><?php endif; ?><div class="muted small">/<?= e((string)$s === 'index' ? '' : $s . '.html') ?><?php if (!empty($p['updated'])): ?> · edited <?= e(date('M j, Y g:ia', strtotime($p['updated']))) ?><?php endif; ?></div></div>
        <a class="btn primary small" href="index.php?a=edit&slug=<?= e($s) ?>">Edit content</a>
        <a class="btn ghost small" href="index.php?a=meta&slug=<?= e($s) ?>">Settings</a>
        <a class="btn ghost small" href="../<?= e((string)$s === 'index' ? '' : $s . '.html') ?>" target="_blank" rel="noopener">View</a>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="modal" id="newPage" hidden>
      <form method="post" action="index.php?a=page_create" class="modal-box">
        <div class="modal-head"><strong>Add a new page</strong></div>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Page name<input name="name" required placeholder="e.g. Our Projects" oninput="this.form.slug.placeholder=this.value.toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')"></label>
        <label>Page address (optional)<div class="addon"><span>/</span><input name="slug" pattern="[a-z0-9-]*" placeholder="our-projects"><span>.html</span></div></label>
        <label>Start from<select name="copy"><option value="">A blank page</option><?php foreach ($pages as $s => $p): if ((string)$s === '404') continue; ?><option value="<?= e($s) ?>">A copy of <?= e($p['name'] ?? $s) ?></option><?php endforeach; ?></select></label>
        <label class="check"><input type="checkbox" name="menu" value="1" checked> Add it to the main menu</label>
        <div class="modal-foot"><button type="button" class="btn ghost" onclick="this.closest('.modal').hidden=true">Cancel</button><button class="btn primary">Create page</button></div>
      </form>
    </div>

  <?php elseif ($view === 'meta'):
      $slug = (string)($_GET['slug'] ?? ''); $p = load_page($slug);
      $prot = in_array($slug, PROTECTED_PAGES, true); ?>
    <div class="head"><h1>Page settings: <?= e($p['name'] ?? $slug) ?></h1><a class="btn primary" href="index.php?a=edit&slug=<?= e($slug) ?>">Edit content</a></div>
    <form method="post" action="index.php?a=page_meta" class="card form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="slug" value="<?= e($slug) ?>">
      <label>Page name (only shown in this admin)<input name="name" value="<?= e($p['name'] ?? '') ?>" required></label>
      <label>Page address<div class="addon"><span>/</span><input name="newslug" value="<?= e($slug) ?>" pattern="[a-z0-9-]+" <?= $prot ? 'readonly' : '' ?>><span>.html</span></div><?php if ($prot): ?><small class="muted">The address of this page can't be changed.</small><?php else: ?><small class="muted">Menu and footer links update automatically if you change this.</small><?php endif; ?></label>
      <label>Browser tab title (and Google title)<input name="title" value="<?= e($p['title'] ?? '') ?>"></label>
      <label>Google description<textarea name="description" rows="3" maxlength="500"><?= e($p['description'] ?? '') ?></textarea></label>
      <label>Image shown when the page is shared on social media<div class="img-field"><input name="image" value="<?= e($p['image'] ?? '') ?>" placeholder="Uses the main background image if empty"><button type="button" class="btn ghost small" data-pick-image>Choose</button></div></label>
      <?php if (!$prot): ?><label class="check"><input type="checkbox" name="hidden" value="1" <?= !empty($p['hidden']) ? 'checked' : '' ?>> Hide this page from visitors (draft)</label><?php endif; ?>
      <div class="actions"><button class="btn primary">Save settings</button></div>
    </form>
    <?php if (!$prot): ?>
    <form method="post" action="index.php?a=page_delete" class="danger-zone" onsubmit="return confirm('Delete this page? You can restore it later from History.')">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="slug" value="<?= e($slug) ?>">
      <button class="btn danger">Delete this page</button>
    </form>
    <?php endif; ?>

  <?php elseif ($view === 'site'):
      $tab = (string)($_GET['tab'] ?? 'brand'); ?>
    <div class="head"><h1><?= ['brand' => 'Logo & colours', 'header' => 'Header & menu', 'footer' => 'Footer & copyright', 'other' => 'Contact & SEO'][$tab] ?? 'Site settings' ?></h1></div>
    <p class="muted">These appear on every page of the website.</p>
    <form method="post" action="index.php?a=save_site" id="siteForm" class="card form" data-tab="<?= e($tab) ?>">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="site" id="siteJson">
      <div id="siteFields"></div>
      <div class="actions sticky"><button class="btn primary">Save &amp; publish</button></div>
    </form>
    <datalist id="pageLinks"><?= page_options($pages) ?></datalist>
    <script>window.CMS_SITE=<?= json_encode(site(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

  <?php elseif ($view === 'media'): ?>
    <div class="head"><h1>Images</h1>
      <form method="post" action="index.php?a=upload" enctype="multipart/form-data" class="inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label class="btn primary upl">Upload image<input type="file" name="file" accept="image/*" hidden onchange="this.form.submit()"></label>
      </form>
    </div>
    <p class="muted">Images you upload here can be used on any page, for the logo and for backgrounds. Max 8 MB each.</p>
    <div class="media-grid">
      <?php foreach (media_files() as $f): $n = basename($f); ?>
      <div class="media-item"><img src="../uploads/<?= e($n) ?>" alt="" loading="lazy"><div class="small ellip" title="<?= e($n) ?>"><?= e($n) ?></div>
        <form method="post" action="index.php?a=media_delete" onsubmit="return confirm('Delete this image? Pages using it will show a broken image.')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="file" value="<?= e($n) ?>"><button class="link danger small">Delete</button></form></div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($view === 'history'):
      $all = glob(BACKUPS . '/*.json') ?: []; rsort($all);
      $label = function (string $k) use ($pages) { if ($k === 'site') return 'Site settings (logo, menu, footer)'; $s = substr($k, 7); return 'Page: ' . ($pages[$s]['name'] ?? $s . ' (deleted)'); }; ?>
    <div class="head"><h1>History &amp; restore</h1></div>
    <p class="muted">Each save keeps the version it replaced. Restoring puts that version back on the live site, and the current version is kept here too.</p>
    <div class="card list">
      <?php if (!$all): ?><div class="row muted">No saved versions yet.</div><?php endif; ?>
      <?php foreach (array_slice($all, 0, 200) as $f): $n = basename($f); if (!preg_match('/^(.+)@(\d{8}-\d{6})\.json$/', $n, $m)) continue; $t = DateTime::createFromFormat('Ymd-His', $m[2]); ?>
      <div class="row"><div class="grow"><strong><?= e($label($m[1])) ?></strong><div class="muted small">Version from <?= e($t ? $t->format('M j, Y g:i:s a') : $m[2]) ?></div></div>
        <form method="post" action="index.php?a=restore" onsubmit="return confirm('Restore this version? It will replace what is live now.')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="file" value="<?= e($n) ?>"><button class="btn ghost small">Restore</button></form></div>
      <?php endforeach; ?>
    </div>

  <?php elseif ($view === 'account'): ?>
    <div class="head"><h1>Account</h1></div>
    <form method="post" action="index.php?a=password" class="card form">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label>Username<input name="user" value="<?= e($cfg['user']) ?>" required autocomplete="username"></label>
      <label>Current password<input name="current" type="password" required autocomplete="current-password"></label>
      <label>New password<input name="pass" type="password" minlength="8" required autocomplete="new-password"></label>
      <label>Confirm new password<input name="pass2" type="password" minlength="8" required autocomplete="new-password"></label>
      <div class="actions"><button class="btn primary">Update login</button></div>
    </form>

  <?php endif; ?>
  </main>
</div>
<div class="modal" id="mediaModal" hidden>
  <div class="modal-box">
    <div class="modal-head"><strong>Choose an image</strong><label class="btn primary small upl">Upload new<input type="file" accept="image/*" id="mediaUpload" hidden></label></div>
    <div class="media-grid pick" id="mediaPick"></div>
    <div class="modal-foot"><label class="grow">Or paste an image link <input id="mediaUrl" placeholder="https://..."></label><button class="btn ghost" data-close>Cancel</button><button class="btn primary" id="mediaUseUrl">Use link</button></div>
  </div>
</div>
<script>window.CMS_CSRF=<?= json_encode($csrf) ?>;window.CMS_MEDIA=<?= json_encode(array_map(fn($f) => 'uploads/' . basename($f), media_files())) ?>;</script>
<script src="assets/admin.js?v=<?= @filemtime(__DIR__ . '/assets/admin.js') ?: 1 ?>"></script>
<?php endif; ?>
</body>
</html>
