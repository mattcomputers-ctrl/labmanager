<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Admin;

use Chroma\Core\App;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Core\ValidationException;
use Chroma\Domain\Export\Exporter;
use Chroma\Domain\Lists\ListAdmin;
use Chroma\Http\Controller;

/** Admin → Customers: the names used on projects and codes. Synced from the CMS, with manual additions and merges. */
final class CustomersController extends Controller
{
    private ListAdmin $lists;

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->lists = new ListAdmin($this->db, $app->audit);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->str('q'),
            'status' => in_array($request->str('status'), ['active', 'inactive', 'all'], true) ? $request->str('status') : 'active',
            'source' => in_array($request->str('source'), ['manual', 'import', 'cms', 'system'], true) ? $request->str('source') : '',
        ];
        $export = $request->str('export');
        if (in_array($export, ['csv', 'xlsx'], true)) {
            $rows = $this->lists->customers($filters, 100000, 0)['rows'];
            return Exporter::respond($export, 'customers', ['name' => 'Customer', 'source' => 'Source', 'cms_customer_id' => 'CMS id', 'active' => 'Active', 'project_count' => 'Projects', 'code_count' => 'Codes', 'notes' => 'Notes'],
                array_map(fn($c) => ['name' => $c['name'], 'source' => $c['source'], 'cms_customer_id' => $c['cms_customer_id'] ?? '', 'active' => (int) $c['active'] === 1, 'project_count' => (int) $c['project_count'], 'code_count' => (int) $c['code_count'], 'notes' => $c['notes'] ?? ''], $rows));
        }
        $paging = $this->paging($request, is_easy() ? 25 : 50);
        $list = $this->lists->customers($filters, $paging['per_page'], $paging['offset']);
        return $this->view('admin/customers/index', [
            'title' => 'Customers',
            'rows' => $list['rows'],
            'total' => $list['total'],
            'paging' => $paging,
            'filters' => $filters,
        ]);
    }

    public function newForm(Request $request): Response
    {
        return $this->view('admin/customers/form', ['title' => 'Add a customer', 'customer' => null]);
    }

    public function create(Request $request): Response
    {
        $id = $this->lists->addCustomer(['name' => $request->str('name'), 'notes' => $request->str('notes')]);
        $this->success('Customer added.');
        return $this->done($request, '/admin/customers/' . $id);
    }

    public function edit(Request $request): Response
    {
        $customer = $this->lists->customer((int) $request->params['id']);
        if ($customer === null) {
            $this->notFound('There is no customer with that id.');
        }
        return $this->view('admin/customers/form', [
            'title' => $customer['name'],
            'customer' => $customer,
            'similar' => $this->lists->similarCustomers((int) $customer['id']),
            'history' => $this->app->audit->forRow('chroma_customers', (int) $customer['id'], 15),
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->lists->updateCustomer($id, ['name' => $request->str('name'), 'notes' => $request->str('notes')]);
        $this->success('Customer saved.');
        return $this->done($request, '/admin/customers/' . $id);
    }

    public function deactivate(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->lists->setCustomerActive($id, false);
        $this->success('Customer deactivated.', 'It no longer appears when picking a customer. Existing projects and codes keep the name.');
        return $this->done($request, '/admin/customers/' . $id);
    }

    public function reactivate(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $this->lists->setCustomerActive($id, true);
        $this->success('Customer reactivated.');
        return $this->done($request, '/admin/customers/' . $id);
    }

    public function mergeForm(Request $request): Response
    {
        $customer = $this->lists->customer((int) $request->params['id']);
        if ($customer === null) {
            $this->notFound('There is no customer with that id.');
        }
        $dupId = $request->int('duplicate_id');
        $duplicate = $dupId ? $this->lists->customer($dupId) : null;
        return $this->view('admin/customers/merge', [
            'title' => 'Merge into ' . $customer['name'],
            'customer' => $customer,
            'duplicate' => $duplicate,
            'similar' => $this->lists->similarCustomers((int) $customer['id']),
        ]);
    }

    public function merge(Request $request): Response
    {
        $keepId = (int) $request->params['id'];
        $dupId = $request->int('duplicate_id') ?? 0;
        if ($dupId <= 0) {
            throw ValidationException::single('duplicate_id', 'Pick the duplicate customer to merge into this one.');
        }
        $moved = $this->lists->mergeCustomers($keepId, $dupId);
        $this->success('Customers merged.', sprintf('Moved %s, %s and %s. The duplicate is now deactivated.', plural($moved['projects'], 'project'), plural($moved['internal_codes'], 'E number'), plural($moved['sell_numbers'], 'sell number')));
        return $this->done($request, '/admin/customers/' . $keepId);
    }
}
