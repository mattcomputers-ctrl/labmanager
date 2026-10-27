<?php
/** Custom wizard step: tick the items that need codes (Easy batch-assign wizard). */
/** @var \Chroma\Http\Wizard\Step $step */
$projects = new \Chroma\Domain\Projects\Projects($app->db, $app->audit, $app->settings);
$project = $projects->get((int) $state['project_id']);
$candidates = array_values(array_filter($projects->items()->forProject((int) $project['id']), fn($i) => $i['internal_code_id'] === null || $i['sell_number_id'] === null));
$picked = array_map('intval', (array) old('items', $state['items'] ?? []));
$errors = form_errors();
?>
<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><?php $view->end(); ?>
<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'wizard' => $wizard]) ?>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/step/<?= (int) $n ?>" class="card wizard-card" novalidate x-data="{ picked: <?= json_attr(array_map('strval', $picked)) ?> }">
    <?= csrf_field() ?>
    <?php if (!empty($state['_edit'])): ?><input type="hidden" name="_jump" value="review"><?php endif; ?>
    <h1><?= e($step->title) ?></h1>
    <?php if ($step->intro): ?><p class="wizard-intro"><?= e($step->intro) ?></p><?php endif; ?>
    <?php if (isset($errors['items'])): ?><p class="error-text" role="alert"><?= e($errors['items']) ?></p><?php endif; ?>
    <?php if (!$candidates): ?>
      <?= component('callout', ['type' => 'info', 'body' => 'Every ' . mb_strtolower((string) $project['item_label_singular']) . ' on this project already has both codes.']) ?>
    <?php else: ?>
    <p><a href="#" class="small" @click.prevent="picked = <?= json_attr(array_map(fn($i) => (string) $i['id'], $candidates)) ?>">Select all</a> · <a href="#" class="small" @click.prevent="picked = []">Select none</a></p>
    <div class="choice-list check-list">
      <?php foreach ($candidates as $it): ?>
        <label class="choice" for="bi-<?= (int) $it['id'] ?>">
          <input type="checkbox" id="bi-<?= (int) $it['id'] ?>" name="items[]" value="<?= (int) $it['id'] ?>" x-model="picked">
          <span><span class="choice-title"><?= (int) $it['sequence'] ?>. <?= e($it['description']) ?></span><br><span class="choice-desc"><?= e(implode(' · ', array_filter([$it['internal_display'] ? 'has ' . $it['internal_display'] : 'no E#', $it['sell_display'] ? 'has ' . $it['sell_display'] : 'no sell number']))) ?></span></span>
        </label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="wizard-actions">
      <span></span>
      <button type="submit" class="btn btn-primary btn-lg" :disabled="!picked.length">Next ›</button>
    </div>
  </form>
</div>
