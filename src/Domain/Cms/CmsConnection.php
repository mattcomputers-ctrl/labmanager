<?php
declare(strict_types=1);

namespace Chroma\Domain\Cms;

use Chroma\Core\Config;
use Chroma\Core\Settings;
use Chroma\Core\ValidationException;
use PDO;

/**
 * The read-only Mar-Kov CMS connection (SQL Server, database CMS).
 *
 * Host, port, database and the read-only username/password live in
 * config.env (written through Config::write) and never in the database.
 * The schema mapping (which table and columns hold customers) lives in
 * settings, because it is not a secret and the lab may need to see it.
 * Nothing about the schema is guessed: until Matt enters the mapping the
 * screen says "ask the CMS administrator".
 *
 * This class never writes to the CMS. The only statements it runs are
 * SELECTs built from the mapping, and the connection is opened read-only
 * where the driver supports it.
 */
final class CmsConnection implements CustomerSource
{
    public const CONFIG_KEYS = ['CMS_HOST', 'CMS_PORT', 'CMS_DATABASE', 'CMS_USER', 'CMS_PASS'];
    public const MAPPING_KEYS = ['cms_customer_table', 'cms_customer_id_column', 'cms_customer_name_column', 'cms_customer_active_column', 'cms_customer_filter'];

    public const DRIVER_MISSING_TEXT = 'The SQL Server driver (pdo_sqlsrv) is not installed on this server. The installer tried to install it; re-run install.sh on the server, or ask whoever set up the server to install the Microsoft ODBC driver 18 and pdo_sqlsrv. Until then customers can still be added by hand.';

    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config, private readonly Settings $settings)
    {
    }

    // ------------------------------------------------------------------ state

    public function isEnabled(): bool
    {
        return $this->settings->bool('cms_enabled');
    }

    public static function driverInstalled(): bool
    {
        return extension_loaded('pdo_sqlsrv');
    }

    /** "ok" or "driver not installed"; also remembered in the cms_driver_status setting. */
    public function driverStatus(?int $userId = null): string
    {
        $status = self::driverInstalled() ? 'ok' : 'driver not installed';
        if ($this->settings->get('cms_driver_status') !== $status) {
            $this->settings->set('cms_driver_status', $status, $userId);
        }
        return $status;
    }

    /** Connection details with the password replaced by "set" / "not set". @return array<string,string> */
    public function connectionSummary(): array
    {
        return [
            'host' => (string) $this->config->get('CMS_HOST', ''),
            'port' => (string) $this->config->get('CMS_PORT', '1433'),
            'database' => (string) $this->config->get('CMS_DATABASE', 'CMS'),
            'user' => (string) $this->config->get('CMS_USER', ''),
            'password_state' => (string) $this->config->get('CMS_PASS', '') === '' ? 'not set' : 'set',
        ];
    }

    /** @return array<string,string> */
    public function mapping(): array
    {
        $out = [];
        foreach (self::MAPPING_KEYS as $k) {
            $out[$k] = (string) $this->settings->get($k, '');
        }
        return $out;
    }

    public function hasConnectionDetails(): bool
    {
        $s = $this->connectionSummary();
        return $s['host'] !== '' && $s['user'] !== '' && $s['password_state'] === 'set';
    }

    public function hasMapping(): bool
    {
        $m = $this->mapping();
        return $m['cms_customer_table'] !== '' && $m['cms_customer_id_column'] !== '' && $m['cms_customer_name_column'] !== '';
    }

    // --------------------------------------------------------------- settings

    /**
     * Validate and save the connection details (to config.env) and the
     * mapping (to settings). A blank password keeps the one already saved.
     * Returns a before/after pair for the audit log (no secrets inside).
     *
     * @param array<string,mixed> $input
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    public function save(array $input, ?int $userId): array
    {
        $before = $this->connectionSummary() + $this->mapping() + ['cms_enabled' => $this->isEnabled() ? '1' : '0'];
        $clean = self::validate($input, $before['password_state'] === 'set');

        $configUpdates = [
            'CMS_HOST' => $clean['host'],
            'CMS_PORT' => $clean['port'],
            'CMS_DATABASE' => $clean['database'],
            'CMS_USER' => $clean['user'],
        ];
        if ($clean['password'] !== '') {
            $configUpdates['CMS_PASS'] = $clean['password'];
        } elseif (!empty($clean['clear_password'])) {
            $configUpdates['CMS_PASS'] = '';
        }
        $this->config->write($configUpdates);
        $this->pdo = null;
        if (($configUpdates['CMS_PASS'] ?? '') !== '') {
            // Belt and braces: make sure the password reads back exactly as typed.
            $stored = (string) Config::load($this->config->path())->get('CMS_PASS', '');
            if ($stored !== $configUpdates['CMS_PASS']) {
                $this->config->write(['CMS_PASS' => '']);
                throw new ValidationException(['password' => 'That password could not be stored exactly as typed, so it was not saved. Use a password without quotes or backslashes.'], 'The password was not saved.');
            }
        }

        foreach (self::MAPPING_KEYS as $k) {
            $this->settings->set($k, $clean[$k], $userId);
        }
        $this->settings->set('cms_enabled', $clean['enabled'] ? '1' : '0', $userId);
        $this->settings->set('cms_deactivate_missing', $clean['deactivate_missing'] ? '1' : '0', $userId);

        $after = $this->connectionSummary() + $this->mapping() + ['cms_enabled' => $clean['enabled'] ? '1' : '0'];
        return [$before, $after];
    }

    /**
     * Characters a CMS password cannot contain. The config file is read back
     * by Config::load (and by install.sh) without un-escaping, so a password
     * holding a double quote or a backslash would come back changed and the
     * connection would fail for no visible reason. Say so up front instead.
     */
    public const PASSWORD_FORBIDDEN = ['"', '\\'];

    /**
     * Pure validation of the CMS form (no I/O), so tests can cover it.
     * $passwordSaved says whether config.env already holds a password, so
     * turning the connection on can insist that one exists.
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(array $input, bool $passwordSaved = false): array
    {
        $str = fn(string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';
        $bool = fn(string $k): bool => in_array(strtolower((string) ($input[$k] ?? '')), ['1', 'true', 'on', 'yes'], true);
        $errors = [];

        $host = $str('host');
        $port = $str('port') === '' ? '1433' : $str('port');
        $database = $str('database') === '' ? 'CMS' : $str('database');
        $user = $str('user');
        $password = is_scalar($input['password'] ?? null) ? (string) $input['password'] : '';
        $clearPassword = $bool('clear_password');
        $enabled = $bool('enabled');

        if ($password !== '') {
            foreach (self::PASSWORD_FORBIDDEN as $ch) {
                if (str_contains($password, $ch)) {
                    $errors['password'] = 'The password cannot contain a double quote (") or a backslash (\\): the server\'s config file cannot store those safely. Ask the CMS administrator for a read-only password without them.';
                    break;
                }
            }
            if (!isset($errors['password']) && (preg_match('/[\x00-\x1F\x7F]/', $password) || strlen($password) > 256)) {
                $errors['password'] = 'The password cannot contain line breaks or other control characters and must be 256 characters or fewer.';
            }
        }
        if ($enabled && $password === '' && (!$passwordSaved || $clearPassword)) {
            $errors['password'] = $clearPassword
                ? 'You are removing the saved password and turning the connection on at the same time. Leave "Remove the saved password" unticked, or turn the connection off.'
                : 'Enter the read-only password before turning the connection on. Without it every sync would fail. Or leave the connection off for now.';
        }

        if ($host !== '' && !preg_match('/^[A-Za-z0-9._-]+(\\\\[A-Za-z0-9_]+)?$/', $host)) {
            $errors['host'] = 'Enter the server name or IP address only, for example 10.10.5.30 or MARKOV-SQL (an instance name after a backslash is fine). No spaces or slashes.';
        }
        if (!preg_match('/^\d{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
            $errors['port'] = 'The port is a number between 1 and 65535. SQL Server normally uses 1433.';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $database)) {
            $errors['database'] = 'The database name can only contain letters, numbers, dots, dashes and underscores. The Mar-Kov database is usually called CMS.';
        }
        if ($user !== '' && !preg_match('/^[^\s"\']{1,128}$/', $user)) {
            $errors['user'] = 'The username cannot contain spaces or quotes.';
        }
        if ($enabled) {
            if ($host === '') {
                $errors['host'] = 'Enter the SQL Server host before turning the connection on, or leave it off for now.';
            }
            if ($user === '') {
                $errors['user'] = 'Enter the read-only username before turning the connection on.';
            }
        }

        $mapping = [];
        foreach (self::MAPPING_KEYS as $k) {
            $mapping[$k] = $str($k);
        }
        $ident = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*){0,2}$/';
        if ($mapping['cms_customer_table'] !== '' && !preg_match($ident, $mapping['cms_customer_table'])) {
            $errors['cms_customer_table'] = 'Enter the table name as the CMS administrator gives it, for example dbo.Customers. Letters, numbers, underscores and dots only.';
        }
        foreach (['cms_customer_id_column' => 'customer ID', 'cms_customer_name_column' => 'customer name', 'cms_customer_active_column' => 'active flag'] as $k => $label) {
            if ($mapping[$k] !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $mapping[$k])) {
                $errors[$k] = "Enter just the column name that holds the $label (letters, numbers and underscores). Ask the CMS administrator if you are not sure.";
            }
        }
        if ($mapping['cms_customer_filter'] !== '') {
            if (strlen($mapping['cms_customer_filter']) > 500 || preg_match('/;|--|\/\*|\bexec\b|\binsert\b|\bupdate\b|\bdelete\b|\bdrop\b|\balter\b|\bmerge\b/i', $mapping['cms_customer_filter'])) {
                $errors['cms_customer_filter'] = 'The filter is the part after WHERE, such as IsActive = 1 AND Type = \'CUST\'. It cannot contain semicolons, comments or anything that changes data.';
            }
        }
        if ($enabled && $mapping['cms_customer_table'] === '') {
            $errors['cms_customer_table'] = 'Enter which CMS table holds customers before turning the sync on. Ask the CMS administrator; Chroma does not guess.';
        }

        if ($errors) {
            throw new ValidationException($errors, 'Please fix the highlighted fields.');
        }
        return [
            'host' => $host, 'port' => $port, 'database' => $database, 'user' => $user, 'password' => $password,
            'clear_password' => $clearPassword, 'enabled' => $enabled, 'deactivate_missing' => $bool('deactivate_missing'),
        ] + $mapping;
    }

    // ------------------------------------------------------------------ query

    /** The SELECT that the sync runs, built only from the mapping. */
    public function customerQuery(?int $top = null): string
    {
        $m = $this->mapping();
        if (!$this->hasMapping()) {
            throw ValidationException::general('The customer table and columns have not been entered yet. Ask the CMS administrator which table holds customers and fill in the mapping first.');
        }
        $cols = [self::quoteIdent($m['cms_customer_id_column']) . ' AS cms_id', self::quoteIdent($m['cms_customer_name_column']) . ' AS cms_name'];
        if ($m['cms_customer_active_column'] !== '') {
            $cols[] = self::quoteIdent($m['cms_customer_active_column']) . ' AS cms_active';
        }
        $sql = 'SELECT ' . ($top !== null ? 'TOP ' . (int) $top . ' ' : '') . implode(', ', $cols) . ' FROM ' . self::quoteTable($m['cms_customer_table']);
        if ($m['cms_customer_filter'] !== '') {
            $sql .= ' WHERE ' . $m['cms_customer_filter'];
        }
        $sql .= ' ORDER BY ' . self::quoteIdent($m['cms_customer_id_column']);
        return $sql;
    }

    private static function quoteIdent(string $name): string
    {
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private static function quoteTable(string $name): string
    {
        return implode('.', array_map(fn($p) => self::quoteIdent($p), explode('.', $name)));
    }

    /** Open the MSSQL connection (read-only intent). Throws a plain-language RuntimeException on failure. */
    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (!self::driverInstalled()) {
            throw new \RuntimeException(self::DRIVER_MISSING_TEXT);
        }
        if (!$this->hasConnectionDetails()) {
            throw new \RuntimeException('The CMS host, username and password have not all been entered yet. Fill them in and save, then test again.');
        }
        $s = $this->connectionSummary();
        $dsn = sprintf('sqlsrv:Server=%s,%s;Database=%s;LoginTimeout=8;TrustServerCertificate=1;ApplicationIntent=ReadOnly', $s['host'], $s['port'], $s['database']);
        try {
            $this->pdo = new PDO($dsn, $s['user'], (string) $this->config->get('CMS_PASS', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (\PDOException $e) {
            throw new \RuntimeException('Could not connect to the CMS SQL Server at ' . $s['host'] . ':' . $s['port'] . '. Check the host, port, username and password, and that this server can reach it on the network. The driver said: ' . self::shorten($e->getMessage()), 0, $e);
        }
        return $this->pdo;
    }

    private static function shorten(string $msg): string
    {
        $msg = preg_replace('/\s+/', ' ', $msg) ?? $msg;
        return mb_strlen($msg) > 300 ? mb_substr($msg, 0, 300) . '…' : $msg;
    }

    /** @return list<array<string,mixed>> */
    public function preview(int $limit = 10): array
    {
        $sql = $this->customerQuery($limit);
        try {
            return $this->pdo()->query($sql)->fetchAll();
        } catch (\PDOException $e) {
            throw new \RuntimeException('The CMS connection works but the customer query failed. Check the table and column names with the CMS administrator. The server said: ' . self::shorten($e->getMessage()), 0, $e);
        }
    }

    /** @return iterable<array{id:string,name:string,active:bool}> */
    public function fetchCustomers(): iterable
    {
        $sql = $this->customerQuery();
        try {
            $stmt = $this->pdo()->query($sql);
        } catch (\PDOException $e) {
            throw new \RuntimeException('The CMS connection works but the customer query failed. Check the table and column names with the CMS administrator. The server said: ' . self::shorten($e->getMessage()), 0, $e);
        }
        $hasActive = $this->mapping()['cms_customer_active_column'] !== '';
        foreach ($stmt as $row) {
            yield self::normalizeRow($row, $hasActive);
        }
    }

    /**
     * Turn a raw mapped row into the shape CustomerSync expects.
     * @param array<string,mixed> $row
     * @return array{id:string,name:string,active:bool}
     */
    public static function normalizeRow(array $row, bool $hasActive): array
    {
        $active = true;
        if ($hasActive && array_key_exists('cms_active', $row)) {
            $v = $row['cms_active'];
            $active = is_bool($v) ? $v : in_array(strtolower(trim((string) $v)), ['1', 'true', 'y', 'yes', 'a', 'active'], true);
        }
        return [
            'id' => trim((string) ($row['cms_id'] ?? '')),
            'name' => trim((string) ($row['cms_name'] ?? '')),
            'active' => $active,
        ];
    }
}
