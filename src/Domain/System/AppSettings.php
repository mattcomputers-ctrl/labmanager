<?php
declare(strict_types=1);

namespace Chroma\Domain\System;

use Chroma\Core\Settings;
use Chroma\Core\ValidationException;
use Chroma\Domain\Audit\AuditLog;

/** The general settings edited on Admin → Settings. Validation lives here, not in the controller. */
final class AppSettings
{
    public const TIMEZONES = [
        'America/New_York' => 'Eastern (New York)',
        'America/Chicago' => 'Central (Chicago)',
        'America/Denver' => 'Mountain (Denver)',
        'America/Phoenix' => 'Mountain, no daylight saving (Phoenix)',
        'America/Los_Angeles' => 'Pacific (Los Angeles)',
        'America/Anchorage' => 'Alaska (Anchorage)',
        'Pacific/Honolulu' => 'Hawaii (Honolulu)',
        'UTC' => 'UTC',
    ];

    public const KEYS = ['company_name', 'app_name', 'timezone', 'sds_base_url', 'max_upload_mb', 'max_items_per_project', 'sell_number_is_iqc_standard', 'rnd_customer_name'];

    public function __construct(private readonly Settings $settings, private readonly AuditLog $audit)
    {
    }

    /** @return array<string,string> */
    public function current(): array
    {
        $out = [];
        foreach (self::KEYS as $k) {
            $out[$k] = (string) $this->settings->get($k, Settings::DEFAULTS[$k] ?? '');
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string>
     */
    public static function validate(array $input): array
    {
        $str = fn(string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $errors = [];
        $clean = [];

        $clean['company_name'] = $str('company_name');
        if ($clean['company_name'] === '') {
            $errors['company_name'] = 'Enter the company name; it appears on printouts.';
        } elseif (mb_strlen($clean['company_name']) > 120) {
            $errors['company_name'] = 'Keep the company name to 120 characters or fewer.';
        }

        $clean['app_name'] = $str('app_name') === '' ? 'Chroma' : $str('app_name');
        if (mb_strlen($clean['app_name']) > 40) {
            $errors['app_name'] = 'Keep the app name to 40 characters or fewer.';
        }

        $clean['timezone'] = $str('timezone');
        if (!isset(self::TIMEZONES[$clean['timezone']])) {
            $errors['timezone'] = 'Pick a time zone from the list.';
        }

        $clean['sds_base_url'] = $str('sds_base_url');
        if ($clean['sds_base_url'] !== '') {
            if (!preg_match('#^https?://#i', $clean['sds_base_url']) || filter_var(str_replace('{E}', 'E1', $clean['sds_base_url']), FILTER_VALIDATE_URL) === false) {
                $errors['sds_base_url'] = 'Enter the full web address of the SDS system starting with http:// or https://, for example http://sds.precisionink.local/sheet/{E}. Leave it blank if there is no SDS link yet.';
            } elseif (mb_strlen($clean['sds_base_url']) > 500) {
                $errors['sds_base_url'] = 'That address is too long (500 characters at most).';
            }
        }

        $clean['max_upload_mb'] = $str('max_upload_mb');
        if (!preg_match('/^\d+$/', $clean['max_upload_mb']) || (int) $clean['max_upload_mb'] < 1 || (int) $clean['max_upload_mb'] > 2048) {
            $errors['max_upload_mb'] = 'Enter a whole number of megabytes between 1 and 2048 for the largest attachment allowed.';
        } else {
            $clean['max_upload_mb'] = (string) (int) $clean['max_upload_mb'];
        }

        $clean['max_items_per_project'] = $str('max_items_per_project');
        if (!preg_match('/^\d+$/', $clean['max_items_per_project']) || (int) $clean['max_items_per_project'] < 1 || (int) $clean['max_items_per_project'] > 500) {
            $errors['max_items_per_project'] = 'Enter a whole number between 1 and 500 for the most colors or items one project may hold (the lab asked for 50).';
        } else {
            $clean['max_items_per_project'] = (string) (int) $clean['max_items_per_project'];
        }

        $clean['sell_number_is_iqc_standard'] = in_array(strtolower($str('sell_number_is_iqc_standard')), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';

        $clean['rnd_customer_name'] = $str('rnd_customer_name');
        if ($clean['rnd_customer_name'] === '') {
            $errors['rnd_customer_name'] = 'Enter the name used as the customer on internal R&D projects, for example Precision Ink R&D.';
        } elseif (mb_strlen($clean['rnd_customer_name']) > 200) {
            $errors['rnd_customer_name'] = 'Keep this name to 200 characters or fewer.';
        }

        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        return $clean;
    }

    /**
     * Validate, save every changed key through Settings::set and audit the change.
     * Returns the keys that changed.
     * @param array<string,mixed> $input
     * @return list<string>
     */
    public function save(array $input, ?int $userId): array
    {
        $clean = self::validate($input);
        $before = $this->current();
        $changed = [];
        foreach ($clean as $k => $v) {
            if (($before[$k] ?? null) !== $v) {
                $this->settings->set($k, $v, $userId);
                $changed[] = $k;
            }
        }
        if ($changed) {
            $this->audit->record('settings.update', 'chroma_settings', null, array_intersect_key($before, array_flip($changed)), array_intersect_key($clean, array_flip($changed)), 'Changed: ' . implode(', ', $changed), $userId);
        }
        return $changed;
    }
}
