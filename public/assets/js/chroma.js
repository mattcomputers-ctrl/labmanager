/* Chroma front-end helpers. Alpine.js provides interactivity; this file
   registers stores and small components used across pages. No build step. */
(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]');
  var CSRF = csrf ? csrf.getAttribute('content') : '';

  function toastFromServer() {
    var el = document.getElementById('server-flash');
    if (!el) return [];
    try { return JSON.parse(el.textContent || '[]'); } catch (e) { return []; }
  }

  window.chroma = {
    csrf: CSRF,
    /** POST JSON (or FormData) and return parsed JSON. Throws {message, errors} on failure. */
    post: function (url, data, method) {
      var opts = { method: method || 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'fetch' }, credentials: 'same-origin' };
      if (data instanceof FormData) {
        data.append('_token', CSRF);
        opts.body = data;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(Object.assign({ _token: CSRF }, data || {}));
      }
      return fetch(url, opts).then(function (r) {
        return r.json().catch(function () { return { error: 'The server sent an unexpected reply. Please reload and try again.' }; }).then(function (json) {
          if (!r.ok) { var err = new Error(json.error || 'Something went wrong.'); err.errors = json.errors || {}; err.status = r.status; throw err; }
          return json;
        });
      });
    },
    get: function (url) {
      return fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    },
    toast: function (type, message, detail) {
      if (window.Alpine && Alpine.store('toasts')) Alpine.store('toasts').add(type, message, detail);
    },
    copy: function (text) {
      var done = function () { window.chroma.toast('success', 'Copied ' + text); };
      if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text).then(done, function () { window.chroma.fallbackCopy(text); done(); });
      }
      window.chroma.fallbackCopy(text); done();
    },
    fallbackCopy: function (text) {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'absolute'; ta.style.left = '-9999px';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e) { /* ignore */ }
      document.body.removeChild(ta);
    },
    setTheme: function (theme) {
      if (theme === 'system') { document.documentElement.removeAttribute('data-theme'); }
      else { document.documentElement.setAttribute('data-theme', theme); }
      try { localStorage.setItem('chroma-theme', theme); } catch (e) { /* ignore */ }
      document.cookie = 'chroma_theme=' + theme + ';path=/;max-age=31536000;samesite=lax';
    },
    debounce: function (fn, ms) {
      var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms || 250); };
    }
  };

  document.addEventListener('alpine:init', function () {
    Alpine.store('toasts', {
      items: [],
      timers: {},
      /* Errors stay 9 s, plain "Saved" successes 2.5 s, everything else 5 s. An identical toast that is still
         showing (same type, message and detail) is not stacked again: its timer restarts instead. */
      life: function (type) { return type === 'error' ? 9000 : (type === 'success' ? 2500 : 5000); },
      add: function (type, message, detail) {
        detail = detail || null;
        var self = this;
        var same = this.items.filter(function (t) { return t.type === type && t.message === message && t.detail === detail; })[0];
        var id = same ? same.id : Date.now() + Math.random();
        if (!same) this.items.push({ id: id, type: type, message: message, detail: detail });
        clearTimeout(this.timers[id]);
        this.timers[id] = setTimeout(function () { self.remove(id); }, this.life(type));
      },
      remove: function (id) {
        clearTimeout(this.timers[id]); delete this.timers[id];
        this.items = this.items.filter(function (t) { return t.id !== id; });
      }
    });

    /* Typeahead lookup: fetches /api/...?q= and lets the user pick one row.
       The floating list only opens when there are matches; "nothing matches" / "will be added as new" is
       static text under the field so it never covers the buttons below. While matches are shown, Enter
       picks the highlighted one (the first by default) and never turns the typed text into a new entry:
       that is an explicit choice, the last row of the list ("Add … as a new customer"). */
    Alpine.data('lookup', function (cfg) {
      return {
        q: cfg.initialLabel || '',
        value: cfg.initialValue || '',
        results: [],
        open: false,
        active: -1,
        loading: false,
        searched: false,
        allowNew: !!cfg.allowNew,
        search: window.chroma.debounce(function () {
          var self = this;
          if (this.q.trim().length < (cfg.minChars || 1)) { this.results = []; this.open = false; this.searched = false; return; }
          this.loading = true;
          window.chroma.get(cfg.url + encodeURIComponent(this.q.trim())).then(function (res) {
            self.results = res.results || res; self.searched = true; self.loading = false;
            self.open = self.results.length > 0; self.active = self.results.length ? 0 : -1;
          }).catch(function () { self.loading = false; });
        }, 200),
        /* True once a search ran and found nothing for what is typed (and nothing was picked). */
        get noMatch() { return this.searched && !this.loading && this.results.length === 0 && this.q.trim() !== '' && !this.value; },
        /* Index of the "Add … as new" row at the end of the list. */
        get newIndex() { return this.allowNew && this.results.length ? this.results.length : -1; },
        onInput: function () { this.value = ''; this.searched = false; this.search(); },
        choose: function (item) {
          this.value = item.id; this.q = item.label; this.open = false; this.results = [];
          this.$dispatch('lookup-chosen', { name: cfg.name, item: item });
          if (cfg.onChoose) cfg.onChoose.call(this, item);
        },
        /* Keep the typed text as a new entry (the hidden _text input carries it). */
        chooseNew: function () {
          this.value = ''; this.open = false; this.results = []; this.searched = true;
          this.$dispatch('lookup-new', { name: cfg.name, text: this.q.trim() });
        },
        clear: function () { this.value = ''; this.q = ''; this.results = []; this.searched = false; this.$dispatch('lookup-chosen', { name: cfg.name, item: null }); },
        move: function (d) {
          if (!this.open) { this.search(); return; }
          var last = this.newIndex >= 0 ? this.newIndex : this.results.length - 1;
          this.active = Math.max(0, Math.min(last, this.active + d));
        },
        enter: function (ev) {
          if (!this.open || !this.results.length) {
            /* Nothing to pick from: the form's own Enter behaviour runs (Next / save). A page that saves
               the typed text as a new entry listens for this event instead of guessing. */
            if (this.noMatch && this.allowNew) { this.$dispatch('lookup-new', { name: cfg.name, text: this.q.trim() }); }
            return;
          }
          ev.preventDefault();
          if (this.active === this.newIndex) { this.chooseNew(); return; }
          this.choose(this.results[this.active >= 0 ? this.active : 0]);
        },
        close: function () { var self = this; setTimeout(function () { self.open = false; }, 150); }
      };
    });

    /* Confirmation dialog for destructive actions; use with <form x-data="confirmForm({ uid })">.
       The dialog is teleported to <body>, so the focus lookup is scoped to THIS form's dialog (data-confirm-modal=uid):
       on a page with many confirm forms the first [data-confirm-focus] in the document belongs to a hidden dialog.
       The confirm button also carries `autofocus`, which x-trap honours, so Enter confirms (SPEC: Enter submits, Esc cancels). */
    Alpine.data('confirmForm', function (opts) {
      opts = opts || {};
      return {
        open: false,
        reason: '',
        ask: function () {
          this.open = true;
          this.$nextTick(function () {
            var el = (opts.uid && document.querySelector('[data-confirm-modal="' + opts.uid + '"] [data-confirm-focus]'))
              || document.querySelector('.modal-backdrop:not([style*="display: none"]) [data-confirm-focus]');
            if (el) el.focus();
          });
        },
        proceed: function () { this.open = false; this.$nextTick(function () { this.$root.submit(); }.bind(this)); },
        /* Enter anywhere in the open dialog (not on a button or link, which act on their own) confirms. */
        enter: function (ev) {
          if (!this.open) return;
          var t = ev.target, tag = t && t.tagName;
          if (tag === 'BUTTON' || tag === 'A' || tag === 'TEXTAREA' || tag === 'SELECT') return;
          if (tag === 'INPUT' && t.hasAttribute('x-model')) return; /* the reason field has its own Enter rule */
          ev.preventDefault();
          this.proceed();
        }
      };
    });

    /* Generic async form: posts via fetch, shows errors inline, toasts success. */
    Alpine.data('asyncForm', function (cfg) {
      return {
        busy: false, errors: {}, error: '',
        submit: function (ev) {
          var self = this, form = this.$root;
          this.busy = true; this.errors = {}; this.error = '';
          var fd = new FormData(form);
          window.chroma.post(form.getAttribute('action') || location.pathname, fd, form.getAttribute('method') || 'POST').then(function (res) {
            self.busy = false;
            if (cfg && cfg.onSuccess) { cfg.onSuccess.call(self, res); }
            else if (res.redirect) { location.href = res.redirect; }
            else { window.chroma.toast('success', res.message || 'Saved'); }
          }).catch(function (err) {
            self.busy = false; self.errors = err.errors || {}; self.error = err.message;
            window.chroma.toast('error', err.message);
            var first = form.querySelector('[aria-invalid="true"], .error-text'); if (first && first.scrollIntoView) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
          });
        }
      };
    });
  });

  document.addEventListener('DOMContentLoaded', function () {
    /* Server-side flash messages become toasts */
    var flashes = toastFromServer();
    var push = function () {
      if (window.Alpine && Alpine.store('toasts')) { flashes.forEach(function (f) { Alpine.store('toasts').add(f.type, f.message, f.detail); }); }
      else { setTimeout(push, 50); }
    };
    if (flashes.length) push();

    /* Copy buttons */
    document.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-copy]');
      if (btn) { ev.preventDefault(); window.chroma.copy(btn.getAttribute('data-copy')); }
    });

    /* Keyboard: "/" focuses search, Esc closes any open [data-closable] */
    document.addEventListener('keydown', function (ev) {
      if (ev.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) {
        var s = document.getElementById('global-search'); if (s) { ev.preventDefault(); s.focus(); }
      }
      if (ev.key === 'Escape') { document.dispatchEvent(new CustomEvent('chroma:escape')); }
    });

    /* Clickable table rows */
    document.addEventListener('click', function (ev) {
      var row = ev.target.closest('tr.clickable[data-href]');
      if (row && !ev.target.closest('a, button, input, select, label')) { location.href = row.getAttribute('data-href'); }
    });

    /* Tables wider than their wrapper: mark the wrapper so the edge shadows show, and tell touch users they can swipe. */
    var markScrollable = function () {
      Array.prototype.forEach.call(document.querySelectorAll('.table-wrap'), function (wrap) {
        var can = wrap.scrollWidth > wrap.clientWidth + 2;
        wrap.classList.toggle('can-scroll', can);
        var hint = wrap.nextElementSibling && wrap.nextElementSibling.classList.contains('scroll-hint') ? wrap.nextElementSibling : null;
        if (can && !hint) { hint = document.createElement('p'); hint.className = 'scroll-hint'; hint.textContent = 'Swipe sideways to see more columns'; wrap.insertAdjacentElement('afterend', hint); }
      });
    };
    markScrollable();
    window.addEventListener('resize', window.chroma.debounce(markScrollable, 150));
    /* The wrapper only scrolls sideways once it carries .can-scroll (chroma.css), so re-measure whenever a table changes size after load (rows added, a tab shown). */
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(window.chroma.debounce(markScrollable, 100));
      Array.prototype.forEach.call(document.querySelectorAll('.table-wrap > table'), function (t) { ro.observe(t); });
    }

    /* Autofocus first visible field with data-autofocus */
    var af = document.querySelector('[data-autofocus]');
    if (af && !/Mobi|Android/i.test(navigator.userAgent)) { af.focus(); }

    /* Easy / Expert mid-task: flipping the switch keeps what has been typed.
       - On a wizard step, the Expert link posts the step's form with _mode=expert; the server stores the half-filled
         answers and redirects to the Expert page carrying every value (WizardController::submitStep).
       - On an Expert page whose form says data-mode-carry="/wizard/<key>", the Easy link serialises the form's fields
         into that wizard's start URL (the wizard's initialState reads the same names) and jumps to the first open step. */
    var modeLink = function (mode) { return document.querySelector('.mode-toggle a[href*="set=' + mode + '"]'); };
    var expertLink = modeLink('expert'), easyLink = modeLink('easy');
    var stepForm = document.querySelector('.wizard form[action^="/wizard/"][action*="/step/"]');
    if (expertLink && stepForm) {
      expertLink.addEventListener('click', function (ev) {
        ev.preventDefault();
        var h = document.createElement('input'); h.type = 'hidden'; h.name = '_mode'; h.value = 'expert';
        stepForm.appendChild(h);
        stepForm.submit();
      });
    }
    var carry = document.querySelector('form[data-mode-carry]');
    if (easyLink && carry) {
      easyLink.addEventListener('click', function (ev) {
        var params = new URLSearchParams();
        var fd = new FormData(carry);
        fd.forEach(function (v, k) {
          if (k === '_token' || k === '_method' || typeof v !== 'string' || v === '') return;
          params.append(k, v);
        });
        params.set('jump', '1');
        ev.preventDefault();
        location.href = '/mode?set=easy&return=' + encodeURIComponent(carry.getAttribute('data-mode-carry') + '?' + params.toString());
      });
    }
  });
})();
