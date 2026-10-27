<?php /** Props: title, lede, actions (html), breadcrumbs (list of [label, href|null]), badge (html) */ ?>
<div class="page-header">
  <div>
    <?php if (!empty($breadcrumbs)): ?><div class="breadcrumbs"><?php foreach ($breadcrumbs as $i => [$bl, $bh]): ?><?= $i ? '<span>›</span>' : '' ?><?= $bh ? '<a href="' . e($bh) . '">' . e($bl) . '</a>' : '<span>' . e($bl) . '</span>' ?><?php endforeach; ?></div><?php endif; ?>
    <h1><?= e($title) ?> <?= $badge ?? '' ?></h1>
    <?php if (!empty($lede)): ?><p class="lede"><?= e($lede) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($actions)): ?><div class="page-actions"><?= $actions ?></div><?php endif; ?>
</div>
