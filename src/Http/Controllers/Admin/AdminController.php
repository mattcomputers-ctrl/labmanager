<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Http\Controller;

final class AdminController extends Controller
{
    /** The admin areas, with the permission that unlocks each. Used by the admin home and the admin side nav. */
    public static function areas(): array
    {
        return [
            ['Prefixes', '/admin/prefixes', Permissions::ADMIN_PREFIXES, 'Sell number prefixes: add, edit, retire, allowed suffixes, next numbers.'],
            ['Suffixes', '/admin/suffixes', Permissions::ADMIN_PREFIXES, 'The master list of suffixes such as UV.'],
            ['Sequences', '/admin/sequences', Permissions::ADMIN_SEQUENCES, 'Next E number and next project number.'],
            ['Lists', '/admin/lists', Permissions::ADMIN_LISTS, 'Ink series, coatings, substrates, project types, stages, item statuses.'],
            ['Customers', '/admin/customers', Permissions::ADMIN_LISTS, 'Customer names used on projects and codes.'],
            ['Users & groups', '/admin/users', Permissions::ADMIN_USERS, 'Accounts, groups and what each group may do.'],
            ['Import legacy codes', '/admin/import', Permissions::ADMIN_IMPORT, 'Bring in the three spreadsheets; resolve import issues.'],
            ['CMS connection', '/admin/cms', Permissions::ADMIN_CMS, 'Read-only Mar-Kov connection and customer sync.'],
            ['Network', '/admin/network', Permissions::ADMIN_NETWORK, 'Server IP address, gateway and DNS.'],
            ['System', '/admin/system', Permissions::ADMIN_SYSTEM, 'Version, updates, backups, API tokens, audit log.'],
        ];
    }

    public function index(Request $request): Response
    {
        $areas = array_values(array_filter(self::areas(), fn($a) => $this->auth->can($a[2])));
        return $this->view('admin/index', ['title' => 'Admin', 'areas' => $areas]);
    }
}
