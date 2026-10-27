<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Helper;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\System\Network;
use Chroma\Http\Controller;

/** Admin → Network: show and change the server's IP address, gateway and DNS. */
final class NetworkController extends Controller
{
    private function network(): Network
    {
        return new Network(new Helper($this->app->log), $this->app->settings, $this->app->audit);
    }

    public function index(Request $request): Response
    {
        $net = $this->network();
        $status = $net->status();
        $mirror = $net->mirror();
        $primary = $status[0] ?? null;
        foreach ($status ?? [] as $i) {
            if ($i['up'] && $i['ip'] !== '') {
                $primary = $i;
                break;
            }
        }
        $defaults = [
            'interface' => $primary['name'] ?? ($mirror['interface'] ?? ''),
            'mode' => ($primary['dhcp'] ?? ($mirror['dhcp'] ?? false)) ? 'dhcp' : 'static',
            'ip' => $primary['ip'] ?? ($mirror['ip'] ?? ''),
            'gateway' => $primary['gateway'] ?? ($mirror['gateway'] ?? ''),
            'dns' => implode(', ', $primary['dns'] ?? ($mirror['dns'] ?? [])),
        ];
        $applied = $this->app->session->pull('_network_applied');
        return $this->view('admin/network/index', [
            'title' => 'Network',
            'helperAvailable' => $net->helperAvailable(),
            'status' => $status,
            'mirror' => $mirror,
            'defaults' => $defaults,
            'applied' => is_array($applied) ? $applied : null,
            'currentHost' => (string) ($request->header('host') ?? ''),
        ]);
    }

    public function apply(Request $request): Response
    {
        $net = $this->network();
        $clean = Network::validate($request->body);
        $r = $net->apply($clean, $this->userId());
        if (!$r['ok']) {
            $this->app->audit->record('network.apply_failed', '', null, null, $clean, mb_substr($r['message'], 0, 500));
            $this->failure('The network settings were not changed.', $r['message']);
            return $this->redirect('/admin/network');
        }
        $this->app->session->set('_network_applied', ['url' => $r['url'], 'message' => $r['message'], 'settings' => $clean]);
        $this->success($r['message']);
        return $this->redirect('/admin/network');
    }
}
