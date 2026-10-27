<?php
/** Expert-mode manual entry. @var string $type @var array $reasons @var array $defaults */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$type = old('type', $type);
// After a validation reload, what was typed wins over the query-string defaults.
foreach ($defaults as $k => $v) {
    $defaults[$k] = old($k, $v);
}
$defaults['resale'] = in_array($defaults['resale'], [1, '1', true], true);
?>
<?= component('page-header', [
    'title' => 'Enter a code by hand',
    'lede' => 'For legacy items and odd cases where the code already exists on paper. For anything new, use Quick assign so Chroma picks the next free number.',
    'actions' => component('button', ['label' => 'Quick assign', 'href' => '/assign?expert=1']) . component('button', ['label' => 'Code lookup', 'href' => '/codes']),
]) ?>
<div class="page-narrow">
  <?= component('callout', ['type' => 'warning', 'title' => 'Every manual entry is logged', 'body' => 'Chroma checks the code is not already used, writes it to the cross-reference and records that you entered it by hand. If the number is above the counter, the counter moves past it.']) ?>
  <div class="mb-4"></div>
  <form method="post" action="/codes/manual" class="card" x-data="{ type: '<?= e($type) ?>', reason: '<?= e(old('reason', $defaults['reason'])) ?>' }" @change="if ($event.target.name === 'type') type = $event.target.value">
    <?= csrf_field() ?>
    <div class="card-body">
      <?= component('form/field', ['name' => 'type', 'type' => 'radio', 'label' => 'Kind of code', 'value' => $type, 'options' => [
          ['sell', 'A sell number', 'Prefix, number and optional suffix, like Y1714UV or X104043. The prefix must exist (retired is fine for legacy items).'],
          ['internal', 'An E number', 'E followed by digits, with an optional version after a dash, like E6374-10.'],
      ]]) ?>
      <div class="form-grid">
        <?= component('form/field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => $defaults['code'], 'placeholder' => 'Y1714UV or E6374-10', 'attrs' => ['maxlength' => 40], 'help' => 'Any spelling: y-1000 is tidied to Y1000 and the original is kept.']) ?>
        <?= component('form/field', ['name' => 'description', 'label' => 'Description', 'required' => true, 'value' => $defaults['description'], 'attrs' => ['maxlength' => 500], 'placeholder' => 'What the product is']) ?>
      </div>
      <div class="mb-4"></div>
      <div x-show="type === 'sell'">
        <div class="form-grid">
          <?= component('form/field', ['name' => 'internal', 'type' => 'lookup', 'label' => 'E number it belongs to', 'optional_text' => true, 'lookup' => '/api/codes/search?q=', 'value' => $defaults['internal'], 'lookup_label' => $defaults['internal_label'], 'placeholder' => 'E number or another sell number of the product', 'help' => 'Leave blank if the formula has no E number yet.']) ?>
          <?= component('form/field', ['name' => 'reason', 'type' => 'select', 'label' => 'Reason', 'optional_text' => true, 'value' => $defaults['reason'], 'placeholder' => 'No particular reason', 'options' => array_map(fn($k, $v) => [$k, $v], array_keys($reasons), $reasons), 'attrs' => ['x-model' => 'reason']]) ?>
        </div>
        <div x-show="reason === 'other'" x-cloak>
          <?php /* required only while shown: a hidden required input would block the whole form */ ?>
          <?= component('form/field', ['name' => 'reason_text', 'label' => 'Say why', 'value' => $defaults['reason_text'], 'attrs' => ['maxlength' => 500, ':required' => "reason === 'other'"], 'placeholder' => 'A few words on why this code is needed', 'help' => 'Needed when the reason is "Other". It is kept with the code.']) ?>
        </div>
        <div class="mb-4"></div>
      </div>
      <div x-show="type === 'internal'" x-cloak>
        <div class="form-grid">
          <?= component('form/field', ['name' => 'resale', 'type' => 'checkbox', 'label' => 'Purchased for resale', 'value' => $defaults['resale'] ? '1' : '', 'help' => 'Bought in rather than made here.']) ?>
          <?= component('form/field', ['name' => 'vendor_name', 'label' => 'Vendor', 'optional_text' => true, 'value' => $defaults['vendor_name'], 'attrs' => ['maxlength' => 200]]) ?>
          <?= component('form/field', ['name' => 'vendor_code', 'label' => "Vendor's code", 'optional_text' => true, 'value' => $defaults['vendor_code'], 'attrs' => ['maxlength' => 100]]) ?>
        </div>
        <div class="mb-4"></div>
      </div>
      <div class="form-grid">
        <?= component('form/field', ['name' => 'original_customer_id', 'type' => 'lookup', 'label' => 'Customer', 'optional_text' => true, 'lookup' => '/api/customers?q=', 'value' => $defaults['original_customer_id'], 'lookup_label' => $defaults['original_customer_label'], 'placeholder' => 'Start typing the customer name']) ?>
        <?= component('form/field', ['name' => 'date_assigned', 'type' => 'date', 'label' => 'Date it was assigned', 'required' => true, 'value' => $defaults['date_assigned'], 'help' => 'The date on the sheet if you know it; otherwise today.']) ?>
      </div>
      <div class="mb-4"></div>
      <?= component('form/field', ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'optional_text' => true, 'value' => $defaults['notes'], 'rows' => 3]) ?>
    </div>
    <div class="form-actions" style="padding:var(--s-4) var(--s-6) var(--s-6);margin-top:0">
      <button type="submit" class="btn btn-primary btn-lg">Enter this code</button>
      <span class="muted small">Chroma refuses a code that is already used and tells you who has it.</span>
    </div>
  </form>
</div>
