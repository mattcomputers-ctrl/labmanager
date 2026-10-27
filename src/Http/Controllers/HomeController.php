<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers;

use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Http\Controller;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $uid = $this->userId();
        $data = ['title' => 'Home'];
        $data['tasks'] = $this->db->all(
            'SELECT t.*, p.project_number, p.title AS project_title FROM chroma_project_tasks t
             JOIN chroma_projects p ON p.id = t.project_id
             WHERE t.owner_user_id = ? AND t.done = 0 ORDER BY t.due_date IS NULL, t.due_date, t.id LIMIT 20',
            [$uid]
        );
        $data['myProjects'] = $this->auth->can(Permissions::PROJECTS_VIEW) ? $this->db->all(
            'SELECT p.id, p.project_number, p.title, p.status, p.date_submitted, pt.label AS type_label, pt.color AS type_color, c.name AS customer,
                    (SELECT COUNT(*) FROM chroma_project_items i WHERE i.project_id = p.id) AS item_count,
                    (SELECT COUNT(*) FROM chroma_project_items i JOIN chroma_item_statuses s ON s.id = i.status_id WHERE i.project_id = p.id AND s.is_done = 1) AS items_done
             FROM chroma_projects p JOIN chroma_project_types pt ON pt.id = p.project_type_id LEFT JOIN chroma_customers c ON c.id = p.customer_id
             WHERE p.status = "open" AND (p.assigned_user_id = ? OR p.created_by = ? OR p.id IN (SELECT project_id FROM chroma_project_team WHERE user_id = ?))
             ORDER BY p.date_submitted DESC, p.id DESC LIMIT 10',
            [$uid, $uid, $uid]
        ) : [];
        $data['recentCodes'] = $this->auth->can(Permissions::CODES_VIEW) ? $this->db->all(
            'SELECT * FROM v_chroma_code_lookup WHERE sell_number_voided = 0 ORDER BY sell_number_id DESC LIMIT 8'
        ) : [];
        $data['stats'] = [
            'open' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects WHERE status = "open"'),
            'mine' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_projects WHERE status = "open" AND assigned_user_id = ?', [$uid]),
            'codes_month' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_sell_numbers WHERE date_assigned >= ?', [substr(today(), 0, 7) . '-01']),
            'overdue' => (int) $this->db->value('SELECT COUNT(*) FROM chroma_project_tasks WHERE done = 0 AND due_date < UTC_DATE() AND owner_user_id = ?', [$uid]),
        ];
        return $this->view('home/index', $data);
    }
}
