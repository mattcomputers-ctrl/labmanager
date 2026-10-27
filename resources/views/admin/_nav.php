<?php /** Admin side navigation. Include at the top of every admin page: $view->partial('admin/_nav', ['current' => '/admin/prefixes']) */
$areas = \Chroma\Http\Controllers\Admin\AdminController::areas();
$current = $current ?? ($request->path ?? '');
?>
<div class="tabs" role="navigation" aria-label="Admin areas">
  <?php foreach ($areas as [$label, $href, $perm]): if (!can($perm)) continue; ?>
    <a href="<?= e($href) ?>" class="<?= str_starts_with($current, $href) ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
