<?php /** Move an item to another project (permission colors.edit, audited, confirmed here). */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><?php $view->end();
$pid = (int) $project['id']; $iid = (int) $item['id']; $errors = form_errors(); $singular = mb_strtolower((string) $project['item_label_singular']);
?>
<?= component('page-header', ['title' => 'Move "' . $item['description'] . '"', 'lede' => 'Use this when a ' . $singular . ' was filed in the wrong project. It keeps its codes, status, notes and attachments.', 'breadcrumbs' => [['Projects', '/projects'], ['Project ' . $project['project_number'], '/projects/' . $pid . '#items'], ['Move', null]]]) ?>
<form method="post" action="/projects/<?= $pid ?>/items/<?= $iid ?>/move" class="card page-narrow" x-data="{ chosen: null }" @lookup-chosen="chosen = $event.detail.item">
  <div class="card-body stack">
    <?= csrf_field() ?>
    <div class="field">
      <label class="label" for="f-to_project_id">Move to which project? <span class="req">*</span></label>
      <?= component('form/lookup', ['name' => 'to_project_id', 'id' => 'f-to_project_id', 'url' => '/api/projects/search?exclude=' . $pid . '&q=', 'placeholder' => 'Project number or words from the title', 'required' => true, 'size' => 'lg', 'autofocus' => true]) ?>
      <p class="help">Open projects are listed first. Type a number or a few words.</p>
      <?php if (isset($errors['to_project_id'])): ?><p class="error-text" role="alert"><?= e($errors['to_project_id']) ?></p><?php endif; ?>
    </div>
    <template x-if="chosen"><div><?= component('callout', ['type' => 'warning', 'html' => true, 'body' => 'Moving <strong>' . e($item['description']) . '</strong> from project ' . (int) $project['project_number'] . ' to <strong><span x-text="chosen.label"></span></strong>. This is written to the log. The codes stay on the ' . e($singular) . '.']) ?></div></template>
  </div>
  <div class="card-foot form-actions" style="padding:var(--s-3) var(--s-4);border-top:1px solid var(--border);display:flex;gap:var(--s-3)">
    <button type="submit" class="btn btn-primary btn-lg" :disabled="!chosen">Move it</button>
    <a class="btn btn-ghost btn-lg" href="/projects/<?= $pid ?>#items">Cancel</a>
  </div>
</form>
