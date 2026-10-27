<?php /** Activity feed: notes (markdown + @mentions) and automatic entries. */ $pid = (int) $project['id']; ?>
<section id="activity" class="card section-tabs">
  <div class="card-head"><h2>Activity</h2><span class="muted small"><?= count($activity) ?> entries</span></div>
  <div class="card-body">
    <?php if ($canEdit): ?>
    <form method="post" action="/projects/<?= $pid ?>/notes" class="note-form mb-4" x-data="{ body: '' }">
      <?= csrf_field() ?>
      <?= component('form/field', ['name' => 'body', 'label' => 'Add a note', 'type' => 'textarea', 'rows' => 3, 'placeholder' => 'What happened? Markdown works (**bold**, lists) and @username mentions someone.', 'attrs' => ['x-model' => 'body', '@keydown.ctrl.enter' => '$el.form.requestSubmit()', '@keydown.meta.enter' => '$el.form.requestSubmit()'], 'error' => $errors['body'] ?? null]) ?>
      <?php if (count($items) > 0): ?>
      <div class="field"><label class="label" for="note-item">About a particular <?= e(mb_strtolower((string) $project['item_label_singular'])) ?> <span class="optional">(optional)</span></label>
        <select id="note-item" name="item_id"><option value="">The whole project</option><?php foreach ($items as $it): ?><option value="<?= (int) $it['id'] ?>"><?= (int) $it['sequence'] ?>. <?= e($it['description']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary" :disabled="!body.trim()">Add note</button>
      <span class="muted small">Ctrl+Enter also sends it.</span>
    </form>
    <?php endif; ?>
    <?php if ($activity): ?>
    <ul class="feed">
      <?php foreach ($activity as $a): $isNote = $a['kind'] === 'note'; $who = $a['display_name'] ?: ($a['username'] ?: 'Chroma'); $ini = ''; foreach (preg_split('/\s+/', (string) $who) ?: [] as $part) { $ini .= mb_substr($part, 0, 1); } ?>
        <li>
          <div class="avatar-sm <?= $isNote ? '' : 'avatar-sys' ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($ini, 0, 2))) ?></div>
          <div class="feed-body">
            <div class="feed-meta"><strong><?= e($who) ?></strong> · <?= e(fmt_datetime($a['created_at'])) ?> (<?= e(fmt_ago($a['created_at'])) ?>)<?php if ($a['item_description']): ?> · <span class="feed-item-ref">about <?= (int) $a['item_sequence'] ?>. <?= e($a['item_description']) ?></span><?php endif; ?></div>
            <?php if ($isNote): ?><div><?= markdown($a['body']) ?></div><?php else: ?><div class="feed-system"><?= e($a['body']) ?></div><?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
      <p class="muted mb-0">Nothing has happened yet. Notes you add and automatic entries (codes assigned, stage changes) appear here.</p>
    <?php endif; ?>
  </div>
</section>
