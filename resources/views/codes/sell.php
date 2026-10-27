<?php
/** @var array $code @var ?array $internal @var array $siblings @var array $origins @var array $history @var bool $canEdit */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><script src="/assets/js/codes.js"></script><?php $view->end();
$void = (int) $code['voided'] === 1;
$id = (int) $code['id'];
$productRef = $internal ? 'E' . (int) $internal['id'] : 'S' . $id;
$actions = '';
if (!$void && can('codes.assign_sell')) {
    $actions .= component('button', ['label' => 'Assign another sell number', 'variant' => 'primary', 'href' => is_easy() ? url('/wizard/quick-assign', ['product' => $productRef]) : url('/assign', ['product' => $productRef])]);
}
if (!$void && can('codes.void')) {
    $actions .= is_easy()
        ? component('button', ['label' => 'Void this sell number', 'variant' => 'danger-outline', 'href' => url('/wizard/void-code', ['type' => 'sell', 'id' => $id])])
        : component('void-button', ['action' => '/codes/s/' . $id . '/void', 'label' => 'Void this sell number', 'title' => 'Void ' . $code['display'] . '?', 'body' => 'The number stays in the logs, greyed out, and is never reused. Anyone looking it up will see it was voided and why.', 'confirm_label' => 'Yes, void it']);
}
?>
<?= component('page-header', [
    'breadcrumbs' => [['Code lookup', '/codes'], [$code['prefix_code'] . ' log', '/codes/logs/' . $code['prefix_code']], [$code['display'], null]],
    'title' => $code['display'],
    'badge' => ($void ? component('badge', ['label' => 'Voided', 'color' => 'red']) : '') . ($code['source'] === 'manual' ? ' ' . component('badge', ['label' => 'Entered by hand', 'color' => 'amber']) : '') . ((int) $code['prefix_retired'] === 1 ? ' ' . component('badge', ['label' => 'Retired prefix', 'color' => 'gray']) : ''),
    'lede' => 'Sell number · ' . $code['prefix_code'] . ' — ' . $code['prefix_label'] . ($code['suffix_code'] ? ' · ' . $code['suffix_code'] . ' suffix' : '') . (setting('sell_number_is_iqc_standard') === '1' ? ' · also the iQC standard name' : ''),
    'actions' => $actions,
]) ?>

<?php if ($void): ?>
  <?= component('callout', ['type' => 'danger', 'title' => 'This sell number was voided' . ($code['voided_at'] ? ' on ' . fmt_datetime($code['voided_at']) : '') . ($code['voided_by_name'] ? ' by ' . $code['voided_by_name'] : ''), 'body' => $code['void_reason'] ?: 'No reason was recorded.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="code-page">
  <div class="stack">
    <div class="card"><div class="card-body">
      <div class="code-hero<?= $void ? ' is-void' : '' ?>" style="margin-bottom:var(--s-4)">
        <span class="big-code"><?= e($code['display']) ?></span>
        <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($code['display']) ?>">Copy</button>
        <?php if ($code['legacy_text'] && $code['legacy_text'] !== $code['display']): ?><span class="muted small">written as "<?= e($code['legacy_text']) ?>" on the old sheet</span><?php endif; ?>
      </div>
      <?= $view->partial('codes/_description', ['code' => $code, 'action' => '/codes/s/' . $id . '/description', 'canEdit' => $canEdit && !$void]) ?>
      <hr style="margin:var(--s-5) 0;border:0;border-top:1px solid var(--border)">
      <?= component('kv', ['rows' => [
          ['Customer', $code['customer'] ?? ($internal['customer'] ?? '')],
          ['Date assigned', fmt_date($code['date_assigned'])],
          ['Assigned by', $code['assigned_by_name'] ?? ''],
          ['Reason', $code['reason'] ? (\Chroma\Domain\Codes\CodeAssigner::REASONS[$code['reason']] ?? $code['reason']) . ($code['reason_text'] ? ' — ' . $code['reason_text'] : '') : null],
          ['Legacy internal code', $code['legacy_internal_code']],
          ['Source', \Chroma\Domain\Codes\CodeSearch::sourceLabel($code['source'])],
          ['Last changed', fmt_datetime($code['updated_at'])],
      ]]) ?>
    </div></div>

    <div class="card" x-data="{ linking: <?= isset(form_errors()['internal']) ? 'true' : 'false' ?> }">
      <div class="card-head">
        <h2>E number (formula)</h2>
        <?php if (can('codes.link') && !$void): ?>
          <?php if (is_easy()): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e(url('/wizard/link-codes', ['sell_id' => $id])) ?>"><?= $internal ? 'Move to another E number' : 'Link to an E number' ?></a>
          <?php else: ?>
            <button type="button" class="btn btn-secondary btn-sm" @click="linking = !linking" x-text="linking ? 'Cancel' : '<?= $internal ? 'Move to another E number' : 'Link to an E number' ?>'"></button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($internal): $iv = (int) $internal['voided'] === 1; ?>
          <div class="flex" style="gap:var(--s-3);align-items:center;flex-wrap:wrap">
            <?= component('code-pill', ['code' => $internal['display'], 'href' => '/codes/e/' . (int) $internal['id'], 'void' => $iv]) ?>
            <span><?= e($internal['description']) ?></span>
            <?php if ($iv): ?><?= component('badge', ['label' => 'Voided', 'color' => 'red']) ?><?php endif; ?>
            <?php if ((int) $internal['resale'] === 1): ?><?= component('badge', ['label' => 'Resale', 'color' => 'purple']) ?><?php endif; ?>
          </div>
          <?php if ($siblings): ?>
            <p class="muted small" style="margin:var(--s-4) 0 var(--s-2)">Other sell numbers of the same formula:</p>
            <div class="flex flex-wrap" style="gap:var(--s-2)">
              <?php foreach ($siblings as $s): ?><?= component('code-pill', ['code' => $s['display'], 'href' => '/codes/s/' . (int) $s['id'], 'void' => (int) $s['voided'] === 1]) ?><?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="muted small mb-0" style="margin-top:var(--s-3)">This is the only sell number for <?= e($internal['display']) ?>.</p>
          <?php endif; ?>
        <?php else: ?>
          <p class="muted mb-0">No E number yet. The code is reserved; the formula has not been given its internal number<?= can('codes.link') ? ', or the link was never recorded on the old sheets. Link it here when you know the E number' : '' ?>.</p>
        <?php endif; ?>
        <?php if (can('codes.link') && !$void && is_expert()): ?>
        <form method="post" action="/codes/s/<?= $id ?>/link" x-show="linking" x-cloak style="margin-top:var(--s-4)">
          <?= csrf_field() ?>
          <div class="link-row">
            <?= component('form/field', ['name' => 'internal', 'type' => 'lookup', 'label' => 'E number', 'lookup' => '/api/codes/search?q=', 'placeholder' => 'E number, or another sell number of the product', 'help' => 'Typing one of the product\'s other sell numbers links to that product\'s E number.']) ?>
            <button type="submit" class="btn btn-primary">Link</button>
          </div>
        </form>
        <?php if ($internal): ?>
          <div style="margin-top:var(--s-3)"><?= component('confirm-form', ['action' => '/codes/s/' . $id . '/link', 'label' => 'Remove the link', 'variant' => 'danger', 'size' => 'sm', 'title' => 'Remove the link to ' . $internal['display'] . '?', 'body' => $code['display'] . ' will have no E number until it is linked again. The change is recorded in the history.', 'confirm_label' => 'Remove the link', 'fields' => ['unlink' => '1']]) ?></div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?= $view->partial('codes/_origins', ['origins' => $origins]) ?>
  </div>

  <div class="stack">
    <?= $view->partial('codes/_notes', ['code' => $code, 'action' => '/codes/s/' . $id . '/notes', 'canEdit' => $canEdit]) ?>
    <?= $view->partial('codes/_history', ['history' => $history]) ?>
  </div>
</div>
