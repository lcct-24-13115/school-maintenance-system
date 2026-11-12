/* Pop-up file viewer for ticket attachments.
 * Any link with data-file-view opens in the <dialog id="file-viewer">:
 *   data-kind image | pdf | docx | other,  data-src (shown inline),  data-download,  data-name,  data-meta,
 *   data-text-url (docx: simplified text),  data-delete-url (only present when the person may remove the file).
 * Previous / next walk through every such link on the page, in page order. Zoom works on images and Word text.
 * Without JavaScript (or an old browser without <dialog>) the links simply open the file as before. */
(function () {
  'use strict';

  var modal = document.getElementById('file-viewer');
  if (!modal || typeof modal.showModal !== 'function') { return; }

  function q(sel) { return modal.querySelector(sel); }
  var body = q('[data-fv-body]'), title = q('[data-fv-title]'), meta = q('[data-fv-meta]'), count = q('[data-fv-count]');
  var prev = q('[data-fv-prev]'), next = q('[data-fv-next]'), openA = q('[data-fv-open]'), dl = q('[data-fv-download]'), del = q('[data-fv-delete]');
  var zoomBox = q('[data-fv-zoom]'), zoomLevel = q('[data-fv-zoom-reset]');

  var STEPS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3, 4];
  var items = [], index = 0, token = 0, zoom = 1, zoomTarget = null;   // zoomTarget: {type:'image'|'text', el, stage?, fitW?}

  function empty(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }

  function message(head, text) {
    var d = document.createElement('div'); d.className = 'fv-empty';
    var h = document.createElement('strong'); h.textContent = head;
    var p = document.createElement('p'); p.textContent = text;
    d.appendChild(h); d.appendChild(p);
    return d;
  }

  function collect() {
    var seen = {}, out = [];
    Array.prototype.forEach.call(document.querySelectorAll('[data-file-view]'), function (a) {
      var key = a.getAttribute('data-src') || a.href;
      if (seen[key]) { return; }
      seen[key] = true;
      out.push(a);
    });
    return out;
  }

  /* ---------- zoom ---------- */
  function applyZoom() {
    zoomLevel.textContent = Math.round(zoom * 100) + '%';
    if (!zoomTarget) { return; }
    if (zoomTarget.type === 'image') {
      var img = zoomTarget.el, stage = zoomTarget.stage;
      if (zoom === 1) {
        img.style.width = ''; img.style.maxWidth = ''; img.style.maxHeight = '';
        stage.classList.remove('zoomed');
      } else if (zoomTarget.fitW) {
        stage.classList.add('zoomed');
        img.style.maxWidth = 'none'; img.style.maxHeight = 'none';
        img.style.width = Math.round(zoomTarget.fitW * zoom) + 'px';
      }
    } else if (zoomTarget.type === 'text') {
      zoomTarget.el.style.fontSize = (zoom * 100) + '%';
    }
  }

  function setZoom(z) {
    if (!zoomTarget) { return; }
    zoom = Math.min(STEPS[STEPS.length - 1], Math.max(STEPS[0], z));
    applyZoom();
  }

  function stepZoom(dir) {
    var i = 0;
    for (var k = 0; k < STEPS.length; k++) { if (STEPS[k] <= zoom + 0.001) { i = k; } }
    setZoom(STEPS[Math.min(STEPS.length - 1, Math.max(0, i + dir))]);
  }

  /* ---------- showing one file ---------- */
  function show(i) {
    if (!items.length) { return; }
    index = (i + items.length) % items.length;

    var a = items[index];
    var kind = a.getAttribute('data-kind') || 'other', src = a.getAttribute('data-src'), name = a.getAttribute('data-name') || 'File';
    var my = ++token;

    title.textContent = name;
    meta.textContent = a.getAttribute('data-meta') || '';
    count.textContent = items.length > 1 ? (index + 1) + ' / ' + items.length : '';
    prev.hidden = next.hidden = items.length < 2;

    var dlUrl = a.getAttribute('data-download');
    dl.hidden = !dlUrl; if (dlUrl) { dl.href = dlUrl; }
    var canOpen = (kind === 'image' || kind === 'pdf') && src;
    openA.hidden = !canOpen; if (canOpen) { openA.href = src; }
    del.hidden = !a.getAttribute('data-delete-url');

    zoom = 1; zoomTarget = null; zoomBox.hidden = true; zoomLevel.textContent = '100%';
    empty(body);
    body.className = 'fv-body fv-' + kind;

    if (kind === 'image' && src) {
      var stage = document.createElement('div'); stage.className = 'fv-stage';
      var img = document.createElement('img'); img.className = 'fv-img'; img.alt = name; img.title = 'Double-click to zoom';
      img.addEventListener('load', function () {
        if (my !== token) { return; }
        zoomTarget = { type: 'image', el: img, stage: stage, fitW: img.getBoundingClientRect().width };
        zoomBox.hidden = false;
      });
      img.addEventListener('error', function () {
        if (my !== token) { return; }
        empty(body); body.appendChild(message('The picture could not be loaded', 'It may have been removed. Try Download instead.'));
      });
      img.addEventListener('dblclick', function () { setZoom(zoom === 1 ? 2 : 1); });
      stage.addEventListener('wheel', function (e) {
        if (!e.ctrlKey || !zoomTarget) { return; }
        e.preventDefault();
        stepZoom(e.deltaY < 0 ? 1 : -1);
      }, { passive: false });
      img.src = src;
      stage.appendChild(img); body.appendChild(stage);
    } else if (kind === 'pdf' && src) {
      var fr = document.createElement('iframe'); fr.className = 'fv-frame'; fr.title = name; fr.src = src;
      body.appendChild(fr);
    } else if (kind === 'docx' && a.getAttribute('data-text-url')) {
      body.appendChild(message('Loading preview…', ''));
      fetch(a.getAttribute('data-text-url'), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (my !== token) { return; }
          empty(body);
          if (d && d.text) {
            var note = document.createElement('div'); note.className = 'fv-note';
            note.textContent = 'Simplified text preview. Formatting, tables and images are not shown: download the file to see the original.';
            var txt = document.createElement('div'); txt.className = 'fv-text'; txt.textContent = d.text;
            body.appendChild(note); body.appendChild(txt);
            zoomTarget = { type: 'text', el: txt }; zoomBox.hidden = false;
          } else {
            body.appendChild(message('No preview available', 'This document could not be previewed. Use Download to open it on your device.'));
          }
        })
        .catch(function () {
          if (my === token) { empty(body); body.appendChild(message('No preview available', 'This document could not be previewed. Use Download to open it on your device.')); }
        });
    } else {
      body.appendChild(message('No preview available for this file type', 'Use Download to open it on your device.'));
    }
  }

  function openViewer(trigger) {
    items = collect();
    var i = items.indexOf(trigger);
    show(i < 0 ? 0 : i);
    if (!modal.open) { modal.showModal(); document.documentElement.classList.add('fv-lock'); }
  }

  function closeViewer() { if (modal.open) { modal.close(); } }

  /* ---------- remove (same POST + CSRF as the "Remove" button in the list; the server checks the permission) ---------- */
  function removeCurrent() {
    var a = items[index], url = a && a.getAttribute('data-delete-url');
    if (!url) { return; }
    var name = a.getAttribute('data-name') || 'this file';
    if (!window.confirm('Remove “' + name + '” from this ticket? This cannot be undone. The removal is recorded in the audit log.')) { return; }

    var tokenMeta = document.querySelector('meta[name="csrf-token"]');
    var f = document.createElement('form'); f.method = 'POST'; f.action = url; f.style.display = 'none';
    [['_method', 'DELETE'], ['_token', tokenMeta ? tokenMeta.content : '']].forEach(function (p) {
      var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = p[0]; inp.value = p[1]; f.appendChild(inp);
    });
    document.body.appendChild(f);
    f.submit();
  }

  /* ---------- wiring ---------- */
  modal.addEventListener('close', function () { token++; empty(body); zoomTarget = null; document.documentElement.classList.remove('fv-lock'); });
  modal.addEventListener('click', function (e) { if (e.target === modal) { closeViewer(); } });   // click on the dark backdrop
  q('[data-fv-close]').addEventListener('click', closeViewer);
  prev.addEventListener('click', function () { show(index - 1); });
  next.addEventListener('click', function () { show(index + 1); });
  q('[data-fv-zoom-in]').addEventListener('click', function () { stepZoom(1); });
  q('[data-fv-zoom-out]').addEventListener('click', function () { stepZoom(-1); });
  zoomLevel.addEventListener('click', function () { setZoom(1); });
  del.addEventListener('click', removeCurrent);

  modal.addEventListener('keydown', function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey) { return; }
    if (e.key === 'ArrowLeft' && items.length > 1) { e.preventDefault(); show(index - 1); }
    else if (e.key === 'ArrowRight' && items.length > 1) { e.preventDefault(); show(index + 1); }
    else if (e.key === '+' || e.key === '=') { e.preventDefault(); stepZoom(1); }
    else if (e.key === '-' || e.key === '_') { e.preventDefault(); stepZoom(-1); }
    else if (e.key === '0') { e.preventDefault(); setZoom(1); }
  });

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-file-view]') : null;
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) { return; }   // keep "open in new tab" shortcuts working
    e.preventDefault();
    openViewer(a);
  });
})();
