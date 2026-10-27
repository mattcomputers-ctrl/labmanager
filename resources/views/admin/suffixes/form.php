<?php
/** @var array<string,mixed>|null $suffix @var list<array<string,mixed>> $history */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/prefix-admin.css"><?php $view->end();
$s = $suffix;
$isNew = $s === null;
$locked = !$isNew && $s['code_count'] > 0;
$action = $isNew ? '/admin/suffixes' : '/admin/suffixes/' . (int) $s['id'];
$title = $isNew ? 'Add a suffix' : 'Suffix ' . $s['code'];
?>
<?= $view->partial('admin/_nav', ['current' => '/admin/suffixes']) ?>
<?= component('page-header', [
    'title' => $title,
    'badge' => (!$isNew && (int) $s['active'] === 0) ? component('badge', ['label' => 'Off', 'color' => 'gray']) : '',
    'lede' => $isNew ? 'Letters that can go on the end of a sell number. After saving, tick the suffix on each prefix that may use it.' : $s['label'],
    'breadcrumbs' => [['Admin', '/admin'], ['Suffixes', '/admin/suffixes'], [$isNew ? 'Add a suffix' : $s['code'], null]],
    'actions' => component('button', ['label' => 'Back to suffixes', 'href' => '/admin/suffixes', 'variant' => 'secondary']),
]) ?>

<div class="grid grid-2 prefix-edit-grid">
  <form method="post" action="<?= e($action) ?>" class="card" novalidate>
    <div class="card-head"><h2>Details</h2></div>
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="form-grid">
        <?php if ($locked): ?>
          <div>
            <?= component('form/field', ['name' => 'code_display', 'label' => 'Suffix letters', 'value' => $s['code'], 'attrs' => ['disabled' => true, 'style' => 'max-width:200px'], 'code' => true, 'help' => sprintf('The letters cannot change: %s already end%s in %s, and renaming would change those product codes. To use different letters, switch this suffix off and add a new one.', plural($s['code_count'], 'sell number'), $s['code_count'] === 1 ? 's' : '', $s['code'])]) ?>
            <input type="hidden" name="code" value="<?= e($s['code']) ?>">
          </div>
        <?php else: ?>
          <?= component('form/field', ['name' => 'code', 'label' => 'Suffix letters', 'value' => old('code', $s['code'] ?? ''), 'required' => true, 'code' => true, 'placeholder' => 'UV', 'attrs' => ['maxlength' => \Chroma\Domain\Codes\PrefixAdmin::CODE_MAX, 'style' => 'text-transform:uppercase;max-width:200px', 'autocapitalize' => 'characters', 'spellcheck' => 'false'], 'help' => 'Letters only, up to 12. Made uppercase for you. Cannot be changed once codes end in it.', 'autofocus' => $isNew]) ?>
        <?php endif; ?>
        <?= component('form/field', ['name' => 'label', 'label' => 'Name', 'value' => old('label', $s['label'] ?? ''), 'required' => true, 'placeholder' => 'e.g. Energy-curable (UV) version', 'attrs' => ['maxlength' => 100], 'help' => 'What the suffix means, in a few words.', 'autofocus' => !$isNew]) ?>
        <?= component('form/field', ['name' => 'sort_order', 'label' => 'Position in lists', 'type' => 'number', 'min' => 0, 'value' => old('sort_order', $s['sort_order'] ?? '100'), 'optional_text' => true, 'attrs' => ['style' => 'max-width:160px'], 'help' => 'Smaller numbers come first in dropdowns.']) ?>
        <?= component('form/field', ['name' => 'active', 'label' => 'Switched on', 'type' => 'checkbox', 'value' => old('active', $isNew ? '1' : (string) $s['active']), 'help' => 'When off, new codes cannot use this suffix. Codes that already have it are untouched.']) ?>
      </div>
    </div>
    <div class="card-foot form-actions">
      <button type="submit" class="btn btn-primary<?= $isNew ? ' btn-lg' : '' ?>"><?= $isNew ? 'Add this suffix' : 'Save changes' ?></button>
      <a class="btn btn-ghost" href="/admin/suffixes">Cancel</a>
    </div>
  </form>

  <div class="stack">
    <div class="card">
      <div class="card-head"><h2>Prefixes that allow <?= e($isNew ? 'this suffix' : $s['code']) ?></h2></div>
      <div class="card-body">
        <?php if ($isNew): ?>
          <p class="muted mb-0">Once the suffix is saved, open each prefix under <a href="/admin/prefixes">Prefixes</a> and tick it in "Allowed suffixes".</p>
        <?php elseif (!$s['prefixes']): ?>
          <p class="muted mb-0">No prefix allows <?= e($s['code']) ?> yet, so nobody can choose it. Open a prefix under <a href="/admin/prefixes">Prefixes</a> and tick it in "Allowed suffixes".</p>
        <?php else: ?>
          <ul class="list">
            <?php foreach ($s['prefixes'] as $pf): ?>
              <li><span class="grow"><a class="code" href="/admin/prefixes/<?= (int) $pf['id'] ?>"><?= e($pf['code']) ?></a> <?= e($pf['label']) ?><?= (int) $pf['retired'] ? ' <span class="muted small">(retired)</span>' : '' ?></span><a class="btn btn-ghost btn-sm" href="/admin/prefixes/<?= (int) $pf['id'] ?>">Open</a></li>
            <?php endforeach; ?>
          </ul>
          <p class="help mt-4">Change this list on each prefix's page, under "Allowed suffixes".</p>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$isNew): ?>
    <div class="card">
      <div class="card-head"><h2>Usage</h2></div>
      <div class="card-body"><p class="muted mb-0"><?= $s['code_count'] > 0 ? sprintf('%s end in %s.', plural($s['code_count'], 'sell number'), e($s['code'])) : sprintf('No sell number ends in %s yet.', e($s['code'])) ?></p></div>
    </div>
    <div class="card">
      <div class="card-head"><h2>History</h2></div>
      <?php if ($history): ?>
      <ul class="list history-list">
        <?php foreach ($history as $h): ?>
          <li><span class="grow"><?= e($h['note'] ?: $h['action']) ?><br><span class="muted small"><?= e($h['user_name'] ?: 'Unknown') ?> · <?= e(fmt_datetime($h['created_at'])) ?></span></span></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
        <div class="card-body"><p class="muted mb-0">No changes have been made to this suffix since it was set up.</p></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
