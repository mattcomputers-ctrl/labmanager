<?php
/** The shared part of the new / edit project-type form. Props: type (array|null), catalogue, colors */
$isNew = $type === null;
$v = fn(string $k, mixed $d = '') => old($k, $isNew ? $d : ($type[$k] ?? $d));
$fieldsOld = old('fields');
$checkedFields = is_array($fieldsOld) ? array_keys(array_filter($fieldsOld, fn($x) => (string) $x === '1')) : ($isNew ? ['description', 'date_submitted', 'assigned_user_id', 'notes'] : $type['fields']);
?>
<h3 class="mt-0">Name and look</h3>
<div class="form-grid">
  <?= component('form/field', ['name' => 'label', 'label' => 'Type name', 'required' => true, 'value' => $v('label'), 'placeholder' => 'e.g. Press trial', 'autofocus' => $isNew, 'attrs' => ['maxlength' => 100]]) ?>
  <?php if ($isNew): ?>
    <?= component('form/field', ['name' => 'code', 'label' => 'Short code', 'required' => true, 'value' => $v('code'), 'placeholder' => 'e.g. press_trial', 'help' => 'Lowercase letters, numbers and underscores. Used in exports and the API; cannot change later.', 'attrs' => ['maxlength' => 40, 'autocapitalize' => 'off', 'spellcheck' => 'false']]) ?>
  <?php else: ?>
    <?= component('form/field', ['name' => 'code_display', 'label' => 'Short code', 'value' => $type['code'], 'attrs' => ['disabled' => true], 'help' => 'Used in exports and the API; it does not change.']) ?>
  <?php endif; ?>
  <div class="span-all"><?= component('form/field', ['name' => 'description', 'label' => 'What is it for?', 'optional_text' => true, 'value' => $v('description'), 'placeholder' => 'One sentence shown when someone picks a type', 'attrs' => ['maxlength' => 255]]) ?></div>
  <?= component('form/field', ['name' => 'item_label', 'label' => 'Items are called (plural)', 'value' => $v('item_label', 'Items'), 'placeholder' => 'e.g. Colors, Trials, Products involved', 'attrs' => ['maxlength' => 40]]) ?>
  <?= component('form/field', ['name' => 'item_label_singular', 'label' => 'One item is called', 'value' => $v('item_label_singular', 'Item'), 'placeholder' => 'e.g. Color, Trial, Product', 'attrs' => ['maxlength' => 40]]) ?>
  <?= component('form/field', ['name' => 'color', 'label' => 'Badge colour', 'type' => 'select', 'value' => $v('color', 'blue'), 'options' => array_map(fn($k, $l) => [$k, $l], array_keys($colors), $colors)]) ?>
  <?= component('form/field', ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'value' => $v('sort_order', 100), 'help' => 'Lower comes first in the type picker.']) ?>
</div>

<h3>Behaviour</h3>
<div class="grid grid-2 type-flags" x-data="{ hasCustomer: <?= $v('has_customer', 1) ? 'true' : 'false' ?> }">
  <div @change="if ($event.target.name === 'has_customer') hasCustomer = $event.target.checked">
    <?= component('form/field', ['name' => 'has_customer', 'type' => 'checkbox', 'label' => 'Has a customer', 'value' => $v('has_customer', 1), 'help' => 'Untick for internal work such as R&D.']) ?>
  </div>
  <div x-show="hasCustomer"><?= component('form/field', ['name' => 'customer_required', 'type' => 'checkbox', 'label' => 'Customer is required', 'value' => $v('customer_required', 1), 'help' => 'A project of this type cannot be saved without one.']) ?></div>
  <?= component('form/field', ['name' => 'has_stages', 'type' => 'checkbox', 'label' => 'Has stages', 'value' => $v('has_stages', 0), 'help' => 'Idea → bench → scale-up … You define the stages on the type page.']) ?>
  <?= component('form/field', ['name' => 'has_team', 'type' => 'checkbox', 'label' => 'Has a lead and a team', 'value' => $v('has_team', 0), 'help' => 'Besides the person working on it, several people can be on the project.']) ?>
  <?= component('form/field', ['name' => 'can_be_confidential', 'type' => 'checkbox', 'label' => 'Can be marked confidential', 'value' => $v('can_be_confidential', 0), 'help' => 'Confidential projects are only visible to the lead, the team, R&D and administrators.']) ?>
  <?php if (!$isNew): ?>
    <?= component('form/field', ['name' => 'active', 'type' => 'checkbox', 'label' => 'Offered when starting a project', 'value' => $v('active', 1), 'help' => 'Untick to stop new projects of this type. Existing projects are unaffected.']) ?>
  <?php endif; ?>
</div>

<h3>Fields on the project</h3>
<p class="help mt-0">Tick the fields a project of this type shows, in the wizard and on the project page. Title and customer are always there.</p>
<div class="perm-areas">
  <fieldset class="perm-area span-all">
    <legend>Catalogue</legend>
    <div class="grid grid-2">
    <?php foreach ($catalogue as $key => $f): $id = 'fld-' . preg_replace('/[^a-z0-9]+/i', '-', $key); ?>
      <label class="check" for="<?= e($id) ?>">
        <input type="hidden" name="fields[<?= e($key) ?>]" value="0">
        <input type="checkbox" id="<?= e($id) ?>" name="fields[<?= e($key) ?>]" value="1"<?= in_array($key, $checkedFields, true) ? ' checked' : '' ?>>
        <span class="check-text"><span><?= e($f['short']) ?> <span class="muted small">— <?= e($f['label']) ?><?= !empty($f['later']) ? ' (filled in later)' : '' ?></span></span><span class="help"><?= e($f['help']) ?></span></span>
      </label>
    <?php endforeach; ?>
    </div>
  </fieldset>
</div>
