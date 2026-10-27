<?php /** Where a code came from. @var array $origins */ ?>
<div class="card">
  <div class="card-head"><h2>Where it came from</h2></div>
  <div class="card-body">
    <?php if (!$origins): ?>
      <p class="muted mb-0">Not attached to a project. Codes assigned with Quick assign or entered by hand (resale items, relabels, legacy rows) show up this way.</p>
    <?php else: ?>
    <ul class="list">
      <?php foreach ($origins as $o): $hidden = (int) $o['confidential'] === 1 && !can('projects.view_confidential'); ?>
        <li><span class="grow">
          <?php if ($hidden): ?><strong>A confidential project</strong><?php else: ?>
          <a href="/projects/<?= (int) $o['project_id'] ?>"><strong>Project <?= (int) $o['project_number'] ?></strong><?= $o['project_title'] ? ' · ' . e($o['project_title']) : '' ?></a>
          <br><span class="muted small"><?= $o['project_customer'] ? e($o['project_customer']) . ' · ' : '' ?>item <?= (int) $o['sequence'] ?><?= $o['item_description'] ? ': ' . e($o['item_description']) : '' ?></span>
          <?php endif; ?>
        </span><?= component('badge', ['label' => ucfirst((string) $o['project_status']), 'color' => $o['project_status']]) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
