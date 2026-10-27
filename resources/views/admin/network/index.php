<?php
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/system-admin.css"><?php $view->end();
echo $view->partial('admin/_nav', ['current' => '/admin/network']);
echo component('page-header', ['title' => 'Network', 'lede' => 'The address this server answers on. Change it only with your IT contact\'s numbers: a wrong address makes Chroma unreachable until someone fixes it at the server itself.']);
$fmtDns = fn(array $d) => $d ? implode(', ', $d) : '—';
?>

<?php if ($applied): ?>
  <div class="callout callout-warning" role="alert"><div><span class="callout-title">New settings accepted — reconnect at the new address</span>
    <?= e($applied['message']) ?>
    <?php if ($applied['url'] !== ''): ?><br>In a moment this page will stop responding. Open <a href="<?= e($applied['url']) ?>"><strong><?= e($applied['url']) ?></strong></a> in your browser and sign in again. Tell the lab the new address.<?php else: ?><br>The server now asks the router for an address. Ask IT or check the router to find it, then open Chroma there.<?php endif; ?>
  </div></div>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="sys-grid">
  <section class="card">
    <div class="card-head"><h2>Right now</h2></div>
    <div class="card-body">
      <?php if ($status !== null && $status !== []): ?>
        <?php foreach ($status as $i): ?>
          <div class="net-iface">
            <h3><?= e($i['name']) ?> <?= component('badge', ['label' => $i['up'] ? 'Up' : 'Down', 'color' => $i['up'] ? 'green' : 'red']) ?> <?php if ($i['dhcp']): ?><?= component('badge', ['label' => 'Automatic (DHCP)', 'color' => 'blue']) ?><?php endif; ?></h3>
            <div class="net-addr"><?= e($i['ip'] !== '' ? $i['ip'] : 'no address') ?></div>
            <?= component('kv', ['rows' => [['Gateway', $i['gateway'] !== '' ? $i['gateway'] : '—'], ['DNS', $fmtDns($i['dns'])]]]) ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <?= component('callout', ['type' => 'info', 'title' => 'Live network details are not available on this machine', 'body' => $helperAvailable ? 'The system helper did not return the network status. The last settings applied from Chroma are shown below.' : 'The system helper that reads and changes the network is installed by install.sh on the server. You are reaching this copy at ' . $currentHost . '.']) ?>
        <?php if ($mirror): ?>
          <div class="net-iface mt-4">
            <h3><?= e($mirror['interface'] ?? '') ?> <?= component('badge', ['label' => 'Last applied from Chroma', 'color' => 'gray']) ?></h3>
            <div class="net-addr"><?= e(!empty($mirror['dhcp']) ? 'Automatic (DHCP)' : ($mirror['ip'] ?? '')) ?></div>
            <?= component('kv', ['rows' => [['Gateway', $mirror['gateway'] ?? ''], ['DNS', $fmtDns($mirror['dns'] ?? [])], ['Applied', fmt_datetime($mirror['applied_at'] ?? null)]]]) ?>
          </div>
        <?php else: ?>
          <p class="muted mt-4">No network settings have been applied from Chroma yet.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head"><h2>Change the address</h2></div>
    <div class="card-body">
      <?php if (is_easy()): ?>
        <p>Chroma will ask you for the interface, the address, the gateway and the DNS servers one at a time, show you a summary, and only then apply the change.</p>
        <?= component('callout', ['type' => 'warning', 'body' => 'After the change the server moves to the new address. Your browser will need to reconnect there, and everyone in the lab will need the new address too.']) ?>
        <div class="mt-4"><a class="btn btn-primary btn-lg" href="/wizard/network-settings">Change network settings</a></div>
      <?php else: ?>
      <form method="post" action="/admin/network" x-data="{ confirm: false, mode: <?= json_attr(old('mode', $defaults['mode'])) ?>, ip: <?= json_attr(old('ip', $defaults['ip'])) ?>, iface: <?= json_attr(old('interface', $defaults['interface'])) ?>, gateway: <?= json_attr(old('gateway', $defaults['gateway'])) ?>, dns: <?= json_attr(old('dns', $defaults['dns'])) ?> }" @submit.prevent="confirm = true" novalidate>
        <?= csrf_field() ?>
        <?php if ($status): ?>
          <?= component('form/field', ['name' => 'interface', 'label' => 'Interface', 'type' => 'select', 'required' => true, 'value' => old('interface', $defaults['interface']), 'options' => array_map(fn($i) => [$i['name'], $i['name'] . ($i['ip'] !== '' ? ' — ' . $i['ip'] : '')], $status), 'attrs' => ['x-model' => 'iface']]) ?>
        <?php else: ?>
          <?= component('form/field', ['name' => 'interface', 'label' => 'Interface', 'required' => true, 'value' => old('interface', $defaults['interface']), 'placeholder' => 'ens18', 'help' => 'The server\'s network card name, for example ens18 or eth0.', 'attrs' => ['x-model' => 'iface']]) ?>
        <?php endif; ?>
        <div @change="if ($event.target.name === 'mode') mode = $event.target.value">
        <?= component('form/field', ['name' => 'mode', 'label' => 'Address', 'type' => 'radio', 'value' => old('mode', $defaults['mode']), 'options' => [['static', 'Fixed address', 'Recommended for a server: the numbers below from your IT contact.'], ['dhcp', 'Automatic (DHCP)', 'The router hands out an address that may change. Needs the current system helper; an older one refuses this and nothing changes.']]]) ?>
        </div>
        <div x-show="mode !== 'dhcp'">
          <div class="form-grid">
            <?= component('form/field', ['name' => 'ip', 'label' => 'IP address / network size', 'value' => old('ip', $defaults['ip']), 'placeholder' => '10.10.5.20/24', 'help' => 'For example 10.10.5.20/24.', 'attrs' => ['x-model' => 'ip', 'inputmode' => 'decimal']]) ?>
            <?= component('form/field', ['name' => 'gateway', 'label' => 'Gateway', 'value' => old('gateway', $defaults['gateway']), 'placeholder' => '10.10.5.1', 'help' => 'The router, on the same network.', 'attrs' => ['x-model' => 'gateway', 'inputmode' => 'decimal']]) ?>
            <?= component('form/field', ['name' => 'dns', 'label' => 'DNS servers', 'value' => old('dns', $defaults['dns']), 'placeholder' => '10.10.5.2, 1.1.1.1', 'help' => 'One or more, separated by commas.', 'class' => 'span-2', 'attrs' => ['x-model' => 'dns']]) ?>
          </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-danger btn-lg" <?= $helperAvailable ? '' : 'title="Not available on this machine"' ?>>Apply network settings…</button></div>
        <?php if (!$helperAvailable): ?><p class="help">Applying is not available on this machine; the form still checks what you type.</p><?php endif; ?>
        <template x-teleport="body">
          <div class="modal-backdrop" x-show="confirm" x-cloak @click.self="confirm=false" @keydown.escape.window="confirm=false" x-trap.noscroll="confirm">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="net-title">
              <div class="modal-head"><h2 id="net-title">Change the server's address?</h2><button type="button" class="btn btn-ghost btn-icon" @click="confirm=false" aria-label="Close">&times;</button></div>
              <div class="modal-body">
                <p>About 10 seconds after you confirm, the server switches to the new settings. <strong>This page will stop working and your browser will need to reconnect at the new address.</strong> Everyone in the lab will need the new address too.</p>
                <template x-if="mode !== 'dhcp'"><p>The new address of Chroma will be <strong class="code" x-text="'http://' + (ip.split('/')[0] || '…') + '/'"></strong> on interface <strong x-text="iface || '…'"></strong>, gateway <strong x-text="gateway || '…'"></strong>, DNS <strong x-text="dns || '…'"></strong>.</p></template>
                <template x-if="mode === 'dhcp'"><p>The server will ask the router for an address. You will have to find out what it got (ask IT or look at the router) before you can open Chroma again.</p></template>
                <p>If the numbers are wrong, Chroma becomes unreachable until someone fixes the network at the server itself. If you are not sure, cancel and check with IT.</p>
              </div>
              <div class="modal-foot"><button type="button" class="btn btn-secondary" @click="confirm=false" data-confirm-focus>Cancel</button><button type="button" class="btn btn-danger" @click="confirm=false; $nextTick(() => $root.submit())">Yes, change the address</button></div>
            </div>
          </div>
        </template>
      </form>
      <?php endif; ?>
    </div>
  </section>
</div>
