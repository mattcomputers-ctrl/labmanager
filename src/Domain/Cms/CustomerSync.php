<?php
declare(strict_types=1);

namespace Chroma\Domain\Cms;

use Chroma\Core\Db;
use Chroma\Core\Settings;
use Chroma\Db\Seeder;
use Chroma\Domain\Audit\AuditLog;
use Chroma\Domain\Customers\Customers;

/**
 * Pulls the customer list from a CustomerSource into chroma_customers.
 *
 * Rules (idempotent: running twice changes nothing the second time):
 *   1. A CMS row is matched first on cms_customer_id, then on normalized
 *      name (Customers::findByName). A name match adopts the CMS id so it
 *      is matched by id next time.
 *   2. Rows with no match are created with source 'cms'.
 *   3. The name of a cms-sourced customer follows the CMS. Manually added
 *      customers keep their name (the lab typed it on purpose).
 *   4. A cms-sourced customer that disappeared from the CMS is marked
 *      inactive only when the cms_deactivate_missing setting is on
 *      (default: off). Nothing is ever deleted.
 *   5. Chroma never writes to the CMS.
 */
final class CustomerSync
{
    public function __construct(
        private readonly Db $db,
        private readonly Settings $settings,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Run a sync and return the counts. Records a row in chroma_cms_sync_runs
     * and updates the cms_last_sync_* settings whatever happens.
     *
     * @return array{run_id:int,status:string,created:int,updated:int,matched:int,deactivated:int,skipped:int,total:int,message:string}
     */
    public function run(CustomerSource $source, ?int $userId = null, string $trigger = 'manual'): array
    {
        $runId = $this->db->insert('chroma_cms_sync_runs', [
            'started_at' => $this->db->now(), 'started_by' => $userId, 'status' => 'running', 'message' => 'Started (' . $trigger . ')',
        ]);
        $counts = ['created' => 0, 'updated' => 0, 'matched' => 0, 'deactivated' => 0, 'skipped' => 0, 'total' => 0];
        try {
            $counts = $this->apply($source, $userId, $counts);
            $message = sprintf('%d from CMS: %d new, %d renamed, %d already matched, %d deactivated, %d skipped',
                $counts['total'], $counts['created'], $counts['updated'], $counts['matched'], $counts['deactivated'], $counts['skipped']);
            $status = 'ok';
        } catch (\Throwable $e) {
            $message = 'Failed: ' . $e->getMessage();
            $status = 'failed';
        }
        $this->db->update('chroma_cms_sync_runs', [
            'finished_at' => $this->db->now(), 'status' => $status,
            'created_count' => $counts['created'], 'updated_count' => $counts['updated'],
            'message' => mb_substr($message, 0, 500),
        ], ['id' => $runId]);
        $this->settings->set('cms_last_sync_at', $this->db->now(), $userId);
        $this->settings->set('cms_last_sync_result', mb_substr($status === 'ok' ? 'OK: ' . $message : $message, 0, 500), $userId);
        $this->audit->record('cms.sync', 'chroma_cms_sync_runs', $runId, null, ['status' => $status] + $counts, mb_substr($message, 0, 500), $userId);
        return ['run_id' => $runId, 'status' => $status, 'message' => $message] + $counts;
    }

    /**
     * @param array<string,int> $counts
     * @return array<string,int>
     */
    private function apply(CustomerSource $source, ?int $userId, array $counts): array
    {
        $customers = new Customers($this->db);
        $seen = [];
        $now = $this->db->now();
        foreach ($source->fetchCustomers() as $row) {
            $counts['total']++;
            $cmsId = mb_substr(trim((string) ($row['id'] ?? '')), 0, 50);
            $name = trim(preg_replace('/\s+/', ' ', (string) ($row['name'] ?? '')) ?? '');
            $active = !array_key_exists('active', $row) || (bool) $row['active'];
            if ($cmsId === '' || $name === '') {
                $counts['skipped']++;
                continue;
            }
            if (isset($seen[$cmsId])) {
                $counts['skipped']++; // duplicate id in the source: first one wins
                continue;
            }
            $seen[$cmsId] = true;
            $name = mb_substr($name, 0, 200);
            $norm = mb_substr(Seeder::normalizeName($name), 0, 200);

            $existing = $this->db->one('SELECT * FROM chroma_customers WHERE cms_customer_id = ?', [$cmsId]);
            if ($existing === null) {
                $byName = $customers->findByName($name);
                if ($byName !== null && ($byName['cms_customer_id'] === null || $byName['cms_customer_id'] === '')) {
                    $existing = $byName;
                }
            }

            if ($existing === null) {
                $id = $this->db->insert('chroma_customers', [
                    'name' => $name, 'name_normalized' => $norm, 'cms_customer_id' => $cmsId, 'active' => $active ? 1 : 0,
                    'source' => 'cms', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit->record('customer.create', 'chroma_customers', $id, null, ['name' => $name, 'cms_customer_id' => $cmsId, 'active' => $active], 'Created by CMS sync', $userId);
                $counts['created']++;
                continue;
            }

            $changes = [];
            if (($existing['cms_customer_id'] ?? null) !== $cmsId) {
                $changes['cms_customer_id'] = $cmsId;
            }
            if ($existing['source'] === 'cms') {
                if ($existing['name'] !== $name) {
                    $changes['name'] = $name;
                    $changes['name_normalized'] = $norm;
                }
                if ((int) $existing['active'] !== ($active ? 1 : 0)) {
                    $changes['active'] = $active ? 1 : 0;
                }
            }
            if ($changes) {
                $changes['updated_at'] = $now;
                $this->db->update('chroma_customers', $changes, ['id' => (int) $existing['id']]);
                $before = array_intersect_key($existing, $changes);
                $this->audit->record('customer.update', 'chroma_customers', (int) $existing['id'], $before, array_diff_key($changes, ['updated_at' => 1]), 'Updated by CMS sync', $userId);
                if (isset($changes['name']) || isset($changes['active'])) {
                    $counts['updated']++;
                } else {
                    $counts['matched']++;
                }
            } else {
                $counts['matched']++;
            }
        }

        if ($this->settings->bool('cms_deactivate_missing') && $seen !== []) {
            $rows = $this->db->all("SELECT id, name, cms_customer_id FROM chroma_customers WHERE source = 'cms' AND active = 1 AND cms_customer_id IS NOT NULL");
            foreach ($rows as $r) {
                if (!isset($seen[(string) $r['cms_customer_id']])) {
                    $this->db->update('chroma_customers', ['active' => 0, 'updated_at' => $now], ['id' => (int) $r['id']]);
                    $this->audit->record('customer.deactivate', 'chroma_customers', (int) $r['id'], ['active' => 1], ['active' => 0], 'No longer in the CMS', $userId);
                    $counts['deactivated']++;
                }
            }
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function recentRuns(int $limit = 10): array
    {
        return $this->db->all('SELECT r.*, u.display_name AS started_by_name FROM chroma_cms_sync_runs r LEFT JOIN chroma_users u ON u.id = r.started_by ORDER BY r.id DESC LIMIT ' . (int) $limit);
    }
}
