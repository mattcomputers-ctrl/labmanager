<div class="grid" style="grid-template-columns: 240px 1fr; gap: var(--s-8)">
  <nav aria-label="Help pages" class="stack-s">
    <h3>Help</h3>
    <?php foreach ($pages as $s => $t): ?><a href="/help<?= $s === 'index' ? '' : '/' . e($s) ?>" class="<?= $s === $slug ? 'code-pill' : '' ?>" style="display:block;padding:6px 0"><?= e($t) ?></a><?php endforeach; ?>
  </nav>
  <article class="card"><div class="card-body" style="max-width:76ch"><?= $html ?></div></article>
</div>
<style>@media (max-width: 800px) { .grid[style*="240px"] { grid-template-columns: 1fr !important; } }</style>
