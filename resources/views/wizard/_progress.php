<div class="wizard-progress" aria-label="Progress">
  <span>Step <?= (int) $n ?> of <?= (int) $total ?></span>
  <div class="bar" role="progressbar" aria-valuemin="1" aria-valuemax="<?= (int) $total ?>" aria-valuenow="<?= (int) $n ?>"><span style="width: <?= (int) round($n / max(1, $total) * 100) ?>%"></span></div>
  <form method="post" action="/wizard/<?= e($wizard->key()) ?>/cancel" style="margin:0"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm">Cancel</button></form>
</div>
