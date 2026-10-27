<?php
declare(strict_types=1);

namespace Chroma\Tests;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Api\Tokens;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Http\Controllers\Api\PublicApiController;

/**
 * The read-only JSON API: token auth, spelling tolerance, confidential
 * projects hidden, pagination and the envelope shape. The controller is
 * called directly with hand-built Requests, exactly as the router would.
 */
final class PublicApiTest extends TestCase
{
    private string $token;
    private CodeAssigner $codes;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = (new Tokens($this->db))->create('tests', $this->adminId);
        $this->codes = new CodeAssigner($this->db, $this->app->audit);
    }

    /** @param array<string,mixed> $query @param array<string,string> $params @return array{0:int,1:array<string,mixed>} */
    private function call(string $method, string $path, array $query = [], array $params = [], ?string $bearer = 'valid', array $headers = []): array
    {
        if ($bearer === 'valid') {
            $headers['authorization'] = 'Bearer ' . $this->token;
        } elseif ($bearer !== null) {
            $headers['authorization'] = 'Bearer ' . $bearer;
        }
        $request = new Request('GET', $path, $query, [], [], $headers, [], '127.0.0.1');
        $request->params = $params;
        $controller = new PublicApiController($this->app);
        /** @var Response $response */
        $response = $controller->$method($request);
        $this->assertStringStartsWith('application/json', $response->headers()['Content-Type'] ?? '');
        return [$response->status(), json_decode($response->body(), true)];
    }

    /** @param array<string,mixed> $over */
    private function project(int $number, string $type = 'color_match', array $over = []): int
    {
        $now = $this->db->now();
        $typeId = (int) $this->db->value('SELECT id FROM chroma_project_types WHERE code = ?', [$type]);
        return $this->db->insert('chroma_projects', $over + [
            'project_number' => $number, 'project_type_id' => $typeId, 'title' => "Project $number", 'status' => 'open',
            'date_submitted' => '2026-10-01', 'confidential' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // ------------------------------------------------------------------

    public function testMissingInvalidAndRevokedTokensGet401Json(): void
    {
        [$status, $body] = $this->call('codes', '/api/v1/codes', [], [], null);
        $this->assertSame(401, $status);
        $this->assertStringContainsString('Authorization header', $body['error']);
        $this->assertArrayNotHasKey('data', $body);

        [$status, $body] = $this->call('prefixes', '/api/v1/prefixes', [], [], 'chroma_notarealtoken');
        $this->assertSame(401, $status);
        $this->assertStringContainsString('not valid', $body['error']);

        [$status] = $this->call('prefixes', '/api/v1/prefixes', [], [], 'Bearer-less garbage');
        $this->assertSame(401, $status);

        [$status] = $this->call('prefixes', '/api/v1/prefixes');
        $this->assertSame(200, $status);
        $this->assertNotNull($this->db->value('SELECT last_used_at FROM chroma_api_tokens WHERE token_hash = ?', [hash('sha256', $this->token)]));

        $id = (int) $this->db->value('SELECT id FROM chroma_api_tokens WHERE token_hash = ?', [hash('sha256', $this->token)]);
        (new Tokens($this->db))->revoke($id);
        [$status, $body] = $this->call('prefixes', '/api/v1/prefixes');
        $this->assertSame(401, $status);
        $this->assertStringContainsString('revoked', $body['error']);

        // A token in the query string is NOT accepted: it would end up in web-server logs and browser history.
        [, $plain] = (new Tokens($this->db))->create('browser', $this->adminId);
        [$status, $body] = $this->call('prefixes', '/api/v1/prefixes', ['token' => $plain], [], null);
        $this->assertSame(401, $status);
        $this->assertStringContainsString('Authorization header', $body['error']);
    }

    public function testUnmatchedApiPathsAnswerJson404And405(): void
    {
        $this->app->loadRoutes();
        $router = $this->app->router;

        // The catch-all must never shadow a real endpoint.
        [$route, $params] = $router->match('GET', '/api/v1/projects/12');
        $this->assertSame('project', $route->handler[1]);
        $this->assertSame('12', $params['number']);
        [$route] = $router->match('GET', '/api/v1/codes/sell/Y1714UV');
        $this->assertSame('sell', $route->handler[1]);

        // Non-numeric project number, unknown path: JSON 404 even with a browser Accept header.
        foreach (['/api/v1/projects/abc', '/api/v1/nothing', '/api/v1/'] as $path) {
            [$route] = $router->match('GET', $path);
            $this->assertSame('unmatched', $route->handler[1], $path);
            [$status, $body] = $this->call('unmatched', $path, [], [], 'valid', ['accept' => 'text/html']);
            $this->assertSame(404, $status, $path);
            $this->assertStringContainsString($path, $body['error']);
            $this->assertArrayNotHasKey('data', $body);
        }

        // A write method on a real endpoint: JSON 405 with an Allow header.
        [$route] = $router->match('POST', '/api/v1/prefixes');
        $this->assertSame('unmatched', $route->handler[1]);
        $request = new Request('POST', '/api/v1/prefixes', [], [], [], ['authorization' => 'Bearer ' . $this->token, 'accept' => 'text/html'], [], '127.0.0.1');
        $response = (new PublicApiController($this->app))->unmatched($request);
        $this->assertSame(405, $response->status());
        $this->assertStringStartsWith('application/json', $response->headers()['Content-Type'] ?? '');
        $this->assertSame('GET, HEAD', $response->headers()['Allow'] ?? '');
        $this->assertStringContainsString('read-only', json_decode($response->body(), true)['error']);

        // A write method on an unknown path is a 404, and no token means 401 before anything else.
        $request = new Request('DELETE', '/api/v1/nothing', [], [], [], ['authorization' => 'Bearer ' . $this->token], [], '127.0.0.1');
        $this->assertSame(404, (new PublicApiController($this->app))->unmatched($request)->status());
        [$status] = $this->call('unmatched', '/api/v1/nothing', [], [], null);
        $this->assertSame(401, $status);
    }

    public function testCodeSearchAcceptsAnySpellingAndReturnsEnvelope(): void
    {
        $e = $this->codes->assignInternal(['description' => 'Blister purple formula'], $this->adminId); // E9102
        $s = $this->codes->assignSell(['prefix_id' => $this->prefixId('Y'), 'suffix_id' => $this->suffixId('UV'), 'description' => 'Yellow UV', 'internal_code_id' => $e['id']], $this->adminId); // Y1715UV
        $this->codes->manualSell('y-1000', ['description' => 'Legacy yellow'], $this->adminId);
        $this->codes->assignSell(['prefix_id' => $this->prefixId('B'), 'description' => 'Blue', 'internal_code_id' => $e['id']], $this->adminId); // B2971

        foreach (['y1715uv', 'Y-1715UV', 'y 1715 uv', 'Y1715'] as $spelling) {
            [$status, $body] = $this->call('codes', '/api/v1/codes', ['q' => $spelling]);
            $this->assertSame(200, $status, $spelling);
            $this->assertSame(['data', 'page', 'per_page', 'total'], array_keys($body));
            $this->assertSame(1, $body['total'], $spelling);
            $this->assertSame('Y1715UV', $body['data'][0]['sell_number']);
            $this->assertSame('E9102', $body['data'][0]['internal_code']);
            $this->assertFalse($body['data'][0]['voided']);
        }
        // Prefix match: "y1" finds both yellows (Y1000 and Y1715UV).
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'y1']);
        $this->assertSame(2, $body['total']);
        // Searching by E number lists every sell number linked to it.
        foreach (['E9102', 'e 9102', '9102'] as $spelling) {
            [, $body] = $this->call('codes', '/api/v1/codes', ['q' => $spelling]);
            $this->assertSame(['Y1715UV', 'B2971'], array_column($body['data'], 'sell_number'), $spelling);
        }
        // Free text hits descriptions.
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'legacy']);
        $this->assertSame(['Y1000'], array_column($body['data'], 'sell_number'));
        // Free text that happens to end in digits ("pantone 300", "stress test 4") parses like a sell
        // number but PANTONE / STRESSTEST are not prefixes, so it must search descriptions like the app does.
        $this->codes->assignSell(['prefix_id' => $this->prefixId('B'), 'description' => 'Pantone 300 blue', 'internal_code_id' => $e['id']], $this->adminId); // B2972
        $this->codes->assignSell(['prefix_id' => $this->prefixId('B'), 'description' => 'stress test 4', 'internal_code_id' => $e['id']], $this->adminId); // B2973
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'pantone 300']);
        $this->assertSame(['B2972'], array_column($body['data'], 'sell_number'));
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'Pantone300']);
        $this->assertSame(['B2972'], array_column($body['data'], 'sell_number'), 'one word ending in digits is still free text');
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'stress test 4']);
        $this->assertSame(['B2973'], array_column($body['data'], 'sell_number'));
        // LIKE wildcards are plain text, not "everything".
        foreach (['%', '_', '%%', 'b%'] as $wild) {
            [$status, $body] = $this->call('codes', '/api/v1/codes', ['q' => $wild]);
            $this->assertSame(200, $status, $wild);
            $this->assertSame(0, $body['total'], "q=$wild must not list every code");
        }
        // Voided rows are flagged and can be excluded.
        $this->codes->voidSell((int) $s['id'], 'typo', $this->adminId);
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'Y1715UV']);
        $this->assertTrue($body['data'][0]['voided']);
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'Y1715UV', 'exclude_voided' => '1']);
        $this->assertSame(0, $body['total']);
    }

    public function testSingleSellAndInternalLookups(): void
    {
        $e = $this->codes->manualInternal('E6374-10', ['description' => 'Old formula v10'], $this->adminId);
        $this->codes->manualInternal('E6374-12', ['description' => 'Old formula v12'], $this->adminId);
        $this->codes->assignSell(['prefix_id' => $this->prefixId('R'), 'description' => 'Red', 'internal_code_id' => $e['id']], $this->adminId); // R2324
        $this->codes->assignSell(['prefix_id' => $this->prefixId('R'), 'description' => 'Red relabel', 'internal_code_id' => $e['id'], 'reason' => 'relabel'], $this->adminId); // R2325

        [$status, $body] = $this->call('sell', '/api/v1/codes/sell/r-2324', [], ['code' => 'r-2324']);
        $this->assertSame(200, $status);
        $this->assertSame('R2324', $body['data']['sell_number']);
        $this->assertSame('E6374-10', $body['data']['internal_code']);
        $this->assertSame(['R2325'], array_column($body['data']['aliases'], 'sell_number'));
        $this->assertSame('relabel', $body['data']['aliases'][0]['reason']);

        [$status, $body] = $this->call('sell', '/api/v1/codes/sell/Q9', [], ['code' => 'Q9']);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('No sell number matches', $body['error']);

        // Historical spellings resolve the way the lookup page resolves them: UVV185 is UVVL185, and the spelling kept in legacy_text counts too.
        $this->codes->manualSell('UVVL185', ['description' => 'Inherited UV varnish', 'legacy_text' => 'UVV185'], $this->adminId);
        $this->codes->manualSell('UVVL184', ['description' => 'Inherited UV varnish 184', 'legacy_text' => 'UVV-184'], $this->adminId);
        foreach (['UVV185', 'uvv185', 'UVV-185', 'UVVL185'] as $spelling) {
            [$status, $body] = $this->call('sell', '/api/v1/codes/sell/x', [], ['code' => $spelling]);
            $this->assertSame(200, $status, $spelling);
            $this->assertSame('UVVL185', $body['data']['sell_number'], $spelling);
        }
        [$status, $body] = $this->call('sell', '/api/v1/codes/sell/x', [], ['code' => 'UVV-184']);
        $this->assertSame(200, $status);
        $this->assertSame('UVVL184', $body['data']['sell_number']);
        foreach (['UVV185', 'UVV-184'] as $spelling) {
            [$status, $body] = $this->call('codes', '/api/v1/codes', ['q' => $spelling]);
            $this->assertSame(200, $status, $spelling);
            $this->assertSame(1, $body['total'], "search for $spelling");
        }

        foreach (['E6374-10', '6374-10', 'e6374-10'] as $spelling) {
            [$status, $body] = $this->call('internal', '/api/v1/codes/internal/x', [], ['code' => $spelling]);
            $this->assertSame(200, $status, $spelling);
            $this->assertSame('E6374-10', $body['data']['internal_code']);
            $this->assertSame('10', $body['data']['version']);
            $this->assertSame(['E6374-10', 'E6374-12'], $body['data']['versions']);
            $this->assertSame(['R2324', 'R2325'], array_column($body['data']['sell_numbers'], 'sell_number'));
        }
        // Number only: no base version exists, so the first version is returned with the list of all versions.
        [$status, $body] = $this->call('internal', '/api/v1/codes/internal/6374', [], ['code' => '6374']);
        $this->assertSame(200, $status);
        $this->assertSame('E6374-10', $body['data']['internal_code']);
        // A version that does not exist is a 404.
        [$status] = $this->call('internal', '/api/v1/codes/internal/E6374-99', [], ['code' => 'E6374-99']);
        $this->assertSame(404, $status);
        [$status, $body] = $this->call('internal', '/api/v1/codes/internal/banana', [], ['code' => 'banana']);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('E6374-10', $body['error']);
    }

    public function testProjectsExcludeConfidentialAndPaginate(): void
    {
        $now = $this->db->now();
        $custId = $this->db->insert('chroma_customers', ['name' => 'Acme', 'name_normalized' => 'acme', 'active' => 1, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
        for ($i = 1; $i <= 7; $i++) {
            $this->project(100 + $i, 'color_match', ['customer_id' => $custId, 'updated_at' => "2026-10-0$i 12:00:00"]);
        }
        $this->project(108, 'rnd', ['confidential' => 1]);
        $this->project(109, 'customer_issue', ['customer_id' => $custId, 'status' => 'completed', 'date_completed' => '2026-10-20']);
        $this->project(110, 'rnd', ['confidential' => 0]);

        [$status, $body] = $this->call('projects', '/api/v1/projects');
        $this->assertSame(200, $status);
        $this->assertSame(9, $body['total']);
        $this->assertNotContains(108, array_column($body['data'], 'project_number'), 'confidential project must not be listed');

        [, $body] = $this->call('projects', '/api/v1/projects', ['status' => 'open', 'per_page' => '3', 'page' => '2']);
        $this->assertSame(8, $body['total']);
        $this->assertSame(2, $body['page']);
        $this->assertSame(3, $body['per_page']);
        $this->assertCount(3, $body['data']);
        [, $last] = $this->call('projects', '/api/v1/projects', ['status' => 'open', 'per_page' => '3', 'page' => '3']);
        $this->assertCount(2, $last['data']);
        [, $empty] = $this->call('projects', '/api/v1/projects', ['status' => 'open', 'per_page' => '3', 'page' => '4']);
        $this->assertSame([], $empty['data']);
        // per_page is capped at 200 and never below 1
        [, $capped] = $this->call('projects', '/api/v1/projects', ['per_page' => '9999']);
        $this->assertSame(200, $capped['per_page']);

        [, $body] = $this->call('projects', '/api/v1/projects', ['status' => 'completed']);
        $this->assertSame([109], array_column($body['data'], 'project_number'));
        [, $body] = $this->call('projects', '/api/v1/projects', ['type' => 'rnd']);
        $this->assertSame([110], array_column($body['data'], 'project_number'));
        [, $body] = $this->call('projects', '/api/v1/projects', ['customer' => 'ac']);
        $this->assertSame(8, $body['total']);
        [, $body] = $this->call('projects', '/api/v1/projects', ['customer' => (string) $custId]);
        $this->assertSame(8, $body['total']);
        [, $body] = $this->call('projects', '/api/v1/projects', ['updated_since' => '2026-10-05T00:00:00Z']);
        $this->assertSame([110, 109, 107, 106, 105], array_column($body['data'], 'project_number'));

        [$status, $body] = $this->call('projects', '/api/v1/projects', ['status' => 'bogus']);
        $this->assertSame(422, $status);
        $this->assertStringContainsString('open, completed or cancelled', $body['error']);
        [$status] = $this->call('projects', '/api/v1/projects', ['updated_since' => 'not a date']);
        $this->assertSame(422, $status);
    }

    public function testSingleProjectWithItemsAndCodesHidesConfidential(): void
    {
        $now = $this->db->now();
        $pid = $this->project(120);
        $e = $this->codes->assignInternal(['description' => 'Pantone 663'], $this->adminId);
        $s = $this->codes->assignSell(['prefix_id' => $this->prefixId('P'), 'description' => 'Pantone 663', 'internal_code_id' => $e['id']], $this->adminId);
        $matched = (int) $this->db->value("SELECT id FROM chroma_item_statuses WHERE code = 'matched' AND project_type_id IS NULL");
        $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 1, 'kind' => 'color', 'description' => 'Pantone 663 Purple', 'internal_code_id' => $e['id'], 'sell_number_id' => $s['id'], 'status_id' => $matched, 'proof_count' => 2, 'proofs_generated' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('chroma_project_items', ['project_id' => $pid, 'sequence' => 2, 'kind' => 'color', 'description' => 'Second color', 'created_at' => $now, 'updated_at' => $now]);
        $this->project(121, 'rnd', ['confidential' => 1]);

        [$status, $body] = $this->call('project', '/api/v1/projects/120', [], ['number' => '120']);
        $this->assertSame(200, $status);
        $this->assertSame(120, $body['data']['project_number']);
        $this->assertSame(2, $body['data']['item_count']);
        $this->assertSame(1, $body['data']['items_done']);
        $this->assertCount(2, $body['data']['items']);
        $this->assertSame('E9102', $body['data']['items'][0]['internal_code']);
        $this->assertSame('P1556', $body['data']['items'][0]['sell_number']['sell_number']);
        $this->assertTrue($body['data']['items'][0]['done']);
        $this->assertNull($body['data']['items'][1]['sell_number']);
        $this->assertFalse($body['data']['items'][1]['done']);

        // The sell number search now knows which project the code came from.
        [, $body] = $this->call('codes', '/api/v1/codes', ['q' => 'P1556']);
        $this->assertSame(120, $body['data'][0]['project_number']);

        [$status, $body] = $this->call('project', '/api/v1/projects/121', [], ['number' => '121']);
        $this->assertSame(404, $status);
        $this->assertStringContainsString('121', $body['error']);
        [$status] = $this->call('project', '/api/v1/projects/999', [], ['number' => '999']);
        $this->assertSame(404, $status);
    }

    public function testPrefixesListAllowedSuffixesAndNextNumbers(): void
    {
        $this->codes->assignSell(['prefix_id' => $this->prefixId('T'), 'description' => 'tint'], $this->adminId); // T2450
        [$status, $body] = $this->call('prefixes', '/api/v1/prefixes');
        $this->assertSame(200, $status);
        $by = array_column($body['data'], null, 'prefix');
        $this->assertSame(['UV'], $by['T']['allowed_suffixes']);
        $this->assertSame([], $by['X']['allowed_suffixes']);
        $this->assertSame(2450, $by['T']['highest_number']);
        $this->assertSame(2451, $by['T']['next_number']);
        $this->assertSame(1, $by['T']['codes_in_use']);
        $this->assertTrue($by['CS']['retired']);
        $this->assertSame(6, $by['X']['min_digits']);
        $this->assertSame(count($body['data']), $body['total']);
    }
}
