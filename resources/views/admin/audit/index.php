<?php
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/system-admin.css"><?php $view->end();
echo $view->partial('admin/_nav', ['current' => '/admin/system']);
$query = array_filter($filters, fn($v) => $v !== null && $v !== '');
$exportBtns = can('admin.system') && $total > 0 ? '<a class="btn btn-secondary btn-sm" href="' . e(url('/admin/audit', $query + ['export' => 'csv'])) . '">Export CSV</a> <a class="btn btn-secondary btn-sm" href="' . e(url('/admin/audit', $query + ['export' => 'xlsx'])) . '">Export Excel</a>' : '';
echo component('page-header', ['title' => 'Audit log', 'lede' => 'Who changed what, and when. Every code assignment, void, counter change and admin change is here with its before and after values.', 'actions' => $exportBtns]);
echo $view->partial('admin/system/_subnav', ['current' => '/admin/audit']);
$dateFmt = is_easy() ? 'M j, Y g:i a' : 'Y-m-d H:i:s';
?>
<?php if (!$hasAny): ?>
  <?= component('empty-state', ['title' => 'Nothing has been logged yet', 'text' => 'As soon as someone assigns a code, changes a setting or edits a project, it will appear here with the before and after values.', 'action_href' => '/admin', 'action_label' => 'Back to admin']) ?>
<?php else: ?>
<form method="get" action="/admin/audit" class="table-toolbar" role="search">
  <div class="filters">
    <label class="sr-only" for="fa-user">Who</label>
    <select id="fa-user" name="user" aria-label="Who"><option value="">Anyone</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $filters['user'] === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
    <select name="action" aria-label="Action"><option value="">Any action</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $filters['action'] === $a ? ' selected' : '' ?>><?= e(isset(\Chroma\Domain\Codes\CodeDetails::ACTIONS[$a]) ? \Chroma\Domain\Codes\CodeDetails::ACTIONS[$a] . ' (' . $a . ')' : $a) ?></option><?php endforeach; ?></select>
    <select name="table" aria-label="Table"><option value="">Any table</option><?php foreach ($tables as $t): ?><option value="<?= e($t) ?>"<?= $filters['table'] === $t ? ' selected' : '' ?>><?= e(str_replace('chroma_', '', $t)) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($filters['from']) ?>" aria-label="From date" title="From date">
    <input type="date" name="to" value="<?= e($filters['to']) ?>" aria-label="To date" title="To date">
    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Note or row number" aria-label="Search notes">
    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ($query): ?><a class="btn btn-ghost" href="/admin/audit">Clear</a><?php endif; ?>
  </div>
</form>
<?php if (!$rows): ?>
  <?= component('empty-state', ['title' => 'No entries match those filters', 'text' => 'Try a wider date range or clear the filters.', 'action_href' => '/admin/audit', 'action_label' => 'Clear filters']) ?>
<?php else: ?>
<div class="table-wrap audit-wrap"><table class="table audit-table <?= is_expert() ? 'table-dense' : '' ?>">
  <thead><tr><th>When</th><th>Who</th><th>What</th><?php if (is_expert()): ?><th>Table / row</th><th>IP</th><?php endif; ?><th>Change</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $changed = array_filter($r['diff'], fn($d) => $d['changed']); ?>
    <tr class="audit-row">
      <td class="nowrap audit-when" data-label="When"><?= e(fmt_datetime($r['created_at'], $dateFmt)) ?><?php if (is_easy()): ?><br><span class="muted small"><?= e(fmt_ago($r['created_at'])) ?></span><?php endif; ?></td>
      <td class="audit-who" data-label="Who"><?= e($r['user_name'] !== '' ? $r['user_name'] : 'system') ?></td>
      <td class="audit-what" data-label="What"><span class="audit-action" title="<?= e($r['action']) ?>"><?= e(\Chroma\Domain\Codes\CodeDetails::ACTIONS[$r['action']] ?? $r['action']) ?></span><?php if ($r['note'] !== ''): ?><br><span class="small"><?= e($r['note']) ?></span><?php endif; ?><?php if (is_easy() && $r['table_name'] !== ''): ?><br><span class="muted small"><?= e(str_replace('chroma_', '', $r['table_name'])) ?><?= $r['row_id'] !== null ? ' #' . (int) $r['row_id'] : '' ?></span><?php endif; ?></td>
      <?php if (is_expert()): ?><td class="nowrap audit-table-row" data-label="Table / row"><?= e(str_replace('chroma_', '', $r['table_name'])) ?><?= $r['row_id'] !== null ? ' #' . (int) $r['row_id'] : '' ?></td><td class="nowrap small muted audit-ip" data-label="IP"><?= e($r['ip']) ?></td><?php endif; ?>
      <td class="audit-change" data-label="Change">
        <?php if (!$r['diff']): ?><span class="muted">—</span>
        <?php else:
          $isCreate = $r['before_json'] === null && $r['after_json'] !== null;
          $show = array_slice(array_values($changed), 0, $isCreate ? 3 : 5);
          $rest = array_slice(array_values($changed), count($show));
          $same = array_values(array_filter($r['diff'], fn($d) => !$d['changed']));
        ?>
          <?php if ($show): ?>
          <table class="audit-diff"><thead><tr><th>Field</th><?php if (!$isCreate): ?><th>Before</th><?php endif; ?><th><?= $isCreate ? 'Value' : 'After' ?></th></tr></thead><tbody>
            <?php foreach ($show as $d): ?><tr class="changed"><th><?= e($d['field']) ?></th><?php if (!$isCreate): ?><td class="before"><?= e($d['before']) ?></td><?php endif; ?><td class="after"><?= e($d['after']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
          <?php endif; ?>
          <?php if ($rest || $same): ?>
          <details class="audit-detail"><summary><?= $isCreate ? 'Created with ' . count($changed) . ' values — show all' : ($rest ? count($rest) . ' more changed ' . (count($rest) === 1 ? 'field' : 'fields') . ($same ? ', ' . count($same) . ' unchanged' : '') : count($same) . ' unchanged ' . (count($same) === 1 ? 'field' : 'fields')) ?></summary>
            <table class="audit-diff"><tbody>
              <?php foreach ($rest as $d): ?><tr class="changed"><th><?= e($d['field']) ?></th><?php if (!$isCreate): ?><td class="before"><?= e($d['before']) ?></td><?php endif; ?><td class="after"><?= e($d['after']) ?></td></tr><?php endforeach; ?>
              <?php foreach ($same as $d): ?><tr class="same"><th><?= e($d['field']) ?></th><td colspan="2"><?= e($d['after'] !== '' ? $d['after'] : $d['before']) ?></td></tr><?php endforeach; ?>
            </tbody></table>
          </details>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/admin/audit', 'query' => $query]) ?>
<?php endif; endif; ?>
