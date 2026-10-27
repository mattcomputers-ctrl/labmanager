<?php
/**
 * "Which prefix?" screen shared by the quick-assign and assign-item-code wizards: everyday prefixes as cards with the next
 * free number, inherited/legacy ones folded away. The wizard provides prefixGroups() and prefixScreen($state)
 * (['default' => ?int, 'context' => ?['label','description','note']]).
 * @var \Chroma\Http\Wizard\Wizard $wizard @var \Chroma\Http\Wizard\Step $step @var array $state
 */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$groups = $wizard->prefixGroups();
$screen = $wizard->prefixScreen($state);
$context = $screen['context'] ?? null;
$errors = form_errors();
$chosen = (string) old('prefix_id', $state['prefix_id'] ?? '');
$default = $screen['default'] ?? null;
if ($chosen === '' && $default !== null) {
    $chosen = (string) $default;
}
$otherChosen = in_array((int) $chosen, array_map(fn($p) => $p['id'], $groups['other']), true);
$card = function (array $p) use ($chosen): string {
    $id = (int) $p['id'];
    return '<label class="choice" for="f-prefix-' . $id . '"><input type="radio" id="f-prefix-' . $id . '" name="prefix_id" value="' . $id . '"' . ((string) $id === $chosen ? ' checked' : '') . '>'
        . '<span><span class="choice-title">' . e($p['code']) . ' — ' . e($p['label']) . '<span class="nxt">next: ' . e($p['next']) . '</span></span>'
        . ($p['description'] ? '<br><span class="choice-desc">' . e($p['description']) . '</span>' : '') . '</span></label>';
};
?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'known' => $known ?? true, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate>
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?php if ($context): ?>
      <div class="product-pick" style="margin-bottom:var(--s-5)">
        <?= component('code-pill', ['code' => $context['label']]) ?>
        <span><?= e($context['description']) ?></span>
        <?php if ($default !== null && $chosen === (string) $default && !empty($context['note'])): ?><span class="muted small"><?= e($context['note']) ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="field prefix-choice">
      <span class="label" id="l-prefix">Prefix <span class="req" aria-hidden="true">*</span> <?= component('help-tip', ['text' => \Chroma\Http\Tips::PREFIX]) ?></span>
      <div class="choice-list" role="radiogroup" aria-labelledby="l-prefix">
        <?php foreach ($groups['everyday'] as $p): ?><?= $card($p) ?><?php endforeach; ?>
      </div>
      <?php if ($groups['other']): ?>
        <details class="prefix-more"<?= $otherChosen ? ' open' : '' ?>>
          <summary>Other and legacy prefixes (<?= count($groups['other']) ?>)</summary>
          <p class="help">Inherited and legacy series. Use these only when the product belongs to one of them.</p>
          <div class="choice-list" role="radiogroup" aria-label="Other and legacy prefixes">
            <?php foreach ($groups['other'] as $p): ?><?= $card($p) ?><?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
      <?php if (isset($errors['prefix_id'])): ?><p class="error-text" role="alert"><span><?= e($errors['prefix_id']) ?></span></p><?php endif; ?>
    </div>
    <div class="wizard-actions">
      <button type="submit" name="_back" value="1" class="btn btn-secondary btn-lg" formnovalidate>‹ Back</button>
      <button type="submit" class="btn btn-primary btn-lg"><?= e($step->nextLabel ?? ($n >= $total - 1 ? 'Review' : 'Next ›')) ?></button>
    </div>
  </form>
</div>
