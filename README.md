# The Credence Group website

PHP site with a built-in admin portal (no database). Runs on any cPanel host with PHP 7.4+.

- Public pages: `page.php` renders `data/pages/<slug>.json` inside the shared header/footer from `data/site.json`.
- Admin: `/admin` (first visit creates the login). Edit page content visually, menu, footer, logo, colours, images, SEO code, page list, and restore older versions.
- Content and backups live in `data/` (blocked from the web). Uploads go to `uploads/`.
