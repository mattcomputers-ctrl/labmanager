<div class="page-narrow" style="margin:0 auto;padding-top:var(--s-12)">
  <div class="card"><div class="card-body center">
    <div class="muted" style="font-size:var(--fs-4xl);font-weight:700;letter-spacing:-.02em"><?= (int) $status ?></div>
    <h1><?= e($title) ?></h1>
    <p class="muted" style="max-width:52ch;margin:0 auto var(--s-6)"><?= e($message) ?></p>
    <div class="flex" style="justify-content:center">
      <a class="btn btn-primary" href="/">Go to the home page</a>
      <a class="btn btn-secondary" href="javascript:history.back()">Go back</a>
    </div>
    <?php if (!empty($detail)): ?><pre class="mt-6" style="text-align:left;white-space:pre-wrap;font-size:12px;background:var(--surface-2);padding:12px;border-radius:8px;overflow:auto"><?= e($detail) ?></pre><?php endif; ?>
  </div></div>
</div>
