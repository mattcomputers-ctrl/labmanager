<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/customers']) ?>
<?php $isNew = $customer === null; $cid = $isNew ? 0 : (int) $customer['id']; $sources = ['manual' => 'Added by hand', 'import' => 'From the spreadsheet import', 'cms' => 'From the CMS', 'system' => 'Built in']; ?>
<?= component('page-header', [
    'title' => $isNew ? 'Add a customer' : $customer['name'],
    'lede' => $isNew ? 'Only for customers that are not in the CMS yet. The CMS sync adds the rest automatically.' : ($sources[$customer['source']] ?? ucfirst((string) $customer['source'])) . ' · ' . plural((int) $customer['project_count'], 'project') . ' · ' . plural((int) $customer['code_count'], 'code'),
    'breadcrumbs' => [['Admin', '/admin'], ['Customers', '/admin/customers'], [$isNew ? 'New' : $customer['name'], null]],
    'badge' => $isNew ? '' : ((int) $customer['active'] ? component('badge', ['label' => 'Active', 'color' => 'green']) : component('badge', ['label' => 'Deactivated', 'color' => 'gray'])),
]) ?>

<div class="grid <?= $isNew ? '' : 'grid-2' ?> user-grid">
  <div class="card"><div class="card-body">
    <form method="post" action="<?= $isNew ? '/admin/customers' : '/admin/customers/' . $cid ?>">
      <?= csrf_field() ?>
      <?= component('form/field', ['name' => 'name', 'label' => 'Customer name', 'required' => true, 'value' => old('name', $isNew ? '' : $customer['name']), 'placeholder' => 'As it should appear on projects', 'autofocus' => true, 'attrs' => ['maxlength' => 200], 'help' => $isNew ? 'Chroma ignores case and punctuation when checking for duplicates.' : null]) ?>
      <?php if (!$isNew): ?>
        <div class="form-grid">
          <?= component('form/field', ['name' => 'source_display', 'label' => 'Source', 'value' => $sources[$customer['source']] ?? $customer['source'], 'attrs' => ['disabled' => true]]) ?>
          <?= component('form/field', ['name' => 'cms_display', 'label' => 'CMS customer id', 'value' => $customer['cms_customer_id'] ?? '', 'placeholder' => 'Not linked to the CMS', 'attrs' => ['disabled' => true], 'help' => 'Set by the CMS sync; cannot be edited here.']) ?>
        </div>
      <?php endif; ?>
      <?= component('form/field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'optional_text' => true, 'rows' => 3, 'value' => old('notes', $isNew ? '' : $customer['notes'])]) ?>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Add the customer' : 'Save changes' ?></button>
        <a class="btn btn-ghost" href="/admin/customers">Cancel</a>
      </div>
    </form>
  </div></div>

  <?php if (!$isNew): ?>
  <div class="stack">
    <div class="card"><div class="card-body">
      <h3 class="mt-0">Duplicates</h3>
      <?php if (!empty($similar)): ?>
        <p class="muted small">These look similar. If one is the same company, merge it into this one; every project and code moves across.</p>
        <ul class="list">
          <?php foreach ($similar as $s): ?>
            <li><span class="grow"><a href="/admin/customers/<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a> <span class="muted small"><?= e($sources[$s['source']] ?? $s['source']) ?><?= (int) $s['active'] ? '' : ' · deactivated' ?></span></span>
              <?php if ((int) $customer['active']): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/customers/' . $cid . '/merge', ['duplicate_id' => $s['id']])) ?>">Merge into this</a><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted small">No similar names found.</p>
      <?php endif; ?>
      <?php if ((int) $customer['active']): ?><p class="mt-4"><a class="btn btn-secondary" href="/admin/customers/<?= $cid ?>/merge">Merge another customer into this one</a></p><?php endif; ?>
    </div></div>
    <div class="card"><div class="card-body">
      <h3 class="mt-0"><?= (int) $customer['active'] ? 'Deactivate' : 'Reactivate' ?></h3>
      <?php if ((int) $customer['active']): ?>
        <p class="muted small">Deactivated customers disappear from pickers. Existing projects and codes keep the name.</p>
        <?= component('confirm-form', ['action' => '/admin/customers/' . $cid . '/deactivate', 'label' => 'Deactivate customer', 'title' => 'Deactivate ' . $customer['name'] . '?', 'body' => 'It will not be offered when starting a project or assigning a code. ' . plural((int) $customer['project_count'], 'existing project') . ' and ' . plural((int) $customer['code_count'], 'code') . ' keep the name.', 'confirm_label' => 'Yes, deactivate']) ?>
      <?php else: ?>
        <form method="post" action="/admin/customers/<?= $cid ?>/reactivate"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Reactivate customer</button></form>
      <?php endif; ?>
    </div></div>
    <?php if (!empty($history)): ?>
    <div class="card"><div class="card-head"><h3>History</h3></div><div class="card-body">
      <ul class="feed small">
        <?php foreach ($history as $h): ?>
          <li><span class="muted nowrap"><?= e(fmt_datetime($h['created_at'], 'M j')) ?></span><span><?= e($h['note'] ?: $h['action']) ?> <span class="muted">· <?= e($h['user_name'] ?: 'system') ?></span></span></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
