<?php
/** Props: page, per_page, total, base (path), query (array of current query params) */
$pages = max(1, (int) ceil($total / max(1, $per_page)));
$query = $query ?? [];
$link = fn(int $p) => url($base, ['page' => $p] + $query);
$from = $total === 0 ? 0 : ($page - 1) * $per_page + 1;
$to = min($total, $page * $per_page);
?>
<nav class="pagination" aria-label="Pages">
  <div>Showing <?= number_format($from) ?>–<?= number_format($to) ?> of <?= number_format($total) ?></div>
  <?php if ($pages > 1): ?>
  <div class="pages">
    <?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>" rel="prev">‹ Previous</a><?php endif; ?>
    <?php
    $window = [];
    foreach ([1, 2, $page - 1, $page, $page + 1, $pages - 1, $pages] as $p) { if ($p >= 1 && $p <= $pages) { $window[$p] = true; } }
    ksort($window);
    $last = 0;
    foreach (array_keys($window) as $p):
        if ($p - $last > 1): ?><span style="padding:0 6px">…</span><?php endif;
        $last = $p;
        if ($p === $page): ?><span class="cur" aria-current="page"><?= $p ?></span><?php else: ?><a href="<?= e($link($p)) ?>"><?= $p ?></a><?php endif;
    endforeach; ?>
    <?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>" rel="next">Next ›</a><?php endif; ?>
  </div>
  <?php endif; ?>
</nav>
