<?php
/**
 * Shared fields for the add and edit prefix forms.
 * Vars: $values (array: code,label,description,min_digits,sort_order,starting_number?, suffix_ids list), $suffixes (active suffix rows),
 *       $locked (bool: code cannot change), $lockedReason (string), $isNew (bool)
 */
$values = $values ?? [];
$suffixes = $suffixes ?? [];
$locked = !empty($locked);
$isNew = !empty($isNew);
// After a failed save keep exactly what the user ticked (an all-unticked form posts no suffix_ids at all).
$submitted = old('label') !== null;
$oldIds = old('suffix_ids');
$selected = $submitted ? (is_array($oldIds) ? array_map('intval', $oldIds) : []) : array_map('intval', $values['suffix_ids'] ?? []);
// Suffixes this prefix allows that are currently switched off: shown ticked but greyed; the link is kept on save.
$inactiveLinked = array_values(array_filter($values['suffixes'] ?? [], fn($s) => (int) ($s['active'] ?? 1) === 0));
$errors = form_errors();
?>
<div class="form-grid">
  <?php if ($locked): ?>
    <div>
      <?= component('form/field', ['name' => 'code_display', 'label' => 'Prefix letters', 'value' => $values['code'], 'attrs' => ['disabled' => true, 'style' => 'max-width:200px'], 'code' => true, 'help' => $lockedReason ?? 'The letters cannot change once codes exist under the prefix.']) ?>
      <input type="hidden" name="code" value="<?= e($values['code']) ?>">
      <?php if (!empty($errors['code'])): ?><p class="error-text" role="alert"><?= e($errors['code']) ?></p><?php endif; ?>
    </div>
  <?php else: ?>
    <?= component('form/field', ['name' => 'code', 'label' => 'Prefix letters', 'value' => old('code', $values['code'] ?? ''), 'required' => true, 'code' => true, 'placeholder' => 'OR', 'attrs' => ['maxlength' => \Chroma\Domain\Codes\PrefixAdmin::CODE_MAX, 'style' => 'text-transform:uppercase;max-width:200px', 'autocapitalize' => 'characters', 'spellcheck' => 'false'], 'help' => 'Letters only, up to 12. Made uppercase for you. Cannot be changed once codes exist under it.', 'autofocus' => $isNew]) ?>
  <?php endif; ?>
  <?= component('form/field', ['name' => 'label', 'label' => 'Name', 'value' => old('label', $values['label'] ?? ''), 'required' => true, 'placeholder' => 'e.g. Orange', 'attrs' => ['maxlength' => 100], 'help' => 'Shown next to the letters wherever a prefix is chosen.', 'autofocus' => !$isNew]) ?>
  <div class="span-all"><?= component('form/field', ['name' => 'description', 'label' => 'What it is used for', 'value' => old('description', $values['description'] ?? ''), 'optional_text' => true, 'placeholder' => 'One line, e.g. Standard color prefix for oranges.', 'attrs' => ['maxlength' => 255]]) ?></div>
  <?= component('form/field', ['name' => 'min_digits', 'label' => 'Minimum digits', 'type' => 'number', 'min' => 1, 'max' => \Chroma\Domain\Codes\PrefixAdmin::MIN_DIGITS_MAX, 'value' => old('min_digits', $values['min_digits'] ?? '4'), 'required' => true, 'attrs' => ['style' => 'max-width:160px'], 'help' => 'Numbers are padded with zeros to this width. Colors use 4 (Y0001), security ink uses 6 (X104043).']) ?>
  <?php if ($isNew): ?>
    <?= component('form/field', ['name' => 'starting_number', 'label' => 'Starting number', 'type' => 'number', 'min' => 1, 'value' => old('starting_number', $values['starting_number'] ?? '1'), 'required' => true, 'attrs' => ['style' => 'max-width:200px'], 'help' => 'The first code gets this number. Usually 1, or 1000 for four-digit codes from the start.']) ?>
  <?php endif; ?>
  <?= component('form/field', ['name' => 'sort_order', 'label' => 'Position in lists', 'type' => 'number', 'min' => 0, 'value' => old('sort_order', $values['sort_order'] ?? '100'), 'optional_text' => true, 'attrs' => ['style' => 'max-width:160px'], 'help' => 'Smaller numbers come first in dropdowns. Colors are 10–100, inherited prefixes 200+.']) ?>
</div>

<fieldset class="suffix-fieldset">
  <legend class="label">Allowed suffixes <span class="optional">(optional)</span> <?= component('help-tip', ['text' => 'A suffix is added to the end of a sell number, like the UV in Y1715UV. It does not start a new count: Y1715 and Y1716UV come from the same counter. Ticking a suffix lets people choose it; the plain number without a suffix is always available too.']) ?></legend>
  <?php if (!$suffixes && !$inactiveLinked): ?>
    <p class="help">There are no suffixes switched on yet. Add them under <a href="/admin/suffixes">Admin → Suffixes</a>, then tick them here.</p>
  <?php else: ?>
    <div class="suffix-list">
      <?php foreach ($suffixes as $s): $sid = (int) $s['id']; ?>
        <label class="check" for="suffix-<?= $sid ?>">
          <input type="checkbox" id="suffix-<?= $sid ?>" name="suffix_ids[]" value="<?= $sid ?>"<?= in_array($sid, $selected, true) ? ' checked' : '' ?>>
          <span class="check-text"><span><span class="code"><?= e($s['code']) ?></span> <?= e($s['label']) ?></span></span>
        </label>
      <?php endforeach; ?>
      <?php foreach ($inactiveLinked as $s): $sid = (int) $s['id']; ?>
        <label class="check suffix-inactive" for="suffix-off-<?= $sid ?>" title="<?= e($s['code']) ?> is switched off under Admin → Suffixes">
          <input type="checkbox" id="suffix-off-<?= $sid ?>" checked disabled>
          <span class="check-text"><span><span class="code"><?= e($s['code']) ?></span> <?= e($s['label']) ?> <span class="muted small">(switched off)</span></span></span>
        </label>
      <?php endforeach; ?>
    </div>
    <p class="help">None ticked means there is no suffix field at all when assigning a code under this prefix.</p>
    <?php if ($inactiveLinked): ?>
      <p class="help">A greyed suffix is switched off under <a href="/admin/suffixes">Admin → Suffixes</a>, so nobody can pick it right now. It stays allowed on this prefix and comes back as soon as it is switched on again. To stop this prefix allowing it, switch it on first, untick it here, then switch it off.</p>
    <?php endif; ?>
    <?php if (!empty($errors['suffix_ids'])): ?><p class="error-text" role="alert"><?= e($errors['suffix_ids']) ?></p><?php endif; ?>
  <?php endif; ?>
</fieldset>
