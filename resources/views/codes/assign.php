<?php
/** Expert-mode quick assign. @var array $prefixes @var array $reasons @var ?array $product @var array $defaults @var bool $canSell @var bool $canInternal @var string $nextInternal */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><script src="/assets/js/codes.js"></script><?php $view->end();
$cfg = [
    'prefixes' => $prefixes,
    'mode' => $defaults['product_mode'],
    'product' => $product ? ['ref' => $product['ref'], 'label' => $product['label'], 'description' => $product['description'], 'hasInternal' => $product['internal_code_id'] !== null, 'internalDisplay' => $product['internal']['display'] ?? ''] : null,
    'want' => $defaults['want'],
    'prefixId' => $defaults['prefix_id'],
    'suffixId' => $defaults['suffix_id'],
    'description' => $defaults['description'] ?: ($product['description'] ?? ''),
    'reason' => $defaults['reason'],
    'canSell' => $canSell,
    'canInternal' => $canInternal,
    'nextInternal' => $nextInternal,
    'submitUrl' => '/assign',
];
?>
<?= component('page-header', [
    'title' => 'Quick assign',
    'lede' => 'Assign an E number or sell number without a project: another sell number for an existing product (a relabel, a customer code, a new version), or codes for a resale item. Chroma picks the next free number.',
    'actions' => component('button', ['label' => 'Code lookup', 'href' => '/codes']) . (can('codes.manual_entry') ? component('button', ['label' => 'Enter a code by hand', 'href' => '/codes/manual?expert=1']) : ''),
]) ?>

<div x-data="quickAssign(<?= json_attr($cfg) ?>)" @lookup-chosen="onChosen($event.detail)">
<div class="assign-layout">
  <div>
    <form method="post" action="/assign" class="card" @submit.prevent="submit()" x-show="!result" novalidate>
      <?= csrf_field() ?>
      <div class="card-body">
        <div class="callout callout-danger" x-show="error && !Object.keys(errors).length" x-cloak role="alert"><div x-text="error"></div></div>

        <div class="field">
          <span class="label" id="l-mode">Product</span>
          <div class="choice-list inline" role="radiogroup" aria-labelledby="l-mode">
            <label class="choice" for="f-mode-existing"><input type="radio" id="f-mode-existing" name="product_mode" value="existing" x-model="mode"><span><span class="choice-title">An existing product</span><br><span class="choice-desc">Add a code to something that already has an E number or a sell number.</span></span></label>
            <label class="choice" for="f-mode-new"><input type="radio" id="f-mode-new" name="product_mode" value="new" x-model="mode"><span><span class="choice-title">A brand-new product</span><br><span class="choice-desc">Nothing coded yet: a resale item, a purchased product, a formula with no project.</span></span></label>
          </div>
        </div>

        <div x-show="mode === 'existing'">
          <?= component('form/field', ['name' => 'product', 'type' => 'lookup', 'label' => 'Find the product', 'lookup' => '/api/codes/search?q=', 'value' => $product['ref'] ?? '', 'lookup_label' => $product['label'] ?? '', 'placeholder' => 'E number, sell number or a word from the description', 'autofocus' => true, 'help' => 'Pick it from the list. Choosing any of a product\'s sell numbers finds the same product.']) ?>
          <p class="error-text" role="alert" x-show="errors.product" x-cloak><span x-text="errors.product"></span></p>
          <div class="product-pick" x-show="product" x-cloak style="margin:calc(-1 * var(--s-3)) 0 var(--s-5)">
            <span class="code-pill" x-text="product ? product.label : ''"></span>
            <span x-text="product ? product.description : ''"></span>
            <span class="badge" :class="product && product.hasInternal ? 'badge-green' : 'badge-amber'" x-text="product && product.hasInternal ? 'has E number ' + product.internalDisplay : 'no E number yet'"></span>
          </div>
        </div>

        <div class="field">
          <span class="label" id="l-want">What do you need?</span>
          <div class="choice-list" role="radiogroup" aria-labelledby="l-want">
            <template x-for="w in wants" :key="w.v">
              <label class="choice"><input type="radio" name="want" :value="w.v" x-model="want"><span><span class="choice-title" x-text="w.label"></span><br><span class="choice-desc" x-text="w.desc"></span></span></label>
            </template>
          </div>
          <p class="help" x-show="wants.length === 0" x-cloak>You do not have permission to assign the kind of code this product needs. Ask an administrator.</p>
          <p class="error-text" role="alert" x-show="errors.want" x-cloak><span x-text="errors.want"></span></p>
        </div>

        <div class="form-grid" x-show="wantSell">
          <div class="field">
            <label class="label" for="f-prefix">Prefix <span class="req" aria-hidden="true">*</span></label>
            <select id="f-prefix" name="prefix_id" x-model="prefixId" :aria-invalid="errors.prefix_id ? 'true' : null">
              <option value="">Choose a prefix…</option>
              <template x-for="p in prefixes" :key="p.id"><option :value="p.id" x-text="p.code + ' — ' + p.label + (p.tag ? ' (' + p.tag + ')' : '') + '   next: ' + p.next"></option></template>
            </select>
            <p class="help" x-text="prefix ? prefix.description : 'The prefix says what kind of product this is. The next free number is shown for each.'"></p>
            <p class="error-text" role="alert" x-show="errors.prefix_id" x-cloak><span x-text="errors.prefix_id"></span></p>
          </div>
          <div class="field" x-show="suffixes.length">
            <span class="label" id="l-suffix">Suffix</span>
            <div class="choice-list inline" role="radiogroup" aria-labelledby="l-suffix">
              <label class="choice"><input type="radio" name="suffix_id" value="" x-model="suffixId"><span><span class="choice-title">(none)</span></span></label>
              <template x-for="s in suffixes" :key="s.id"><label class="choice"><input type="radio" name="suffix_id" :value="s.id" x-model="suffixId"><span><span class="choice-title" x-text="s.code"></span><br><span class="choice-desc" x-text="s.label"></span></span></label></template>
            </div>
            <p class="error-text" role="alert" x-show="errors.suffix_id" x-cloak><span x-text="errors.suffix_id"></span></p>
          </div>
        </div>

        <div class="field">
          <label class="label" for="f-description">Description <span class="req" aria-hidden="true">*</span></label>
          <input type="text" id="f-description" name="description" x-model="description" @input="descriptionTouched = true" maxlength="500" class="input" placeholder="What the product is" :aria-invalid="errors.description ? 'true' : null">
          <p class="help" x-text="product ? 'Filled in from ' + product.label + '. Change it if the new code means something different, e.g. the UV version.' : 'Short and searchable: colour, use, customer if relevant.'"></p>
          <p class="error-text" role="alert" x-show="errors.description" x-cloak><span x-text="errors.description"></span></p>
        </div>

        <div class="form-grid" x-show="wantSell">
          <div class="field">
            <label class="label" for="f-reason">Reason <span class="req" aria-hidden="true" x-show="needsReason">*</span><span class="optional" x-show="!needsReason">(optional)</span></label>
            <select id="f-reason" name="reason" x-model="reason" :aria-invalid="errors.reason ? 'true' : null">
              <option value="">Pick a reason…</option>
              <?php foreach ($reasons as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <p class="help">Why this product needs a (new) sell number. Kept with the code.</p>
            <p class="error-text" role="alert" x-show="errors.reason" x-cloak><span x-text="errors.reason"></span></p>
          </div>
          <div class="field" x-show="reason === 'other'">
            <label class="label" for="f-reason-text">Say why <span class="req" aria-hidden="true">*</span></label>
            <input type="text" id="f-reason-text" name="reason_text" value="<?= e($defaults['reason_text']) ?>" maxlength="500" class="input">
            <p class="error-text" role="alert" x-show="errors.reason_text" x-cloak><span x-text="errors.reason_text"></span></p>
          </div>
        </div>

        <?= component('form/field', ['name' => 'original_customer_id', 'type' => 'lookup', 'label' => 'Customer', 'optional_text' => true, 'lookup' => '/api/customers?q=', 'value' => $defaults['original_customer_id'], 'lookup_label' => $defaults['original_customer_label'], 'placeholder' => 'Start typing the customer name', 'help' => 'For an existing product the customer is kept unless you pick another.']) ?>

        <div x-show="wantInternal">
          <div class="form-grid">
            <?= component('form/field', ['name' => 'resale', 'type' => 'checkbox', 'label' => 'Purchased for resale', 'value' => !empty($defaults['resale']) ? '1' : '', 'help' => 'Bought in rather than made here.']) ?>
            <?= component('form/field', ['name' => 'vendor_name', 'label' => 'Vendor', 'optional_text' => true, 'value' => $defaults['vendor_name'] ?? '', 'attrs' => ['maxlength' => 200]]) ?>
            <?= component('form/field', ['name' => 'vendor_code', 'label' => "Vendor's code", 'optional_text' => true, 'value' => $defaults['vendor_code'] ?? '', 'attrs' => ['maxlength' => 100]]) ?>
          </div>
          <div class="mb-4"></div>
        </div>

        <?= component('form/field', ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'optional_text' => true, 'value' => $defaults['notes'] ?? '', 'rows' => 2]) ?>
      </div>
      <div class="form-actions" style="padding:var(--s-4) var(--s-6) var(--s-6);margin-top:0">
        <button type="submit" class="btn btn-primary btn-lg" :disabled="busy || wants.length === 0"><span x-text="busy ? 'Reserving…' : 'Assign the code'"></span></button>
        <span class="muted small">Nothing is reserved until you press the button. Numbers are never reused; void a mistake afterwards.</span>
      </div>
    </form>

    <div class="card assign-result" x-show="result" x-cloak id="assign-result">
      <div class="card-body">
        <h2 x-text="result && result.codes.length > 1 ? 'Codes reserved' : 'Code reserved'"></h2>
        <p class="muted">Write it on the sample or the job sheet. It is in the cross-reference now.</p>
        <template x-for="c in (result ? result.codes : [])" :key="c.code">
          <div class="code-display">
            <div class="code-label" x-text="c.label"></div>
            <div class="big-code" x-text="c.code"></div>
            <div class="code-desc" x-text="c.description"></div>
            <div class="mt-4"><button type="button" class="btn btn-secondary" :data-copy="c.code">Copy <span x-text="c.code"></span></button> <a class="btn btn-ghost" :href="c.url">Open</a></div>
          </div>
        </template>
        <p class="muted small" x-show="result && result.detail" x-text="result ? result.detail : ''"></p>
        <div class="next-actions"><button type="button" class="btn btn-primary btn-lg" @click="reset()">Assign another code</button><a class="btn btn-secondary" href="/codes">Code lookup</a></div>
      </div>
    </div>
  </div>

  <aside class="card"><div class="card-body next-preview">
    <h3 style="margin-bottom:var(--s-2)">What you will get</h3>
    <template x-if="wantInternal"><div><div class="np-label">Next E number</div><div class="np-code" x-text="nextInternal"></div></div></template>
    <template x-if="wantSell"><div><div class="np-label">Next sell number</div><div class="np-code" x-text="nextSell || '— pick a prefix'"></div></div></template>
    <template x-if="wantSell && product && product.hasInternal"><p class="muted small mb-0">It will be linked to <strong x-text="product.internalDisplay"></strong>, so the lookup shows them together.</p></template>
    <template x-if="want === 'both'"><p class="muted small mb-0">The sell number will be linked to the new E number.</p></template>
    <p class="muted small mb-0">These are the next free numbers right now. If someone else assigns one first, you get the one after; no number is ever given out twice.</p>
    <p class="small mb-0"><a href="/help/quick-assign">How quick assign works</a></p>
  </div></aside>
</div>
</div>
