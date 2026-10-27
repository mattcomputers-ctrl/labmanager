<?php
/**
 * Generic form field. Props:
 *   name, label, type (text|textarea|number|date|select|checkbox|radio|lookup|hidden|password|email|color|file),
 *   value, help, required (bool), options (list of [value,label] or [value,label,desc]), placeholder,
 *   error (string|null; defaults to form_errors()[name]), attrs (array), autofocus (bool), id, lookup (url for typeahead),
 *   lookup_label (initial label for lookup), allow_new (lookup), min, max, step, rows, size ('lg'), class, optional_text, prefix,
 *   tip (one paragraph behind a "?" button next to the label)
 */
$name = (string) ($name ?? '');
$type = $type ?? 'text';
$id = $id ?? 'f-' . preg_replace('/[^a-z0-9_]+/i', '-', $name);
$label = $label ?? ucfirst(str_replace('_', ' ', $name));
$required = !empty($required);
$errors = form_errors();
$error = $error ?? ($errors[$name] ?? null);
$value = $value ?? old($name, $default ?? null);
$help = $help ?? null;
$attrs = $attrs ?? [];
$placeholder = $placeholder ?? '';
$class = $class ?? '';
$attrStr = '';
foreach ($attrs as $k => $v) {
    if ($v === true) { $attrStr .= ' ' . e($k); }
    elseif ($v !== false && $v !== null) { $attrStr .= ' ' . e($k) . '="' . e($v) . '"'; }
}
if (!empty($autofocus)) { $attrStr .= ' data-autofocus'; }
if ($error) { $attrStr .= ' aria-invalid="true"'; }
$describedBy = [];
if ($help) { $describedBy[] = $id . '-help'; }
if ($error) { $describedBy[] = $id . '-error'; }
if ($describedBy) { $attrStr .= ' aria-describedby="' . e(implode(' ', $describedBy)) . '"'; }
$sizeClass = (($size ?? '') === 'lg') ? ' input-lg' : '';
if ($type === 'hidden'): ?>
<input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"<?= $attrStr ?>>
<?php return; endif; ?>
<div class="field <?= e($class) ?>">
<?php if ($type === 'checkbox'): ?>
  <label class="check" for="<?= e($id) ?>">
    <input type="hidden" name="<?= e($name) ?>" value="0">
    <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>" value="1"<?= ($value && $value !== '0') ? ' checked' : '' ?><?= $attrStr ?>>
    <span class="check-text"><span><?= e($label) ?><?php if (!empty($tip)): ?> <?= component('help-tip', ['text' => $tip]) ?><?php endif; ?></span><?php if ($help): ?><span class="help" id="<?= e($id) ?>-help"><?= e($help) ?></span><?php endif; ?></span>
  </label>
<?php else: ?>
  <label class="label" for="<?= e($id) ?>"><?= e($label) ?><?php if ($required): ?> <span class="req" aria-hidden="true">*</span><?php elseif (!empty($optional_text)): ?> <span class="optional">(optional)</span><?php endif; ?>
    <?php if (!empty($tip)): ?><?= component('help-tip', ['text' => $tip]) ?><?php endif; ?>
  </label>
  <?php if ($type === 'textarea'): ?>
    <textarea id="<?= e($id) ?>" name="<?= e($name) ?>" rows="<?= (int) ($rows ?? 4) ?>" placeholder="<?= e($placeholder) ?>"<?= $required ? ' required' : '' ?><?= $attrStr ?>><?= e($value) ?></textarea>
  <?php elseif ($type === 'select'): ?>
    <select id="<?= e($id) ?>" name="<?= e($name) ?>"<?= $required ? ' required' : '' ?><?= $attrStr ?> class="<?= $sizeClass ?>">
      <?php if (!empty($placeholder)): ?><option value=""><?= e($placeholder) ?></option><?php endif; ?>
      <?php foreach (($options ?? []) as $opt): [$ov, $ol] = $opt; ?>
        <option value="<?= e($ov) ?>"<?= (string) $ov === (string) $value ? ' selected' : '' ?>><?= e($ol) ?></option>
      <?php endforeach; ?>
    </select>
  <?php elseif ($type === 'radio'): ?>
    <div class="choice-list" role="radiogroup" aria-labelledby="<?= e($id) ?>-label">
      <?php foreach (($options ?? []) as $i => $opt): [$ov, $ol] = $opt; $od = $opt[2] ?? null; $oid = $id . '-' . $i; ?>
        <label class="choice" for="<?= e($oid) ?>">
          <input type="radio" id="<?= e($oid) ?>" name="<?= e($name) ?>" value="<?= e($ov) ?>"<?= (string) $ov === (string) $value ? ' checked' : '' ?><?= $i === 0 && !empty($autofocus) ? ' data-autofocus' : '' ?>>
          <span><span class="choice-title"><?= e($ol) ?></span><?php if ($od): ?><br><span class="choice-desc"><?= e($od) ?></span><?php endif; ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  <?php elseif ($type === 'lookup'): ?>
    <?= component('form/lookup', ['name' => $name, 'id' => $id, 'url' => $lookup, 'value' => $value, 'label_value' => $lookup_label ?? '', 'placeholder' => $placeholder, 'allow_new' => $allow_new ?? false, 'required' => $required, 'size' => $size ?? '', 'autofocus' => $autofocus ?? false]) ?>
  <?php else: ?>
    <?php if (!empty($prefix)): ?><div class="input-row"><span class="btn btn-secondary" aria-hidden="true" style="cursor:default"><?= e($prefix) ?></span><?php endif; ?>
    <input type="<?= e($type) ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($value) ?>" placeholder="<?= e($placeholder) ?>"<?= $required ? ' required' : '' ?><?= isset($min) ? ' min="' . e($min) . '"' : '' ?><?= isset($max) ? ' max="' . e($max) . '"' : '' ?><?= isset($step) ? ' step="' . e($step) . '"' : '' ?><?= $attrStr ?> class="input<?= $sizeClass ?><?= in_array($type, ['text'], true) && !empty($code) ? ' input-code' : '' ?>"<?= ($type === 'text' && empty($attrs['autocomplete'])) ? ' autocomplete="off"' : '' ?>>
    <?php if (!empty($prefix)): ?></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($help): ?><p class="help" id="<?= e($id) ?>-help"><?= e($help) ?></p><?php endif; ?>
<?php endif; ?>
<?php if ($error): ?><p class="error-text" id="<?= e($id) ?>-error" role="alert"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span><?= e($error) ?></span></p><?php endif; ?>
</div>
