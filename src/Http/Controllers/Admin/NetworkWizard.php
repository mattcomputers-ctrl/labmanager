<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Helper;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\ValidationException;
use Chroma\Domain\System\Network;
use Chroma\Http\Wizard\Step;
use Chroma\Http\Wizard\Wizard;
use Chroma\Http\Wizard\WizardResult;

/** Easy-mode shell over Network::apply: one question per screen. Reachable at /wizard/network-settings. */
final class NetworkWizard extends Wizard
{
    public function key(): string
    {
        return 'network-settings';
    }

    public function title(): string
    {
        return 'Change network settings';
    }

    public function permissions(): array
    {
        return [Permissions::ADMIN_NETWORK];
    }

    private function network(): Network
    {
        return new Network(new Helper($this->app->log), $this->app->settings, $this->app->audit);
    }

    public function initialState(Request $request): array
    {
        $net = $this->network();
        $status = $net->status() ?? [];
        $mirror = $net->mirror() ?? [];
        $primary = $status[0] ?? null;
        foreach ($status as $i) {
            if ($i['up'] && $i['ip'] !== '') {
                $primary = $i;
                break;
            }
        }
        return [
            'interface' => $primary['name'] ?? ($mirror['interface'] ?? ''),
            'mode' => ($primary['dhcp'] ?? ($mirror['dhcp'] ?? false)) ? 'dhcp' : 'static',
            'ip' => $primary['ip'] ?? ($mirror['ip'] ?? ''),
            'gateway' => $primary['gateway'] ?? ($mirror['gateway'] ?? ''),
            'dns' => implode(', ', $primary['dns'] ?? ($mirror['dns'] ?? [])),
            '_interfaces' => array_map(fn($i) => [$i['name'], $i['name'] . ($i['ip'] !== '' ? ' — ' . $i['ip'] : '') . ($i['up'] ? '' : ' (down)')], $status),
        ];
    }

    public function steps(array $state): array
    {
        $ifaces = $state['_interfaces'] ?? [];
        $steps = [
            new Step('interface', 'Which network connection?', 'The server usually has one. If you are not sure, keep the one already chosen.', [
                $ifaces !== []
                    ? ['name' => 'interface', 'label' => 'Network interface', 'type' => 'radio', 'options' => $ifaces, 'required' => true]
                    : ['name' => 'interface', 'label' => 'Network interface', 'required' => true, 'placeholder' => 'ens18', 'help' => 'The name the server gives its network card, for example ens18 or eth0. The installer printed it.'],
            ]),
            new Step('mode', 'Fixed address or automatic?', 'A fixed (static) address is best for a server so everyone can find it at the same address every day.', [
                ['name' => 'mode', 'label' => 'How should the server get its address?', 'type' => 'radio', 'required' => true, 'options' => [
                    ['static', 'A fixed address', 'Recommended. You type the address, gateway and DNS your IT contact gave you.'],
                    ['dhcp', 'Automatic (DHCP)', 'The router hands out an address. It may change, so the lab may have to look for the new one.'],
                ]],
            ]),
        ];
        if (($state['mode'] ?? 'static') !== 'dhcp') {
            $steps[] = new Step('ip', 'What address should the server use?', 'Type the address with its network size, the way IT writes it.', [
                ['name' => 'ip', 'label' => 'IP address / network size', 'required' => true, 'placeholder' => '10.10.5.20/24', 'help' => 'Four numbers separated by dots, then a slash and the network size (usually 24).'],
            ]);
            $steps[] = new Step('gateway', 'What is the gateway?', 'The gateway is the router the server sends traffic through. It is on the same network, often ending in .1.', [
                ['name' => 'gateway', 'label' => 'Gateway', 'required' => true, 'placeholder' => '10.10.5.1'],
            ]);
            $steps[] = new Step('dns', 'Which DNS servers?', 'DNS turns names into addresses. One is enough; separate several with commas.', [
                ['name' => 'dns', 'label' => 'DNS servers', 'required' => true, 'placeholder' => '10.10.5.2, 1.1.1.1'],
            ]);
        }
        return $steps;
    }

    public function validateStep(Step $step, array $input, array $state): array
    {
        $clean = parent::validateStep($step, $input, $state);
        // Early, field-level feedback using the same rules as the domain class.
        $merged = array_merge($state, $clean);
        try {
            Network::validate($merged + ['mode' => $merged['mode'] ?? 'static']);
        } catch (ValidationException $e) {
            $mine = array_intersect_key($e->errors, array_flip(array_map(fn($f) => $f['name'], $step->fields)));
            if ($mine !== []) {
                throw new ValidationException($mine);
            }
        }
        return $clean;
    }

    public function review(array $state): array
    {
        $rows = [
            ['label' => 'Interface', 'value' => (string) ($state['interface'] ?? ''), 'step' => 'interface'],
            ['label' => 'Address', 'value' => ($state['mode'] ?? '') === 'dhcp' ? 'Automatic (DHCP)' : 'Fixed', 'step' => 'mode'],
        ];
        if (($state['mode'] ?? '') !== 'dhcp') {
            $rows[] = ['label' => 'IP address', 'value' => (string) ($state['ip'] ?? ''), 'step' => 'ip'];
            $rows[] = ['label' => 'Gateway', 'value' => (string) ($state['gateway'] ?? ''), 'step' => 'gateway'];
            $rows[] = ['label' => 'DNS servers', 'value' => (string) ($state['dns'] ?? ''), 'step' => 'dns'];
            $rows[] = ['label' => 'New address of Chroma', 'value' => 'http://' . explode('/', (string) ($state['ip'] ?? ''))[0] . '/'];
        }
        return $rows;
    }

    public function commitLabel(): string
    {
        return 'Apply network settings';
    }

    public function commitExplanation(): string
    {
        return 'The server will switch to the new settings about 10 seconds after you confirm. Your browser will lose this page: open Chroma again at the new address shown above (or ask the router for the address if you chose automatic).';
    }

    public function commit(array $state): WizardResult
    {
        $clean = Network::validate($state + ['mode' => $state['mode'] ?? 'static']);
        $r = $this->network()->apply($clean, $this->app->auth->id());
        if (!$r['ok']) {
            $this->app->audit->record('network.apply_failed', '', null, null, $clean, mb_substr($r['message'], 0, 500));
            throw ValidationException::general($r['message']);
        }
        $details = [['Interface', $clean['interface']]];
        if ($clean['dhcp']) {
            $details[] = ['Address', 'Automatic (DHCP)'];
        } else {
            $details[] = ['IP address', $clean['ip']];
            $details[] = ['Gateway', $clean['gateway']];
            $details[] = ['DNS', implode(', ', $clean['dns'])];
        }
        return new WizardResult(
            'Network settings applied',
            $r['url'] !== '' ? 'In about 10 seconds the server moves to ' . $r['url'] . ' — this page will stop responding. Open that address in your browser.' : 'In about 10 seconds the server asks the router for an address. Ask IT or check the router for the new address, then open Chroma there.',
            [],
            $details,
            $r['url'] !== '' ? [['label' => 'Open Chroma at ' . $r['url'], 'href' => $r['url'], 'primary' => true]] : [['label' => 'Back to admin', 'href' => '/admin']],
        );
    }

    public function expertUrl(array $state): ?string
    {
        return url('/mode', ['set' => 'expert', 'return' => '/admin/network']);
    }

    public function cancelUrl(array $state): string
    {
        return '/admin/network';
    }
}
