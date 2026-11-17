/* Admin portal: image picker and the site settings forms. */
(function () {
  'use strict';
  var CSRF = window.CMS_CSRF || '';

  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') el.textContent = attrs[k];
      else if (k === 'html') el.innerHTML = attrs[k];
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] === true) el.setAttribute(k, '');
      else if (attrs[k] !== false && attrs[k] != null) el.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }

  // ---------------------------------------------------------------- image picker
  function upload(file) {
    var fd = new FormData(); fd.append('file', file); fd.append('csrf', CSRF);
    return fetch('index.php?a=upload', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.error) throw new Error(d.error); (window.CMS_MEDIA = window.CMS_MEDIA || []).unshift(d.url); return d.url; });
  }
  function pickImage(cb) {
    var m = document.getElementById('mediaModal'); if (!m) return;
    var grid = document.getElementById('mediaPick'); grid.innerHTML = '';
    var done = function (url) { m.hidden = true; cb(url); };
    (window.CMS_MEDIA || []).forEach(function (u) {
      grid.appendChild(h('button', { type: 'button', class: 'media-item', title: u, onclick: function () { done(u); } }, [h('img', { src: '../' + u, alt: '', loading: 'lazy' })]));
    });
    if (!(window.CMS_MEDIA || []).length) grid.appendChild(h('p', { class: 'muted', text: 'No images yet. Click "Upload new".' }));
    var inp = document.getElementById('mediaUpload');
    inp.value = '';
    inp.onchange = function () {
      if (!inp.files[0]) return;
      grid.insertBefore(h('p', { class: 'muted', text: 'Uploading…' }), grid.firstChild);
      upload(inp.files[0]).then(done).catch(function (e) { alert(e.message); m.hidden = true; });
    };
    var urlIn = document.getElementById('mediaUrl'); urlIn.value = '';
    document.getElementById('mediaUseUrl').onclick = function () { if (urlIn.value.trim()) done(urlIn.value.trim()); };
    m.hidden = false;
  }
  window.CMS_pickImage = pickImage;
  window.CMS_upload = upload;
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-close]'); if (c) c.closest('.modal').hidden = true;
    var p = e.target.closest('[data-pick-image]');
    if (p) { var input = p.parentNode.querySelector('input'); pickImage(function (u) { input.value = u; input.dispatchEvent(new Event('input', { bubbles: true })); }); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.modal').forEach(function (m) { m.hidden = true; }); });

  // ---------------------------------------------------------------- site settings
  var form = document.getElementById('siteForm');
  if (!form || !window.CMS_SITE) return;
  var S = window.CMS_SITE, root = document.getElementById('siteFields'), tab = form.getAttribute('data-tab');
  var dirty = false;
  function touch() { dirty = true; }
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  form.addEventListener('submit', function () { dirty = false; document.getElementById('siteJson').value = JSON.stringify(S); });

  function obj(path) { // ensure nested object exists
    var o = S; path.split('.').forEach(function (k) { if (o[k] == null || typeof o[k] !== 'object') o[k] = {}; o = o[k]; }); return o;
  }
  function text(o, key, label, opts) {
    opts = opts || {};
    var inp = h(opts.multi ? 'textarea' : 'input', { value: o[key] || '', placeholder: opts.ph || '', rows: opts.rows || 3, list: opts.list || null, type: opts.type || null, oninput: function () { o[key] = inp.value; touch(); if (opts.after) opts.after(); } });
    if (opts.multi) inp.value = o[key] || '';
    var kids = [label, inp];
    if (opts.image) {
      var prev = h('img', { class: 'thumb', alt: '' });
      var sync = function () { prev.src = o[key] ? (/^(https?:)?\/\//.test(o[key]) ? o[key] : '../' + o[key]) : ''; prev.hidden = !o[key]; };
      inp.addEventListener('input', sync); sync();
      kids = [label, h('div', { class: 'img-field' }, [prev, inp, h('button', { type: 'button', class: 'btn ghost small', 'data-pick-image': true, text: 'Choose' }),
        h('button', { type: 'button', class: 'btn ghost small', text: 'Clear', onclick: function () { inp.value = ''; o[key] = ''; sync(); touch(); } })])];
    }
    if (opts.hint) kids.push(h('small', { class: 'muted', html: opts.hint }));
    return h('label', {}, kids);
  }
  function check(o, key, label) {
    var c = h('input', { type: 'checkbox', onchange: function () { o[key] = c.checked; touch(); } }); c.checked = !!o[key];
    return h('label', { class: 'check' }, [c, ' ' + label]);
  }
  function color(o, key, label) {
    var t = h('input', { value: o[key] || '', class: 'hex', maxlength: 9 });
    var c = h('input', { type: 'color', value: /^#[0-9a-f]{6}$/i.test(o[key] || '') ? o[key] : '#000000' });
    c.oninput = function () { o[key] = c.value; t.value = c.value; touch(); };
    t.oninput = function () { o[key] = t.value; if (/^#[0-9a-f]{6}$/i.test(t.value)) c.value = t.value; touch(); };
    return h('label', {}, [label, h('div', { class: 'color-field' }, [c, t])]);
  }
  function section(title, kids, note) {
    return h('fieldset', {}, [h('legend', { text: title })].concat(note ? [h('p', { class: 'muted small', html: note })] : []).concat(kids));
  }
  // repeater: arr of objects, render(item) returns fields
  function repeater(arr, render, blank, addLabel, max) {
    var box = h('div', { class: 'rep' });
    function draw() {
      box.innerHTML = '';
      arr.forEach(function (item, i) {
        var tools = h('div', { class: 'rep-tools' }, [
          h('button', { type: 'button', class: 'icon-btn', title: 'Move up', text: '↑', disabled: i === 0, onclick: function () { arr.splice(i - 1, 0, arr.splice(i, 1)[0]); touch(); draw(); } }),
          h('button', { type: 'button', class: 'icon-btn', title: 'Move down', text: '↓', disabled: i === arr.length - 1, onclick: function () { arr.splice(i + 1, 0, arr.splice(i, 1)[0]); touch(); draw(); } }),
          h('button', { type: 'button', class: 'icon-btn danger', title: 'Remove', text: '✕', onclick: function () { arr.splice(i, 1); touch(); draw(); } })
        ]);
        box.appendChild(h('div', { class: 'rep-row' }, [h('div', { class: 'rep-fields' }, render(item, i)), tools]));
      });
      if (!max || arr.length < max) box.appendChild(h('button', { type: 'button', class: 'btn ghost small add', text: '+ ' + addLabel, onclick: function () { arr.push(JSON.parse(JSON.stringify(blank))); touch(); draw(); } }));
    }
    draw();
    return box;
  }
  function linkRow(item, withTab) {
    var f = [text(item, 'label', 'Text'), text(item, 'url', 'Link', { list: 'pageLinks', ph: 'Pick a page or paste https://…' })];
    if (withTab) f.push(check(item, 'newTab', 'Open in new tab'));
    return f;
  }

  var b = obj('brand'), c = obj('colors'), mb = obj('menuButton'), fc = obj('footerCta'), ft = obj('footer'), ct = obj('contact'), seo = obj('seo');
  S.menu = S.menu || []; ft.columns = ft.columns || []; ft.social = ft.social || [];

  if (tab === 'brand') {
    root.appendChild(section('Logo', [
      text(b, 'name', 'Company name', { hint: 'Shown as the logo when no logo image is set, and used in page titles.' }),
      text(b, 'logo', 'Header logo image', { image: true, hint: 'Leave empty to show the company name as text.' }),
      text(b, 'logoHeight', 'Logo height (pixels)', { type: 'number' }),
      text(b, 'footerLogo', 'Footer logo image', { image: true, hint: 'Footer has a dark background, so a white/light logo works best. Leave empty to show the name.' }),
      text(b, 'favicon', 'Browser tab icon (favicon)', { image: true, hint: 'A small square image (PNG or ICO).' })
    ]));
    root.appendChild(section('Background image', [text(S, 'heroImage', 'Main background image', { image: true, hint: 'Used behind the home page banner, page headers and call-to-action bands, unless a section has its own.' })]));
    root.appendChild(section('Colours', [h('div', { class: 'grid2' }, [
      color(c, 'accent', 'Accent (gold buttons, highlights)'), color(c, 'accentText', 'Text on accent buttons'),
      color(c, 'dark', 'Dark backgrounds (footer, bands)'), color(c, 'text', 'Main text colour')])]));
  } else if (tab === 'header') {
    root.appendChild(section('Main menu', [repeater(S.menu, function (it) { return linkRow(it, true); }, { label: '', url: '', newTab: false }, 'Add menu item')],
      'Add, remove or reorder the links at the top of every page. The current page is highlighted automatically.'));
    root.appendChild(section('Menu button', [check(mb, 'show', 'Show the button at the end of the menu'), text(mb, 'label', 'Button text'), text(mb, 'url', 'Button link', { list: 'pageLinks' })]));
  } else if (tab === 'footer') {
    root.appendChild(section('Call-to-action band (above the footer)', [check(fc, 'show', 'Show this band'), text(fc, 'title', 'Heading'), text(fc, 'text', 'Text', { multi: true, rows: 2 }),
      text(fc, 'buttonLabel', 'Button text'), text(fc, 'buttonUrl', 'Button link', { list: 'pageLinks' })]));
    root.appendChild(section('Company details', [text(ft, 'tagline', 'Tagline under the logo'), text(ft, 'email', 'Email'), text(ft, 'phone', 'Phone (optional)'), text(ft, 'address', 'Address / location')]));
    root.appendChild(section('Link columns', [repeater(ft.columns, function (col) {
      col.links = col.links || [];
      return [text(col, 'title', 'Column heading'), h('div', { class: 'sub' }, [h('small', { class: 'muted', text: 'Links in this column (leave the link empty for plain text):' }), repeater(col.links, function (it) { return linkRow(it, false); }, { label: '', url: '' }, 'Add link')])];
    }, { title: 'New column', links: [] }, 'Add column', 4)], 'Up to 4 columns.'));
    root.appendChild(section('Social media links', [repeater(ft.social, function (it) { return [text(it, 'label', 'Name (e.g. LinkedIn)'), text(it, 'url', 'Profile link', { ph: 'https://' })]; }, { label: '', url: '' }, 'Add social link')]));
    root.appendChild(section('Copyright bar', [text(ft, 'copyright', 'Copyright text', { hint: 'Type <b>{year}</b> where the current year should appear. It updates by itself.' }), text(ft, 'bottomRight', 'Text on the right')]));
  } else {
    root.appendChild(section('Contact form', [text(ct, 'formEmail', 'Messages from the contact form go to', { type: 'email' })]));
    root.appendChild(section('Tracking & extra code', [
      text(seo, 'headCode', 'Code added inside <head> on every page', { multi: true, rows: 5, hint: 'For Google Analytics, Search Console verification, Meta Pixel and similar. Paste exactly what the service gives you.' }),
      text(seo, 'footerCode', 'Code added before </body> on every page', { multi: true, rows: 5 })
    ], 'Page titles and Google descriptions are set per page in <b>Pages → Settings</b>.'));
  }
})();
