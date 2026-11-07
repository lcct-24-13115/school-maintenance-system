/*
 * Help & FAQ accessibility helpers (Phase 5D). No library. The page works without this file:
 *   - <details>/<summary> is already keyboard friendly (Tab to a question, Enter or Space to open it).
 *   - this file only adds two things:
 *       1. printing: every answer is opened for the printer and put back as it was afterwards
 *       2. a link such as /help#faq-12 opens that answer (the feedback redirect and bookmarks use this)
 */
(function () {
  'use strict';

  var reopened = [];
  var printing = false;

  function items() {
    return Array.prototype.slice.call(document.querySelectorAll('details.help-item'));
  }

  function openAllForPrint() {
    if (printing) {
      return;   // beforeprint and the print media query may both fire: remember only the first time
    }
    printing = true;
    reopened = [];
    items().forEach(function (d) {
      if (!d.open) {
        d.open = true;
        reopened.push(d);
      }
    });
  }

  function restoreAfterPrint() {
    if (!printing) {
      return;
    }
    printing = false;
    reopened.forEach(function (d) {
      d.open = false;
    });
    reopened = [];
  }

  window.addEventListener('beforeprint', openAllForPrint);
  window.addEventListener('afterprint', restoreAfterPrint);

  if (window.matchMedia) {
    var mql = window.matchMedia('print');
    var onChange = function (e) {
      if (e.matches) {
        openAllForPrint();
      } else {
        restoreAfterPrint();
      }
    };
    if (mql.addEventListener) {
      mql.addEventListener('change', onChange);
    } else if (mql.addListener) {
      mql.addListener(onChange);
    }
  }

  function openFromHash() {
    var m = /^#(faq-\d+)$/.exec(window.location.hash || '');
    if (!m) {
      return;
    }
    var el = document.getElementById(m[1]);
    if (el && el.tagName === 'DETAILS') {
      el.open = true;
    }
  }

  openFromHash();
  window.addEventListener('hashchange', openFromHash);
})();