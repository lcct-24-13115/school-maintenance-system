/* /register: the fields below "I am a" follow the chosen tile. The server checks the same rules (config/registration.php). */
(function () {
  /* character counter under "Why do you need an account?" */
  var ta = document.querySelector('textarea[data-counter]');
  var out = ta && document.querySelector('[data-counter-for="' + ta.id + '"]');
  if (ta && out) {
    var upd = function () { out.textContent = ta.value.length.toLocaleString(); };
    ta.addEventListener('input', upd); upd();
  }

  /* password strength meter (Weak / Fair / Good / Strong) */
  var meter = document.querySelector('[data-meter-for]');
  var pw = meter && document.getElementById(meter.getAttribute('data-meter-for'));
  var note = meter && document.querySelector('[data-meter-text]');
  if (meter && pw) {
    var labels = ['', 'Weak', 'Fair', 'Good', 'Strong'], base = note ? note.textContent : '';
    pw.addEventListener('input', function () {
      var v = pw.value, score = 0;
      if (v.length >= 8) { score++; }
      if (v.length >= 12) { score++; }
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) { score++; }
      if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) { score++; }
      if (!v) { score = 0; }
      if (v.length > 0 && v.length < 8) { score = 1; }
      meter.setAttribute('data-level', String(score));
      if (note) { note.textContent = v ? 'Strength: ' + labels[score] : base; }
    });
  }

  var form = document.querySelector('form[data-reg-rules]');
  if (!form) { return; }
  var rules = {};
  try { rules = JSON.parse(form.getAttribute('data-reg-rules') || '{}'); } catch (e) { return; }

  var aff = document.getElementById('affiliation'), purpose = document.getElementById('purpose');
  var deptField = form.querySelector('[data-reg-field="department"]');
  var affField = form.querySelector('[data-reg-field="affiliation"]');
  var purField = form.querySelector('[data-reg-field="purpose"]');
  var emailHelp = form.querySelector('[data-reg-email-help]');
  if (!aff || !purpose || !affField || !purField) { return; }

  function apply() {
    var checked = form.querySelector('input[name="requester_type"]:checked');
    var r = checked ? rules[checked.value] : null;
    if (!r) { return; }

    var lbl = affField.querySelector('[data-reg-label]'), help = affField.querySelector('[data-reg-help]');
    var o1 = affField.querySelector('[data-reg-optional]'), o2 = purField.querySelector('[data-reg-optional]');
    if (lbl) { lbl.textContent = r.aff_label; }
    if (help) { help.textContent = r.aff_help; }
    aff.placeholder = r.aff_ph;
    aff.required = !!r.aff;
    purpose.placeholder = r.purpose_ph;
    purpose.required = !!r.purpose;
    if (o1) { o1.hidden = !!r.aff; }
    if (o2) { o2.hidden = !!r.purpose; }
    if (emailHelp) { emailHelp.textContent = r.email_help; }
    if (deptField) {
      deptField.hidden = !r.dept;
      var sel = deptField.querySelector('select');
      if (sel && !r.dept) { sel.value = ''; sel.dispatchEvent(new Event('change', { bubbles: true })); }
    }
  }

  form.querySelectorAll('input[name="requester_type"]').forEach(function (el) { el.addEventListener('change', apply); });
  apply();
})();
