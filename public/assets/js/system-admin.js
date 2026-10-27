/* Admin → System: live update log, update check, log refresh (module E2). */
(function () {
  'use strict';
  document.addEventListener('alpine:init', function () {
    /* Polls GET /admin/system/update/log?job=…&from=… until the job is done. */
    Alpine.data('updateJob', function (cfg) {
      return {
        job: cfg.job || null,
        lines: [],
        offset: 0,
        running: false,
        done: false,
        exit: null,
        error: '',
        branch: '',
        confirm: false,
        timer: null,
        init: function () { if (this.job) { this.running = true; this.poll(); } },
        start: function () {
          var self = this;
          this.confirm = false; this.error = ''; this.lines = []; this.offset = 0; this.done = false; this.exit = null; this.running = true;
          window.chroma.post('/admin/system/update', { branch: this.branch }).then(function (res) {
            self.job = res.job;
            try { history.replaceState(null, '', '/admin/system?job=' + res.job + '#updates'); } catch (e) { /* ignore */ }
            self.poll();
          }).catch(function (err) { self.running = false; self.error = err.message; window.chroma.toast('error', err.message); });
        },
        poll: function () {
          var self = this;
          if (!this.job) return;
          window.chroma.get('/admin/system/update/log?job=' + encodeURIComponent(this.job) + '&from=' + this.offset).then(function (res) {
            if (res.error) { self.error = res.error; self.running = false; return; }
            if (res.lines && res.lines.length) { self.lines = self.lines.concat(res.lines); self.$nextTick(function () { var p = self.$refs.panel; if (p) p.scrollTop = p.scrollHeight; }); }
            self.offset = res.offset;
            if (res.done) {
              self.done = true; self.exit = res.exit; self.running = false;
              window.chroma.toast(res.exit === 0 ? 'success' : 'error', res.exit === 0 ? 'Update finished. Reload the page to use the new version.' : 'The update did not finish cleanly (exit code ' + res.exit + '). Read the log below.');
            } else {
              self.timer = setTimeout(function () { self.poll(); }, 1500);
            }
          }).catch(function () {
            /* PHP-FPM reloads half way through an update; keep trying for a while. */
            self.timer = setTimeout(function () { self.poll(); }, 3000);
          });
        },
        lineClass: function (l) { return /error|fail|rolled back|rollback/i.test(l) ? 'l-error' : (/warn/i.test(l) ? 'l-warn' : ''); }
      };
    });

    Alpine.data('updateCheck', function () {
      return {
        busy: false, result: null,
        check: function () {
          var self = this; this.busy = true; this.result = null;
          window.chroma.post('/admin/system/check-update', {}).then(function (res) { self.busy = false; self.result = res; })
            .catch(function (err) { self.busy = false; self.result = { ok: false, state: 'error', message: err.message }; });
        }
      };
    });

    Alpine.data('logTail', function () {
      return {
        lines: [], busy: false,
        refresh: function () {
          var self = this; this.busy = true;
          window.chroma.get('/admin/system/log?lines=100').then(function (res) { self.lines = res.lines || []; self.busy = false; })
            .catch(function () { self.busy = false; window.chroma.toast('error', 'Could not read the log.'); });
        }
      };
    });
  });
})();
