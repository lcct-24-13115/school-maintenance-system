/*
 * Help & FAQ "Was this helpful?" (Phase 5A). Progressive enhancement: the buttons are a normal POST form that
 * works with JavaScript off (the server redirects back to the entry). This file only saves the vote with fetch,
 * so the page does not reload and the answer stays open.
 * If the request cannot even be sent (offline, blocked), it falls back to the normal form submit.
 * Everything written to the page uses textContent, so nothing from the server can become HTML.
 */
(function () {
  'use strict';

  var SELECTOR = '[data-help-feedback-form]';

  function setStatus(form, text, isError) {
    var status = form.querySelector('.help-feedback-status');
    if (!status) {
      return;
    }
    status.textContent = text;
    status.classList.toggle('error', !!isError);
  }

  function setPressed(form, helpful) {
    var buttons = form.querySelectorAll('button[name="helpful"]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.setAttribute('aria-pressed', (button.value === '1') === helpful ? 'true' : 'false');
    });
  }

  function setBusy(form, busy) {
    var buttons = form.querySelectorAll('button[name="helpful"]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.disabled = busy;
    });
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  /** Normal form submit, with the chosen answer (form.submit() does not send the clicked button). */
  function fallback(form, value) {
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'helpful';
    hidden.value = value;
    form.appendChild(hidden);
    form.submit();
  }

  function onSubmit(event) {
    var form = event.target;
    var submitter = event.submitter;

    if (!form || !form.matches || !form.matches(SELECTOR)) {
      return;
    }

    // Old browser: let the normal form submit do the work.
    if (!submitter || !submitter.value || typeof window.fetch !== 'function' || typeof window.FormData !== 'function') {
      return;
    }

    event.preventDefault();

    var value = submitter.value === '1' ? '1' : '0';
    var body = new FormData(form);
    body.set('helpful', value);

    setBusy(form, true);

    fetch(form.getAttribute('action'), {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (data) {
        setBusy(form, false);

        if (response.ok && data && data.ok) {
          setPressed(form, value === '1');
          setStatus(form, data.message || 'Thank you for your feedback.', false);
        } else if (response.status === 419) {
          setStatus(form, 'This page has expired. Please reload it and try again.', true);
        } else {
          setStatus(form, (data && data.message) || 'Your vote could not be saved. Please try again later.', true);
        }
      });
    }).catch(function () {
      // The request never reached the server: use the normal form instead.
      setBusy(form, false);
      fallback(form, value);
    });
  }

  document.addEventListener('submit', onSubmit);
})();
