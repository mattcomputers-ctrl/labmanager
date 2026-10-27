<?php /** Attachments: upload (size-limited), inline image previews, download, soft delete. */ $pid = (int) $project['id']; ?>
<section id="attachments" class="card section-tabs">
  <div class="card-head"><h2>Attachments</h2><span class="muted small"><?= count($attachments) ?></span></div>
  <div class="card-body">
    <?php if ($attachments): ?>
    <div class="att-grid mb-4">
      <?php foreach ($attachments as $a): $url = '/projects/' . $pid . '/attachments/' . (int) $a['id']; ?>
        <div class="att-card">
          <?php if ($a['is_image']): ?><a href="<?= e($url) ?>?inline=1" target="_blank" rel="noopener"><img class="thumb" src="<?= e($url) ?>?inline=1" alt="<?= e($a['caption'] ?: $a['original_name']) ?>" loading="lazy"></a>
          <?php else: ?><a href="<?= e($url) ?>" class="att-icon"><?= e(strtoupper(pathinfo((string) $a['original_name'], PATHINFO_EXTENSION) ?: 'file')) ?></a><?php endif; ?>
          <a class="att-name" href="<?= e($url) ?>" title="Download"><?= e($a['original_name']) ?></a>
          <?php if ($a['caption']): ?><div class="small"><?= e($a['caption']) ?></div><?php endif; ?>
          <div class="att-meta"><?= e($a['size_human']) ?> · <?= e($a['uploaded_by_name'] ?: '') ?> · <?= e(fmt_date($a['created_at'])) ?><?= $a['item_description'] ? '<br>on: ' . e($a['item_description']) : '' ?></div>
          <?php if ($canEdit): ?><div><?= component('confirm-form', ['action' => $url . '/delete', 'label' => 'Remove', 'size' => 'sm', 'title' => 'Remove ' . $a['original_name'] . '?', 'body' => 'It disappears from the project. The file is kept in the backup and an administrator can restore it.', 'confirm_label' => 'Remove']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="muted">No attachments yet. Drawdown photos, iQC exports, customer artwork, press-trial reports and formula cards all go here.</p>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" action="/projects/<?= $pid ?>/attachments" enctype="multipart/form-data" class="stack-s" x-data="uploader(<?= json_attr(['maxBytes' => $maxUploadMb * 1048576]) ?>)">
      <?= csrf_field() ?>
      <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int) ($maxUploadMb * 1048576) ?>">
      <div class="dropzone" :class="{ over: over }" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="drop($event)">
        <div>Drop files here, or</div>
        <input type="file" name="file[]" multiple @change="pick($event.target.files)" aria-label="Choose files">
        <div class="small muted">Up to <?= (int) $maxUploadMb ?> MB each. Images show a preview.</div>
        <ul class="small mt-2" x-show="names.length" x-cloak style="list-style:none;padding:0;margin:0"><template x-for="n in names"><li x-text="n"></li></template></ul>
        <p class="error-text" x-show="tooBig" x-cloak>One of these is over the <?= (int) $maxUploadMb ?> MB limit; it will be refused.</p>
      </div>
      <?php if (isset($errors['file'])): ?><p class="error-text" role="alert"><?= e($errors['file']) ?></p><?php endif; ?>
      <div class="grid grid-2">
        <?= component('form/field', ['name' => 'caption', 'label' => 'Caption', 'optional_text' => true, 'placeholder' => 'e.g. Drawdown on SBS, 2nd pass', 'attrs' => ['maxlength' => 255]]) ?>
        <?php if (count($items) > 0): ?><?= component('form/field', ['name' => 'item_id', 'label' => 'About a particular ' . mb_strtolower((string) $project['item_label_singular']), 'type' => 'select', 'placeholder' => 'The whole project', 'options' => array_map(fn($it) => [(int) $it['id'], $it['sequence'] . '. ' . $it['description']], $items)]) ?><?php endif; ?>
      </div>
      <div><button type="submit" class="btn btn-secondary" :disabled="!names.length || tooBig">Attach</button></div>
    </form>
    <?php endif; ?>
  </div>
</section>
