<?php
/** Void wizard opened for a code that is already voided: say so, offer the way back. @var \Chroma\Http\Wizard\Step $step @var array $state */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$back = $wizard->cancelUrl($state);
?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'known' => $known ?? true, 'wizard' => $wizard]) ?>
  <div class="card wizard-card">
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?= component('callout', ['type' => 'info', 'title' => 'Nothing to do', 'body' => $step->help ?? 'This code is already voided. Voiding it again would change nothing.']) ?>
    <div class="wizard-actions">
      <span></span>
      <a class="btn btn-primary btn-lg" href="<?= e($back) ?>">Back to <?= e((string) ($state['code'] ?? 'the code')) ?></a>
    </div>
  </div>
</div>
