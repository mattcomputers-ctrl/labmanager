<?php /** @var array<string,mixed> $defaults @var list<array<string,mixed>> $suffixes */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/prefix-admin.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/prefixes']) ?>
<?= component('page-header', [
    'title' => 'Add a prefix',
    'lede' => 'A new family of sell numbers with its own counter. It is available for assigning as soon as you save.',
    'breadcrumbs' => [['Admin', '/admin'], ['Prefixes', '/admin/prefixes'], ['Add a prefix', null]],
]) ?>
<div class="page-narrow">
  <?php if (is_easy()): ?>
    <?= component('callout', ['type' => 'info', 'body' => 'Prefer one question at a time? The guided version asks the same things step by step.', 'html' => true, 'title' => '']) ?>
    <p class="mb-4"><a class="btn btn-secondary btn-sm" href="/wizard/add-prefix">Use the step-by-step version</a></p>
  <?php endif; ?>
  <form method="post" action="/admin/prefixes" class="card" novalidate>
    <div class="card-body">
      <?= csrf_field() ?>
      <?= $view->partial('admin/prefixes/_fields', ['values' => $defaults, 'suffixes' => $suffixes, 'locked' => false, 'isNew' => true]) ?>
    </div>
    <div class="card-foot form-actions">
      <button type="submit" class="btn btn-primary btn-lg">Add this prefix</button>
      <a class="btn btn-ghost" href="/admin/prefixes">Cancel</a>
    </div>
  </form>
</div>
