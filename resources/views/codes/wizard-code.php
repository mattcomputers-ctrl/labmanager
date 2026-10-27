<?php
/** Quick assign wizard, step 2: choose the code. @var \Chroma\Http\Wizard\QuickAssignWizard $wizard @var \Chroma\Http\Wizard\Step $step @var array $state */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><script src="/assets/js/codes.js"></script><?php $view->end();
$product = $wizard->productFor($state);
$wants = $wizard->wantsFor($product);
$prefixes = $wizard->prefixChoices();
$errors = form_errors();
$val = fn(string $k, $default = '') => old($k, $state[$k] ?? $default);
$cfg = [
    'prefixes' => $prefixes,
    'mode' => $product ? 'existing' : 'new',
    'product' => $product ? ['ref' => $product['ref'], 'label' => $product['label'], 'description' => $product['description'], 'hasInternal' => $product['internal_code_id'] !== null, 'internalDisplay' => $product['internal']['display'] ?? ''] : null,
    'want' => $val('want', array_key_first($wants) ?? ''),
    'prefixId' => $val('prefix_id'),
    'suffixId' => $val('suffix_id'),
    // The product step clears the description when the product changes; an empty value means "use the product's".
    'description' => ($val('description') !== '' ? $val('description') : ($product['description'] ?? '')),
    'reason' => $val('reason'),
    'canSell' => can('codes.assign_sell'),
    'canInternal' => can('codes.assign_internal'),
    'nextInternal' => $wizard->nextInternal(),
];
$err = fn(string $k) => isset($errors[$k]) ? '<p class="error-text" role="alert"><span>' . e($errors[$k]) . '</span></p>' : '';
?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate x-data="quickAssign(<?= json_attr($cfg) ?>)" @lookup-chosen="onChosen($event.detail)">
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>

    <?php if ($product): ?>
      <div class="product-pick" style="margin-bottom:var(--s-6)">
        <?= component('code-pill', ['code' => $product['label']]) ?>
        <span><?= e($product['description']) ?></span>
        <?= $product['internal_code_id'] !== null ? component('badge', ['label' => 'has E number ' . $product['internal']['display'], 'color' => 'green']) : component('badge', ['label' => 'no E number yet', 'color' => 'amber']) ?>
      </div>
    <?php endif; ?>

    <?php if (!$wants): ?>
      <?= component('callout', ['type' => 'warning', 'title' => 'Nothing to assign', 'body' => 'You do not have permission to assign the kind of code this product needs. Ask an administrator.']) ?>
    <?php else: ?>
    <div class="field">
      <span class="label" id="l-want">What do you need?</span>
      <div class="choice-list" role="radiogroup" aria-labelledby="l-want">
        <?php $i = 0; foreach ($wants as $k => $label): ?>
          <label class="choice" for="f-want-<?= $i ?>"><input type="radio" id="f-want-<?= $i ?>" name="want" value="<?= e($k) ?>" x-model="want"<?= $i === 0 ? ' data-autofocus' : '' ?>><span><span class="choice-title"><?= e($label) ?></span></span></label>
        <?php $i++; endforeach; ?>
      </div>
      <?= $err('want') ?>
    </div>

    <div class="field prefix-choice" x-show="wantSell">
      <span class="label" id="l-prefix">Prefix <span class="req" aria-hidden="true">*</span></span>
      <p class="help" style="margin-top:-4px">The prefix says what kind of product this is. The next free number is shown for each.</p>
      <div class="choice-list" role="radiogroup" aria-labelledby="l-prefix">
        <?php foreach ($prefixes as $p): ?>
          <label class="choice" for="f-prefix-<?= (int) $p['id'] ?>"><input type="radio" id="f-prefix-<?= (int) $p['id'] ?>" name="prefix_id" value="<?= (int) $p['id'] ?>" x-model="prefixId"><span><span class="choice-title"><?= e($p['code']) ?> — <?= e($p['label']) ?><span class="nxt">next: <?= e($p['next']) ?></span></span><?= $p['description'] ? '<br><span class="choice-desc">' . e($p['description']) . '</span>' : '' ?></span></label>
        <?php endforeach; ?>
      </div>
      <?= $err('prefix_id') ?>
    </div>

    <div class="field" x-show="wantSell && suffixes.length" x-cloak>
      <span class="label" id="l-suffix">Suffix</span>
      <p class="help" style="margin-top:-4px">Only the suffixes this prefix allows. The suffix never starts a new count.</p>
      <div class="choice-list inline" role="radiogroup" aria-labelledby="l-suffix">
        <label class="choice"><input type="radio" name="suffix_id" value="" x-model="suffixId"><span><span class="choice-title">(none)</span><br><span class="choice-desc">The plain version</span></span></label>
        <template x-for="s in suffixes" :key="s.id"><label class="choice"><input type="radio" name="suffix_id" :value="s.id" x-model="suffixId"><span><span class="choice-title" x-text="s.code"></span><br><span class="choice-desc" x-text="s.label"></span></span></label></template>
      </div>
      <?= $err('suffix_id') ?>
    </div>

    <div class="field">
      <label class="label" for="f-description">Description <span class="req" aria-hidden="true">*</span></label>
      <input type="text" id="f-description" name="description" x-model="description" @input="descriptionTouched = true" maxlength="500" class="input input-lg" placeholder="What the product is"<?= isset($errors['description']) ? ' aria-invalid="true"' : '' ?>>
      <p class="help"><?= $product ? 'Filled in from ' . e($product['label']) . '. Change it if the new code means something different, for example the UV version.' : 'Short and searchable: colour, use, customer if relevant.' ?></p>
      <?= $err('description') ?>
    </div>

    <div x-show="wantSell">
      <div class="field">
        <label class="label" for="f-reason">Reason <?= $product ? '<span class="req" aria-hidden="true">*</span>' : '<span class="optional">(optional)</span>' ?></label>
        <select id="f-reason" name="reason" x-model="reason" class="input-lg"<?= isset($errors['reason']) ? ' aria-invalid="true"' : '' ?>>
          <option value="">Pick a reason…</option>
          <?php foreach (\Chroma\Domain\Codes\CodeAssigner::REASONS as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <p class="help">Why this product needs a (new) sell number. It is kept with the code.</p>
        <?= $err('reason') ?>
      </div>
      <div class="field" x-show="reason === 'other'" x-cloak>
        <label class="label" for="f-reason-text">Say why <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="f-reason-text" name="reason_text" value="<?= e($val('reason_text')) ?>" maxlength="500" class="input input-lg">
        <?= $err('reason_text') ?>
      </div>
    </div>

    <?= component('form/field', ['name' => 'original_customer_id', 'type' => 'lookup', 'label' => 'Customer', 'optional_text' => true, 'lookup' => '/api/customers?q=', 'value' => $val('original_customer_id'), 'lookup_label' => $state['original_customer_id_label'] ?? '', 'placeholder' => 'Start typing the customer name', 'size' => 'lg', 'help' => $product && $product['customer'] ? 'Leave blank to keep ' . $product['customer'] . '.' : 'Leave blank if there is no particular customer.']) ?>

    <div x-show="wantInternal" x-cloak>
      <?= component('form/field', ['name' => 'resale', 'type' => 'checkbox', 'label' => 'Purchased for resale', 'value' => $val('resale'), 'help' => 'Bought in rather than made here.']) ?>
      <div class="form-grid">
        <?= component('form/field', ['name' => 'vendor_name', 'label' => 'Vendor', 'optional_text' => true, 'value' => $val('vendor_name'), 'attrs' => ['maxlength' => 200]]) ?>
        <?= component('form/field', ['name' => 'vendor_code', 'label' => "Vendor's code", 'optional_text' => true, 'value' => $val('vendor_code'), 'attrs' => ['maxlength' => 100]]) ?>
      </div>
      <div class="mb-6"></div>
    </div>

    <?= component('form/field', ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'optional_text' => true, 'value' => $val('notes'), 'rows' => 2, 'size' => 'lg', 'help' => 'Anything worth knowing about this code. Kept on the code page.']) ?>

    <div class="card" style="background:var(--surface-2);box-shadow:none;margin-bottom:var(--s-6)"><div class="card-body next-preview" style="padding:var(--s-4) var(--s-5)">
      <template x-if="wantInternal"><div><span class="np-label">Next E number: </span><span class="np-code" x-text="nextInternal"></span></div></template>
      <template x-if="wantSell"><div><span class="np-label">Next sell number: </span><span class="np-code" x-text="nextSell || '— pick a prefix'"></span></div></template>
      <p class="muted small mb-0">The next free numbers right now. Nothing is reserved until you confirm on the next screen.</p>
    </div></div>
    <?php endif; ?>

    <div class="wizard-actions">
      <button type="submit" name="_back" value="1" class="btn btn-secondary btn-lg" formnovalidate>‹ Back</button>
      <?php if ($wants): ?><button type="submit" class="btn btn-primary btn-lg">Review</button><?php endif; ?>
    </div>
  </form>
</div>
