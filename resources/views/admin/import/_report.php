<?php
/**
 * The dry-run / commit report. Props: summary (ImportPlan::summary()), compact (bool: hide the issue list)
 * @var array<string,mixed> $summary
 */
$t = $summary['totals'] ?? [];
$a = $summary['applied'] ?? null;
$lists = $summary['lists'] ?? [];
$committed = !empty($summary['committed']);
$n = fn($k) => number_format((int) ($a[$k] ?? $t[$k] ?? 0));
$listItems = fn(string $k) => $lists[$k]['items'] ?? [];
$listTotal = fn(string $k) => (int) ($lists[$k]['total'] ?? 0);
$more = function (string $k) use ($lists) {
    $total = (int) ($lists[$k]['total'] ?? 0);
    $shown = count($lists[$k]['items'] ?? []);
    return $total > $shown ? '<li class="muted">… and ' . number_format($total - $shown) . ' more (the full list is in the text report)</li>' : '';
};
$section = function (string $title, string $key, string $explain, callable $render) use ($listItems, $listTotal, $more) {
    $total = $listTotal($key);
    $items = $listItems($key);
    echo '<details class="import-section"' . ($total > 0 && $total <= 40 ? ' open' : '') . '><summary>' . e($title) . ' ' . component('badge', ['label' => number_format($total), 'color' => $total ? 'amber' : 'gray']) . '</summary>';
    echo '<p class="help">' . e($explain) . '</p>';
    if (!$items) {
        echo '<p class="none">None.</p>';
    } else {
        echo '<ul class="import-list">';
        foreach ($items as $k => $it) {
            echo '<li>' . $render($it, $k) . '</li>';
        }
        echo $more($key) . '</ul>';
    }
    echo '</details>';
};
$where = fn($it) => '<span class="where">' . e(($it['file_label'] ?? '') . ' · row ' . ($it['row'] ?? '')) . '</span>';
?>
<h2 class="mb-2"><?= $committed ? 'What was imported' : 'What would happen' ?></h2>
<ul class="import-files">
  <?php foreach (($summary['files'] ?? []) as $f): $c = $f['counts'] ?? []; ?>
    <li class="import-file">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h10l6 6v10H4z"/><path d="M8 13h8M8 17h8M8 9h3"/></svg>
      <div>
        <div class="name"><?= e($f['name']) ?></div>
        <div class="meta">Recognised as <strong><?= e($f['label']) ?></strong> · sheet “<?= e($f['sheet']) ?>” · header on row <?= (int) $f['header_row'] ?> · <?= number_format((int) $f['row_count']) ?> rows with something on them</div>
        <div class="counts">
          <span><b><?= number_format((int) ($c['create_internal'] ?? 0)) ?></b> E numbers new</span>
          <span><b><?= number_format((int) ($c['update_internal'] ?? 0)) ?></b> E numbers filled in</span>
          <span><b><?= number_format((int) ($c['create_sell'] ?? 0)) ?></b> sell numbers new</span>
          <span><b><?= number_format((int) ($c['update_sell'] ?? 0)) ?></b> sell numbers filled in</span>
          <span><b><?= number_format((int) ($c['skip'] ?? 0)) ?></b> skipped (empty or pre-filled)</span>
          <?php if (!empty($c['repeat'])): ?><span><b><?= number_format((int) $c['repeat']) ?></b> already covered by another row</span><?php endif; ?>
          <span><b><?= number_format((int) ($c['issues'] ?? 0)) ?></b> issues</span>
          <?php if (!empty($c['issues_known'])): ?><span><b><?= number_format((int) $c['issues_known']) ?></b> already on the issues list</span><?php endif; ?>
        </div>
      </div>
    </li>
  <?php endforeach; ?>
</ul>

<div class="import-totals">
  <div class="card stat"><div class="stat-value"><?= $n('create_internal') ?></div><div class="stat-label">E numbers <?= $committed ? 'created' : 'to create' ?></div></div>
  <div class="card stat"><div class="stat-value"><?= $n('create_sell') ?></div><div class="stat-label">Sell numbers <?= $committed ? 'created' : 'to create' ?></div></div>
  <div class="card stat"><div class="stat-value"><?= number_format((int) ($a['update_internal'] ?? $t['update_internal'] ?? 0) + (int) ($a['update_sell'] ?? $t['update_sell'] ?? 0)) ?></div><div class="stat-label">Existing codes <?= $committed ? 'filled in' : 'to fill in' ?></div></div>
  <div class="card stat"><div class="stat-value"><?= number_format((int) ($t['skip'] ?? 0)) ?></div><div class="stat-label">Rows skipped</div></div>
  <div class="card stat<?= ($a['issues'] ?? $t['issues'] ?? 0) ? ' warn' : '' ?>"><div class="stat-value"><?= $n('issues') ?></div><div class="stat-label"><?= $committed ? 'New issues to resolve' : 'Issues to expect' ?><?= !empty($summary['issues_known']) ? '<br><span class="muted">+ ' . number_format((int) $summary['issues_known']) . ' already on the list</span>' : '' ?></div></div>
  <div class="card stat"><div class="stat-value"><?= number_format((int) ($a['customers'] ?? $t['new_customers'] ?? 0)) ?></div><div class="stat-label">New customers</div></div>
  <div class="card stat"><div class="stat-value"><?= number_format((int) ($a['prefixes'] ?? $t['new_prefixes'] ?? 0)) ?></div><div class="stat-label">New retired prefixes</div></div>
</div>

<?php if ($a && !empty($a['refused'])): ?>
  <?= component('callout', ['type' => 'warning', 'title' => number_format((int) $a['refused']) . ' rows were refused by the code rules', 'body' => 'They are listed under Import issues with the reason. Nothing else was affected.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>
<?php if ($a && !empty($a['counters'])): ?>
  <?= component('callout', ['type' => 'info', 'title' => 'Counters moved', 'body' => implode("\n", $a['counters'])]) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<?php
$section('Unknown prefixes', 'unknown_prefixes', 'These prefixes are not in Chroma yet. They will be added as retired prefixes: visible in lookup and the logs, but nobody can assign new numbers under them. You can un-retire one later under Admin → Prefixes.',
    fn($p, $code) => '<span class="code">' . e($code) . '</span> ' . e(number_format((int) $p['count']) . ' rows, e.g. ' . $p['sample']) . $where($p));
$section('UVV rows imported as UVVL', 'uvv_rows', empty($summary['options']['map_uvv'])
    ? 'The UVV → UVVL mapping is switched off for this run, so UVV rows go under a new retired prefix called UVV.'
    : 'The cross-reference spells the inherited UVVL prefix as UVV on these rows. They will be stored as UVVL codes and the original spelling is kept in the legacy text. Please confirm this below.',
    fn($r) => '<span class="code">' . e($r['original']) . '</span> → <span class="code">' . e($r['mapped']) . '</span> ' . e($r['internal'] ?? '') . $where($r));
$section('Duplicate sell numbers', 'duplicates', 'A sell number that is already taken — earlier in the same sheet, in another sheet, or by Chroma itself — where this row names a different E number (a typo or a genuine relabel). The first row, or Chroma\'s own row, is kept; each later row becomes an issue you can correct, merge or discard.',
    fn($d) => '<span class="code">' . e($d['code']) . '</span> ' . e(sprintf('%s; row %d (%s, "%s") not linked', \Chroma\Domain\Import\ImportPlan::keptText($d), $d['row'], $d['internal'] ?: 'no E#', mb_substr((string) $d['description'], 0, 50))) . $where($d));
$section('Codes that could not be read', 'unparseable', 'These are not codes Chroma understands (a prefix, a number and an optional suffix). The E number on the same row is still imported; the sell number goes to issues.',
    fn($u) => '<span class="code">' . e($u['text']) . '</span> ' . e($u['internal'] ?? '') . $where($u));
$section('Unknown suffixes', 'unknown_suffixes', 'The code ends in letters that are not a known suffix. Add the suffix under Admin → Suffixes and run the dry run again, or sort the row out from Import issues afterwards.',
    fn($u) => '<span class="code">' . e($u['text']) . '</span> suffix ' . e($u['suffix']) . $where($u));
$section('Legacy internal codes', 'legacy_internal', 'Internal codes that are not E numbers (from the company the UV series came from). They are kept as text on the sell number and never become E numbers.',
    fn($l) => '<span class="code">' . e($l['legacy']) . '</span> on ' . e($l['code'] ?: 'no sell number') . $where($l));
$section('Dates that could not be read', 'bad_dates', 'The date on these rows is not something Chroma can read, so the date is left blank. Everything else on the row is imported.',
    fn($d) => e('"' . $d['text'] . '"') . $where($d));
$section('Suffixes found per prefix', 'prefix_suffixes', 'Each prefix only allows the suffixes that were actually used on it in the sheets. These pairs will be allowed; you can change them under Admin → Prefixes.',
    fn($count, $pair) => '<span class="code">' . e(str_replace('|', ' + ', (string) $pair)) . '</span> ' . e(number_format((int) $count) . ' rows'));
$section('New customers', 'new_customers', 'Original Customer names that are not in Chroma yet. They are created so the codes keep their customer.',
    fn($c) => e((string) $c));
if (empty($compact)) {
    $issues = $summary['issues'] ?? [];
    $total = (int) ($summary['issues_total'] ?? count($issues));
    echo '<details class="import-section"' . ($total > 0 && $total <= 40 ? ' open' : '') . '><summary>Issues ' . component('badge', ['label' => number_format($total), 'color' => $total ? 'amber' : 'gray']) . '</summary>';
    $known = (int) ($summary['issues_known'] ?? 0);
    echo '<p class="help">' . ($committed ? 'These rows were written to Import issues, where you can correct, merge or discard each one.' : 'These rows will be sent to Import issues when you commit, so the import never stops on them.')
        . ($known ? ' ' . number_format($known) . ' of them ' . ($known === 1 ? 'is' : 'are') . ' already on the issues list from an earlier run (open or resolved) and will not be added again.' : '') . '</p>';
    if (!$issues) {
        echo '<p class="none">None.</p>';
    } else {
        echo '<ul class="import-list">';
        foreach ($issues as $i) {
            echo '<li>' . component('badge', ['label' => \Chroma\Domain\Import\ImportPlan::issueTypeLabel((string) $i['type']), 'color' => empty($i['known']) ? 'amber' : 'gray'])
                . (empty($i['known']) ? '' : ' ' . component('badge', ['label' => 'Already on the list', 'color' => 'gray']))
                . ' <span>' . e($i['message']) . '</span><span class="where">' . e($i['file_label'] . ' · row ' . $i['row']) . '</span></li>';
        }
        if ($total > count($issues)) {
            echo '<li class="muted">… and ' . number_format($total - count($issues)) . ' more</li>';
        }
        echo '</ul>';
    }
    echo '</details>';
}
?>
