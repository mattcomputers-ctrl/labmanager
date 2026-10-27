<?php
/** @var array<string,mixed> $prefix @var list<array<string,mixed>> $suffixes @var list<array<string,mixed>> $history @var bool $canSetNext */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/prefix-admin.css"><?php $view->end();
$p = $prefix;
$retired = (int) $p['retired'] === 1;
$href = '/admin/prefixes/' . (int) $p['id'];
$badges = '';
if ($retired) { $badges .= component('badge', ['label' => 'Retired', 'color' => 'gray']); }
if ($p['tag'] === 'inherited') { $badges .= ' ' . component('badge', ['label' => 'Inherited', 'color' => 'purple']); }
if ($p['tag'] === 'legacy') { $badges .= ' ' . component('badge', ['label' => 'Legacy', 'color' => 'amber']); }
$lockedReason = $p['code_count'] > 0
    ? sprintf('The letters cannot change: %s already carr%s them, and renaming would change those product codes. To use different letters, retire this prefix and add a new one.', plural($p['code_count'], 'sell number'), $p['code_count'] === 1 ? 'ies' : 'y')
    : '';
$codesKept = $p['code_count'] > 0
    ? sprintf('The %s already assigned stay exactly as they are, in lookup and in the logs.', plural($p['code_count'], 'code'))
    : 'No codes have been given out under it yet, so nothing else changes.';
$retireForm = $retired
    ? component('confirm-form', ['action' => $href . '/unretire', 'label' => 'Un-retire ' . $p['code'], 'variant' => 'primary', 'title' => 'Un-retire ' . $p['code'] . '?', 'body' => sprintf('%s goes back into the prefix list and new %s numbers can be given out again, starting at %s. This is logged.', $p['code'], $p['code'], $p['next_display']), 'confirm_label' => 'Yes, un-retire it'])
    : component('confirm-form', ['action' => $href . '/retire', 'label' => 'Retire ' . $p['code'], 'title' => 'Retire ' . $p['code'] . '?', 'body' => sprintf('No new %s numbers can be given out until it is un-retired. %s This is logged and can be undone with one click.', $p['code'], $codesKept), 'confirm_label' => 'Yes, retire it']);
$floor = ($p['last_used'] ?? 0) + 1;
?>
<?= $view->partial('admin/_nav', ['current' => '/admin/prefixes']) ?>
<?= component('page-header', [
    'title' => 'Prefix ' . $p['code'],
    'badge' => $badges,
    'lede' => $p['label'] . ($p['description'] !== '' ? ' — ' . $p['description'] : ''),
    'breadcrumbs' => [['Admin', '/admin'], ['Prefixes', '/admin/prefixes'], [$p['code'], null]],
    'actions' => component('button', ['label' => 'Back to prefixes', 'href' => '/admin/prefixes', 'variant' => 'secondary']),
]) ?>

<?php if ($retired): ?>
  <?= component('callout', ['type' => 'warning', 'title' => 'This prefix is retired', 'body' => sprintf('It does not appear when assigning codes. %s Retired on %s. Press "Un-retire" to make it available again.', $p['code_count'] > 0 ? sprintf('The %s under it are untouched and still show in lookup and the logs.', plural($p['code_count'], 'sell number')) : 'No sell numbers have been given out under it yet.', fmt_datetime($p['retired_at']) ?: 'an unknown date')]) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="grid grid-3 prefix-stats">
  <div class="card stat"><div class="stat-value code"><?= e($p['last_used_display'] !== '' ? $p['last_used_display'] : '—') ?></div><div class="stat-label">Last number used<?= $p['last_used_display'] === '' ? ' (none yet)' : '' ?></div></div>
  <div class="card stat"><div class="stat-value code"><?= e($retired ? '—' : $p['next_display']) ?></div><div class="stat-label"><?= $retired ? 'No next number while retired' : 'Next number to be given out' ?></div></div>
  <div class="card stat"><div class="stat-value"><?= number_format($p['code_count']) ?></div><div class="stat-label"><?= $p['code_count'] === 1 ? 'Sell number' : 'Sell numbers' ?> under <?= e($p['code']) ?><?php if ($p['code_count'] > 0 && can('codes.view')): ?> · <a href="<?= e('/codes/logs/' . $p['code']) ?>">see the log</a><?php endif; ?></div></div>
</div>

<div class="grid grid-2 prefix-edit-grid">
  <form method="post" action="<?= e($href) ?>" class="card" novalidate>
    <div class="card-head"><h2>Details</h2></div>
    <div class="card-body">
      <?= csrf_field() ?>
      <?= $view->partial('admin/prefixes/_fields', ['values' => $p, 'suffixes' => $suffixes, 'locked' => $p['code_count'] > 0, 'lockedReason' => $lockedReason, 'isNew' => false]) ?>
    </div>
    <div class="card-foot form-actions">
      <button type="submit" class="btn btn-primary">Save changes</button>
      <a class="btn btn-ghost" href="/admin/prefixes">Back to prefixes</a>
    </div>
  </form>

  <div class="stack">
    <div class="card">
      <div class="card-head"><h2>Next number</h2></div>
      <div class="card-body">
        <p class="muted mb-0">The counter stands at <strong class="code"><?= e($p['next_display']) ?></strong>. You can move it up to skip ahead to a new block<?= $p['last_used'] !== null && $p['next_number_effective'] > $floor ? sprintf(', or back down as far as %s (the number after the last one used)', e(\Chroma\Domain\Codes\CodeFormat::sell($p['code'], $floor, (int) $p['min_digits']))) : '' ?>. It can never be set to a number that has already been given out.</p>
        <?php if ($canSetNext): ?>
        <form method="post" action="<?= e($href . '/next-number') ?>" class="mt-4" novalidate>
          <?= csrf_field() ?>
          <?= component('form/field', ['name' => 'next_number', 'label' => 'Set the next number to', 'type' => 'number', 'tip' => \Chroma\Http\Tips::NEXT_NUMBER, 'min' => $floor, 'value' => old('next_number', (string) $p['next_number_effective']), 'prefix' => $p['code'], 'required' => true, 'attrs' => ['style' => 'max-width:200px'], 'help' => sprintf('Whole number only, at least %d. It is shown as %s.', $floor, \Chroma\Domain\Codes\CodeFormat::sell($p['code'], $p['next_number_effective'], (int) $p['min_digits']))]) ?>
          <button type="submit" class="btn btn-secondary">Move the counter</button>
        </form>
        <?php else: ?>
          <p class="help mt-4">Moving the counter needs the "Set next numbers" permission. Ask an administrator.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= $retired ? 'Bring it back' : 'Retire this prefix' ?></h2></div>
      <div class="card-body">
        <p class="muted"><?= $retired
            ? sprintf('Un-retiring puts %s back in the prefix list so new numbers can be given out again, continuing from %s.', e($p['code']), e($p['next_display']))
            : sprintf('Retiring removes %s from the Assign dropdowns. Existing codes stay in lookup and the logs, nothing is deleted, and you can un-retire it any time.', e($p['code'])) ?></p>
        <?= $retireForm ?>
      </div>
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
        <div class="card-body"><p class="muted mb-0">No changes have been made to this prefix since it was set up.</p></div>
      <?php endif; ?>
    </div>
  </div>
</div>
