<?php
declare(strict_types=1);

namespace Chroma\Core;

/** Key/value app settings stored in chroma_settings, cached per request. */
final class Settings
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public const DEFAULTS = [
        'company_name' => 'Precision Ink Corporation',
        'app_name' => 'Chroma',
        'timezone' => 'America/Chicago',
        'sds_base_url' => '',
        'max_items_per_project' => '50',
        'max_upload_mb' => '25',
        'cms_enabled' => '0',
        'cms_driver_status' => '',
        'cms_last_sync_at' => '',
        'cms_last_sync_result' => '',
        'cms_customer_table' => '',
        'cms_customer_id_column' => '',
        'cms_customer_name_column' => '',
        'cms_customer_active_column' => '',
        'cms_customer_filter' => '',
        'project_number_label' => 'Project',
        'rnd_customer_name' => 'Precision Ink R&D',
        'network_mirror' => '',
        'sell_number_is_iqc_standard' => '1',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    private function load(): void
    {
        if ($this->cache === null) {
            $this->cache = self::DEFAULTS;
            try {
                foreach ($this->db->all('SELECT `key`, `value` FROM chroma_settings') as $row) {
                    $this->cache[(string) $row['key']] = (string) $row['value'];
                }
            } catch (\PDOException) {
                // Before migrations have run; defaults are fine.
            }
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->load();
        return $this->cache[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower((string) $this->get($key, '0')), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key);
        return $v === null || $v === '' ? $default : (int) $v;
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $this->load();
        $this->db->run(
            'INSERT INTO chroma_settings (`key`, `value`, updated_by, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
            [$key, $value, $userId]
        );
        $this->cache[$key] = $value;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $this->load();
        return $this->cache ?? [];
    }
}
