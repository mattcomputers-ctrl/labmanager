<?php
/** Props: label, href (renders <a>) or type (submit/button), variant (primary|secondary|ghost|danger|danger-outline|success), size (lg|sm), icon (svg html), attrs, disabled, block, name, value */
$variant = $variant ?? 'secondary';
$cls = 'btn btn-' . $variant . (!empty($size) ? ' btn-' . $size : '') . (!empty($block) ? ' btn-block' : '') . (!empty($class) ? ' ' . $class : '');
$attrStr = '';
foreach (($attrs ?? []) as $k => $v) { if ($v === true) { $attrStr .= ' ' . e($k); } elseif ($v !== false && $v !== null) { $attrStr .= ' ' . e($k) . '="' . e($v) . '"'; } }
if (!empty($href)): ?>
<a class="<?= e($cls) ?>" href="<?= e($href) ?>"<?= $attrStr ?><?= !empty($disabled) ? ' aria-disabled="true" tabindex="-1"' : '' ?>><?= $icon ?? '' ?><?= e($label) ?></a>
<?php else: ?>
<button class="<?= e($cls) ?>" type="<?= e($type ?? 'submit') ?>"<?= isset($name) ? ' name="' . e($name) . '"' : '' ?><?= isset($value) ? ' value="' . e($value) . '"' : '' ?><?= !empty($disabled) ? ' disabled' : '' ?><?= $attrStr ?>><?= $icon ?? '' ?><?= e($label) ?></button>
<?php endif; ?>
