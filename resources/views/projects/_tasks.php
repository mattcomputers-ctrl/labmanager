<?php /** Tasks / next actions. */ $pid = (int) $project['id']; $me = (int) $auth->id(); $open = array_filter($tasks, fn($t) => (int) $t['done'] === 0); ?>
<section id="tasks" class="card section-tabs">
  <div class="card-head"><h2>Tasks</h2><span class="muted small"><?= count($open) ?> open</span></div>
  <div class="card-body">
    <?php if ($tasks): ?>
    <ul class="list mb-4">
      <?php foreach ($tasks as $t): $done = (int) $t['done'] === 1; $overdue = !$done && $t['due_date'] && $t['due_date'] < today(); $mayTick = $canEdit || (int) $t['owner_user_id'] === $me; ?>
        <li class="task-row <?= $done ? 'done' : '' ?>">
          <?php if ($mayTick && $project['status'] === 'open'): ?>
          <form method="post" action="/projects/<?= $pid ?>/tasks/<?= (int) $t['id'] ?>/<?= $done ? 'undo' : 'done' ?>" style="margin:0"><?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-icon" aria-label="<?= $done ? 'Mark not done' : 'Mark done' ?>" title="<?= $done ? 'Mark not done' : 'Mark done' ?>">
              <?php if ($done): ?><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5"/></svg><?php else: ?><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/></svg><?php endif; ?>
            </button>
          </form>
          <?php else: ?><span style="width:44px;text-align:center"><?= $done ? '✓' : '○' ?></span><?php endif; ?>
          <span class="grow"><span class="task-title"><?= e($t['title']) ?></span><br><span class="task-meta"><?= e($t['owner_name'] ?: ($t['owner_username'] ?: 'No owner')) ?><?= $done && $t['done_at'] ? ' · done ' . e(fmt_date($t['done_at'])) : '' ?></span></span>
          <?php if ($t['due_date'] && !$done): ?><span class="badge <?= $overdue ? 'badge-red' : 'badge-gray' ?>"><?= $overdue ? 'Overdue · ' : '' ?><?= e(fmt_date($t['due_date'])) ?></span><?php endif; ?>
          <?php if ($canEdit): ?><?= component('confirm-form', ['action' => '/projects/' . $pid . '/tasks/' . (int) $t['id'] . '/delete', 'label' => '×', 'size' => 'sm', 'title' => 'Remove this task?', 'body' => '"' . $t['title'] . '" is removed from the project. This cannot be undone.', 'confirm_label' => 'Remove task']) ?><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
      <p class="muted">No tasks yet. Tasks are next actions with an owner and a due date; they show on the owner's home page so long-running work does not go quiet.</p>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" action="/projects/<?= $pid ?>/tasks" class="stack-s">
      <?= csrf_field() ?>
      <?= component('form/field', ['name' => 'title', 'label' => 'New task', 'placeholder' => 'e.g. Send drawdowns to the customer', 'attrs' => ['maxlength' => 255], 'error' => $errors['title'] ?? null]) ?>
      <div class="grid grid-2">
        <?= component('form/field', ['name' => 'owner_user_id', 'label' => 'Owner', 'type' => 'select', 'value' => old('owner_user_id', $project['assigned_user_id'] ?: $me), 'placeholder' => 'Nobody yet', 'options' => array_map(fn($u) => [(int) $u['id'], $u['display_name'] ?: $u['username']], $users)]) ?>
        <?= component('form/field', ['name' => 'due_date', 'label' => 'Due', 'type' => 'date', 'optional_text' => true, 'error' => $errors['due_date'] ?? null]) ?>
      </div>
      <div><button type="submit" class="btn btn-secondary">Add task</button></div>
    </form>
    <?php endif; ?>
  </div>
</section>
