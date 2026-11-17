<?php
// Public page renderer: /, /about.html and /about all come here via .htaccess.
declare(strict_types=1);
require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/render.php';

$slug = strtolower(trim((string)($_GET['slug'] ?? 'index'), '/'));
if ($slug === '' ) $slug = 'index';
$slug = preg_replace('/\.html?$/', '', $slug);

$edit = isset($_GET['cms_edit']) && is_admin();
$page = valid_slug($slug) ? load_page($slug) : null;

if ($page === null || (!empty($page['hidden']) && !$edit && !is_admin())) {
    http_response_code(404);
    $slug = '404';
    $page = load_page('404') ?? ['title' => 'Page Not Found', 'body' => '<section class="nf"><div class="container"><h1>404</h1><p>Page not found</p></div></section>'];
}

render_page($slug, $page, $edit);
