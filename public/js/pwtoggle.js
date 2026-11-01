/* Phase 18 - show / hide (eye) button on every password field.
   Loaded by layouts/guest and layouts/app through partials/ui-assets. No build step needed.
   A field that already has its own .eye-btn next to it is left alone. */
(function () {
  function add(inp) {
    if (inp.dataset.pw) return;
    inp.dataset.pw = '1';

    var wrap = inp.parentNode;
    if (wrap.querySelector(':scope > .eye-btn')) return;           // already has one
    if (!wrap.classList.contains('input-wrap') && !wrap.classList.contains('pw-wrap')) {
      wrap = document.createElement('div');
      wrap.className = 'pw-wrap';
      inp.parentNode.insertBefore(wrap, inp);
      wrap.appendChild(inp);
    }
    inp.classList.add('has-eye');

    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'eye-btn';
    b.setAttribute('aria-label', 'Show password');
    b.setAttribute('aria-pressed', 'false');
    b.title = 'Show password';
    b.innerHTML = '<i class="fa-solid fa-eye"></i>';
    b.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      b.innerHTML = '<i class="fa-solid ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
      var t = show ? 'Hide password' : 'Show password';
      b.setAttribute('aria-label', t);
      b.title = t;
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      inp.focus({ preventScroll: true });
      try { var n = inp.value.length; inp.setSelectionRange(n, n); } catch (e) { /* not supported on this type */ }
    });
    wrap.appendChild(b);

    // a password must never stay visible after the form is sent
    if (inp.form) inp.form.addEventListener('submit', function () { if (inp.type === 'text') inp.type = 'password'; });
  }

  function run() { document.querySelectorAll('input[type=password]').forEach(add); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
})();
