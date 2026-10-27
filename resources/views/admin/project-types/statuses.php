<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/lists']) ?>
<?= component('page-header', [
    'title' => 'Shared item statuses',
    'lede' => 'The status list used by every project type that has not been given its own.',
    'breadcrumbs' => [['Admin', '/admin'], ['Lists', '/admin/lists'], ['Project types', '/admin/project-types'], ['Shared item statuses', null]],
    'actions' => can('codes.export') && $statuses ? component('button', ['label' => 'Export CSV', 'size' => 'sm', 'href' => '/admin/item-statuses?export=csv']) . ' ' . component('button', ['label' => 'Export Excel', 'size' => 'sm', 'href' => '/admin/item-statuses?export=xlsx']) : '',
]) ?>
<div class="card"><div class="card-body">
  <p class="muted small mb-4">Used by: <?= $typesUsing ? e(implode(', ', array_column($typesUsing, 'label'))) : 'no type at the moment (every type has its own list)' ?>.</p>
  <?= $view->partial('admin/project-types/_status-rows', ['statuses' => $statuses, 'colors' => $colors, 'addAction' => '/admin/item-statuses', 'canEdit' => true, 'editId' => $editStatus ?? null]) ?>
</div></div>
