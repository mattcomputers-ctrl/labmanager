<?php /** Color chip. Props: hex (e.g. #ff0000), title */ $hex = $hex ?? ''; if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $hex)) { return; } ?>
<span class="chip" style="background: <?= e($hex) ?>" title="<?= e($title ?? $hex) ?>" aria-label="Color swatch <?= e($hex) ?>"></span>
