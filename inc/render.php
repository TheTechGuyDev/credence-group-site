<?php
// Builds the shared header, footer and page shell from data/site.json.
declare(strict_types=1);

const ICON_MAIL = '<svg class="icon-sm" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7" /> <rect x="2" y="4" width="20" height="16" rx="2" /></svg>';
const ICON_PIN = '<svg class="icon-sm" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /> <circle cx="12" cy="10" r="3" /></svg>';
const ICON_PHONE = '<svg class="icon-sm" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
const ICON_ARROW = '<svg class="icon-sm" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14" /> <path d="m12 5 7 7-7 7" /></svg>';

function link_attrs(string $url, bool $newTab = false): string {
    $ext = (bool)preg_match('#^https?://#i', $url);
    $a = 'href="' . e($url) . '"';
    if ($newTab || $ext) $a .= ' target="_blank" rel="noopener noreferrer"';
    return $a;
}

function is_current(string $url, string $slug): bool {
    $u = strtolower(trim($url));
    if ($u === '' ) return false;
    $u = preg_replace('#^\./|^/#', '', $u);
    if ($slug === 'index' && ($u === '' || $u === 'index.html' || $u === 'index')) return true;
    return $u === $slug . '.html' || $u === $slug;
}

function brand_html(array $s, bool $footer = false): string {
    $b = $s['brand'] ?? [];
    $name = $b['name'] ?? '';
    $logo = $footer ? ($b['footerLogo'] ?? '') : ($b['logo'] ?? '');
    $h = max(16, min(160, (int)($b['logoHeight'] ?? 40)));
    if ($logo !== '') {
        return '<img src="' . e($logo) . '" alt="' . e($name) . '" style="height:' . $h . 'px;width:auto">';
    }
    return e($name);
}

function render_header(string $slug): string {
    $s = site();
    $menu = $s['menu'] ?? [];
    $btn = $s['menuButton'] ?? [];
    $desk = ''; $mob = '';
    foreach ($menu as $m) {
        if (trim($m['label'] ?? '') === '') continue;
        $act = is_current($m['url'] ?? '', $slug) ? ' active' : '';
        $at = link_attrs($m['url'] ?? '#', !empty($m['newTab']));
        $desk .= '<a ' . $at . ' class="nav-link' . $act . '">' . e($m['label']) . '</a>';
        $mob .= '<a ' . $at . ' class="mobile-link' . $act . '">' . e($m['label']) . '</a>';
    }
    if (!empty($btn['show']) && trim($btn['label'] ?? '') !== '') {
        $at = link_attrs($btn['url'] ?? '#');
        $desk .= '<a ' . $at . ' class="btn btn-gold btn-sm">' . e($btn['label']) . '</a>';
        $mob .= '<a ' . $at . ' class="btn btn-gold btn-block">' . e($btn['label']) . '</a>';
    }
    $logoClass = ($s['brand']['logo'] ?? '') !== '' ? ' brand-logo' : '';
    return '<header class="site-header" data-cms-zone="header">
<div class="container header-inner">
<a href="index.html" class="brand' . $logoClass . '">' . brand_html($s) . '</a>
<nav class="desktop-nav" aria-label="Main">' . $desk . '</nav>
<button class="menu-toggle" id="menuToggle" aria-label="Toggle menu" aria-expanded="false" aria-controls="mobileMenu"><span class="i-open"><svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16" /> <path d="M4 12h16" /> <path d="M4 19h16" /></svg></span><span class="i-close"><svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18" /> <path d="m6 6 12 12" /></svg></span></button>
</div>
<nav class="mobile-menu" id="mobileMenu" aria-label="Mobile"><div class="container">' . $mob . '</div></nav>
</header>';
}

function render_footer(): string {
    $s = site();
    $f = $s['footer'] ?? [];
    $cta = $s['footerCta'] ?? [];
    $out = '<footer class="site-footer" data-cms-zone="footer">';
    if (!empty($cta['show'])) {
        $btn = '';
        if (trim($cta['buttonLabel'] ?? '') !== '') {
            $btn = '<a ' . link_attrs($cta['buttonUrl'] ?? '#') . ' class="btn btn-gold">' . e($cta['buttonLabel']) . ' ' . ICON_ARROW . '</a>';
        }
        $out .= '<div class="footer-cta"><div class="container footer-cta-inner"><div><h3>' . e($cta['title'] ?? '') . '</h3><p>' . e($cta['text'] ?? '') . '</p></div>' . $btn . '</div></div>';
    }
    $cols = $f['columns'] ?? [];
    $out .= '<div class="container footer-grid" style="--footer-cols:' . count($cols) . '">';
    $out .= '<div><div class="brand brand-footer">' . brand_html($s, true) . '</div>';
    if (trim($f['tagline'] ?? '') !== '') $out .= '<p class="tagline">' . e($f['tagline']) . '</p>';
    if (trim($f['email'] ?? '') !== '') $out .= '<p class="foot-line">' . ICON_MAIL . '<a href="mailto:' . e($f['email']) . '">' . e($f['email']) . '</a></p>';
    if (trim($f['phone'] ?? '') !== '') $out .= '<p class="foot-line">' . ICON_PHONE . '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $f['phone'])) . '">' . e($f['phone']) . '</a></p>';
    if (trim($f['address'] ?? '') !== '') $out .= '<p class="foot-line">' . ICON_PIN . e($f['address']) . '</p>';
    $social = array_filter($f['social'] ?? [], fn($x) => trim($x['label'] ?? '') !== '' && trim($x['url'] ?? '') !== '');
    if ($social) {
        $out .= '<p class="foot-social">';
        foreach ($social as $x) $out .= '<a ' . link_attrs($x['url'], true) . '>' . e($x['label']) . '</a>';
        $out .= '</p>';
    }
    $out .= '</div>';
    foreach ($cols as $c) {
        $out .= '<div><h4>' . e($c['title'] ?? '') . '</h4><ul>';
        foreach ($c['links'] ?? [] as $l) {
            if (trim($l['label'] ?? '') === '') continue;
            $out .= trim($l['url'] ?? '') !== ''
                ? '<li><a ' . link_attrs($l['url']) . '>' . e($l['label']) . '</a></li>'
                : '<li>' . e($l['label']) . '</li>';
        }
        $out .= '</ul></div>';
    }
    $out .= '</div>';
    $copy = str_replace('{year}', '<span data-year>' . date('Y') . '</span>', e($f['copyright'] ?? ''));
    $out .= '<div class="container footer-bottom"><span>' . $copy . '</span><span>' . e($f['bottomRight'] ?? '') . '</span></div>';
    $out .= '</footer>';
    return $out;
}

// CSS variables resolve url() against the stylesheet, so give them root-relative paths.
function abs_url(string $u): string {
    if ($u === '' || preg_match('#^(https?:)?//|^/|^data:#i', $u)) return $u;
    return base_url() . ltrim($u, './');
}

function color_or(string $v, string $fallback): string {
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $fallback;
}

function render_page(string $slug, array $page, bool $editMode = false): void {
    $s = site();
    $c = $s['colors'] ?? [];
    $name = $s['brand']['name'] ?? '';
    $title = trim($page['title'] ?? '') !== '' ? $page['title'] : $name;
    $desc = $page['description'] ?? '';
    $img = ($page['image'] ?? '') !== '' ? $page['image'] : ($s['heroImage'] ?? '');
    $hero = $s['heroImage'] ?? 'assets/hero-bg.jpg';
    $base = base_url();
    $v = @filemtime(ROOT . '/css/style.css') ?: 1;
    $jv = @filemtime(ROOT . '/js/main.js') ?: 1;
    header('Content-Type: text/html; charset=utf-8');
    if ($editMode) header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= e($base) ?>">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php if ($img !== ''): ?><meta property="og:image" content="<?= e($img) ?>">
<?php endif; ?>
<link rel="icon" href="<?= e($s['brand']['favicon'] ?? 'favicon.ico') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=<?= $v ?>">
<style>:root{--gold:<?= color_or($c['accent'] ?? '', '#d9a520') ?>;--gold-fg:<?= color_or($c['accentText'] ?? '', '#0f263d') ?>;--navy:<?= color_or($c['dark'] ?? '', '#213045') ?>;--fg:<?= color_or($c['text'] ?? '', '#1b2232') ?>;--hero-img:url('<?= e(str_replace("'", '', abs_url($hero))) ?>')}</style>
<?php if ($editMode): ?><link rel="stylesheet" href="admin/assets/frame.css?v=<?= @filemtime(ROOT . '/admin/assets/frame.css') ?: 1 ?>">
<?php else: echo $s['seo']['headCode'] ?? ''; endif; ?>
</head>
<body data-contact-email="<?= e($s['contact']['formEmail'] ?? '') ?>"<?= $editMode ? ' class="cms-editing"' : '' ?>>
<?= render_header($slug) ?>

<main id="cms-main">
<?= $page['body'] ?? '' ?>

</main>
<?= render_footer() ?>

<script src="js/main.js?v=<?= $jv ?>"></script>
<?php if (!$editMode) echo $s['seo']['footerCode'] ?? ''; ?>
</body>
</html>
<?php
}
