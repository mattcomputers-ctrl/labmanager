<div class="wizard">
  <?= $view->partial('wizard/_progress', ['n' => $n, 'total' => $total, 'wizard' => $wizard]) ?>
  <div class="card wizard-card">
    <h1><?= e($wizard->reviewTitle()) ?></h1>
    <p class="wizard-intro">Nothing has been saved yet. Check the answers below, then press <strong><?= e($wizard->commitLabel()) ?></strong>.</p>
    <?php $errs = form_errors(); if ($errs): ?>
      <?= component('callout', ['type' => 'danger', 'title' => 'This could not be completed', 'body' => implode("\n", array_values($errs))]) ?>
      <div class="mb-4"></div>
    <?php endif; ?>
    <ul class="review-list">
      <?php foreach ($rows as $row): ?>
        <li>
          <span class="rl-label"><?= e($row['label']) ?></span>
          <span class="rl-value"><?= !empty($row['html']) ? $row['value'] : e($row['value'] === '' ? '—' : $row['value']) ?></span>
          <span><?php if (!empty($row['step'])): foreach ($steps as $i => $s): if ($s->key === $row['step']): ?><a class="btn btn-ghost btn-sm" href="/wizard/<?= e($wizard->key()) ?>/step/<?= $i + 1 ?>">Change</a><?php endif; endforeach; endif; ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($wizard->commitExplanation()): ?><p class="help"><?= e($wizard->commitExplanation()) ?></p><?php endif; ?>
    <form method="post" action="/wizard/<?= e($wizard->key()) ?>/commit" x-data="{ busy: false }" @submit="busy = true">
      <?= csrf_field() ?>
      <div class="wizard-actions">
        <a class="btn btn-secondary btn-lg" href="/wizard/<?= e($wizard->key()) ?>/step/<?= max(1, count($steps)) ?>">‹ Back</a>
        <button type="submit" class="btn btn-primary btn-lg" :disabled="busy"><span x-show="!busy"><?= e($wizard->commitLabel()) ?></span><span x-show="busy" x-cloak>Working…</span></button>
      </div>
    </form>
  </div>
</div>
