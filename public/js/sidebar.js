/* Collapse / expand the sidebar on desktop and remember the choice (the class is also set early in <head> to avoid a flash). */
(function () {
  var KEY = 'lcct.sb', root = document.documentElement;
  var btn = document.querySelector('.sb-toggle');

  document.querySelectorAll('.sidebar .nav a, .sidebar .sb-links a').forEach(function (a) {
    var nl = a.querySelector('.nl');
    if (nl && !a.title) { a.title = nl.textContent.trim(); }
  });

  if (!btn) { return; }
  var icon = btn.querySelector('i'), label = btn.querySelector('.nl');

  function paint() {
    var mini = root.classList.contains('sb-mini');
    btn.setAttribute('aria-expanded', mini ? 'false' : 'true');
    btn.title = mini ? 'Expand sidebar' : 'Collapse sidebar';
    if (icon) { icon.className = 'fa-solid ' + (mini ? 'fa-angles-right' : 'fa-angles-left'); }
    if (label) { label.textContent = mini ? 'Expand' : 'Collapse'; }
  }

  btn.addEventListener('click', function () {
    var mini = root.classList.toggle('sb-mini');
    try { localStorage.setItem(KEY, mini ? 'mini' : 'full'); } catch (e) {}
    paint();
  });
  paint();
})();