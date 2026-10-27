<?php
/** @var array $code @var array $sells @var array $origins @var array $history @var ?string $sds_url @var bool $canEdit */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><script src="/assets/js/codes.js"></script><?php $view->end();
$void = (int) $code['voided'] === 1;
$id = (int) $code['id'];
$actions = '';
if ($sds_url) {
    $actions .= component('button', ['label' => 'Open SDS', 'href' => $sds_url, 'attrs' => ['target' => '_blank', 'rel' => 'noopener', 'title' => 'Opens the safety data sheet for ' . $code['display'] . ' in the SDS system']]);
}
if (!$void && can('codes.assign_sell')) {
    $actions .= component('button', ['label' => 'Assign a sell number', 'variant' => 'primary', 'href' => is_easy() ? url('/wizard/quick-assign', ['product' => 'E' . $id]) : url('/assign', ['product' => 'E' . $id])]);
}
if (!$void && can('codes.void')) {
    $actions .= is_easy()
        ? component('button', ['label' => 'Void this E number', 'variant' => 'danger-outline', 'href' => url('/wizard/void-code', ['type' => 'internal', 'id' => $id])])
        : component('void-button', ['action' => '/codes/e/' . $id . '/void', 'label' => 'Void this E number', 'title' => 'Void ' . $code['display'] . '?', 'body' => 'The number stays in the logs, greyed out, and is never reused. Its sell numbers are not voided; void those separately if they are wrong too.', 'confirm_label' => 'Yes, void it']);
}
?>
<?= component('page-header', [
    'breadcrumbs' => [['Code lookup', '/codes'], ['E numbers', '/codes/logs/E'], [$code['display'], null]],
    'title' => $code['display'],
    'badge' => ($void ? component('badge', ['label' => 'Voided', 'color' => 'red']) : '') . ((int) $code['resale'] === 1 ? ' ' . component('badge', ['label' => 'Resale', 'color' => 'purple']) : '') . ($code['source'] === 'manual' ? ' ' . component('badge', ['label' => 'Entered by hand', 'color' => 'amber']) : ''),
    'lede' => 'E number · formula code',
    'actions' => $actions,
]) ?>

<?php if ($void): ?>
  <?= component('callout', ['type' => 'danger', 'title' => 'This E number was voided' . ($code['voided_at'] ? ' on ' . fmt_datetime($code['voided_at']) : '') . ($code['voided_by_name'] ? ' by ' . $code['voided_by_name'] : ''), 'body' => $code['void_reason'] ?: 'No reason was recorded.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="code-page">
  <div class="stack">
    <div class="card"><div class="card-body">
      <div class="code-hero<?= $void ? ' is-void' : '' ?>" style="margin-bottom:var(--s-4)">
        <span class="big-code"><?= e($code['display']) ?></span>
        <button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($code['display']) ?>">Copy</button>
      </div>
      <?= $view->partial('codes/_description', ['code' => $code, 'action' => '/codes/e/' . $id . '/description', 'canEdit' => $canEdit && !$void]) ?>
      <hr style="margin:var(--s-5) 0;border:0;border-top:1px solid var(--border)">
      <?= component('kv', ['rows' => [
          ['Customer', $code['customer'] ?? ''],
          ['Date assigned', fmt_date($code['date_assigned'])],
          ['Assigned by', $code['assigned_by_name'] ?? ''],
          ['Version', $code['version'] !== '' ? $code['version'] : null],
          ['Resale', (int) $code['resale'] === 1 ? 'Yes — purchased for resale' : null],
          ['Vendor', trim((string) $code['vendor_name'] . ' ' . (string) $code['vendor_code'])],
          ['Handschy number', $code['legacy_handschy_number']],
          ['Legacy internal code', $code['legacy_internal_code']],
          ['Source', \Chroma\Domain\Codes\CodeSearch::sourceLabel($code['source'])],
          ['Last changed', fmt_datetime($code['updated_at'])],
      ]]) ?>
    </div></div>

    <div class="card">
      <div class="card-head"><h2>Sell numbers for this formula</h2><?php if (!$void && can('codes.assign_sell')): ?><a class="btn btn-secondary btn-sm" href="<?= e(is_easy() ? url('/wizard/quick-assign', ['product' => 'E' . $id]) : url('/assign', ['product' => 'E' . $id])) ?>">Add another</a><?php endif; ?></div>
      <?php if (!$sells): ?>
        <div class="card-body"><p class="muted mb-0">No sell number yet. The formula exists but nothing has been coded for sale under it.<?= can('codes.link') ? ' If a sell number already exists on paper, open it and link it here.' : '' ?></p></div>
      <?php else: ?>
      <div class="table-wrap" style="border:0;box-shadow:none;border-radius:0">
        <table class="table">
          <thead><tr><th>Sell number</th><th>Description</th><th>Date</th><th>Reason</th><th>Customer</th></tr></thead>
          <tbody>
          <?php foreach ($sells as $s): $sv = (int) $s['voided'] === 1; ?>
            <tr class="clickable<?= $sv ? ' void-row' : '' ?>" data-href="/codes/s/<?= (int) $s['id'] ?>">
              <td class="nowrap"><?= component('code-pill', ['code' => $s['display'], 'href' => '/codes/s/' . (int) $s['id'], 'void' => $sv]) ?><?= $sv ? ' ' . component('badge', ['label' => 'Voided', 'color' => 'red']) : '' ?></td>
              <td><?= e($s['description']) ?></td>
              <td class="nowrap"><?= e(fmt_date($s['date_assigned'])) ?></td>
              <td class="small"><?= e($s['reason'] ? (\Chroma\Domain\Codes\CodeAssigner::REASONS[$s['reason']] ?? $s['reason']) : '') ?></td>
              <td><?= e($s['customer'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?= $view->partial('codes/_origins', ['origins' => $origins]) ?>
  </div>

  <div class="stack">
    <?= $view->partial('codes/_notes', ['code' => $code, 'action' => '/codes/e/' . $id . '/notes', 'canEdit' => $canEdit]) ?>
    <?= $view->partial('codes/_history', ['history' => $history]) ?>
  </div>
</div>
