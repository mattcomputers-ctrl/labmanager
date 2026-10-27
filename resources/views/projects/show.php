<?php
/** Project page. Expert: inline-editable header, inline new row and assign popovers. Easy: larger rows, wizards for every multi-step task. */
use Chroma\Domain\Projects\Projects;
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><script src="/assets/js/projects.js"></script><?php $view->end();
$pid = (int) $project['id'];
$isOpen = $project['status'] === Projects::STATUS_OPEN;
$statusColor = ['open' => 'blue', 'completed' => 'green', 'cancelled' => 'gray'];
$canEdit = can('projects.edit') && $isOpen;
$canItems = can('colors.edit') && $isOpen;
$singular = mb_strtolower((string) $project['item_label_singular']);
$plural = mb_strtolower((string) $project['item_label']);
$errors = form_errors();
$headerCfg = ['url' => '/projects/' . $pid, 'canEdit' => $canEdit, 'title' => (string) $project['title'], 'fields' => array_map(fn($f) => ['name' => $f['name'], 'value' => $f['value'], 'display' => $f['display']], $fields)];

$actions = '';
$actions .= component('button', ['label' => 'Print', 'variant' => 'secondary', 'href' => '/projects/' . $pid . '/print', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]);
if (is_easy() && $canEdit) {
    $actions .= ' ' . component('button', ['label' => 'Edit details', 'variant' => 'secondary', 'href' => '/projects/' . $pid . '/edit']);
}
if (can('projects.complete')) {
    if ($isOpen) {
        // One primary action per screen: while colors are still open, the next step is adding / assigning / matching them
        // (the Colors section holds that button), so "Mark completed" stays secondary until every item is done.
        $allDone = $project['progress']['total'] > 0 && $project['progress']['done'] >= $project['progress']['total'];
        $actions .= ' ' . component('confirm-form', ['action' => '/projects/' . $pid . '/complete', 'label' => 'Mark completed', 'variant' => $allDone ? 'primary' : 'secondary', 'title' => 'Mark project ' . $project['project_number'] . ' completed?', 'body' => 'This sets the completed date to today and moves the project out of the open list. ' . ($project['progress']['total'] > $project['progress']['done'] ? ($project['progress']['total'] - $project['progress']['done']) . ' ' . $plural . ' are not done yet; they stay as they are. ' : '') . 'You can reopen it later.', 'confirm_label' => 'Mark completed']);
        // Cancelling is rare and destructive-looking, so it lives under "More" instead of sitting red beside the everyday buttons.
        $actions .= ' <div class="menu more-menu" x-data="{ open: false }" @click.outside="open = false" @chroma:escape.document="open = false">'
            . '<button type="button" class="btn btn-ghost" @click="open = !open" :aria-expanded="open" aria-haspopup="true">More ▾</button>'
            . '<div class="menu-panel" x-show="open" x-cloak>'
            . component('confirm-form', ['action' => '/projects/' . $pid . '/cancel', 'label' => 'Cancel project', 'variant' => 'danger', 'class' => 'menu-item-form', 'title' => 'Cancel project ' . $project['project_number'] . '?', 'body' => 'The project is marked cancelled and leaves the open list. Codes already assigned stay assigned. Say why; the reason is kept with the project.', 'confirm_label' => 'Cancel the project', 'reason' => true, 'reason_placeholder' => 'e.g. Customer withdrew the request'])
            . '</div></div>';
    } else {
        $actions .= ' ' . component('confirm-form', ['action' => '/projects/' . $pid . '/reopen', 'label' => 'Reopen', 'variant' => 'primary', 'title' => 'Reopen project ' . $project['project_number'] . '?', 'body' => 'It goes back to the open list and the completed date is cleared.', 'confirm_label' => 'Reopen']);
    }
}
?>
<?= component('page-header', [
    'title' => 'Project ' . $project['project_number'],
    'badge' => component('badge', ['label' => $project['type_label'], 'color' => $project['type_color']]) . ' ' . component('badge', ['label' => Projects::STATUSES[$project['status']] ?? $project['status'], 'color' => $statusColor[$project['status']] ?? 'gray', 'dot' => $isOpen]) . ((int) $project['confidential'] ? ' ' . component('badge', ['label' => 'Confidential', 'color' => 'amber']) : ''),
    'breadcrumbs' => [['Projects', '/projects'], ['Project ' . $project['project_number'], null]],
    'actions' => $actions,
]) ?>

<?php if ($project['status'] === Projects::STATUS_CANCELLED): ?>
  <?= component('callout', ['type' => 'warning', 'title' => 'Cancelled', 'body' => ($project['cancelled_reason'] ?: 'No reason recorded.') . ($project['completed_by_name'] ? ' — ' . $project['completed_by_name'] . ', ' . fmt_date($project['date_completed']) : '')]) ?>
  <div class="mb-4"></div>
<?php elseif ($project['status'] === Projects::STATUS_COMPLETED): ?>
  <?= component('callout', ['type' => 'success', 'title' => 'Completed ' . fmt_date($project['date_completed']), 'body' => ($project['completed_by_name'] ? 'Marked by ' . $project['completed_by_name'] . '. ' : '') . 'Reopen it to make changes.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="project-head mb-5">
  <div class="card" x-data="projectHeader(<?= json_attr($headerCfg) ?>)">
    <div class="card-body">
      <h2 class="mt-0" style="font-size:var(--fs-xl)">
        <?php if (is_expert() && $canEdit): ?>
          <span data-edit="title">
            <template x-if="editing !== 'title'"><span class="hf-value editable" style="display:inline-flex" @click="start('title')" title="Click to edit"><span data-project-title x-text="display.title || <?= json_attr($project['title']) ?>"></span><svg class="pencil" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span></template>
            <template x-if="editing === 'title'"><span class="hf-edit" style="flex-wrap:wrap"><input type="text" x-model="values.title" maxlength="200" @keydown.enter.prevent="save('title')" @keydown.escape="cancel()" @input="error.title = ''" :aria-invalid="error.title ? 'true' : null" style="min-width:320px"><button type="button" class="btn btn-primary btn-sm" @click="save('title')" :disabled="busy">Save</button><button type="button" class="btn btn-ghost btn-sm" @click="cancel()">Cancel</button><span class="error-text" style="flex-basis:100%;font-size:var(--fs-s);font-weight:400" x-show="error.title" x-text="error.title" role="alert"></span></span></template>
          </span>
        <?php else: ?><span data-project-title><?= e($project['title']) ?></span><?php endif; ?>
      </h2>
      <div class="head-fields">
        <?php foreach ($fields as $f): $n = $f['name']; $wide = $f['type'] === 'textarea'; ?>
          <div class="hf <?= $wide ? 'wide' : '' ?>" data-edit="<?= e($n) ?>">
            <div class="hf-label"><?= e($f['label']) ?></div>
            <?php if (is_expert() && $canEdit): ?>
              <template x-if="editing !== '<?= e($n) ?>'">
                <div class="hf-value editable" @click="start('<?= e($n) ?>')" title="Click to edit" role="button" tabindex="0" @keydown.enter="start('<?= e($n) ?>')">
                  <span x-text="display['<?= e($n) ?>'] || ''"></span><span class="hf-empty" x-show="!display['<?= e($n) ?>']"><?= e(!empty($f['later']) ? 'Fill in once known' : 'Not set') ?></span>
                  <svg class="pencil" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </div>
              </template>
              <template x-if="editing === '<?= e($n) ?>'">
                <div class="hf-edit">
                  <?php if ($f['type'] === 'textarea'): ?>
                    <textarea x-model="values.<?= e($n) ?>" rows="4" style="flex:1" @keydown.ctrl.enter.prevent="save('<?= e($n) ?>')" @keydown.meta.enter.prevent="save('<?= e($n) ?>')" @keydown.escape="cancel()" @input="error['<?= e($n) ?>'] = ''" :aria-invalid="error['<?= e($n) ?>'] ? 'true' : null"></textarea>
                  <?php elseif ($f['type'] === 'select'): ?>
                    <select x-model="values.<?= e($n) ?>" @change="save('<?= e($n) ?>')" @keydown.escape="cancel()" style="flex:1"><option value="">Not set</option><?php foreach ($f['options'] as [$ov, $ol]): ?><option value="<?= e($ov) ?>"><?= e($ol) ?></option><?php endforeach; ?></select>
                  <?php elseif ($f['type'] === 'checkbox'): ?>
                    <label class="check" style="margin:0"><input type="checkbox" x-model="values.<?= e($n) ?>" :true-value="1" :false-value="0" @change="save('<?= e($n) ?>')"> <span class="check-text"><span>Yes</span></span></label>
                  <?php elseif ($f['type'] === 'lookup'): ?>
                    <?php /* Enter picks a match while matches are shown; only a name that matches nothing is saved as a new customer (the lookup says so under the field). */ ?>
                    <div style="flex:1" @lookup-chosen="lookupChosen($event, '<?= e($n) ?>')"<?= $n === 'customer_id' ? ' @lookup-new="$event.preventDefault(); lookupNew(\'' . e($n) . '\', $event.detail.text)"' : '' ?> @keydown.escape="cancel()">
                      <?= component('form/lookup', ['name' => $n . '_inline', 'id' => 'ie-' . $n, 'url' => $n === 'customer_id' ? '/api/customers?q=' : ($f['lookup'] ?? '/api/users?q='), 'value' => $f['value'], 'label_value' => $f['display'], 'allow_new' => $n === 'customer_id', 'new_hint' => 'when you press Enter', 'placeholder' => 'Start typing…']) ?>
                    </div>
                  <?php else: ?>
                    <input type="<?= e($f['type']) ?>" x-model="values.<?= e($n) ?>" @keydown.enter.prevent="save('<?= e($n) ?>')" @keydown.escape="cancel()" @input="error['<?= e($n) ?>'] = ''" :aria-invalid="error['<?= e($n) ?>'] ? 'true' : null" style="flex:1"<?= !empty($f['list']) ? ' list="substrate-list"' : '' ?>>
                  <?php endif; ?>
                  <?php if (!in_array($f['type'], ['select', 'checkbox', 'lookup'], true)): ?><button type="button" class="btn btn-primary btn-sm" @click="save('<?= e($n) ?>')" :disabled="busy">Save</button><?php endif; ?>
                  <button type="button" class="btn btn-ghost btn-sm" @click="cancel()" aria-label="Cancel">&times;</button>
                </div>
              </template>
              <p class="error-text" x-show="editing === '<?= e($n) ?>' && error['<?= e($n) ?>']" x-text="error['<?= e($n) ?>']" x-cloak role="alert"></p>
            <?php else: ?>
              <div class="hf-value"><?php if ($f['display'] !== ''): ?><?= nl2br(e($f['display'])) ?><?php else: ?><span class="hf-empty"><?= e(!empty($f['later']) ? 'Not yet' : 'Not set') ?></span><?php endif; ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ((int) $project['has_team'] === 1): ?>
          <div class="hf wide"><div class="hf-label">Team</div><div class="hf-value"><div class="team-list"><?php if ($team): foreach ($team as $u): ?><span class="badge badge-gray"><?= e($u['display_name'] ?: $u['username']) ?></span><?php endforeach; else: ?><span class="hf-empty">Just the lead</span><?php endif; ?><?php if ($canEdit): ?> <a class="small" href="/projects/<?= $pid ?>/edit#team">Change</a><?php endif; ?></div></div></div>
        <?php endif; ?>
      </div>
      <datalist id="substrate-list"><?php foreach ($substrates as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?></datalist>
      <?php if (is_expert() && $canEdit): ?><p class="help mt-3">Click any value to change it. Enter saves, Esc cancels (Ctrl+Enter in long text).</p><?php endif; ?>
    </div>
  </div>

  <div class="card project-side"><div class="card-body">
    <div>
      <div class="hf-label">Progress</div>
      <div class="progress progress-big" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $project['progress']['total'] ?>" aria-valuenow="<?= (int) $project['progress']['done'] ?>"><span style="width:<?= (int) $project['progress']['pct'] ?>%"></span></div>
      <div class="progress-label" style="font-size:var(--fs-m);font-weight:600;color:var(--text)"><?= e($project['progress']['label']) ?></div>
    </div>
    <?php if ((int) $project['has_stages'] === 1): ?>
    <div>
      <div class="hf-label">Stage</div>
      <?php if ($canEdit): ?>
      <form method="post" action="/projects/<?= $pid ?>/stage" class="stage-form">
        <?= csrf_field() ?>
        <label class="sr-only" for="stage-select">Stage</label>
        <select id="stage-select" name="stage_id" onchange="this.form.submit()">
          <option value="">Not set</option>
          <?php foreach ($type['stages'] ?? [] as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) $project['stage_id'] ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-secondary btn-sm">Set</button></noscript>
      </form>
      <?php else: ?><div><?= e($project['stage_label'] ?: 'Not set') ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
    <dl class="kv" style="margin:0">
      <dt>Tech / lead</dt><dd><?= e($project['tech_name'] ?: '—') ?></dd>
      <dt>Submitted</dt><dd><?= e(fmt_date($project['date_submitted']) ?: '—') ?></dd>
      <?php if ($project['target_date']): ?><dt>Target</dt><dd><?= e(fmt_date($project['target_date'])) ?></dd><?php endif; ?>
      <?php if ($project['date_completed']): ?><dt><?= $project['status'] === 'cancelled' ? 'Cancelled' : 'Completed' ?></dt><dd><?= e(fmt_date($project['date_completed'])) ?></dd><?php endif; ?>
      <dt>Created</dt><dd><?= e(fmt_datetime($project['created_at'])) ?><?= $project['created_by_name'] ? ' by ' . e($project['created_by_name']) : '' ?></dd>
    </dl>
  </div></div>
</div>

<?= $view->partial('projects/_items', compact('project', 'items', 'statuses', 'type', 'canItems', 'isOpen', 'singular', 'plural', 'iqcHint', 'prefixes', 'prefixSuffixes', 'inkSeries', 'coatings', 'substrates', 'users', 'kinds', 'maxItems', 'errors')) ?>

<div class="home-grid mt-5">
  <div class="stack">
    <?= $view->partial('projects/_activity', compact('project', 'activity', 'canEdit', 'items', 'errors')) ?>
  </div>
  <div class="stack">
    <?= $view->partial('projects/_tasks', compact('project', 'tasks', 'canEdit', 'users', 'errors')) ?>
    <?= $view->partial('projects/_attachments', compact('project', 'attachments', 'canEdit', 'items', 'maxUploadMb', 'errors')) ?>
  </div>
</div>
