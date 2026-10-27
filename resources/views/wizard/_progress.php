<?php
// $known is false while the next answer decides how many steps there are (e.g. the project type): then the
// line says only "Step 1" and the bar shows the known steps as a share of at least one more, instead of a
// total that would read "1 of 2" and jump to 7 on the next screen.
$known = $known ?? true;
$max = $known ? (int) $total : (int) $total + 1;
?>
<div class="wizard-progress" aria-label="Progress">
  <span>Step <?= (int) $n ?><?= $known ? ' of ' . (int) $total : '' ?></span>
  <div class="bar" role="progressbar" aria-valuemin="1" aria-valuemax="<?= $max ?>" aria-valuenow="<?= (int) $n ?>"<?= $known ? '' : ' aria-valuetext="Step ' . (int) $n . ', more steps follow"' ?>><span style="width: <?= (int) round($n / max(1, $max) * 100) ?>%"></span></div>
  <a class="btn btn-ghost btn-sm wizard-help" href="/help/<?= e($wizard->helpSlug()) ?>" target="_blank" rel="noopener" aria-label="Help for this task (opens in a new tab)" title="Help for this task"><span aria-hidden="true">?</span><span class="wizard-help-text">Help</span></a>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/cancel" style="margin:0"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm">Cancel</button></form>
</div>
