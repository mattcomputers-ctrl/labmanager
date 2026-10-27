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
      add: function (type, message, detail) {
        var id = Date.now() + Math.random();
        this.items.push({ id: id, type: type, message: message, detail: detail || null });
        var self = this;
        setTimeout(function () { self.remove(id); }, type === 'error' ? 9000 : 5000);
      },
      remove: function (id) { this.items = this.items.filter(function (t) { return t.id !== id; }); }
    });

    /* Typeahead lookup: fetches /api/...?q= and lets the user pick one row. */
    Alpine.data('lookup', function (cfg) {
      return {
        q: cfg.initialLabel || '',
        value: cfg.initialValue || '',
        results: [],
        open: false,
        active: -1,
        loading: false,
        allowNew: !!cfg.allowNew,
        search: window.chroma.debounce(function () {
          var self = this;
          if (this.q.trim().length < (cfg.minChars || 1)) { this.results = []; this.open = false; return; }
          this.loading = true;
          window.chroma.get(cfg.url + encodeURIComponent(this.q.trim())).then(function (res) {
            self.results = res.results || res; self.open = true; self.active = -1; self.loading = false;
          }).catch(function () { self.loading = false; });
        }, 200),
        onInput: function () { this.value = ''; this.search(); },
        choose: function (item) {
          this.value = item.id; this.q = item.label; this.open = false; this.results = [];
          this.$dispatch('lookup-chosen', { name: cfg.name, item: item });
          if (cfg.onChoose) cfg.onChoose.call(this, item);
        },
        clear: function () { this.value = ''; this.q = ''; this.results = []; this.$dispatch('lookup-chosen', { name: cfg.name, item: null }); },
        move: function (d) { if (!this.open) { this.search(); return; } this.active = Math.max(-1, Math.min(this.results.length - 1, this.active + d)); },
        enter: function (ev) { if (this.open && this.active >= 0) { ev.preventDefault(); this.choose(this.results[this.active]); } else if (this.open && this.results.length === 1) { ev.preventDefault(); this.choose(this.results[0]); } },
        close: function () { var self = this; setTimeout(function () { self.open = false; }, 150); }
      };
    });

    /* Confirmation dialog for destructive actions; use with <form x-data="confirmForm(...)"> */
    Alpine.data('confirmForm', function (opts) {
      return {
        open: false,
        reason: '',
        ask: function () { this.open = true; this.$nextTick(function () { var el = document.querySelector('[data-confirm-focus]'); if (el) el.focus(); }); },
        proceed: function () { this.open = false; this.$nextTick(function () { this.$root.submit(); }.bind(this)); }
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

    /* Autofocus first visible field with data-autofocus */
    var af = document.querySelector('[data-autofocus]');
    if (af && !/Mobi|Android/i.test(navigator.userAgent)) { af.focus(); }
  });
})();
