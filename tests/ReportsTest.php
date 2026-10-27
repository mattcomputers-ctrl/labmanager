<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Reports\Reports;

/**
 * Report maths on a small, hand-built set of projects, items and codes.
 * "Today" is pinned to 2026-10-27 so every age and month is predictable.
 */
final class ReportsTest extends TestCase
{
    private const TODAY = '2026-10-27';

    /** @var array<string,int> */
    private array $types = [];
    /** @var array<string,int> */
    private array $statuses = [];
    /** @var array<string,int> */
    private array $stages = [];
    private int $techId;
    private int $custA;
    private int $custB;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->db->all('SELECT id, code FROM chroma_project_types') as $t) {
            $this->types[$t['code']] = (int) $t['id'];
        }
        foreach ($this->db->all('SELECT id, code FROM chroma_item_statuses WHERE project_type_id IS NULL') as $s) {
            $this->statuses[$s['code']] = (int) $s['id'];
        }
        foreach ($this->db->all('SELECT id, code FROM chroma_project_stages') as $s) {
            $this->stages[$s['code']] = (int) $s['id'];
        }
        $this->techId = $this->makeUser('tech', 'Lab');
        $this->custA = $this->customer('Acme Packaging');
        $this->custB = $this->customer('Bravo Labels');
    }

    private function reports(bool $confidential = false, ?int $viewerId = null): Reports
    {
        return new Reports($this->db, self::TODAY, $confidential, $viewerId);
    }

    private function customer(string $name): int
    {
        $now = $this->db->now();
        return $this->db->insert('chroma_customers', ['name' => $name, 'name_normalized' => strtolower($name), 'active' => 1, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string,mixed> $over */
    private function project(int $number, string $type, array $over = []): int
    {
        $now = $this->db->now();
        return $this->db->insert('chroma_projects', $over + [
            'project_number' => $number, 'project_type_id' => $this->types[$type], 'customer_id' => $this->custA, 'title' => "Project $number",
            'date_submitted' => self::TODAY, 'status' => 'open', 'assigned_user_id' => $this->adminId, 'confidential' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $over */
    private function item(int $projectId, string $status, array $over = []): int
    {
        $now = $this->db->now();
        return $this->db->insert('chroma_project_items', $over + [
            'project_id' => $projectId, 'sequence' => 1, 'kind' => 'color', 'description' => 'Item', 'status_id' => $this->statuses[$status],
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // ------------------------------------------------------------------

    public function testTurnaroundCountsAverageAndMedianPerGroup(): void
    {
        // Acme: 10, 20, 60 days -> avg 30, median 20. Bravo: 4 days. Open projects and ones without dates are ignored.
        $this->project(101, 'color_match', ['status' => 'completed', 'date_submitted' => '2026-09-01', 'date_completed' => '2026-09-11']);
        $this->project(102, 'color_match', ['status' => 'completed', 'date_submitted' => '2026-09-01', 'date_completed' => '2026-09-21', 'assigned_user_id' => $this->techId]);
        $this->project(103, 'color_match', ['status' => 'completed', 'date_submitted' => '2026-08-01', 'date_completed' => '2026-09-30']);
        $this->project(104, 'customer_issue', ['customer_id' => $this->custB, 'status' => 'completed', 'date_submitted' => '2026-10-01', 'date_completed' => '2026-10-05']);
        $this->project(105, 'color_match', ['status' => 'open']);
        $this->project(106, 'color_match', ['status' => 'completed', 'date_submitted' => null, 'date_completed' => '2026-10-05']);

        $r = $this->reports()->turnaround(['from' => '2026-01-01', 'to' => self::TODAY, 'by' => 'customer']);
        $rows = array_column($r['tables']['summary']['rows'], null, 'group');
        $this->assertSame(3, $rows['Acme Packaging']['count']);
        $this->assertSame(30.0, (float) $rows['Acme Packaging']['average']);
        $this->assertSame(20.0, (float) $rows['Acme Packaging']['median']);
        $this->assertSame(10, $rows['Acme Packaging']['fastest']);
        $this->assertSame(60, $rows['Acme Packaging']['slowest']);
        $this->assertSame(1, $rows['Bravo Labels']['count']);
        $this->assertSame(4.0, (float) $rows['Bravo Labels']['average']);
        $this->assertSame(4, $r['overall']['count']);
        $this->assertSame(23.5, (float) $r['overall']['average']);
        $this->assertSame(15.0, (float) $r['overall']['median']); // 4,10,20,60 -> (10+20)/2

        // By tech: the tech user completed one project (20 days).
        $r = $this->reports()->turnaround(['from' => '2026-01-01', 'to' => self::TODAY, 'by' => 'tech']);
        $rows = array_column($r['tables']['summary']['rows'], null, 'group');
        $this->assertSame(1, $rows['Tech']['count']);
        $this->assertSame(20.0, (float) $rows['Tech']['median']);
        $this->assertSame(3, $rows['Admin']['count']);

        // Date range is on the completed date.
        $r = $this->reports()->turnaround(['from' => '2026-10-01', 'to' => self::TODAY, 'by' => 'customer']);
        $this->assertSame(1, $r['overall']['count']);
        // Customer filter
        $r = $this->reports()->turnaround(['from' => '2026-01-01', 'to' => self::TODAY, 'by' => 'customer', 'customer_id' => $this->custB]);
        $this->assertSame(1, $r['overall']['count']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM chroma_projects WHERE project_number = 106"));
    }

    public function testAgingBucketsAndList(): void
    {
        $ages = [0, 7, 8, 14, 15, 30, 31, 60, 61, 200];
        foreach ($ages as $i => $age) {
            $this->project(200 + $i, 'color_match', ['date_submitted' => (new \DateTimeImmutable(self::TODAY))->modify("-$age days")->format('Y-m-d')]);
        }
        $this->project(299, 'color_match', ['status' => 'completed', 'date_submitted' => '2026-01-01', 'date_completed' => '2026-02-01']);
        $r = $this->reports()->aging([]);
        $this->assertSame(10, $r['total']);
        $counts = array_column($r['buckets'], 'count', 'key');
        $this->assertSame(['0-7' => 2, '8-14' => 2, '15-30' => 2, '31-60' => 2, '60+' => 2], $counts);
        $this->assertSame(200, $r['max_age']);
        $this->assertSame(42.6, $r['average_age']);
        // The list is oldest first and paginated.
        $this->assertSame(200, (int) $r['list'][0]['age']);
        $this->assertSame('60+ days', $r['list'][0]['bucket']);
        $page2 = $this->reports()->aging([], 2, 4);
        $this->assertCount(4, $page2['list']);
        $this->assertSame(10, $page2['list_total']);
        $this->assertSame(30, (int) $page2['list'][0]['age']);
        // Bucket filter narrows the list but not the bucket counts.
        $b = $this->reports()->aging(['bucket' => '31-60']);
        $this->assertSame(2, $b['list_total']);
        $this->assertSame([60, 31], array_map(fn($p) => (int) $p['age'], $b['list']));
        $this->assertSame(10, $b['total']);
        $this->assertCount(10, $this->reports()->agingAll([]));
        $this->assertSame('0-7 days', Reports::bucketFor(7));
        $this->assertSame('8-14 days', Reports::bucketFor(8));
        $this->assertSame('60+ days', Reports::bucketFor(61));
    }

    public function testCodesPerMonthPivotIncludesENumbersAndVoided(): void
    {
        $codes = new CodeAssigner($this->db, $this->app->audit);
        $y = $this->prefixId('Y');
        $t = $this->prefixId('T');
        $a = $codes->assignSell(['prefix_id' => $y, 'description' => 'y1', 'date_assigned' => '2026-10-02'], $this->adminId);
        $codes->assignSell(['prefix_id' => $y, 'description' => 'y2', 'date_assigned' => '2026-09-15'], $this->adminId);
        $codes->assignSell(['prefix_id' => $t, 'description' => 't1', 'date_assigned' => '2026-09-01'], $this->adminId);
        $codes->assignSell(['prefix_id' => $t, 'description' => 'old', 'date_assigned' => '2025-10-31'], $this->adminId); // outside the 12-month window
        $codes->assignInternal(['description' => 'e1', 'date_assigned' => '2026-10-27'], $this->adminId);
        $codes->assignInternal(['description' => 'e2', 'date_assigned' => '2026-08-03'], $this->adminId);
        $codes->voidSell((int) $a['id'], 'typo', $this->adminId);

        $r = $this->reports()->codesPerMonth(['to' => self::TODAY]);
        $this->assertCount(12, $r['months']);
        $this->assertSame('2025-11', $r['months'][0]);
        $this->assertSame('2026-10', $r['months'][11]);
        $rows = array_column($r['rows'], null, 'prefix');
        $this->assertSame(1, $rows['Y']['2026-10']);
        $this->assertSame(1, $rows['Y']['2026-09']);
        $this->assertSame(2, $rows['Y']['total']);
        $this->assertSame(1, $rows['Y']['voided']);
        $this->assertSame(1, $rows['T']['total']); // the 2025-10-31 one is outside the window
        $this->assertSame(0, $rows['T']['2026-10']);
        $this->assertSame(3, $r['totals_row']['total']);
        $this->assertSame(2, $r['totals_row']['2026-09']);
        $this->assertSame(2, $r['e_row']['total']);
        $this->assertSame(1, $r['e_row']['2026-08']);
        $this->assertSame('Y', $r['rows'][0]['prefix'], 'busiest prefix first');
        // Retired prefixes with nothing in the window are hidden; active ones with zero stay.
        $this->assertArrayNotHasKey('CS', $rows);
        $this->assertArrayHasKey('X', $rows);
        // Prefix filter
        $only = $this->reports()->codesPerMonth(['to' => self::TODAY, 'prefix_id' => $t]);
        $this->assertSame(['T'], array_column($only['rows'], 'prefix'));
        // Export table ends with the totals and E rows
        $export = $only['tables']['pivot']['rows'];
        $this->assertSame('E', end($export)['prefix']);
    }

    public function testProofsPerMonthAndTech(): void
    {
        $p = $this->project(301, 'color_match', ['date_submitted' => '2026-09-10']);
        $this->item($p, 'matched', ['proofs_generated' => 1, 'proof_count' => 3, 'date_completed' => '2026-09-20']);
        $this->item($p, 'matched', ['sequence' => 2, 'proofs_generated' => 1, 'proof_count' => 2, 'date_completed' => '2026-10-02', 'assigned_user_id' => $this->techId]);
        $this->item($p, 'pending', ['sequence' => 3, 'proofs_generated' => 0, 'proof_count' => 0]); // dated by project submitted date
        $r = $this->reports()->proofs(['from' => '2026-09-01', 'to' => self::TODAY]);
        $this->assertSame(5, $r['total_proofs']);
        $this->assertSame(2, $r['total_items_with_proofs']);
        $months = array_column($r['tables']['month']['rows'], null, 'ym');
        $this->assertSame(3, $months['2026-09']['proofs']);
        $this->assertSame(2, $months['2026-09']['items']);
        $this->assertSame(2, $months['2026-10']['proofs']);
        $techs = array_column($r['tables']['tech']['rows'], null, 'tech');
        $this->assertSame(3, $techs['Admin']['proofs']);
        $this->assertSame(2, $techs['Tech']['proofs']);
        $this->assertSame(1, $techs['Tech']['items_with_proofs']);
        // Tech filter follows the item's tech, falling back to the project's.
        $r = $this->reports()->proofs(['from' => '2026-09-01', 'to' => self::TODAY, 'user_id' => $this->techId]);
        $this->assertSame(2, $r['total_proofs']);
    }

    public function testRndByStageListsEveryStageAndHidesConfidentialUnlessAllowed(): void
    {
        $this->project(401, 'rnd', ['stage_id' => $this->stages['bench'], 'date_submitted' => '2026-10-17']);
        $this->project(402, 'rnd', ['stage_id' => $this->stages['bench'], 'date_submitted' => '2026-10-07']);
        $this->project(403, 'rnd', ['stage_id' => $this->stages['idea'], 'confidential' => 1]);
        $this->project(404, 'rnd', ['stage_id' => $this->stages['released'], 'status' => 'completed', 'date_completed' => self::TODAY]);
        $this->project(405, 'rnd', ['stage_id' => null]);
        $this->project(406, 'color_match'); // not R&D

        $r = $this->reports()->rndByStage(['status' => 'open']);
        $this->assertSame(3, $r['total']); // 401, 402, 405; 403 confidential hidden; 404 completed
        $rows = array_column($r['tables']['stages']['rows'], null, 'stage');
        $this->assertSame(2, $rows['Bench']['count']);
        $this->assertSame(15.0, (float) $rows['Bench']['avg_age']);
        $this->assertSame(0, $rows['Idea']['count']);
        $this->assertSame(1, $rows['(no stage yet)']['count']);
        $this->assertArrayHasKey('Released', $rows, 'empty stages still listed');

        $all = $this->reports(true)->rndByStage(['status' => 'all']);
        $this->assertSame(5, $all['total']);
        $this->assertSame(1, array_column($all['tables']['stages']['rows'], null, 'stage')['Idea']['count']);
        $this->assertSame(1, array_column($all['tables']['stages']['rows'], null, 'stage')['Released']['count']);
    }

    public function testConfidentialProjectsCountForTheirLeadCreatorAndTeamWithoutThePermission(): void
    {
        $lead = $this->makeUser('lead', 'Lab');
        $creator = $this->makeUser('creator', 'Lab');
        $member = $this->makeUser('member', 'Lab');
        $outsider = $this->makeUser('outsider', 'Lab');
        $secret = $this->project(601, 'rnd', ['stage_id' => $this->stages['bench'], 'confidential' => 1, 'assigned_user_id' => $lead, 'created_by' => $creator, 'date_submitted' => '2026-10-17', 'customer_id' => null]);
        $this->db->run('INSERT INTO chroma_project_team (project_id, user_id) VALUES (?, ?)', [$secret, $member]);
        $this->item($secret, 'matched');
        $this->item($secret, 'in_progress', ['sequence' => 2]);
        $this->project(602, 'rnd', ['stage_id' => $this->stages['idea'], 'date_submitted' => '2026-10-20', 'customer_id' => null]);

        // Lead, creator and team member each see the confidential project; an outsider and an anonymous viewer do not.
        foreach (['lead' => $lead, 'creator' => $creator, 'team member' => $member] as $who => $uid) {
            $r = $this->reports(false, $uid)->rndByStage(['status' => 'open']);
            $this->assertSame(2, $r['total'], "$who sees it in R&D by stage");
            $this->assertContains(601, array_column($r['projects'], 'project_number'), $who);
            $aging = $this->reports(false, $uid)->aging([], 1, 50);
            $this->assertSame(2, $aging['total'], "$who sees it in aging");
            $this->assertSame(10, (int) $aging['max_age'], "$who: oldest open is the confidential one");
            $this->assertStringContainsString('oldest 10 days', $this->reports(false, $uid)->headlines()['aging']['label'], "$who: headline agrees");
            $items = $this->reports(false, $uid)->itemsDone(['status' => 'open', 'by' => 'type']);
            $this->assertSame(2, $items['total'], "$who sees its items");
            $this->assertSame(1, $items['done'], $who);
        }
        foreach (['outsider' => $outsider, 'anonymous' => null] as $who => $uid) {
            $r = $this->reports(false, $uid)->rndByStage(['status' => 'open']);
            $this->assertSame(1, $r['total'], "$who must not see it");
            $this->assertNotContains(601, array_column($r['projects'], 'project_number'), $who);
            $this->assertSame(1, $this->reports(false, $uid)->aging([], 1, 50)['total'], $who);
            $this->assertSame(0, $this->reports(false, $uid)->itemsDone(['status' => 'open', 'by' => 'type'])['total'], $who);
        }
        // The permission still shows everything to anyone who holds it.
        $this->assertSame(2, $this->reports(true, $outsider)->rndByStage(['status' => 'open'])['total']);
    }

    public function testItemsDoneVsTotalByTypeTechAndCustomer(): void
    {
        $p1 = $this->project(501, 'color_match');
        $this->item($p1, 'matched');
        $this->item($p1, 'approved', ['sequence' => 2]);
        $this->item($p1, 'rejected', ['sequence' => 3]);
        $this->item($p1, 'pending', ['sequence' => 4, 'assigned_user_id' => $this->techId]);
        $p2 = $this->project(502, 'customer_issue', ['customer_id' => $this->custB]);
        $this->item($p2, 'in_progress');
        $this->item($p2, 'not_needed', ['sequence' => 2]);
        $done = $this->project(503, 'color_match', ['status' => 'completed', 'date_completed' => self::TODAY]);
        $this->item($done, 'matched');
        $conf = $this->project(504, 'rnd', ['confidential' => 1]);
        $this->item($conf, 'pending');

        $r = $this->reports()->itemsDone(['by' => 'type', 'status' => 'open']);
        $this->assertSame(6, $r['total']);
        $this->assertSame(4, $r['done']);
        $this->assertSame(67, $r['percent']);
        $rows = array_column($r['tables']['summary']['rows'], null, 'group');
        $this->assertSame(['total' => 4, 'done' => 3, 'succeeded' => 2, 'remaining' => 1, 'percent' => 75.0], array_intersect_key($rows['Color match'], array_flip(['total', 'done', 'succeeded', 'remaining', 'percent'])));
        $this->assertSame(2, $rows['Customer issue']['total']);
        $this->assertSame(1, $rows['Customer issue']['done']);
        $this->assertArrayNotHasKey('R&D', $rows, 'confidential hidden');

        $byTech = array_column($this->reports()->itemsDone(['by' => 'tech', 'status' => 'open'])['tables']['summary']['rows'], null, 'group');
        $this->assertSame(1, $byTech['Tech']['total']);
        $this->assertSame(5, $byTech['Admin']['total']);

        $byCust = array_column($this->reports()->itemsDone(['by' => 'customer', 'status' => 'all'])['tables']['summary']['rows'], null, 'group');
        $this->assertSame(5, $byCust['Acme Packaging']['total']);
        $this->assertSame(2, $byCust['Bravo Labels']['total']);

        $withConf = $this->reports(true)->itemsDone(['by' => 'type', 'status' => 'open']);
        $this->assertSame(7, $withConf['total']);
    }

    public function testHeadlinesAndFilterParsing(): void
    {
        $this->project(601, 'color_match', ['date_submitted' => '2026-10-01']);
        $h = $this->reports()->headlines();
        $this->assertSame('1', $h['aging']['value']);
        $this->assertStringContainsString('oldest 26 days', $h['aging']['label']);
        $this->assertSame('0 / 0', $h['items']['value']);

        $parsed = $this->reports()->parseFilters(['from' => 'yesterday', 'to' => '2026-10-01', 'customer_id' => 'abc', 'user_id' => '7', 'by' => 'nope', 'status' => 'open', 'bucket' => '60+'], ['by' => 'customer']);
        $this->assertCount(1, $parsed['problems']);
        $this->assertStringContainsString('"from" date', $parsed['problems'][0]);
        $this->assertNull($parsed['filters']['from']);
        $this->assertSame('2026-10-01', $parsed['filters']['to']);
        $this->assertNull($parsed['filters']['customer_id']);
        $this->assertSame(7, $parsed['filters']['user_id']);
        $this->assertSame('customer', $parsed['filters']['by']);
        $this->assertSame('open', $parsed['filters']['status']);
        $this->assertSame('60+', $parsed['filters']['bucket']);

        $swapped = $this->reports()->parseFilters(['from' => '2026-10-20', 'to' => '2026-10-01']);
        $this->assertSame(['2026-10-01', '2026-10-20'], [$swapped['filters']['from'], $swapped['filters']['to']]);
        $this->assertStringContainsString('swapped', $swapped['problems'][0]);
    }
}
