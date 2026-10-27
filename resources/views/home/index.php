<?php $u = $auth->user(); $first = explode(' ', trim((string) ($u['display_name'] ?: $u['username'])))[0]; ?>
<?= component('page-header', ['title' => 'Hello, ' . $first, 'lede' => is_easy() ? 'What would you like to do? Pick a task below and Chroma will walk you through it.' : 'Your open work and the latest codes.']) ?>

<section class="mb-6">
  <h2 class="sr-only">Start a task</h2>
  <div class="quick-actions">
    <?php if (can('projects.create')): ?>
    <a class="quick-action" href="<?= is_easy() ? '/wizard/new-project' : '/projects/new' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      <span class="qa-title">Start a project</span><span class="qa-desc">A color match, customer issue, evaluation or R&D project.</span>
    </a>
    <?php endif; ?>
    <?php if (can('codes.assign_internal', 'codes.assign_sell')): ?>
    <a class="quick-action" href="<?= is_easy() ? '/wizard/quick-assign' : '/assign' ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-9M14 17H5M17 4v6M7 14v6"/></svg>
      <span class="qa-title">Assign a code</span><span class="qa-desc">An E# or sell number without a project, or a new sell number for an existing product.</span>
    </a>
    <?php endif; ?>
    <?php if (can('codes.view')): ?>
    <a class="quick-action" href="/codes">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <span class="qa-title">Look up a code</span><span class="qa-desc">Find any E#, sell number, customer or description.</span>
    </a>
    <?php endif; ?>
    <?php if (can('projects.view')): ?>
    <a class="quick-action" href="/projects">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18M3 12h18M3 17h12"/></svg>
      <span class="qa-title">Open projects</span><span class="qa-desc"><?= plural($stats['open'], 'project') ?> open in the lab right now.</span>
    </a>
    <?php endif; ?>
  </div>
</section>

<div class="home-grid">
  <div class="stack">
    <?php if (can('projects.view')): ?>
    <div class="card">
      <div class="card-head"><h2>Your open projects</h2><a href="/projects?mine=1" class="small">See all</a></div>
      <?php if ($myProjects): ?>
      <div class="table-wrap" style="border:0;box-shadow:none;border-radius:0">
        <table class="table home-table home-projects">
          <thead><tr><th>#</th><th>Project</th><th>Customer</th><th>Progress</th><th>Submitted</th></tr></thead>
          <tbody>
          <?php foreach ($myProjects as $p): ?>
            <tr class="clickable" data-href="/projects/<?= (int) $p['id'] ?>">
              <td class="num"><a class="row-link" href="/projects/<?= (int) $p['id'] ?>"><?= (int) $p['project_number'] ?></a></td>
              <td><?= component('badge', ['label' => $p['type_label'], 'color' => $p['type_color']]) ?> <?= e($p['title']) ?></td>
              <td class="home-secondary"><?= e($p['customer'] ?? '—') ?></td>
              <td style="min-width:140px"><?php $pct = $p['item_count'] ? (int) round($p['items_done'] / $p['item_count'] * 100) : 0; ?><div class="progress" title="<?= (int) $p['items_done'] ?> of <?= (int) $p['item_count'] ?> done"><span style="width:<?= $pct ?>%"></span></div><div class="progress-label"><?= (int) $p['items_done'] ?> of <?= (int) $p['item_count'] ?> done</div></td>
              <td class="nowrap home-secondary" data-label="Submitted"><?= e(fmt_date($p['date_submitted'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <?= component('empty-state', ['title' => 'Nothing assigned to you', 'text' => 'Projects you create or that are assigned to you will show up here.', 'action_href' => can('projects.create') ? (is_easy() ? '/wizard/new-project' : '/projects/new') : null, 'action_label' => 'Start a project']) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (can('codes.view')): ?>
    <div class="card">
      <div class="card-head"><h2>Latest sell numbers</h2><a href="/codes/logs" class="small">Code logs</a></div>
      <?php if ($recentCodes): ?>
      <div class="table-wrap" style="border:0;box-shadow:none;border-radius:0">
        <table class="table home-table home-codes">
          <thead><tr><th>Sell number</th><th>E#</th><th>Description</th><th>Customer</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recentCodes as $c): ?>
            <tr>
              <td><?= component('code-pill', ['code' => $c['sell_number'], 'href' => '/codes/s/' . (int) $c['sell_number_id']]) ?></td>
              <td><?= $c['internal_code'] ? component('code-pill', ['code' => $c['internal_code'], 'href' => '/codes/e/' . (int) $c['internal_code_id'], 'void' => (int) $c['internal_code_voided'] === 1]) : '<span class="muted">—</span>' ?></td>
              <td class="truncate" style="max-width:320px"><?= e($c['description']) ?></td>
              <td class="home-secondary"><?= e($c['customer'] ?? '') ?></td>
              <td class="nowrap home-secondary"><?= e(fmt_date($c['date_assigned'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <?= component('empty-state', ['title' => 'No codes yet', 'text' => 'Once the legacy spreadsheets are imported, or the first code is assigned, the newest sell numbers appear here.']) ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="stack">
    <div class="grid grid-2">
      <div class="card stat"><div class="stat-value"><?= (int) $stats['mine'] ?></div><div class="stat-label">Open projects assigned to you</div></div>
      <div class="card stat"><div class="stat-value"><?= (int) $stats['codes_month'] ?></div><div class="stat-label">Sell numbers this month</div></div>
    </div>
    <div class="card">
      <div class="card-head"><h2>Your open items</h2></div>
      <div class="card-body">
        <?php if ($tasks): ?>
        <ul class="list">
          <?php foreach ($tasks as $t): $overdue = $t['due_date'] && $t['due_date'] < today(); ?>
            <li>
              <form method="post" action="/projects/<?= (int) $t['project_id'] ?>/tasks/<?= (int) $t['id'] ?>/done" style="margin:0"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-icon btn-sm" aria-label="Mark done" title="Mark done"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg></button></form>
              <span class="grow"><a href="/projects/<?= (int) $t['project_id'] ?>"><?= e($t['title']) ?></a><br><span class="muted small">Project <?= (int) $t['project_number'] ?> · <?= e($t['project_title']) ?></span></span>
              <?php if ($t['due_date']): ?><span class="badge <?= $overdue ? 'badge-red' : 'badge-gray' ?>"><?= $overdue ? 'Overdue · ' : '' ?><?= e(fmt_date($t['due_date'])) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
          <p class="muted mb-0">No open tasks. Tasks you add on a project with you as the owner show up here.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="card"><div class="card-body">
      <h3>New here?</h3>
      <p class="muted"><?= is_easy() ? 'You are in Easy mode: every task is a short set of questions. Switch to Expert at the top for one-page forms.' : 'You are in Expert mode: everything on one page. Switch to Easy at the top for step-by-step guidance.' ?></p>
      <a class="btn btn-secondary" href="/help">Open the help pages</a>
    </div></div>
  </div>
</div>
