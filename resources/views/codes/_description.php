<?php /** Description with an inline editor. @var array $code @var string $action @var bool $canEdit */ ?>
<div class="desc-row" x-data="inlineEdit({ open: <?= isset(form_errors()['description']) ? 'true' : 'false' ?> })">
  <div class="desc-text" x-show="!open"><?= e($code['description'] ?: '(no description)') ?></div>
  <?php if ($canEdit): ?>
  <button type="button" class="btn btn-ghost btn-sm" x-show="!open" @click="toggle()">Change</button>
  <form method="post" action="<?= e($action) ?>" x-show="open" x-cloak style="flex:1 1 100%">
    <?= csrf_field() ?>
    <?= component('form/field', ['name' => 'description', 'label' => 'Description', 'value' => old('description', $code['description']), 'required' => true, 'attrs' => ['maxlength' => 500], 'help' => 'The change is recorded in the history.']) ?>
    <div class="flex" style="gap:var(--s-2);margin-top:var(--s-2)"><button type="submit" class="btn btn-primary btn-sm">Save description</button><button type="button" class="btn btn-secondary btn-sm" @click="toggle()">Cancel</button></div>
  </form>
  <?php endif; ?>
</div>
