/* Phase 18 - themed dropdown. Upgrades every <select> into a styled picker (bottom sheet on phones).
   The real <select> stays in the form, so submitting, validation, Alpine x-model and the server code are unchanged.
   Left alone: <select multiple>, <select size>, <select data-native>, and selects inside a <table>
   (a table scrolls sideways and would cut the list off). Optional: data-title="..." sets the phone sheet title. */
(function () {
  var all = [];

  function skip(sel) {
    return sel.multiple || sel.size > 1 || sel.hasAttribute('data-native') ||
           sel.classList.contains('cs-native') || !!sel.closest('table');
  }

  function build(sel) {
    var wrap = document.createElement('div');
    wrap.className = 'cs';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('cs-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');

    var title = sel.getAttribute('data-title') || (sel.id && document.querySelector('label[for="' + sel.id + '"]') ? document.querySelector('label[for="' + sel.id + '"]').textContent.replace(/\(optional\)/i, '').trim() : '') || 'Choose an option';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cs-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (sel.getAttribute('aria-label')) btn.setAttribute('aria-label', sel.getAttribute('aria-label'));
    var lbl = document.createElement('span'); lbl.className = 'cs-lbl';
    var chev = document.createElement('i'); chev.className = 'fa-solid fa-chevron-down cs-chev';
    btn.append(lbl, chev);

    var back = document.createElement('div'); back.className = 'cs-back';
    var panel = document.createElement('div'); panel.className = 'cs-panel';
    var head = document.createElement('div'); head.className = 'cs-head';
    head.innerHTML = '<b></b><button type="button" class="cs-x" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>';
    head.firstChild.textContent = title;
    var list = document.createElement('ul'); list.className = 'cs-list'; list.setAttribute('role', 'listbox');
    panel.append(head, list);
    wrap.append(btn, back, panel);

    var items = [], active = -1, seen = '';

    function makeItems() {
      list.innerHTML = ''; items = []; active = -1;
      Array.prototype.forEach.call(sel.options, function (o, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option'); li.dataset.v = o.value; li.dataset.i = i;
        if (o.value === '') li.classList.add('cs-ph');
        if (o.disabled) li.classList.add('cs-off');
        li.innerHTML = '<span class="cs-t"></span><i class="fa-solid fa-check cs-ok"></i>';
        li.firstChild.textContent = o.textContent;
        list.appendChild(li); items.push(li);
      });
    }

    function paint() {
      var o = sel.options[sel.selectedIndex];
      var empty = !o || o.value === '';
      lbl.textContent = o ? o.textContent : '';
      btn.classList.toggle('cs-empty', empty);
      btn.disabled = sel.disabled;
      items.forEach(function (li, i) {
        var on = i === sel.selectedIndex && li.dataset.v !== '';
        li.classList.toggle('on', on);
        li.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      seen = sel.selectedIndex + '|' + sel.disabled + '|' + sel.options.length;
    }

    function setActive(i) {
      if (i < 0 || i >= items.length) return;
      if (active > -1 && items[active]) items[active].classList.remove('hl');
      active = i; items[i].classList.add('hl');
      // scroll only the list, never the page
      var top = items[i].offsetTop, bottom = top + items[i].offsetHeight;
      if (top < list.scrollTop) list.scrollTop = top - 6;
      else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight + 6;
    }

    function open() {
      if (sel.disabled || wrap.classList.contains('open')) return;
      // open upwards when there is no room below (desktop only; phones use the bottom sheet)
      var r = btn.getBoundingClientRect(), need = Math.min(290, list.scrollHeight + 14);
      wrap.classList.toggle('up', window.innerHeight - r.bottom < need && r.top > need);
      wrap.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      document.documentElement.classList.add('cs-lock');
      setActive(Math.max(sel.selectedIndex, 0));
    }
    function close(focus) {
      wrap.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      document.documentElement.classList.remove('cs-lock');
      if (focus) btn.focus();
    }
    function pick(i) {
      if (items[i] && items[i].classList.contains('cs-off')) return;
      sel.selectedIndex = i; paint(); close(true);
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    btn.addEventListener('click', function () { wrap.classList.contains('open') ? close() : open(); });
    back.addEventListener('click', function () { close(); });
    head.querySelector('.cs-x').addEventListener('click', function () { close(true); });
    list.addEventListener('click', function (e) { var li = e.target.closest('li'); if (li) pick(+li.dataset.i); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    wrap.addEventListener('keydown', function (e) {
      var isOpen = wrap.classList.contains('open');
      if (e.key === 'Escape' && isOpen) { e.preventDefault(); e.stopPropagation(); close(true); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); isOpen ? setActive(Math.min(active + 1, items.length - 1)) : open(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); isOpen ? setActive(Math.max(active - 1, 0)) : open(); }
      else if ((e.key === 'Enter' || e.key === ' ') && isOpen && active > -1) { e.preventDefault(); pick(active); }
      else if (e.key === 'Tab' && isOpen) close();
    });

    // a click on the <label> should focus our button (the real select is hidden)
    if (sel.id) document.querySelectorAll('label[for="' + sel.id + '"]').forEach(function (l) {
      l.addEventListener('click', function (e) { e.preventDefault(); btn.focus(); });
    });

    sel.addEventListener('invalid', function () { btn.classList.add('cs-bad'); });
    sel.addEventListener('change', function () { btn.classList.remove('cs-bad'); paint(); });

    // options added / removed later (for example by Alpine)
    new MutationObserver(function () { makeItems(); paint(); }).observe(sel, { childList: true, subtree: true, characterData: true });

    makeItems();
    paint();
    all.push({ sel: sel, paint: paint, seen: function () { return seen; } });
  }

  // the value can be changed by code (Alpine x-model, form reset) without any event: re-check now and then
  function watch() {
    setInterval(function () {
      all.forEach(function (a) {
        var s = a.sel;
        if (!document.body.contains(s)) return;
        if ((s.selectedIndex + '|' + s.disabled + '|' + s.options.length) !== a.seen()) a.paint();
      });
    }, 300);
  }

  function run() {
    document.querySelectorAll('select').forEach(function (s) { if (!skip(s)) build(s); });
    if (all.length) watch();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
