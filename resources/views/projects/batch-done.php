<?php /** Summary after a batch assignment: every code just assigned, copyable as a block. */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><?php $view->end();
$pid = (int) $project['id'];
$block = implode("\n", array_map(fn($a) => $a['code'] . "\t" . $a['description'], $assigned));
?>
<?= component('page-header', ['title' => count($assigned) . ' code' . (count($assigned) === 1 ? '' : 's') . ' assigned', 'breadcrumbs' => [['Projects', '/projects'], ['Project ' . $project['project_number'], '/projects/' . $pid . '#items'], ['Codes assigned', null]], 'actions' => component('button', ['label' => 'Back to the project', 'variant' => 'primary', 'href' => '/projects/' . $pid . '#items'])]) ?>
<div class="page-narrow stack">
  <?php if ($errors): ?>
    <?= component('callout', ['type' => 'warning', 'title' => count($errors) . ' could not be assigned', 'body' => implode("\n", array_map(fn($e) => $e['description'] . ': ' . $e['message'], $errors))]) ?>
  <?php endif; ?>
  <?php if ($assigned): ?>
  <div class="card"><div class="card-body">
    <div class="table-wrap mb-3"><table class="table">
      <thead><tr><th>Code</th><th>Type</th><th><?= e($project['item_label_singular']) ?></th></tr></thead>
      <tbody><?php foreach ($assigned as $a): ?><tr><td><?= component('code-pill', ['code' => $a['code'], 'href' => ($a['type'] === 'sell' ? '/codes/s/' : '/codes/e/') . (int) $a['id'], 'copy' => true]) ?></td><td><?= $a['type'] === 'sell' ? 'Sell number' : 'E number' ?></td><td><?= e($a['description']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <div class="code-block"><?= e($block) ?></div>
    <p class="mt-3"><button type="button" class="btn btn-secondary" data-copy="<?= e($block) ?>">Copy all as a block</button> <span class="muted small">One code per line with its description, ready for the lab sheet or an email.</span></p>
  </div></div>
  <?php else: ?>
    <?= component('empty-state', ['title' => 'No codes were assigned', 'text' => 'See the messages above, fix the cause and try again.', 'action_href' => '/projects/' . $pid . '/batch', 'action_label' => 'Try again']) ?>
  <?php endif; ?>
</div>
