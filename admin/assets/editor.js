/* Visual page editor. Works directly on the live page loaded in the iframe. */
(function () {
  'use strict';
  var ed = document.getElementById('editor');
  var SLUG = ed.getAttribute('data-slug'), CSRF = ed.getAttribute('data-csrf');
  var frame = document.getElementById('frame'), side = document.getElementById('sideBody');
  var saveBtn = document.getElementById('saveBtn'), saveState = document.getElementById('saveState'), undoBtn = document.getElementById('undoBtn');
  var doc, win, main, sel = null, editing = null, hoverEl = null;
  var history = [], dirty = false, editSnapshotTaken = false;

  var BLOCK_TEXT = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'P', 'LI', 'BLOCKQUOTE', 'LABEL', 'TD', 'TH', 'FIGCAPTION', 'DT', 'DD'];
  var INLINE_TEXT = ['A', 'BUTTON', 'SPAN', 'STRONG', 'EM', 'B', 'I', 'SMALL', 'DIV'];
  var BG_SECTIONS = ['hero-home', 'page-hero', 'cta-banner'];

  // ------------------------------------------------------------ helpers
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
  function inMain(el) { return el && el !== main && main.contains(el); }
  function hasOwnText(el) {
    for (var i = 0; i < el.childNodes.length; i++) { var n = el.childNodes[i]; if (n.nodeType === 3 && n.textContent.trim()) return true; }
    return false;
  }
  function isSvg(el) { return el && (el.tagName === 'svg' || el.ownerSVGElement); }
  function svgRoot(el) { return el.tagName === 'svg' ? el : el.ownerSVGElement; }

  function nameOf(el) {
    var map = [['hero-home', 'Home banner'], ['page-hero', 'Page header'], ['cta-banner', 'Call-to-action band'], ['port-card', 'Card'], ['bordered', 'Card'],
      ['navy-card', 'Card'], ['small-card', 'Card'], ['job', 'Job'], ['leader', 'Person'], ['co-row', 'Company'], ['faq-item', 'FAQ item'], ['faq-q', 'Question'], ['faq-a', 'Answer'],
      ['btn-row', 'Buttons'], ['btn', 'Button'], ['two-col', 'Columns'], ['img-text', 'Image + text'], ['port-grid', 'Cards grid'], ['grid-3', 'Grid'], ['grid-4', 'Grid'], ['grid-2', 'Grid'],
      ['grid-jobs', 'Jobs list'], ['prose', 'Text block'], ['section-head', 'Heading block'], ['eyebrow', 'Small label'], ['ico-box', 'Icon box'], ['info-item', 'Info line'],
      ['leader-photo', 'Photo'], ['co-side', 'Icon panel'], ['form', 'Form'], ['map-ph', 'Map box'], ['bar', 'Divider'], ['container', 'Container'], ['section', 'Section']];
    if (isSvg(el)) return 'Icon';
    for (var i = 0; i < map.length; i++) if (el.classList.contains(map[i][0])) return map[i][1];
    var tags = { SECTION: 'Section', H1: 'Main heading', H2: 'Heading', H3: 'Sub-heading', H4: 'Small heading', P: 'Paragraph', A: 'Link', IMG: 'Image', LI: 'List item', UL: 'List', OL: 'List',
      SPAN: 'Text', STRONG: 'Bold text', EM: 'Italic text', LABEL: 'Form field', BUTTON: 'Button', FORM: 'Form', DIV: 'Box' };
    return tags[el.tagName] || el.tagName.toLowerCase();
  }

  // ------------------------------------------------------------ state
  function setDirty(v) {
    dirty = v;
    saveBtn.disabled = !v;
    saveState.textContent = v ? 'Unsaved changes' : 'All changes saved';
    saveState.classList.toggle('dirty', v);
  }
  function snapshot() {
    history.push(cleanHTML());
    if (history.length > 60) history.shift();
    undoBtn.disabled = false;
  }
  function undo() {
    stopEditing();
    if (!history.length) return;
    main.innerHTML = history.pop();
    undoBtn.disabled = !history.length;
    select(null);
    setDirty(true);
  }
  function cleanHTML() {
    var c = main.cloneNode(true);
    c.querySelectorAll('[contenteditable]').forEach(function (e) { e.removeAttribute('contenteditable'); e.removeAttribute('spellcheck'); });
    c.querySelectorAll('.cms-hover,.cms-selected').forEach(function (e) { e.classList.remove('cms-hover', 'cms-selected'); });
    c.querySelectorAll('.faq-item.open').forEach(function (e) { e.classList.remove('open'); });
    c.querySelectorAll('.faq-q[aria-expanded]').forEach(function (e) { e.setAttribute('aria-expanded', 'false'); });
    c.querySelectorAll('#formNote').forEach(function (e) { e.textContent = ''; });
    c.querySelectorAll('[class=""]').forEach(function (e) { e.removeAttribute('class'); });
    return c.innerHTML.trim();
  }

  // ------------------------------------------------------------ text editing
  function startEditing(el) {
    if (editing === el) return;
    stopEditing();
    editing = el;
    editSnapshotTaken = false;
    el.setAttribute('contenteditable', 'true');
    el.setAttribute('spellcheck', 'true');
    el.focus({ preventScroll: true });
  }
  function stopEditing() {
    if (!editing) return;
    editing.removeAttribute('contenteditable');
    editing.removeAttribute('spellcheck');
    editing = null;
  }
  function textTarget(t) {
    var block = t.closest(BLOCK_TEXT.join(','));
    if (block && inMain(block)) return block;
    // walk up through inline elements with their own text (buttons, links, labels)
    var cand = null, el = t;
    while (el && inMain(el)) {
      if (INLINE_TEXT.indexOf(el.tagName) > -1 && hasOwnText(el)) cand = el;
      if (el.tagName === 'DIV' && cand) break;
      if (el.tagName === 'DIV' && !hasOwnText(el)) break;
      el = el.parentElement;
    }
    return cand;
  }

  // ------------------------------------------------------------ selection
  function select(el) {
    if (sel) sel.classList.remove('cms-selected');
    sel = el;
    if (sel && !editing) sel.classList.add('cms-selected');
    if (sel && editing && sel !== editing) sel.classList.add('cms-selected');
    renderSide();
  }

  function onClick(e) {
    var t = e.target;
    if (t.nodeType !== 1) t = t.parentElement;
    var a = t.closest('a,button,input[type=submit]');
    if (a) e.preventDefault();
    var zone = t.closest('[data-cms-zone]');
    if (zone) { stopEditing(); select(null); renderZone(zone.getAttribute('data-cms-zone')); return; }
    if (!inMain(t)) return;
    if (isSvg(t)) { stopEditing(); select(svgRoot(t)); return; }
    if (t.tagName === 'IMG' || t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') { stopEditing(); select(t); return; }
    var tt = textTarget(t);
    if (tt) {
      if (editing !== tt) { startEditing(tt); }
      select(tt);
      return;
    }
    stopEditing();
    select(t);
  }

  // ------------------------------------------------------------ actions
  function change(fn) { stopEditing(); snapshot(); fn(); setDirty(true); renderSide(); }
  function duplicate(el) { change(function () { var c = el.cloneNode(true); c.classList.remove('cms-selected'); el.after(c); select(c); }); }
  function remove(el) {
    if (!confirm('Delete this ' + nameOf(el).toLowerCase() + '?')) return;
    change(function () { var p = el.parentElement; el.remove(); select(inMain(p) ? p : null); });
  }
  function move(el, dir) {
    change(function () {
      var sib = dir < 0 ? el.previousElementSibling : el.nextElementSibling;
      if (!sib) return;
      if (dir < 0) sib.before(el); else sib.after(el);
      el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    });
  }
  function insertAfter(ref, html, then) {
    change(function () {
      var tmp = doc.createElement('div'); tmp.innerHTML = html.trim();
      var node = tmp.firstElementChild;
      if (ref) ref.after(node); else main.appendChild(node);
      select(node);
      node.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (then) then(node);
    });
  }
  function currentSection() {
    var el = sel; while (el && el.parentElement !== main) el = el.parentElement; return inMain(el) ? el : null;
  }
  function fmt(cmd, val) {
    if (!editing) return;
    if (!editSnapshotTaken) { snapshot(); editSnapshotTaken = true; }
    doc.execCommand(cmd, false, val || null);
    setDirty(true);
    editing.focus();
  }

  var TEMPLATES = {
    'Page header': '<section class="page-hero"><div class="container"><h1>Page title</h1><p>A short line about this page.</p></div></section>',
    'Text': '<section class="section"><div class="container narrow"><h2>Section heading</h2><div class="prose"><p>Write your text here. Click any text to edit it.</p></div></div></section>',
    'Heading + text': '<section class="section"><div class="container two-col"><div><h2>Heading on<br>the left</h2><div class="bar"></div></div><div class="prose"><p>Text on the right. Click to edit.</p><p>Add more paragraphs as you need.</p></div></div></section>',
    'Image + text': '<section class="section"><div class="container img-text"><img class="cms-img" src="assets/hero-bg.jpg" alt=""><div><span class="eyebrow dark">Small label</span><h2>Heading</h2><p>Describe this image or topic here.</p><div class="btn-row"><a href="contact.html" class="btn btn-gold">Get in Touch</a></div></div></div></section>',
    '3 cards': '<section class="section bg-muted"><div class="container"><div class="section-head center"><span class="eyebrow dark">Small label</span><h2>Section heading</h2></div><div class="grid-3"><div class="bordered"><h3>Card title</h3><p>Card text goes here.</p></div><div class="bordered"><h3>Card title</h3><p>Card text goes here.</p></div><div class="bordered"><h3>Card title</h3><p>Card text goes here.</p></div></div></div></section>',
    'Call to action': '<section class="cta-banner"><div class="container"><h2>A bold message<br>for your visitors.</h2><div class="btn-row center"><a href="contact.html" class="btn btn-gold">Get in Touch</a></div></div></section>',
    'FAQ': '<section class="section"><div class="container narrow"><div class="faq-item"><button class="faq-q" aria-expanded="false">A question?<svg class="icon-sm chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg></button><div class="faq-a"><div><p>The answer.</p></div></div></div></div></section>',
    'Dark band': '<section class="section bg-navy center-text"><div class="container narrow"><h2>Heading</h2><p class="lead light">Supporting text on a dark background.</p></div></section>'
  };
  var ELEMENTS = {
    'Paragraph': '<p>New paragraph. Click to edit.</p>',
    'Heading': '<h3>New heading</h3>',
    'Button': '<div class="btn-row"><a href="contact.html" class="btn btn-gold">Button text</a></div>',
    'Image': '<img class="cms-img" src="assets/hero-bg.jpg" alt="">',
    'List': '<ul class="prose"><li>First point</li><li>Second point</li></ul>'
  };

  // icons: everything already on the site plus a few extras
  var EXTRA_ICONS = [
    '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
    '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
    '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
    '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
    '<path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 6v6l4 2"/>',
    '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>'
  ];
  function iconSet() {
    var seen = {}, out = [];
    doc.querySelectorAll('#cms-main svg').forEach(function (s) { var k = s.innerHTML.trim(); if (!seen[k] && !s.classList.contains('chev')) { seen[k] = 1; out.push(k); } });
    EXTRA_ICONS.forEach(function (k) { if (!seen[k]) { seen[k] = 1; out.push(k); } });
    return out;
  }

  function pickImage(cb) { window.CMS_pickImageEd(cb); }
  function rootPath(u) { try { return /^(https?:)?\/\//.test(u) ? u : new URL(u, doc.baseURI).pathname; } catch (e) { return u; } }

  // ------------------------------------------------------------ sidebar
  function sec(title, kids) { return h('div', { class: 'sec' }, [title ? h('h3', { text: title }) : null].concat(kids)); }
  function btn(label, fn, cls) { return h('button', { type: 'button', class: 'btn ' + (cls || 'ghost') + ' small', text: label, onclick: fn }); }

  function renderZone(zone) {
    side.innerHTML = '';
    var isHead = zone === 'header';
    side.appendChild(sec(isHead ? 'Header & menu' : 'Footer', [
      h('p', { class: 'tip', text: isHead ? 'The logo, menu links and menu button are the same on every page, so they are edited in one place.' : 'The footer (contact details, link columns, copyright) is the same on every page, so it is edited in one place.' }),
      h('div', { class: 'btns' }, isHead
        ? [btn('Edit menu', function () { leave('index.php?a=site&tab=header'); }, 'primary'), btn('Change logo', function () { leave('index.php?a=site&tab=brand'); })]
        : [btn('Edit footer', function () { leave('index.php?a=site&tab=footer'); }, 'primary'), btn('Change logo', function () { leave('index.php?a=site&tab=brand'); })])
    ]));
  }

  function renderSide() {
    side.innerHTML = '';
    if (!sel) {
      side.appendChild(sec('How to edit', [h('div', { class: 'tip', html: '<b>Click any text</b> on the page and type to change it.<br><br><b>Click an image or icon</b> to replace it.<br><br><b>Click a card, section or button</b> to copy, move or delete it.<br><br>Press <b>Save &amp; publish</b> when you are done.' })]));
      side.appendChild(sec('Add a section at the end', [tplGrid(null)]));
      return;
    }
    // breadcrumbs
    var chain = [], el = sel;
    while (inMain(el)) { chain.unshift(el); el = el.parentElement; }
    side.appendChild(sec('Selected: ' + nameOf(sel), [
      h('div', { class: 'crumbs' }, chain.filter(function (x) { return x === sel || !x.classList.contains('container'); }).map(function (x) {
        return h('button', { type: 'button', class: x === sel ? 'on' : '', text: nameOf(x), title: 'Select ' + nameOf(x).toLowerCase(), onclick: function () { stopEditing(); select(x); x.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } });
      })),
      h('small', { class: 'muted', text: 'Tip: click a name above to select the whole card or section around it.' })
    ]));

    // text formatting
    if (editing && editing === sel) {
      side.appendChild(sec('Text', [
        h('p', { class: 'muted small', text: 'Type directly on the page. Select words first to make them bold, italic or a link.' }),
        h('div', { class: 'fmt' }, [
          h('button', { type: 'button', title: 'Bold', html: '<b>B</b>', onmousedown: function (e) { e.preventDefault(); fmt('bold'); } }),
          h('button', { type: 'button', title: 'Italic', html: '<i>I</i>', onmousedown: function (e) { e.preventDefault(); fmt('italic'); } }),
          h('button', { type: 'button', title: 'Add link to selected words', text: 'Link', onmousedown: function (e) { e.preventDefault(); var u = prompt('Link address (e.g. contact.html or https://...)'); if (u) fmt('createLink', u); } }),
          h('button', { type: 'button', title: 'Remove link', text: 'Unlink', onmousedown: function (e) { e.preventDefault(); fmt('unlink'); } }),
          h('button', { type: 'button', title: 'Line break', text: '↵', onmousedown: function (e) { e.preventDefault(); fmt('insertLineBreak'); } }),
          h('button', { type: 'button', title: 'Remove bold/italic', text: 'Clear', onmousedown: function (e) { e.preventDefault(); fmt('removeFormat'); } })
        ])
      ]));
    }

    // link / button settings
    var link = sel.tagName === 'A' ? sel : null;
    if (link) {
      var url = h('input', { value: link.getAttribute('href') || '', list: 'edPages' });
      var nt = h('input', { type: 'checkbox' }); nt.checked = link.getAttribute('target') === '_blank';
      var apply = function () {
        snapshot(); link.setAttribute('href', url.value.trim() || '#');
        if (nt.checked) { link.setAttribute('target', '_blank'); link.setAttribute('rel', 'noopener noreferrer'); } else { link.removeAttribute('target'); link.removeAttribute('rel'); }
        setDirty(true);
      };
      url.addEventListener('change', apply); nt.addEventListener('change', apply);
      var kids = [h('label', {}, ['Goes to', url]), h('label', { class: 'check' }, [nt, ' Open in a new tab'])];
      if (link.classList.contains('btn')) {
        var styles = { 'btn-gold': 'Gold', 'btn-outline': 'Outline (on dark)', 'btn-outline-dark': 'Outline (on light)', 'btn-navy': 'Dark', 'btn-ghost': 'Text only' };
        var s = h('select', { onchange: function () { snapshot(); Object.keys(styles).forEach(function (k) { link.classList.remove(k); }); link.classList.add(s.value); setDirty(true); } });
        Object.keys(styles).forEach(function (k) { var o = h('option', { value: k, text: styles[k] }); if (link.classList.contains(k)) o.selected = true; s.appendChild(o); });
        kids.push(h('label', {}, ['Button style', s]));
      }
      side.appendChild(sec('Link', kids));
    }

    // image
    if (sel.tagName === 'IMG') {
      var img = sel;
      var alt = h('input', { value: img.getAttribute('alt') || '', onchange: function () { snapshot(); img.setAttribute('alt', alt.value); setDirty(true); } });
      side.appendChild(sec('Image', [
        h('div', { class: 'btns' }, [btn('Replace image', function () { pickImage(function (u) { change(function () { img.setAttribute('src', u); select(img); }); }); }, 'primary')]),
        h('label', {}, ['Description (for Google and screen readers)', alt])
      ]));
    }

    // icon
    if (isSvg(sel)) {
      var svg = sel, grid = h('div', { class: 'icon-grid' });
      iconSet().forEach(function (inner) {
        grid.appendChild(h('button', { type: 'button', title: 'Use this icon', html: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>',
          onclick: function () { change(function () { svg.innerHTML = inner; select(svg); }); } }));
      });
      side.appendChild(sec('Icon', [grid, btn('Use a photo or image instead', function () {
        pickImage(function (u) { change(function () { var im = doc.createElement('img'); im.className = 'cms-icon-img'; im.src = u; im.alt = ''; svg.replaceWith(im); select(im); }); });
      })]));
    }

    // section background / style
    var section = currentSection();
    if (section && sel === section) {
      var bg = BG_SECTIONS.filter(function (c) { return section.classList.contains(c); })[0];
      var kids2 = [];
      if (bg) {
        kids2.push(h('div', { class: 'btns' }, [
          btn('Change background image', function () { pickImage(function (u) { change(function () { section.style.setProperty('--hero-img', "url('" + rootPath(u).replace(/'/g, '') + "')"); select(section); }); }); }, 'primary'),
          btn('Use default', function () { change(function () { section.style.removeProperty('--hero-img'); if (!section.getAttribute('style')) section.removeAttribute('style'); select(section); }); })
        ]));
      } else if (section.classList.contains('section')) {
        var looks = { '': 'White', 'bg-muted': 'Light grey', 'bg-navy': 'Dark' };
        var sl = h('select', { onchange: function () { change(function () { section.classList.remove('bg-muted', 'bg-navy'); if (sl.value) section.classList.add(sl.value); select(section); }); } });
        Object.keys(looks).forEach(function (k) { var o = h('option', { value: k, text: looks[k] }); if ((k && section.classList.contains(k)) || (!k && !section.classList.contains('bg-muted') && !section.classList.contains('bg-navy'))) o.selected = true; sl.appendChild(o); });
        kids2.push(h('label', {}, ['Background', sl]));
      }
      if (kids2.length) side.appendChild(sec('Section look', kids2));
    }

    // structure
    var target = sel;
    side.appendChild(sec('Arrange', [h('div', { class: 'btns' }, [
      btn('Duplicate', function () { duplicate(target); }),
      btn('Move up', function () { move(target, -1); }),
      btn('Move down', function () { move(target, 1); }),
      btn('Delete', function () { remove(target); }, 'danger')
    ])]));

    // insert element after
    if (!section || sel !== section) {
      var eg = h('div', { class: 'tpl-grid' });
      Object.keys(ELEMENTS).forEach(function (k) {
        eg.appendChild(h('button', { type: 'button', text: k, onclick: function () {
          insertAfter(sel, ELEMENTS[k], function (n) { if (k === 'Image') pickImage(function (u) { n.setAttribute('src', u); }); });
        } }));
      });
      side.appendChild(sec('Add below this', [eg]));
    }
    side.appendChild(sec('Add a new section below', [tplGrid(section)]));
  }

  function tplGrid(after) {
    var g = h('div', { class: 'tpl-grid' });
    Object.keys(TEMPLATES).forEach(function (k) { g.appendChild(h('button', { type: 'button', text: k, onclick: function () { insertAfter(after || main.lastElementChild, TEMPLATES[k]); } })); });
    return g;
  }

  // ------------------------------------------------------------ frame wiring
  function leave(url) { if (!dirty || confirm('You have unsaved changes. Leave without saving?')) { dirty = false; location.href = url; } }

  frame.addEventListener('load', function () {
    doc = frame.contentDocument; win = frame.contentWindow;
    main = doc.getElementById('cms-main');
    if (!main) { side.innerHTML = '<p class="tip">Could not load the page for editing. Your login may have expired. Reload this page.</p>'; return; }
    history = []; undoBtn.disabled = true; setDirty(false); sel = null; editing = null;
    var dl = h('datalist', { id: 'edPages' });
    (window.CMS_PAGES || []).forEach(function (p) { if (p.url !== '404.html') dl.appendChild(h('option', { value: p.url, text: p.name })); });
    document.body.appendChild(dl);

    doc.addEventListener('click', onClick, true);
    doc.addEventListener('submit', function (e) { e.preventDefault(); }, true);
    doc.addEventListener('mouseover', function (e) {
      var t = e.target.nodeType === 1 ? e.target : e.target.parentElement;
      if (hoverEl) hoverEl.classList.remove('cms-hover');
      hoverEl = inMain(t) ? (isSvg(t) ? svgRoot(t) : (textTarget(t) || t)) : null;
      if (hoverEl && hoverEl !== editing) hoverEl.classList.add('cms-hover');
    });
    doc.addEventListener('input', function () { if (!editSnapshotTaken && editing) { editSnapshotTaken = true; } setDirty(true); });
    doc.addEventListener('beforeinput', function () { if (editing && !editSnapshotTaken) { snapshot(); editSnapshotTaken = true; } }, true);
    doc.addEventListener('keydown', function (e) {
      if (!editing) return;
      if (e.key === 'Enter') { e.preventDefault(); doc.execCommand('insertLineBreak'); }
      if (e.key === ' ' && editing.closest('button')) { e.preventDefault(); doc.execCommand('insertText', false, ' '); }
      if (e.key === 'Escape') { stopEditing(); select(sel); }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
    }, true);
    doc.addEventListener('paste', function (e) { // paste as plain text so outside formatting doesn't break the design
      if (!editing) return;
      e.preventDefault();
      var t = (e.clipboardData || win.clipboardData).getData('text/plain');
      doc.execCommand('insertText', false, t);
    }, true);
    renderSide();
  });

  // ------------------------------------------------------------ save
  function save() {
    if (!main) return;
    stopEditing();
    saveBtn.disabled = true; saveState.textContent = 'Saving…';
    var fd = new FormData(); fd.append('csrf', CSRF); fd.append('slug', SLUG); fd.append('body', cleanHTML());
    fetch('index.php?a=save_body', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { error: 'Server error (' + r.status + ')' }; }); })
      .then(function (d) {
        if (d.error) throw new Error(d.error);
        setDirty(false); saveState.textContent = 'Saved and live ✓';
      })
      .catch(function (e) { setDirty(true); alert('Not saved: ' + e.message); });
  }
  saveBtn.addEventListener('click', save);
  undoBtn.addEventListener('click', undo);
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); if (dirty) save(); }
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  document.querySelectorAll('.seg button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.seg button').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelector('.ed-canvas').classList.toggle('mobile', b.getAttribute('data-dev') === 'mobile');
    });
  });
  document.getElementById('pageJump').addEventListener('change', function () { leave('index.php?a=edit&slug=' + encodeURIComponent(this.value)); });

  // HTML view
  var codeModal = document.getElementById('codeModal'), codeArea = document.getElementById('codeArea');
  document.getElementById('codeBtn').addEventListener('click', function () { if (!main) return; stopEditing(); codeArea.value = cleanHTML().replace(/<\/(section|div|p|h[1-6]|li|ul)>(?!\n)/g, '</$1>\n'); codeModal.hidden = false; });
  document.getElementById('codeApply').addEventListener('click', function () { change(function () { main.innerHTML = codeArea.value; select(null); }); codeModal.hidden = true; });

  // image picker (same modal as the rest of the admin)
  window.CMS_pickImageEd = function (cb) {
    var m = document.getElementById('mediaModal'), grid = document.getElementById('mediaPick');
    grid.innerHTML = '';
    var done = function (u) { m.hidden = true; cb(u); };
    (window.CMS_MEDIA || []).forEach(function (u) { grid.appendChild(h('button', { type: 'button', class: 'media-item', title: u, onclick: function () { done(u); } }, [h('img', { src: '../' + u, alt: '', loading: 'lazy' })])); });
    if (!(window.CMS_MEDIA || []).length) grid.appendChild(h('p', { class: 'muted', text: 'No images yet. Click "Upload new".' }));
    var inp = document.getElementById('mediaUpload'); inp.value = '';
    inp.onchange = function () {
      if (!inp.files[0]) return;
      var fd = new FormData(); fd.append('file', inp.files[0]); fd.append('csrf', CSRF);
      grid.insertBefore(h('p', { class: 'muted', text: 'Uploading…' }), grid.firstChild);
      fetch('index.php?a=upload', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.error) throw new Error(d.error); window.CMS_MEDIA.unshift(d.url); done(d.url); })
        .catch(function (e) { alert(e.message); m.hidden = true; });
    };
    var urlIn = document.getElementById('mediaUrl'); urlIn.value = '';
    document.getElementById('mediaUseUrl').onclick = function () { if (urlIn.value.trim()) done(urlIn.value.trim()); };
    m.hidden = false;
  };
  document.addEventListener('click', function (e) { var c = e.target.closest('[data-close]'); if (c) c.closest('.modal').hidden = true; });
})();
