<?php /** Alpine modal shell. Props: show (alpine expr), title, body (html), foot (html), size (lg) */ ?>
<template x-teleport="body">
  <div class="modal-backdrop" x-show="<?= $show ?>" x-cloak @click.self="<?= $show ?> = false" @keydown.escape.window="<?= $show ?> = false" x-trap.noscroll="<?= $show ?>">
    <div class="modal <?= !empty($size) ? 'modal-' . e($size) : '' ?>" role="dialog" aria-modal="true">
      <div class="modal-head"><h2><?= e($title) ?></h2><button type="button" class="btn btn-ghost btn-icon" @click="<?= $show ?> = false" aria-label="Close">&times;</button></div>
      <div class="modal-body"><?= $body ?></div>
      <?php if (!empty($foot)): ?><div class="modal-foot"><?= $foot ?></div><?php endif; ?>
    </div>
  </div>
</template>
