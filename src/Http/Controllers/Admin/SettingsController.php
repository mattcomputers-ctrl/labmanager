<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\System\AppSettings;
use Chroma\Http\Controller;

/** Admin → Settings: company and app names, time zone, SDS link, limits. */
final class SettingsController extends Controller
{
    private function settings(): AppSettings
    {
        return new AppSettings($this->app->settings, $this->app->audit);
    }

    public function index(Request $request): Response
    {
        return $this->view('admin/settings/index', [
            'title' => 'Settings',
            'values' => $this->settings()->current(),
            'timezones' => AppSettings::TIMEZONES,
        ]);
    }

    public function save(Request $request): Response
    {
        $changed = $this->settings()->save($request->body, $this->userId());
        if ($changed) {
            $this->success('Settings saved.', count($changed) . ' ' . (count($changed) === 1 ? 'setting' : 'settings') . ' changed.');
        } else {
            $this->app->flash->info('Nothing changed.');
        }
        return $this->done($request, '/admin/settings');
    }
}
