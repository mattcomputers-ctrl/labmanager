<?php /** The three-step indicator shared by upload, review and done. Props: step (1..3) */
$steps = [1 => 'Upload the sheets', 2 => 'Check the dry run', 3 => 'Commit'];
$step = (int) ($step ?? 1);
?>
<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/import.css"><?php $view->end(); ?>
<ol class="import-steps" aria-label="Import steps">
  <?php foreach ($steps as $n => $label): ?>
    <?php if ($n > 1): ?><li class="sep" aria-hidden="true"></li><?php endif; ?>
    <li class="step <?= $n === $step ? 'current' : ($n < $step ? 'done' : '') ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
      <span class="n"><?= $n < $step ? '✓' : $n ?></span><span><?= e($label) ?></span>
    </li>
  <?php endforeach; ?>
</ol>
