/* Live badge: the number of accounts waiting for approval next to "Users" updates by itself (no reload). */
(function () {
  var el = document.getElementById('pending-users-badge');
  if (!el) { return; }
  var url = el.getAttribute('data-pending-url');
  var ms = Math.max(3, Number(el.getAttribute('data-interval') || 5)) * 1000;
  var timer = null, last = Number(el.textContent) || 0;

  function show(n) {
    el.textContent = n > 99 ? '99+' : String(n);
    el.style.display = n > 0 ? '' : 'none';
    if (n !== last) {
      window.dispatchEvent(new CustomEvent('lcct:pending-users', { detail: { count: n, previous: last } }));
      last = n;
    }
  }

  function poll() {
    if (document.hidden) { return; }
    fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        if (res.status === 401 || res.status === 403 || res.status === 419) { clearInterval(timer); return null; }
        return res.ok ? res.json() : null;
      })
      .then(function (data) { if (data && typeof data.count === 'number') { show(data.count); } })
      .catch(function () { /* offline: try again next time */ });
  }

  timer = setInterval(poll, ms);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();