/*
 * Help & FAQ instant filter (Phase 2E). Progressive enhancement: the page already works with JavaScript off
 * (the search box is a plain GET form, the topic chips are plain links). This file only adds a faster feel:
 *   - typing narrows the questions that are ON the page without reloading (Enter still searches every question)
 *   - the topic chips keep the typed words
 * Everything written to the page uses textContent / createTextNode, so typed text can never become HTML.
 */
(function () {
  'use strict';

  var MAX = 100;

  /** Same idea as the server: collapse spaces, trim, at most 100 characters. */
  function clean(text) {
    return String(text || '').replace(/\s+/g, ' ').trim().slice(0, MAX);
  }

  function normalize(text) {
    return clean(text).toLowerCase();
  }

  /** `text` cut around every case-insensitive match of `query`. Odd positions are the matches (like the server's mark). */
  function splitMatches(text, query) {
    var q = normalize(query);
    var lower = text.toLowerCase();

    if (q === '' || lower.length !== text.length) {
      return [text];
    }

    var out = [];
    var pos = 0;
    var at;

    while ((at = lower.indexOf(q, pos)) !== -1) {
      out.push(text.slice(pos, at), text.slice(at, at + q.length));
      pos = at + q.length;
    }
    out.push(text.slice(pos));

    return out;
  }

  function paint(summary, title, query) {
    summary.textContent = title;

    var parts = splitMatches(title, query);
    if (parts.length < 2) {
      return;
    }

    summary.textContent = '';
    parts.forEach(function (part, i) {
      if (part === '') {
        return;
      }
      if (i % 2 === 1) {
        var mark = document.createElement('mark');
        mark.textContent = part;
        summary.appendChild(mark);
      } else {
        summary.appendChild(document.createTextNode(part));
      }
    });
  }

  function setChipQuery(chip, q) {
    try {
      var url = new URL(chip.getAttribute('href'), window.location.href);
      if (q) {
        url.searchParams.set('q', q);
      } else {
        url.searchParams.delete('q');
      }
      chip.setAttribute('href', url.pathname + url.search + url.hash);
    } catch (e) { /* leave the link as the server made it */ }
  }

  function init() {
    var input = document.getElementById('help-q');
    var nodes = document.querySelectorAll('[data-help-item]');

    if (!input || nodes.length === 0) {
      return;   // nothing to filter (the server already shows its own empty state)
    }

    var groups = Array.prototype.slice.call(document.querySelectorAll('[data-help-group]'));
    var chips = Array.prototype.slice.call(document.querySelectorAll('.help-chips a'));
    var status = document.getElementById('help-status');
    var none = document.getElementById('help-js-empty');
    var noneQ = document.getElementById('help-js-q');
    var clear = document.getElementById('help-js-clear');
    var statusAtLoad = status ? status.textContent : '';

    var entries = Array.prototype.slice.call(nodes).map(function (el) {
      var summary = el.querySelector('summary');
      return {
        el: el,
        summary: summary,
        title: summary ? summary.textContent : '',
        // only the question and the answer are searched (not the "Was this helpful?" buttons)
        text: normalize((summary ? summary.textContent : '') + ' ' + (el.querySelector('.help-answer') ? el.querySelector('.help-answer').textContent : '')),
        openAtLoad: el.hasAttribute('open')
      };
    });

    function apply() {
      var shownQ = clean(input.value);
      var q = shownQ.toLowerCase();
      var matches = [];

      entries.forEach(function (e) {
        var ok = q === '' || e.text.indexOf(q) !== -1;
        e.el.hidden = !ok;
        if (ok) {
          matches.push(e);
        }
        if (e.summary) {
          paint(e.summary, e.title, q);
        }
      });

      var few = q !== '' && matches.length > 0 && matches.length <= 3;   // same rule as the server
      matches.forEach(function (e) {
        e.el.open = few ? true : e.openAtLoad;
      });

      groups.forEach(function (g) {
        g.hidden = !g.querySelector('[data-help-item]:not([hidden])');
      });

      if (status) {
        if (q === '') {
          status.textContent = statusAtLoad;
        } else if (matches.length === 0) {
          status.textContent = '';
        } else {
          status.textContent = matches.length + (matches.length === 1 ? ' answer' : ' answers') + ' found for \u201C' + shownQ + '\u201D';
        }
      }

      if (none) {
        none.hidden = matches.length !== 0;
        if (noneQ) {
          noneQ.textContent = shownQ;
        }
      }

      chips.forEach(function (chip) {
        setChipQuery(chip, shownQ);
      });
    }

    input.addEventListener('input', apply);
    input.addEventListener('search', apply);   // the little "x" of a search box in some browsers

    if (clear) {
      clear.addEventListener('click', function () {
        input.value = '';
        apply();
        input.focus();
      });
    }
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { clean: clean, normalize: normalize, splitMatches: splitMatches };
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }
})();