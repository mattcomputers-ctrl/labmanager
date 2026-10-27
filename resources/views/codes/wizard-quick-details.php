<?php
/** Quick assign wizard: description and reason (the generic codes/wizard-details is the manual-entry one). @var \Chroma\Http\Wizard\QuickAssignWizard $wizard @var \Chroma\Http\Wizard\Step $step @var array $state */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$product = $wizard->productFor($state);
$want = $wizard->wantFor($state, $product);
$errors = form_errors();
$val = fn(string $k, $default = '') => (string) old($k, $state[$k] ?? $default);
$description = $val('description') !== '' ? $val('description') : ($product['description'] ?? '');
$err = fn(string $k) => isset($errors[$k]) ? '<p class="error-text" role="alert"><span>' . e($errors[$k]) . '</span></p>' : '';
?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'known' => $known ?? true, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate x-data="<?= json_attr(['reason' => $val('reason')]) ?>">
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?php if ($want !== 'internal'): ?>
    <div class="field">
      <label class="label" for="f-reason">Reason <?= $product ? '<span class="req" aria-hidden="true">*</span>' : '<span class="optional">(optional)</span>' ?> <?= component('help-tip', ['text' => \Chroma\Http\Tips::REASON]) ?></label>
      <select id="f-reason" name="reason" x-model="reason" class="input-lg" data-autofocus<?= isset($errors['reason']) ? ' aria-invalid="true"' : '' ?>>
        <option value="">Pick a reason…</option>
        <?php foreach (\Chroma\Domain\Codes\CodeAssigner::REASONS as $k => $label): ?><option value="<?= e($k) ?>"<?= $val('reason') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select>
      <p class="help">Why this product needs a <?= $product ? 'new' : '' ?> sell number. It is kept with the code.</p>
      <?= $err('reason') ?>
    </div>
    <div class="field" x-show="reason === 'other'" x-cloak>
      <label class="label" for="f-reason-text">Say why <span class="req" aria-hidden="true">*</span></label>
      <input type="text" id="f-reason-text" name="reason_text" value="<?= e($val('reason_text')) ?>" maxlength="500" class="input input-lg" placeholder="A few words on why this code is needed">
      <?= $err('reason_text') ?>
    </div>
    <?php endif; ?>
    <div class="field">
      <label class="label" for="f-description">Description <span class="req" aria-hidden="true">*</span></label>
      <input type="text" id="f-description" name="description" value="<?= e($description) ?>" maxlength="500" class="input input-lg" placeholder="What the product is"<?= $want === 'internal' ? ' data-autofocus' : '' ?><?= isset($errors['description']) ? ' aria-invalid="true"' : '' ?>>
      <p class="help"><?= $product ? 'Filled in from ' . e($product['label']) . '. Change it if the new code means something different, for example the UV version.' : 'Short and searchable: colour, use, customer if relevant.' ?></p>
      <?= $err('description') ?>
    </div>
    <div class="wizard-actions">
      <button type="submit" name="_back" value="1" class="btn btn-secondary btn-lg" formnovalidate>‹ Back</button>
      <button type="submit" class="btn btn-primary btn-lg"><?= e($step->nextLabel ?? ($n >= $total - 1 ? 'Review' : 'Next ›')) ?></button>
    </div>
  </form>
</div>
