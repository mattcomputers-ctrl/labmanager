<?php
/**
 * A single horizontal bar for report tables. The real number always sits in
 * its own table cell; the bar is decorative, so it is hidden from screen
 * readers. Props: value (number), max (number), tone (primary|success|warning|danger|muted), label (optional text drawn inside/after the bar)
 */
$value = (float) ($value ?? 0);
$max = (float) ($max ?? 0);
$pct = $max > 0 ? max(0, min(100, $value / $max * 100)) : 0;
$tone = $tone ?? 'primary';
?>
<div class="rbar rbar-<?= e($tone) ?>" aria-hidden="true"><span class="rbar-fill" style="width:<?= number_format($pct, 1, '.', '') ?>%"></span><?php if (isset($label) && $label !== ''): ?><span class="rbar-label"><?= e($label) ?></span><?php endif; ?></div>
