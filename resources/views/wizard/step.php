<?php /** @var \Chroma\Http\Wizard\Step $step */ ?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'known' => $known ?? true, 'wizard' => $wizard]) ?>
  <?php
  // The primary button says what it will do. When the step has an "add another?" radio, the label follows the answer
  // (Next: color 2 › / Review ›) so the user is never promised the summary and shown another color instead.
  // It only says "Review" on the last step when no further step can appear ($known: the answers so far fix the count).
  $by = $step->nextLabelBy;
  $byField = $by['field'] ?? null;
  $byNow = $byField !== null ? (string) old($byField, $state[$byField] ?? '') : '';
  if ($byField !== null && $byNow === '') { foreach ($step->fields as $f) { if ($f['name'] === $byField) { $byNow = (string) ($f['default'] ?? ''); } } }
  $plainLabel = $step->nextLabel ?? ($n >= $total - 1 && ($known ?? true) ? 'Review ›' : 'Next ›');
  ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate<?= $byField !== null ? ' x-data="' . json_attr(['v' => $byNow, 'labels' => $by['labels'], 'plain' => $plainLabel]) . '" @change="if ($event.target.name === ' . json_attr($byField) . ') v = $event.target.value"' : '' ?>>
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
      <button type="submit" class="btn btn-primary btn-lg"<?= $byField !== null ? ' x-text="labels[v] || plain"' : '' ?>><?= e($byField !== null ? ($by['labels'][$byNow] ?? $plainLabel) : $plainLabel) ?></button>
    </div>
  </form>
</div>
