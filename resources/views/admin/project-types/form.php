<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/lists']) ?>
<?= component('page-header', [
    'title' => 'Add a project type',
    'lede' => 'A new kind of work for the lab. Stages and item statuses are added on the next screen.',
    'breadcrumbs' => [['Admin', '/admin'], ['Lists', '/admin/lists'], ['Project types', '/admin/project-types'], ['New', null]],
]) ?>
<form method="post" action="/admin/project-types">
  <?= csrf_field() ?>
  <div class="card mb-4"><div class="card-body">
    <?= $view->partial('admin/project-types/_type-fields', ['type' => null, 'catalogue' => $catalogue, 'colors' => $colors]) ?>
  </div></div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Create the project type</button>
    <a class="btn btn-ghost" href="/admin/project-types">Cancel</a>
  </div>
</form>
