<?php /** @var \Chroma\Http\Wizard\Step $step */ ?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate>
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?php foreach ($step->fields as $i => $f): ?>
      <?= component('form/field', $f + ['value' => old($f['name'], $state[$f['name']] ?? ($f['default'] ?? null)), 'autofocus' => $i === 0, 'size' => 'lg', 'lookup_label' => $state[$f['name'] . '_label'] ?? ($f['lookup_label'] ?? '')]) ?>
    <?php endforeach; ?>
    <?php if ($step->help): ?><?= component('callout', ['type' => 'info', 'body' => $step->help]) ?><?php endif; ?>
    <div class="wizard-actions">
      <?php if ($n > 1): ?><button type="submit" name="_back" value="1" class="btn btn-secondary btn-lg" formnovalidate>‹ Back</button><?php else: ?><span></span><?php endif; ?>
      <button type="submit" class="btn btn-primary btn-lg"><?= e($step->nextLabel ?? ($n >= $total - 1 ? 'Review' : 'Next ›')) ?></button>
    </div>
  </form>
</div>
