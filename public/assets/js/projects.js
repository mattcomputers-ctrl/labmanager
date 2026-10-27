/* Projects module: Alpine components used by resources/views/projects/*.
   Loaded after chroma.js; registers on alpine:init. */
(function () {
  'use strict';

  function storageGet(key, fallback) {
    try { var v = localStorage.getItem(key); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; }
  }
  function storageSet(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ignore */ }
  }

  /* Merge component objects without losing getters: Object.assign copies a getter's current value
     (so a computed like `full` would be frozen at false). Descriptors keep them live. */
  function compose() {
    var out = {};
    Array.prototype.forEach.call(arguments, function (part) {
      if (part) Object.defineProperties(out, Object.getOwnPropertyDescriptors(part));
    });
    return out;
  }

  document.addEventListener('alpine:init', function () {

    /* Column chooser for the Expert projects list (remembered per browser). */
    Alpine.data('columnChooser', function (cfg) {
      /* A column with narrow:false starts hidden on a tablet-width screen (under 1024px) so the important columns fit;
         the chooser (remembered per browser) can switch it back on. */
      var narrow = window.innerWidth < 1024;
      var defaults = {};
      (cfg.columns || []).forEach(function (c) { defaults[c.key] = c.on !== false && !(narrow && c.narrow === false); });
      return {
        open: false,
        columns: cfg.columns || [],
        cols: Object.assign({}, defaults, storageGet('chroma-cols-' + cfg.key, {})),
        toggle: function (k) { this.cols[k] = !this.cols[k]; storageSet('chroma-cols-' + cfg.key, this.cols); },
        reset: function () { this.cols = Object.assign({}, defaults); storageSet('chroma-cols-' + cfg.key, this.cols); },
        show: function (k) { return this.cols[k] !== false; }
      };
    });

    /* Expert new-project items grid: starts with one empty line, + adds up to the cap,
       Tab/Enter from the last cell of the last row adds a line, pasting a list fills one line per row. */
    Alpine.data('itemsGrid', function (cfg) {
      var blank = function (kind) { return { description: '', pantone: '', hex_color: '', kind: kind || cfg.defaultKind || 'color', existing_ref: '', existing_label: '', proof_count: '', is_digital_match: false, notes: '' }; };
      var rows = (cfg.rows && cfg.rows.length) ? cfg.rows.map(function (r) { return Object.assign(blank(cfg.defaultKind), r); }) : [blank(cfg.defaultKind)];
      return {
        rows: rows,
        cap: cfg.cap || 50,
        kind: cfg.defaultKind || 'color',
        /* The Expert page keeps the chosen project type and the per-type labels here too, so one component owns the whole form. */
        type: cfg.type || '',
        meta: cfg.meta || {},
        get full() { return this.rows.length >= this.cap; },
        setKind: function (k) { this.kind = k; var self = this; this.rows.forEach(function (r) { if (!r.description && !r.existing_ref) { r.kind = k; } }); },
        add: function (focus) {
          if (this.full) { window.chroma.toast('warning', 'A project can hold at most ' + this.cap + ' items. Start another project for the rest.'); return null; }
          this.rows.push(blank(this.kind));
          var i = this.rows.length - 1;
          if (focus !== false) { var root = this.$root; this.$nextTick(function () { var el = (root || document).querySelector('[data-row="' + i + '"] [data-first]'); if (el) el.focus(); }); }
          return i;
        },
        remove: function (i) { this.rows.splice(i, 1); if (this.rows.length === 0) { this.rows.push(blank(this.kind)); } },
        lastCell: function (ev, i) {
          /* Tab or Enter from the last cell of the last row adds a line. Ctrl/Cmd+Enter is the form's own "create". */
          if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) return;
          if (ev.key === 'Enter') { this.enterNext(ev, i); return; }
          if (i !== this.rows.length - 1) return;
          if (ev.key === 'Tab' && !ev.shiftKey) {
            if (this.full) return;
            ev.preventDefault();
            this.add(true);
          }
        },
        enterNext: function (ev, i) {
          /* Enter in any grid cell moves to (or creates) the next row instead of submitting the form; Ctrl/Cmd+Enter creates the project. */
          if (ev.key !== 'Enter' || ev.ctrlKey || ev.metaKey) return;
          ev.preventDefault();
          if (i === this.rows.length - 1) { if (this.full) return; this.add(true); return; }
          var el = this.$root.querySelector('[data-row="' + (i + 1) + '"] [data-first]'); if (el) el.focus();
        },
        paste: function (ev, i) {
          var text = (ev.clipboardData || window.clipboardData).getData('text') || '';
          var lines = text.split(/\r?\n/).map(function (s) { return s.trim(); }).filter(Boolean);
          if (lines.length < 2) return; /* single value: default paste */
          ev.preventDefault();
          var self = this, added = 0, skipped = 0;
          lines.forEach(function (line, n) {
            var idx = i + n;
            if (idx >= self.rows.length) {
              if (self.rows.length >= self.cap) { skipped++; return; }
              self.rows.push(blank(self.kind));
            }
            self.rows[idx].description = line;
            added++;
          });
          if (skipped) { window.chroma.toast('warning', skipped + (skipped === 1 ? ' line was' : ' lines were') + ' not added: the limit is ' + this.cap + ' items per project.'); }
          else { window.chroma.toast('success', added + ' lines pasted.'); }
        },
        pickExisting: function (i, item) {
          this.rows[i].existing_ref = item ? item.id : '';
          this.rows[i].existing_label = item ? item.label : '';
          if (item && !this.rows[i].description) { this.rows[i].description = item.sub || ''; }
        }
      };
    });

    /* Inline (Expert) editing of the project header: click a value, change it, Enter/blur saves one field at a time via fetch. */
    Alpine.data('projectHeader', function (cfg) {
      var values = {}, display = {};
      (cfg.fields || []).forEach(function (f) { values[f.name] = f.value === null || f.value === undefined ? '' : f.value; display[f.name] = f.display || ''; });
      /* The title is edited like a field but is not in cfg.fields: seed it, or an emptied title is undefined, dropped from the JSON and silently "saved". */
      values.title = cfg.title || ''; display.title = cfg.title || '';
      return {
        url: cfg.url,
        editing: null,
        busy: false,
        values: values,
        display: display,
        error: {},
        start: function (name) {
          if (!cfg.canEdit) return;
          this.editing = name;
          this.error = {};
          var self = this;
          /* Lookup editors start with hidden inputs: skip those or focus() is a no-op and typing goes nowhere. */
          this.$nextTick(function () { var el = document.querySelector('[data-edit="' + name + '"] input:not([type=hidden]), [data-edit="' + name + '"] textarea, [data-edit="' + name + '"] select'); if (el) { el.focus(); if (el.select && el.type !== 'checkbox') el.select(); } });
        },
        cancel: function () { this.editing = null; },
        save: function (name, extra) {
          var self = this, payload = {};
          payload[name] = this.values[name];
          if (extra) Object.assign(payload, extra);
          this.busy = true;
          return window.chroma.post(this.url, payload).then(function (res) {
            self.busy = false; self.editing = null;
            if (res.fields) { Object.keys(res.fields).forEach(function (k) { self.display[k] = res.fields[k].display; self.values[k] = res.fields[k].value === null ? '' : res.fields[k].value; }); }
            if (typeof res.title === 'string') {
              /* The title is not one of the header fields: keep the reactive copy in step so the x-if template shows the new text. */
              self.display.title = res.title; self.values.title = res.title;
              var t = document.querySelector('[data-project-title]'); if (t) t.textContent = res.title;
              if (document.title.indexOf(' · ') > 0) document.title = document.title.replace(/ · .*$/, ' · ' + res.title);
            }
            window.chroma.toast('success', 'Saved');
          }).catch(function (err) {
            self.busy = false;
            /* Show the real reason under the field (the server's generic message says "highlighted fields", so highlight one). */
            var msg = (err.errors && (err.errors[name] || err.errors[Object.keys(err.errors)[0]])) || err.message || 'Could not save';
            self.error[name] = msg;
            window.chroma.toast('error', msg);
          });
        },
        lookupChosen: function (ev, name) {
          /* From the lookup component: item or null */
          var item = ev.detail.item;
          if (item) { this.values[name] = item.id; this.save(name); }
        },
        lookupNew: function (name, text) {
          if (text && text.trim()) { this.values[name] = ''; this.save(name, { customer_text: text.trim() }); }
        }
      };
    });

    /* Per-row "Assign E#" / "Assign sell number" popover on the Expert project page. */
    Alpine.data('inlineAssign', function (cfg) {
      return {
        open: false,
        which: cfg.which || 'sell',
        busy: false,
        prefix_id: cfg.defaultPrefix ? String(cfg.defaultPrefix) : '',
        suffix_id: '',
        description: cfg.description || '',
        preview: '',
        get suffixes() { return (cfg.prefixSuffixes && cfg.prefixSuffixes[this.prefix_id]) || []; },
        show: function (which) {
          this.which = which; this.open = true; this.suffix_id = '';
          var root = this.$root; this.$nextTick(function () { var el = (root || document).querySelector('[data-assign-focus]'); if (el) el.focus(); });
          this.refreshPreview();
        },
        refreshPreview: function () {
          var self = this;
          var q = this.which === 'sell' && this.prefix_id ? '?prefix_id=' + this.prefix_id : '';
          window.chroma.get('/api/codes/preview' + q).then(function (r) { self.preview = self.which === 'sell' ? (r.sell || '') : (r.internal || ''); }).catch(function () { self.preview = ''; });
        },
        submit: function () {
          var self = this;
          this.busy = true;
          var url = this.which === 'sell' ? cfg.sellUrl : cfg.internalUrl;
          var data = this.which === 'sell' ? { prefix_id: this.prefix_id, suffix_id: this.suffix_id, description: this.description } : { description: this.description };
          window.chroma.post(url, data).then(function (res) {
            self.busy = false; self.open = false;
            location.reload();
          }).catch(function (err) {
            self.busy = false;
            window.chroma.toast('error', err.message || 'Could not assign the code');
          });
        }
      };
    });

    /* Checkbox selection of items for batch assignment. */
    function batchSelect(cfg) {
      return {
        selected: [],
        ids: cfg.ids || [],
        toggleAll: function (on) { this.selected = on ? this.ids.slice() : []; },
        get allOn() { return this.ids.length > 0 && this.selected.length === this.ids.length; },
        go: function () {
          if (!this.selected.length) { window.chroma.toast('warning', 'Tick the items that need codes first.'); return; }
          location.href = cfg.url + '?items=' + this.selected.join(',');
        }
      };
    }
    Alpine.data('batchSelect', batchSelect);

    /* The items section of the project page: checkbox selection for batch assignment plus drag-and-drop ordering,
       composed with live getters (allOn) intact. */
    Alpine.data('itemsSection', function (cfg) {
      return compose(batchSelect(cfg), itemsOrder(cfg));
    });

    /* Batch review page: prefix and suffix per row, defaulting to the row above; suffix options follow the prefix. */
    Alpine.data('batchForm', function (cfg) {
      var state = {};
      (cfg.rows || []).forEach(function (r) { state[r.id] = { internal: r.internal, sell: r.sell, prefix: r.prefix ? String(r.prefix) : '', suffix: '', touched: false }; });
      var allows = function (prefix, suffix) {
        if (!suffix) return true;
        return ((cfg.prefixSuffixes && cfg.prefixSuffixes[prefix]) || []).some(function (s) { return String(s.id) === String(suffix); });
      };
      return {
        rows: cfg.rows || [],
        r: state,
        suffixes: function (id) { var p = this.r[id].prefix; return (cfg.prefixSuffixes && cfg.prefixSuffixes[p]) || []; },
        /* Copy this row's prefix and suffix to the rows below that have not been set by hand. */
        fillDown: function (id, withPrefix) {
          var found = false, self = this, src = this.r[id];
          this.rows.forEach(function (row) {
            if (row.id === id) { found = true; return; }
            var t = self.r[row.id];
            if (!found || t.touched) return;
            if (withPrefix) { t.prefix = src.prefix; t.suffix = src.suffix; }
            else if (t.prefix === src.prefix) { t.suffix = src.suffix; }
          });
        },
        prefixChanged: function (id) {
          this.r[id].touched = true;
          if (!allows(this.r[id].prefix, this.r[id].suffix)) { this.r[id].suffix = ''; }
          this.fillDown(id, true);
        },
        suffixChanged: function (id) {
          this.r[id].touched = true;
          this.fillDown(id, false);
        },
        setAll: function (what, on) { var self = this; this.rows.forEach(function (row) { if (what === 'internal' && row.canInternal) self.r[row.id].internal = on; if (what === 'sell' && row.canSell) self.r[row.id].sell = on; }); },
        get count() { var n = 0, self = this; this.rows.forEach(function (row) { if (self.r[row.id].internal) n++; if (self.r[row.id].sell) n++; }); return n; },
        get valid() { var ok = this.count > 0, self = this; this.rows.forEach(function (row) { if (self.r[row.id].sell && !self.r[row.id].prefix) ok = false; }); return ok; }
      };
    });

    /* Drag-and-drop reordering of the items table (also works with the up/down buttons without JS). */
    function itemsOrder(cfg) {
      return {
        dragging: null,
        over: null,
        start: function (id) { this.dragging = id; },
        enter: function (id) { if (this.dragging && id !== this.dragging) this.over = id; },
        end: function () { this.dragging = null; this.over = null; },
        drop: function (id) {
          if (!this.dragging || this.dragging === id) { this.end(); return; }
          var tbody = this.$root.querySelector('tbody[data-items]');
          var from = tbody.querySelector('[data-item-id="' + this.dragging + '"]');
          var to = tbody.querySelector('[data-item-id="' + id + '"]');
          if (from && to) {
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-item-id]'));
            if (rows.indexOf(from) < rows.indexOf(to)) { to.after(from); } else { to.before(from); }
          }
          var order = Array.prototype.map.call(tbody.querySelectorAll('tr[data-item-id]'), function (tr) { return tr.getAttribute('data-item-id'); });
          this.end();
          window.chroma.post(cfg.reorderUrl || cfg.url, { order: order }).then(function () { window.chroma.toast('success', 'Order saved'); Array.prototype.forEach.call(tbody.querySelectorAll('tr[data-item-id] td.n'), function (td, i) { td.textContent = i + 1; }); })
            .catch(function (err) { window.chroma.toast('error', err.message); location.reload(); });
        }
      };
    }
    Alpine.data('itemsOrder', itemsOrder);

    /* Status select on an item row: saves via fetch, says so in a toast, highlights the row and updates the progress box
       in place. Only when the change completes the project does the page reload (its header and buttons change). */
    Alpine.data('itemStatus', function (cfg) {
      return {
        busy: false,
        change: function (ev) {
          var self = this, select = ev.target, label = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
          this.busy = true;
          window.chroma.post(cfg.url, { status_id: select.value }).then(function (res) {
            if (res.completed || (res.project_status && res.project_status !== 'open')) { location.reload(); return; }
            self.busy = false; cfg.current = select.value;
            window.chroma.toast('success', res.message || ('"' + (cfg.description || 'Item') + '" is now ' + label.toLowerCase() + '.'), res.detail || null);
            var row = select.closest('tr'); if (row) { row.classList.add('row-flash'); setTimeout(function () { row.classList.remove('row-flash'); }, 1600); }
            if (res.progress) {
              var bar = document.querySelector('.project-side .progress-big span'); if (bar) bar.style.width = res.progress.pct + '%';
              var pb = document.querySelector('.project-side .progress-big'); if (pb) pb.setAttribute('aria-valuenow', res.progress.done);
              var txt = document.querySelector('.project-side .progress-label'); if (txt) txt.textContent = res.progress.label;
            }
          }).catch(function (err) { self.busy = false; window.chroma.toast('error', err.message); select.value = cfg.current; });
        }
      };
    });

    /* Attachment upload with a file list preview and size check before sending. */
    Alpine.data('uploader', function (cfg) {
      return {
        names: [],
        tooBig: false,
        over: false,
        pick: function (files) {
          var self = this; this.names = []; this.tooBig = false;
          Array.prototype.forEach.call(files || [], function (f) { self.names.push(f.name + ' (' + (f.size > 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.round(f.size / 1024) + ' KB') + ')'); if (f.size > cfg.maxBytes) self.tooBig = true; });
        },
        drop: function (ev) {
          this.over = false;
          var input = this.$root.querySelector('input[type=file]');
          if (input && ev.dataTransfer && ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; this.pick(input.files); }
        }
      };
    });
  });
})();
