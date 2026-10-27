<?php /** Quick assign wizard, step 1: which product. @var \Chroma\Http\Wizard\Step $step @var array $state */
$mode = old('product_mode', $state['product_mode'] ?? 'existing');
?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'known' => $known ?? true, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate x-data="<?= json_attr(['mode' => $mode]) ?>" @change="if ($event.target.name === 'product_mode') mode = $event.target.value">
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?= component('form/field', $step->fields[0] + ['value' => $mode, 'autofocus' => true, 'size' => 'lg']) ?>
    <div x-show="mode === 'existing'">
      <?= component('form/field', $step->fields[1] + ['value' => old('product', $state['product'] ?? ''), 'lookup_label' => $state['product_label'] ?? '', 'size' => 'lg', 'help' => 'Pick it from the list. Any of a product\'s sell numbers finds the same product.']) ?>
    </div>
    <?= component('callout', ['type' => 'info', 'body' => 'Not sure whether it already has a code? Pick "An existing product" and search for a word from the description. If nothing comes up, go back and choose "A brand-new product".']) ?>
    <div class="wizard-actions">
      <span></span>
      <button type="submit" class="btn btn-primary btn-lg">Next ›</button>
    </div>
  </form>
</div>
