<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Cms\CmsConnection;
use Chroma\Domain\Cms\CustomerSync;
use Chroma\Http\Controller;

/** Admin → CMS connection: read-only Mar-Kov link and the customer sync. */
final class CmsController extends Controller
{
    private function cms(): CmsConnection
    {
        return new CmsConnection($this->app->config, $this->app->settings);
    }

    public function index(Request $request): Response
    {
        $cms = $this->cms();
        $preview = $this->app->session->pull('_cms_preview');
        return $this->view('admin/cms/index', [
            'title' => 'CMS connection',
            'conn' => $cms->connectionSummary(),
            'mapping' => $cms->mapping(),
            'enabled' => $cms->isEnabled(),
            'deactivateMissing' => $this->app->settings->bool('cms_deactivate_missing'),
            'driverStatus' => $cms->driverStatus($this->userId()),
            'driverText' => CmsConnection::DRIVER_MISSING_TEXT,
            'hasConnection' => $cms->hasConnectionDetails(),
            'hasMapping' => $cms->hasMapping(),
            'query' => $cms->hasMapping() ? $cms->customerQuery(10) : '',
            'lastSyncAt' => (string) $this->app->settings->get('cms_last_sync_at', ''),
            'lastSyncResult' => (string) $this->app->settings->get('cms_last_sync_result', ''),
            'runs' => (new CustomerSync($this->db, $this->app->settings, $this->app->audit))->recentRuns(10),
            'preview' => is_array($preview) ? $preview : null,
            'customerCounts' => $this->db->one("SELECT SUM(source = 'cms') AS cms, COUNT(*) AS total FROM chroma_customers"),
        ]);
    }

    public function save(Request $request): Response
    {
        [$before, $after] = $this->cms()->save($request->body, $this->userId());
        $this->app->audit->record('cms.settings', 'chroma_settings', null, $before, $after, 'CMS connection settings saved');
        $this->success('CMS connection settings saved.', $after['cms_enabled'] === '1' ? 'The nightly sync is on.' : 'The connection is off; nothing will be synced until you turn it on.');
        return $this->redirect('/admin/cms');
    }

    public function test(Request $request): Response
    {
        $cms = $this->cms();
        $result = ['ok' => false, 'rows' => [], 'message' => '', 'query' => ''];
        try {
            $result['query'] = $cms->customerQuery(10);
            $rows = $cms->preview(10);
            $result['ok'] = true;
            $result['rows'] = $rows;
            $result['message'] = $rows === []
                ? 'Connected and the query ran, but it returned no rows. Check the table name and filter with the CMS administrator.'
                : 'Connected. Here are the first ' . count($rows) . ' rows the query returns; check that the ID and name columns look right before syncing.';
        } catch (ValidationException|\RuntimeException $e) {
            $result['message'] = $e->getMessage();
        }
        $this->app->audit->record($result['ok'] ? 'cms.test_ok' : 'cms.test_failed', '', null, null, null, mb_substr($result['message'], 0, 500));
        if ($request->wantsJson()) {
            return $this->json($result, $result['ok'] ? 200 : 422);
        }
        $this->app->session->set('_cms_preview', $result);
        return $this->redirect('/admin/cms#test');
    }

    public function sync(Request $request): Response
    {
        $cms = $this->cms();
        if (!$cms->isEnabled()) {
            $this->failure('The CMS connection is turned off.', 'Turn it on and save before syncing customers.');
            return $this->redirect('/admin/cms');
        }
        $r = (new CustomerSync($this->db, $this->app->settings, $this->app->audit))->run($cms, $this->userId(), 'manual');
        if ($r['status'] === 'ok') {
            $this->success('Customers synced.', $r['message']);
        } else {
            $this->failure('The sync did not finish.', $r['message']);
        }
        return $this->redirect('/admin/cms#sync');
    }
}
