<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/customers']) ?>
<?php $cid = (int) $customer['id']; ?>
<?= component('page-header', [
    'title' => 'Merge a duplicate into ' . $customer['name'],
    'lede' => 'Every project, E number and sell number on the duplicate moves to this customer. The duplicate is then deactivated, never deleted.',
    'breadcrumbs' => [['Admin', '/admin'], ['Customers', '/admin/customers'], [$customer['name'], '/admin/customers/' . $cid], ['Merge', null]],
]) ?>
<div class="page-narrow">
  <div class="card"><div class="card-body">
    <form method="post" action="/admin/customers/<?= $cid ?>/merge" x-data="{ dup: '<?= $duplicate ? (int) $duplicate['id'] : '' ?>', dupLabel: <?= json_attr($duplicate ? $duplicate['name'] : '') ?> }" @lookup-chosen.window="if ($event.detail.name === 'duplicate_id') { dup = $event.detail.item ? $event.detail.item.id : ''; dupLabel = $event.detail.item ? $event.detail.item.label : ''; }">
      <?= csrf_field() ?>
      <?= component('kv', ['rows' => [['Keep', $customer['name']], ['Projects on it now', (string) (int) $customer['project_count']], ['Codes on it now', (string) (int) $customer['code_count']]]]) ?>
      <div class="mt-4">
        <?= component('form/field', ['name' => 'duplicate_id', 'label' => 'Duplicate to merge into it', 'type' => 'lookup', 'lookup' => '/api/customers?q=', 'value' => $duplicate ? (int) $duplicate['id'] : old('duplicate_id'), 'lookup_label' => $duplicate ? $duplicate['name'] : '', 'placeholder' => 'Start typing the duplicate\'s name', 'required' => true, 'help' => 'Only active customers appear here. The one you pick is deactivated after the merge.']) ?>
      </div>
      <?php if ($similar): ?>
        <p class="small muted">Similar names: <?php foreach ($similar as $i => $s): ?><?= $i ? ', ' : '' ?><a href="<?= e(url('/admin/customers/' . $cid . '/merge', ['duplicate_id' => $s['id']])) ?>"><?= e($s['name']) ?></a><?php endforeach; ?></p>
      <?php endif; ?>
      <?php if ($duplicate): ?>
        <?= component('callout', ['type' => 'warning', 'title' => 'What will happen', 'body' => sprintf('%s and %s move from "%s" to "%s". "%s" is then deactivated. This is logged and can be undone only by moving things back by hand.', plural((int) $duplicate['project_count'], 'project'), plural((int) $duplicate['code_count'], 'code'), $duplicate['name'], $customer['name'], $duplicate['name'])]) ?>
      <?php endif; ?>
      <div class="form-actions stack-actions">
        <button class="btn btn-danger btn-wrap" type="submit" :disabled="!dup">Merge <span x-text="dupLabel ? '“' + dupLabel + '”' : 'the duplicate'"></span> into <?= e($customer['name']) ?></button>
        <a class="btn btn-ghost" href="/admin/customers/<?= $cid ?>">Cancel</a>
      </div>
    </form>
  </div></div>
</div>
