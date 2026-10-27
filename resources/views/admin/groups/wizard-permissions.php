<?php /** Custom wizard step: permission tick boxes grouped by area. Same shell as wizard/step. @var \Chroma\Http\Wizard\Step $step */ ?>
<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate>
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?= $view->partial('admin/groups/_permissions', ['areas' => \Chroma\Domain\Users\GroupAdmin::catalogueByArea(), 'checked' => (array) ($state['permissions'] ?? [])]) ?>
    <?= component('callout', ['type' => 'info', 'body' => 'Not sure? Lab technicians usually need everything under Projects and Codes except "Void codes" and "Enter a specific code by hand". Sales staff usually only need "Look up codes" and "See projects".']) ?>
    <div class="wizard-actions">
      <?php if ($n > 1): ?><button type="submit" name="_back" value="1" class="btn btn-secondary btn-lg" formnovalidate>‹ Back</button><?php else: ?><span></span><?php endif; ?>
      <button type="submit" class="btn btn-primary btn-lg"><?= e($step->nextLabel ?? ($n >= $total - 1 ? 'Review' : 'Next ›')) ?></button>
    </div>
  </form>
</div>
