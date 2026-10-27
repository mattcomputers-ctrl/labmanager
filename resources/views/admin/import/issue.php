<?= $view->partial('admin/_nav', ['current' => '/admin/import']) ?>
<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/import.css"><?php $view->end(); ?>
<?php $raw = $issue['raw']; $open = $issue['status'] === 'open'; $ctx = !empty($importId) ? ['import_id' => (int) $importId] : []; $act = fn(string $what) => url('/admin/import/issues/' . (int) $issue['id'] . '/' . $what, $ctx); ?>
<?= component('page-header', [
    'title' => $issue['type_label'],
    'badge' => $open ? component('badge', ['label' => 'Open', 'color' => 'amber', 'dot' => true]) : component('badge', ['label' => ucfirst((string) $issue['resolution']), 'color' => 'green']),
    'breadcrumbs' => [['Admin', '/admin'], ['Import legacy codes', '/admin/import'], ['Import issues', url('/admin/import/issues', $ctx)], ['#' . (int) $issue['id'], null]],
]) ?>

<?= component('callout', ['type' => $open ? 'warning' : 'success', 'title' => $open ? 'What happened' : 'Resolved', 'body' => $open ? $issue['message'] : (ucfirst((string) $issue['resolution']) . ' by ' . ($issue['resolved_by_name'] ?? '') . ' on ' . fmt_datetime($issue['resolved_at']) . ($issue['resolution_note'] ? "\n" . $issue['resolution_note'] : '') . "\n\nOriginal issue: " . $issue['message'])]) ?>

<div class="grid grid-2 mt-6">
  <div class="card"><div class="card-body">
    <h3>The row in the sheet</h3>
    <?= component('kv', ['rows' => [
        ['Sheet', $issue['source_file'] . ($raw['file_name'] ?? '' ? ' (' . $raw['file_name'] . ')' : '') . ', row ' . (int) $issue['source_row']],
        ['Code as written', $raw['sell'] ?? ''],
        ['Internal code', $raw['internal'] ?? ''],
        ['Description', $raw['description'] ?? ''],
        ['Original customer', $raw['customer'] ?? ''],
        ['Date', ($raw['date'] ?? null) ? fmt_date($raw['date']) : ($raw['date_raw'] ?? '')],
        ['Resale', $raw['resale'] ?? ''],
        ['Vendor', trim(($raw['vendor_name'] ?? '') . ' ' . ($raw['vendor_code'] ?? ''))],
        ['Handschy number', $raw['handschy'] ?? ''],
        ['Comments', $raw['comments'] ?? ''],
        ['Kept', !empty($raw['kept_code']) ? (isset($raw['kept_row']) && $raw['kept_row'] !== null
            ? ($raw['kept_where'] ?? '') . ' row ' . (int) $raw['kept_row'] . ' holds ' . $raw['kept_code'] . (!empty($raw['kept_internal']) ? ' (' . $raw['kept_internal'] . ')' : '')
            : 'Chroma already holds ' . $raw['kept_code'] . (!empty($raw['kept_internal']) ? ', linked to ' . $raw['kept_internal'] : '')) : ''],
    ]]) ?>
    <?php if (!empty($raw['internal']) && \Chroma\Domain\Codes\CodeFormat::looksInternal((string) $raw['internal'])): ?>
      <p class="small mb-0"><a href="<?= e(url('/codes', ['q' => $raw['internal']])) ?>">Look up <?= e($raw['internal']) ?></a></p>
    <?php endif; ?>
  </div></div>

  <?php if ($open): ?>
  <div class="stack">
    <?php if (can('codes.manual_entry')): ?>
    <div class="card"><div class="card-body">
      <h3>Enter the corrected code</h3>
      <p class="small muted">If the sheet has a typo, type the code it should have been. Chroma creates it with this row's description, customer, date and E number link, and checks it is not already used.</p>
      <form method="post" action="<?= e($act('correct')) ?>">
        <?= csrf_field() ?>
        <?= component('form/field', ['name' => 'code', 'label' => 'Corrected code', 'required' => true, 'placeholder' => 'e.g. Y1501 or E8102', 'code' => true, 'autofocus' => true, 'attrs' => ['autocapitalize' => 'characters', 'maxlength' => 30], 'help' => 'A sell number (prefix, number, optional suffix) or an E number.']) ?>
        <?= component('form/field', ['name' => 'note', 'label' => 'Note', 'optional_text' => true, 'placeholder' => 'Why this code, if it helps the next person', 'attrs' => ['maxlength' => 300]]) ?>
        <div class="form-actions"><button class="btn btn-primary<?= is_easy() ? ' btn-lg' : '' ?>" type="submit">Save the corrected code</button></div>
      </form>
    </div></div>
    <?php else: ?>
      <?= component('callout', ['type' => 'info', 'body' => 'Entering a specific code by hand needs the "Enter a specific code by hand" permission. You can still merge or discard this row.']) ?>
    <?php endif; ?>

    <?php if ($target): ?>
    <div class="card"><div class="card-body">
      <h3>Merge into <?= e($target['display']) ?></h3>
      <p class="small muted">Use this when the row is a genuine duplicate of <?= e($target['display']) ?> (“<?= e(mb_substr($target['description'], 0, 80)) ?>”). Its description and comments are added to that code's notes, and a missing E number link is filled in. Nothing is overwritten.</p>
      <form method="post" action="<?= e($act('merge')) ?>">
        <?= csrf_field() ?>
        <?= component('form/field', ['name' => 'note', 'label' => 'Note', 'optional_text' => true, 'placeholder' => 'e.g. relabel for Evergreen', 'attrs' => ['maxlength' => 300]]) ?>
        <div class="form-actions"><button class="btn btn-secondary" type="submit">Merge into <?= e($target['display']) ?></button></div>
      </form>
    </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
      <h3>Leave this row out</h3>
      <p class="small muted">For junk rows and anything that should not become a code. The row stays here, marked discarded with your reason, so nobody wonders about it later.</p>
      <?= component('confirm-reason-form', ['action' => $act('discard'), 'label' => 'Discard this row', 'title' => 'Leave this row out of Chroma?', 'body' => 'No code will be created for it. The issue stays in the list as discarded with your reason, and you can reopen it later if needed.', 'confirm_label' => 'Yes, discard it', 'placeholder' => 'Why? e.g. not a product code']) ?>
    </div></div>
  </div>
  <?php else: ?>
  <div class="card"><div class="card-body">
    <h3>Changed your mind?</h3>
    <p class="small muted">Reopening puts the issue back on the open list. It does not undo any code that was created or merged.</p>
    <form method="post" action="/admin/import/issues/<?= (int) $issue['id'] ?>/reopen"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Reopen this issue</button></form>
  </div></div>
  <?php endif; ?>
</div>
<p class="mt-6"><a href="<?= e(url('/admin/import/issues', $ctx)) ?>">‹ Back to the issues list</a></p>
