<?= component('page-header', ['title' => 'Admin', 'lede' => 'Settings and lists that shape how Chroma works. Every change here is logged.']) ?>
<div class="quick-actions">
  <?php foreach ($areas as [$label, $href, $perm, $desc]): ?>
    <a class="quick-action" href="<?= e($href) ?>"><span class="qa-title"><?= e($label) ?></span><span class="qa-desc"><?= e($desc) ?></span></a>
  <?php endforeach; ?>
</div>
