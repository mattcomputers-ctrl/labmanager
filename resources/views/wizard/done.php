<?php /** @var \Chroma\Http\Wizard\WizardResult $result */ ?>
<div class="wizard">
  <div class="card wizard-card success-screen">
    <div class="success-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg></div>
    <h1><?= e($result->title) ?></h1>
    <?php if ($result->message): ?><p class="wizard-intro"><?= e($result->message) ?></p><?php endif; ?>
    <?php foreach ($result->codes as $c): ?>
      <?= component('code-display', ['code' => $c['code'], 'label' => $c['label'] ?? '', 'description' => $c['description'] ?? '']) ?>
    <?php endforeach; ?>
    <?php if (count($result->codes) > 1): ?>
      <p><button type="button" class="btn btn-secondary" data-copy="<?= e(implode("\n", array_map(fn($c) => $c['code'] . (isset($c['description']) ? "\t" . $c['description'] : ''), $result->codes))) ?>">Copy all codes as a block</button></p>
    <?php endif; ?>
    <?php if ($result->details): ?>
      <div style="text-align:left;max-width:520px;margin:var(--s-4) auto 0"><?= component('kv', ['rows' => $result->details]) ?></div>
    <?php endif; ?>
    <div class="next-actions">
      <?php foreach ($result->nextActions as $a): ?>
        <a class="btn <?= !empty($a['primary']) ? 'btn-primary btn-lg' : 'btn-secondary' ?>" href="<?= e($a['href']) ?>"><?= e($a['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
