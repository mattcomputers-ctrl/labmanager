<?php /** History list from the audit log. @var array $history */ ?>
<div class="card">
  <div class="card-head"><h2>History</h2></div>
  <div class="card-body">
    <?php if (!$history): ?>
      <p class="muted mb-0">Nothing recorded yet. Assignments, voids, links and edits made in Chroma show up here with who did them and when.</p>
    <?php else: ?>
    <ul class="history">
      <?php foreach ($history as $h): $color = match (true) { str_starts_with((string) $h['action'], 'code.void') => 'red', $h['action'] === 'code.link' => 'blue', str_starts_with((string) $h['action'], 'code.manual') => 'amber', default => 'green' }; ?>
        <li>
          <div class="h-head"><?= component('badge', ['label' => $h['label'], 'color' => $color]) ?><span class="h-meta"><?= e(fmt_datetime($h['created_at'])) ?><?= $h['user_name'] ? ' · ' . e($h['user_name']) : '' ?></span></div>
          <?php if ($h['note']): ?><div class="h-note"><?= e($h['note']) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
