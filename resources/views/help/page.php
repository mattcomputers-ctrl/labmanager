<div class="grid help-layout">
  <?php /* On a phone the long topic list would push the article a full screen down, so it becomes a dropdown there. */ ?>
  <div class="help-pick field">
    <label class="label" for="help-topic">Help topic</label>
    <select id="help-topic" onchange="location = this.value">
      <?php foreach ($pages as $s => $t): ?><option value="/help<?= $s === 'index' ? '' : '/' . e($s) ?>"<?= $s === $slug ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
    </select>
  </div>
  <nav aria-label="Help topics" class="stack-s help-topics">
    <h3>Help topics</h3>
    <?php foreach ($pages as $s => $t): ?><a href="/help<?= $s === 'index' ? '' : '/' . e($s) ?>"<?= $s === $slug ? ' class="current" aria-current="page"' : '' ?>><?= e($t) ?></a><?php endforeach; ?>
  </nav>
  <article class="card"><div class="card-body" style="max-width:76ch"><?= $html ?></div></article>
</div>
