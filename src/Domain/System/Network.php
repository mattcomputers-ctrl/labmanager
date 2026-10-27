<?php
declare(strict_types=1);

namespace Chroma\Domain\System;

use Chroma\Core\Helper;
use Chroma\Core\Settings;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;

/**
 * Server network settings (IP address, gateway, DNS) applied through the
 * privileged helper's set-network command, which writes netplan.
 *
 * The last settings that were applied are mirrored in the network_mirror
 * setting (JSON) so the screen can still show them when the helper is not
 * available, for example on a developer's machine.
 */
final class Network
{
    public const MIRROR_KEY = 'network_mirror';

    public function __construct(
        private readonly Helper $helper,
        private readonly Settings $settings,
        private readonly AuditLog $audit,
    ) {
    }

    public function helperAvailable(): bool
    {
        return $this->helper->available();
    }

    /**
     * Live interfaces from the helper, or null when it is not available.
     * @return list<array{name:string,ip:string,gateway:string,dns:list<string>,dhcp:bool,up:bool}>|null
     */
    public function status(): ?array
    {
        if (!$this->helper->available()) {
            return null;
        }
        [$code, $text] = $this->helper->run('network-status');
        if ($code !== 0) {
            return null;
        }
        $data = json_decode($text, true);
        if (!is_array($data) || !isset($data['interfaces']) || !is_array($data['interfaces'])) {
            return null;
        }
        $out = [];
        foreach ($data['interfaces'] as $i) {
            if (!is_array($i) || empty($i['name'])) {
                continue;
            }
            $out[] = [
                'name' => (string) $i['name'],
                'ip' => (string) ($i['ip'] ?? ''),
                'gateway' => (string) ($i['gateway'] ?? ''),
                'dns' => array_values(array_map('strval', is_array($i['dns'] ?? null) ? $i['dns'] : [])),
                'dhcp' => (bool) ($i['dhcp'] ?? false),
                'up' => (bool) ($i['up'] ?? false),
            ];
        }
        return $out;
    }

    /** The last settings applied from Chroma, if any. @return array<string,mixed>|null */
    public function mirror(): ?array
    {
        $raw = (string) $this->settings->get(self::MIRROR_KEY, '');
        if ($raw === '') {
            return null;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /**
     * Strict validation of the form. Returns the cleaned values:
     * interface, dhcp (bool), ip (a.b.c.d/nn), gateway, dns (list).
     * @param array<string,mixed> $input
     * @return array{interface:string,dhcp:bool,ip:string,gateway:string,dns:list<string>}
     */
    public static function validate(array $input): array
    {
        $str = fn(string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $errors = [];
        $iface = $str('interface');
        if ($iface === '') {
            $errors['interface'] = 'Pick the network interface to change (usually the only one listed, such as ens18 or eth0).';
        } elseif (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,15}$/', $iface)) {
            $errors['interface'] = 'An interface name looks like ens18 or eth0: letters and numbers, no spaces.';
        }
        $dhcp = in_array(strtolower($str('mode')), ['dhcp'], true) || in_array(strtolower($str('dhcp')), ['1', 'true', 'on', 'yes'], true);

        $ip = $str('ip');
        $gateway = $str('gateway');
        $dnsRaw = $input['dns'] ?? '';
        $dnsList = is_array($dnsRaw) ? array_map('strval', $dnsRaw) : (preg_split('/[\s,;]+/', (string) $dnsRaw) ?: []);
        $dnsList = array_values(array_filter(array_map('trim', $dnsList), fn($d) => $d !== ''));

        if (!$dhcp) {
            if ($ip === '') {
                $errors['ip'] = 'Enter the IP address with its network size, for example 10.10.5.20/24.';
            } elseif (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $ip, $m) || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || (int) $m[2] < 1 || (int) $m[2] > 32) {
                $errors['ip'] = 'That is not a valid address. Use the form 10.10.5.20/24: four numbers 0–255 separated by dots, a slash, and the network size (1–32, usually 24).';
            } else {
                $ip = $m[1] . '/' . (int) $m[2];
                $last = (int) substr($m[1], strrpos($m[1], '.') + 1);
                if (str_starts_with($m[1], '127.') || str_starts_with($m[1], '0.') || (int) explode('.', $m[1])[0] >= 224) {
                    $errors['ip'] = 'That address cannot be used for a server on the lab network (it is a loopback, reserved or multicast address). Use the address your IT contact gave you, for example 10.10.5.20/24.';
                } elseif ((int) $m[2] <= 30 && ($last === 0 || $last === 255)) {
                    $errors['ip'] = 'An address ending in .0 or .255 is the network or broadcast address and cannot be given to the server. Pick another number in the same range.';
                }
            }
            if ($gateway === '') {
                $errors['gateway'] = 'Enter the gateway (the router address, often the .1 of the same network, for example 10.10.5.1).';
            } elseif (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $errors['gateway'] = 'The gateway must be a plain IPv4 address such as 10.10.5.1, with no slash.';
            } elseif (!isset($errors['ip']) && $ip !== '' && !self::sameSubnet($ip, $gateway)) {
                $errors['gateway'] = 'The gateway ' . $gateway . ' is not inside the network of ' . $ip . '. Check both with your IT contact; the gateway is normally the router on the same network.';
            }
            if ($dnsList === []) {
                $errors['dns'] = 'Enter at least one DNS server, separated by commas, for example 10.10.5.2, 1.1.1.1.';
            } else {
                foreach ($dnsList as $d) {
                    if (filter_var($d, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                        $errors['dns'] = '"' . $d . '" is not an IPv4 address. Separate servers with commas, for example 10.10.5.2, 1.1.1.1.';
                        break;
                    }
                }
                if (count($dnsList) > 3) {
                    $errors['dns'] = 'Enter at most three DNS servers.';
                }
            }
        }
        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        return ['interface' => $iface, 'dhcp' => $dhcp, 'ip' => $dhcp ? '' : $ip, 'gateway' => $dhcp ? '' : $gateway, 'dns' => $dhcp ? [] : $dnsList];
    }

    public static function sameSubnet(string $cidr, string $ip): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
        return ((ip2long($net) & $mask) === (ip2long($ip) & $mask));
    }

    /**
     * Turn the helper's refusal into plain words. The one case that needs
     * translating: an older chroma-helper (before "set-network <iface> dhcp"
     * existed) insists on four arguments, so choosing DHCP is refused with
     * a message about argument counts. Nothing was changed in either case.
     */
    public static function refusalMessage(bool $dhcp, int $code, string $text): string
    {
        $t = trim(preg_replace('/^chroma-helper:\s*/m', '', $text) ?? $text);
        if ($dhcp && (preg_match('/needs exactly 4 arguments/i', $t) || str_contains(strtolower($t), 'usage:'))) {
            return 'This server\'s system helper is an older version that cannot switch the interface to automatic (DHCP) yet. Nothing was changed. '
                . 'Re-run install.sh on the server (or use "Update now" on the System page) to bring the helper up to date, or choose a fixed address instead.';
        }
        if ($dhcp && preg_match('/no network interface called/i', $t)) {
            return 'Nothing was changed: ' . $t . '. Pick one of the interfaces listed under "Right now".';
        }
        return 'The network change was refused, nothing was changed. ' . ($t !== '' ? $t : 'Exit code ' . $code . '.');
    }

    /** The URL the browser will need after the change. */
    public static function newUrl(array $clean): string
    {
        if ($clean['dhcp']) {
            return '';
        }
        return 'http://' . explode('/', $clean['ip'])[0] . '/';
    }

    /**
     * Apply validated settings through the helper and store the mirror.
     * @param array{interface:string,dhcp:bool,ip:string,gateway:string,dns:list<string>} $clean
     * @return array{ok:bool,message:string,url:string}
     */
    public function apply(array $clean, ?int $userId): array
    {
        $args = $clean['dhcp']
            ? [$clean['interface'], 'dhcp']
            : [$clean['interface'], $clean['ip'], $clean['gateway'], implode(',', $clean['dns'])];
        [$code, $text] = $this->helper->run('set-network', $args);
        $text = trim($text);
        if ($code !== 0) {
            $msg = $code === 127 ? $text : self::refusalMessage($clean['dhcp'], $code, $text);
            return ['ok' => false, 'message' => $msg, 'url' => ''];
        }
        $before = $this->mirror();
        $mirror = $clean + ['applied_at' => gmdate('Y-m-d H:i:s'), 'applied_by' => $userId];
        $this->settings->set(self::MIRROR_KEY, (string) json_encode($mirror, JSON_UNESCAPED_SLASHES), $userId);
        $this->audit->record('network.apply', 'chroma_settings', null, $before, $mirror, 'Network settings applied through the helper', $userId);
        $url = self::newUrl($clean);
        if (preg_match('#https?://\S+#', $text, $m)) {
            $url = $m[0];
        }
        return ['ok' => true, 'message' => 'The new settings were accepted. The server switches to them in about 10 seconds.', 'url' => $url];
    }
}
