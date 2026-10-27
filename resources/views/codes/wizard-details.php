<?php
/**
 * Generic wizard step with fields that can depend on another answer:
 * a field spec may carry 'show_when' (an Alpine expression over the
 * values of the step's fields, e.g. "reason === 'other'"). The form keeps
 * every field's current value in x-data so the expression can read it.
 * @var \Chroma\Http\Wizard\Step $step @var array $state
 */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$values = [];
foreach ($step->fields as $f) {
    $values[$f['name']] = (string) old($f['name'], $state[$f['name']] ?? ($f['default'] ?? ''));
}
?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'known' => $known ?? true, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate x-data="<?= json_attr($values) ?>">
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?php foreach ($step->fields as $i => $f): $when = $f['show_when'] ?? null; unset($f['show_when']);
      if ($when !== null && !empty($f['required'])) {
          // Required only while shown: a hidden required input would block the whole form in the browser.
          $f['required'] = false;
          $f['attrs'] = ($f['attrs'] ?? []) + [':required' => $when];
      } ?>
      <?php if ($when !== null): ?><div x-show="<?= e($when) ?>" x-cloak><?php endif; ?>
      <?= component('form/field', $f + ['value' => $values[$f['name']], 'autofocus' => $i === 0, 'size' => 'lg', 'lookup_label' => $state[$f['name'] . '_label'] ?? ($f['lookup_label'] ?? '')]) ?>
      <?php if ($when !== null): ?></div><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($step->help): ?><?= component('callout', ['type' => 'info', 'body' => $step->help]) ?><?php endif; ?>
    <div class="wizard-actions">
      <?php if ($n > 1): ?><button type="submit" name="_back" value="1" class="btn btn-secondary btn-lg" formnovalidate>‹ Back</button><?php else: ?><span></span><?php endif; ?>
      <button type="submit" class="btn btn-primary btn-lg"><?= e($step->nextLabel ?? ($n >= $total - 1 ? 'Review' : 'Next ›')) ?></button>
    </div>
  </form>
</div>
