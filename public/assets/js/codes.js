/* Codes module: quick assign form logic (Expert page and the wizard's
   "choose code" step share it), inline edits on code detail pages. */
(function () {
  'use strict';

  document.addEventListener('alpine:init', function () {
    /**
     * cfg: { prefixes: [{id, code, label, next, suffixes:[{id,code,label}], tag, description}],
     *        mode: 'existing'|'new', product: {ref,label,description,hasInternal,internalDisplay}|null,
     *        want, prefixId, suffixId, description, reason, canSell, canInternal, nextInternal, submitUrl }
     */
    Alpine.data('quickAssign', function (cfg) {
      return {
        prefixes: cfg.prefixes || [],
        mode: cfg.mode || 'existing',
        product: cfg.product || null,
        want: cfg.want || '',
        prefixId: cfg.prefixId ? String(cfg.prefixId) : '',
        suffixId: cfg.suffixId ? String(cfg.suffixId) : '',
        description: cfg.description || '',
        descriptionTouched: !!cfg.description,
        reason: cfg.reason || '',
        canSell: cfg.canSell !== false,
        canInternal: cfg.canInternal !== false,
        nextInternal: cfg.nextInternal || '',
        busy: false,
        errors: {},
        error: '',
        result: null,

        init: function () {
          var self = this;
          this.$watch('prefixId', function () {
            if (!self.suffixes.some(function (s) { return String(s.id) === String(self.suffixId); })) { self.suffixId = ''; }
          });
          this.$watch('mode', function (m) { if (m === 'new') { self.product = null; } self.fixWant(); });
          this.fixWant();
        },

        /* Which "what do you need" options make sense right now. */
        get wants() {
          var out = [];
          var p = this.mode === 'existing' ? this.product : null;
          if (p && p.hasInternal) {
            if (this.canSell) out.push({ v: 'sell', label: 'Another sell number for ' + p.internalDisplay, desc: 'A relabel, a customer-specific code or a new version of the same formula.' });
            return out;
          }
          if (p) {
            if (this.canSell) out.push({ v: 'sell', label: 'Another sell number (alias of ' + p.label + ')', desc: 'Both sell numbers stay without an E number.' });
            if (this.canInternal) out.push({ v: 'internal', label: 'An E number for ' + p.label, desc: 'It has none yet; the sell number will point at the new E number.' });
            if (this.canInternal && this.canSell) out.push({ v: 'both', label: 'Both: an E number and another sell number', desc: p.label + ' and the new sell number will share the new E number.' });
            return out;
          }
          if (this.canSell) out.push({ v: 'sell', label: 'A sell number', desc: 'The code the customer sees, for example a resale item.' });
          if (this.canInternal) out.push({ v: 'internal', label: 'An E number', desc: 'An internal formula code with no sell number yet.' });
          if (this.canInternal && this.canSell) out.push({ v: 'both', label: 'Both an E number and a sell number', desc: 'Assigned together; the sell number is linked to the new E number.' });
          return out;
        },
        fixWant: function () {
          var ok = this.wants.map(function (w) { return w.v; });
          if (ok.indexOf(this.want) === -1) { this.want = ok[0] || ''; }
        },
        get wantSell() { return this.want === 'sell' || this.want === 'both'; },
        get wantInternal() { return this.want === 'internal' || this.want === 'both'; },
        get prefix() {
          var id = String(this.prefixId);
          return this.prefixes.filter(function (p) { return String(p.id) === id; })[0] || null;
        },
        get suffixes() { return this.prefix ? this.prefix.suffixes : []; },
        get suffix() {
          var id = String(this.suffixId);
          return this.suffixes.filter(function (s) { return String(s.id) === id; })[0] || null;
        },
        get nextSell() { return this.prefix ? this.prefix.next + (this.suffix ? this.suffix.code : '') : ''; },
        get needsReason() { return this.wantSell && this.mode === 'existing' && !!this.product; },

        /* The product typeahead chose something (or was cleared). */
        onChosen: function (detail) {
          if (!detail || detail.name !== 'product') return;
          var item = detail.item;
          if (!item) { this.product = null; this.fixWant(); return; }
          var hasInternal = item.type === 'internal' || !!item.internal_code_id;
          this.product = {
            ref: item.id, label: item.label.replace(/ \(voided\)$/, ''), description: item.sub || '',
            hasInternal: hasInternal,
            internalDisplay: item.type === 'internal' ? item.label.replace(/ \(voided\)$/, '') : (item.label.split(' · ')[1] || '')
          };
          if (!this.descriptionTouched || this.description === '') { this.description = item.sub || ''; }
          this.fixWant();
        },

        /* Expert page only: post and show the result inline. */
        submit: function () {
          var self = this, form = this.$root.querySelector('form') || this.$root;
          this.busy = true; this.errors = {}; this.error = '';
          window.chroma.post(cfg.submitUrl || '/assign', new FormData(form)).then(function (res) {
            self.busy = false; self.result = res;
            window.chroma.toast('success', res.message || 'Code reserved', res.detail || null);
            self.$nextTick(function () { var el = document.getElementById('assign-result'); if (el && el.scrollIntoView) el.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
          }).catch(function (err) {
            self.busy = false; self.errors = err.errors || {}; self.error = err.message || 'Something went wrong.';
            window.chroma.toast('error', self.error);
          });
        },
        reset: function () {
          this.result = null; this.errors = {}; this.error = '';
          this.description = ''; this.descriptionTouched = false; this.reason = ''; this.suffixId = '';
          var el = document.getElementById('f-product'); if (el) el.focus();
        }
      };
    });

    /* Inline "change the description" / notes editor on code pages. */
    Alpine.data('inlineEdit', function (cfg) {
      return {
        open: !!(cfg && cfg.open),
        toggle: function () { this.open = !this.open; var self = this; this.$nextTick(function () { var el = self.$root.querySelector('input, textarea'); if (self.open && el) el.focus(); }); }
      };
    });
  });
})();
