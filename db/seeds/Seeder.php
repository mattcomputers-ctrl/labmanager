<?php
declare(strict_types=1);

namespace Chroma\Db;

use Chroma\Core\Db;
use Chroma\Core\Permissions;

/**
 * Reference data: groups and permissions, code prefixes and suffixes,
 * project types, stages, item statuses and the two sequences. Every step
 * is idempotent: existing rows are left alone so admin edits survive.
 */
final class Seeder
{
    public function __construct(private readonly Db $db)
    {
    }

    public function run(): void
    {
        $this->groups();
        $this->sequences();
        $this->suffixes();
        $this->prefixes();
        $this->projectTypes();
        $this->itemStatuses();
        $this->customers();
    }

    private function now(): string
    {
        return $this->db->now();
    }

    private function groups(): void
    {
        $all = Permissions::all();
        $groups = [
            'Administrators' => ['Everything, including users, prefixes, updates and network.', $all, 1],
            'Lab' => ['Lab technicians: projects, colors and code assignment.', [
                Permissions::PROJECTS_VIEW, Permissions::PROJECTS_CREATE, Permissions::PROJECTS_EDIT, Permissions::PROJECTS_COMPLETE,
                Permissions::COLORS_EDIT, Permissions::CODES_VIEW, Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL,
                Permissions::CODES_LINK, Permissions::CODES_EXPORT, Permissions::REPORTS_VIEW,
            ], 1],
            'Sales' => ['Sales staff: look up codes and read projects.', [
                Permissions::PROJECTS_VIEW, Permissions::CODES_VIEW, Permissions::CODES_EXPORT,
            ], 1],
            'Read only' => ['Can look at everything that is not confidential, change nothing.', [
                Permissions::PROJECTS_VIEW, Permissions::CODES_VIEW, Permissions::REPORTS_VIEW,
            ], 1],
            'R&D' => ['Research and development: may see confidential R&D projects.', [
                Permissions::PROJECTS_VIEW, Permissions::PROJECTS_CREATE, Permissions::PROJECTS_EDIT, Permissions::PROJECTS_COMPLETE,
                Permissions::PROJECTS_VIEW_CONFIDENTIAL, Permissions::COLORS_EDIT, Permissions::CODES_VIEW,
                Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL, Permissions::CODES_LINK, Permissions::REPORTS_VIEW,
            ], 1],
        ];
        foreach ($groups as $name => [$desc, $perms, $system]) {
            $id = $this->db->value('SELECT id FROM chroma_groups WHERE name = ?', [$name]);
            if ($id === null) {
                $id = $this->db->insert('chroma_groups', ['name' => $name, 'description' => $desc, 'is_system' => $system, 'created_at' => $this->now()]);
                foreach ($perms as $p) {
                    $this->db->run('INSERT IGNORE INTO chroma_group_permissions (group_id, permission) VALUES (?, ?)', [(int) $id, $p]);
                }
            } elseif ($name === 'Administrators') {
                // Administrators always hold every permission, including ones added in later versions.
                foreach ($all as $p) {
                    $this->db->run('INSERT IGNORE INTO chroma_group_permissions (group_id, permission) VALUES (?, ?)', [(int) $id, $p]);
                }
            }
        }
    }

    private function sequences(): void
    {
        foreach (['internal_code' => 9102, 'project_number' => 101] as $name => $value) {
            $this->db->run('INSERT IGNORE INTO chroma_sequences (name, next_value, updated_at) VALUES (?, ?, ?)', [$name, $value, $this->now()]);
        }
    }

    private function suffixes(): void
    {
        $rows = [
            ['UV', 'Energy-curable (UV) version', 10],
            ['ND', 'ND version', 20],
            ['F', 'F version', 30],
            ['HS', 'HS version', 40],
        ];
        foreach ($rows as $i => [$code, $label, $sort]) {
            $this->db->run(
                'INSERT IGNORE INTO chroma_code_suffixes (code, label, active, sort_order, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?)',
                [$code, $label, $sort, $this->now(), $this->now()]
            );
        }
    }

    private function prefixes(): void
    {
        // code, label, description, last used (reference only), min digits, sort, tag, retired, allowed suffixes
        $rows = [
            ['Y', 'Yellow', 'Standard color prefix for yellows.', 1714, 4, 10, '', 0, ['UV']],
            ['R', 'Red', 'Standard color prefix for reds.', 2323, 4, 20, '', 0, ['UV']],
            ['B', 'Blue', 'Standard color prefix for blues.', 2970, 4, 30, '', 0, ['UV']],
            ['BK', 'Black', 'Standard color prefix for blacks.', 1376, 4, 40, '', 0, ['UV']],
            ['G', 'Green', 'Standard color prefix for greens.', 2101, 4, 50, '', 0, ['UV']],
            ['P', 'Purple / violet', 'Standard color prefix for purples and violets.', 1555, 4, 60, '', 0, ['UV']],
            ['BN', 'Brown', 'Standard color prefix for browns.', 1439, 4, 70, '', 0, ['UV']],
            ['OR', 'Orange', 'Standard color prefix for oranges.', 1453, 4, 80, '', 0, ['UV']],
            ['W', 'White', 'Standard color prefix for whites.', 1098, 4, 90, '', 0, ['UV']],
            ['MS', 'Miscellaneous', 'Varnishes and other miscellaneous products.', 1461, 4, 100, '', 0, ['UV']],
            ['T', 'Tint', 'Tints, e.g. T2449 Blister Pantone 663 Purple.', 2449, 4, 110, '', 0, ['UV']],
            ['X', 'Security ink', 'Security-ink products. Six digits, currently in the 104xxx block.', 104043, 6, 120, '', 0, []],
            ['AQ', 'Aqueous coating', 'Aqueous coatings.', 5254, 4, 130, '', 0, []],
            ['UV', 'UV legacy series', 'Legacy UV25xxx series inherited from another company. Rarely used but still assignable.', 25672, 5, 200, 'inherited', 0, []],
            ['UVXL', 'UVXL (inherited)', 'Prefix inherited from another company.', 428, 3, 210, 'inherited', 0, []],
            ['UVVL', 'UVVL (inherited)', 'Prefix inherited from another company. Older sheets spell it UVV.', 185, 3, 220, 'inherited', 0, []],
            ['UVNG', 'UVNG (inherited)', 'Prefix inherited from another company.', 72, 2, 230, 'inherited', 0, []],
            ['WBC', 'Aqueous coating (legacy)', 'Older prefix for aqueous coatings. Use AQ for new products.', 1030, 4, 300, 'legacy', 0, []],
            // Retired historical prefixes: visible in lookup, not assignable.
            ['A', 'A (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 900, 'retired', 1, []],
            ['UVC', 'UVC (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 901, 'retired', 1, []],
            ['UVD', 'UVD (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 902, 'retired', 1, []],
            ['UVR', 'UVR (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 903, 'retired', 1, []],
            ['UVMS', 'UVMS (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 904, 'retired', 1, []],
            ['UVMQ', 'UVMQ (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 905, 'retired', 1, []],
            ['UVXLCG', 'UVXLCG (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 906, 'retired', 1, []],
            ['CS', 'CS (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 907, 'retired', 1, []],
            ['EG', 'EG (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 908, 'retired', 1, []],
            ['HBK', 'HBK (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 909, 'retired', 1, []],
            ['UK', 'UK (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 910, 'retired', 1, []],
            ['UC', 'UC (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 911, 'retired', 1, []],
            ['CTA', 'CTA (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 912, 'retired', 1, []],
            ['AV', 'AV (retired)', 'Historical prefix found in the cross-reference.', 0, 4, 913, 'retired', 1, []],
        ];
        $suffixIds = [];
        foreach ($this->db->all('SELECT id, code FROM chroma_code_suffixes') as $s) {
            $suffixIds[$s['code']] = (int) $s['id'];
        }
        foreach ($rows as [$code, $label, $desc, $last, $digits, $sort, $tag, $retired, $suffixes]) {
            $existing = $this->db->value('SELECT id FROM chroma_code_prefixes WHERE code = ?', [$code]);
            if ($existing !== null) {
                continue;
            }
            $id = $this->db->insert('chroma_code_prefixes', [
                'code' => $code,
                'label' => $label,
                'description' => $desc,
                'next_number' => $last + 1,
                'min_digits' => $digits,
                'retired' => $retired,
                'retired_at' => $retired ? $this->now() : null,
                'sort_order' => $sort,
                'tag' => $tag,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
            foreach ($suffixes as $sx) {
                if (isset($suffixIds[$sx])) {
                    $this->db->run('INSERT IGNORE INTO chroma_code_prefix_suffixes (prefix_id, suffix_id) VALUES (?, ?)', [$id, $suffixIds[$sx]]);
                }
            }
        }
    }

    private function projectTypes(): void
    {
        $types = [
            [
                'code' => 'color_match', 'label' => 'Color match', 'color' => 'blue', 'sort' => 10,
                'description' => 'Match one or more colors for a customer and assign codes.',
                'item_label' => 'Colors', 'item_singular' => 'Color',
                'has_customer' => 1, 'customer_required' => 1, 'has_stages' => 0, 'has_team' => 0, 'confidential' => 0,
                'fields' => ['description', 'date_submitted', 'assigned_user_id', 'default_ink_series_id', 'default_substrate', 'default_coating_id', 'notes'],
                'stages' => [],
            ],
            [
                'code' => 'customer_issue', 'label' => 'Customer issue', 'color' => 'red', 'sort' => 20,
                'description' => 'Diagnose a problem a customer is having with a product (print defect, drying, adhesion, batch complaint).',
                'item_label' => 'Products involved', 'item_singular' => 'Product',
                'has_customer' => 1, 'customer_required' => 1, 'has_stages' => 0, 'has_team' => 0, 'confidential' => 0,
                'fields' => ['problem_statement', 'date_submitted', 'assigned_user_id', 'root_cause', 'resolution', 'notes'],
                'stages' => [],
            ],
            [
                'code' => 'customer_evaluation', 'label' => 'Customer evaluation', 'color' => 'green', 'sort' => 30,
                'description' => 'Test or recommend products for a specific customer use (new substrate, new press, product trial).',
                'item_label' => 'Items under evaluation', 'item_singular' => 'Item',
                'has_customer' => 1, 'customer_required' => 1, 'has_stages' => 0, 'has_team' => 0, 'confidential' => 0,
                'fields' => ['goal', 'date_submitted', 'assigned_user_id', 'outcome', 'notes'],
                'stages' => [],
            ],
            [
                'code' => 'rnd', 'label' => 'R&D', 'color' => 'purple', 'sort' => 40,
                'description' => 'Internal research and development: new products, reformulations, raw-material trials.',
                'item_label' => 'Trials', 'item_singular' => 'Trial',
                'has_customer' => 0, 'customer_required' => 0, 'has_stages' => 1, 'has_team' => 1, 'confidential' => 1,
                'fields' => ['goal', 'date_submitted', 'assigned_user_id', 'target_date', 'stage_id', 'confidential', 'outcome', 'notes'],
                'stages' => [
                    ['idea', 'Idea', 0], ['bench', 'Bench', 0], ['scale_up', 'Scale-up', 0], ['press_trial', 'Press trial', 0],
                    ['released', 'Released', 1], ['shelved', 'Shelved', 1],
                ],
            ],
        ];
        foreach ($types as $t) {
            $id = $this->db->value('SELECT id FROM chroma_project_types WHERE code = ?', [$t['code']]);
            if ($id === null) {
                $id = $this->db->insert('chroma_project_types', [
                    'code' => $t['code'], 'label' => $t['label'], 'description' => $t['description'],
                    'item_label' => $t['item_label'], 'item_label_singular' => $t['item_singular'],
                    'has_customer' => $t['has_customer'], 'customer_required' => $t['customer_required'],
                    'has_stages' => $t['has_stages'], 'has_team' => $t['has_team'], 'can_be_confidential' => $t['confidential'],
                    'fields_json' => json_encode($t['fields']), 'color' => $t['color'], 'active' => 1, 'sort_order' => $t['sort'],
                    'created_at' => $this->now(), 'updated_at' => $this->now(),
                ]);
            }
            foreach ($t['stages'] as $i => [$code, $label, $terminal]) {
                $this->db->run(
                    'INSERT IGNORE INTO chroma_project_stages (project_type_id, code, label, sort_order, is_terminal, active) VALUES (?, ?, ?, ?, ?, 1)',
                    [(int) $id, $code, $label, ($i + 1) * 10, $terminal]
                );
            }
        }
    }

    private function itemStatuses(): void
    {
        // Global statuses (project_type_id NULL) apply to every type unless a type defines its own.
        $rows = [
            ['pending', 'Pending', 10, 0, 0, 'gray'],
            ['in_progress', 'In progress', 20, 0, 0, 'blue'],
            ['matched', 'Matched', 30, 1, 1, 'green'],
            ['approved', 'Approved', 40, 1, 1, 'green'],
            ['rejected', 'Rejected', 50, 1, 0, 'red'],
            ['not_needed', 'Not needed', 60, 1, 0, 'gray'],
        ];
        foreach ($rows as [$code, $label, $sort, $done, $success, $color]) {
            $exists = $this->db->value('SELECT id FROM chroma_item_statuses WHERE project_type_id IS NULL AND code = ?', [$code]);
            if ($exists === null) {
                $this->db->insert('chroma_item_statuses', [
                    'project_type_id' => null, 'code' => $code, 'label' => $label, 'sort_order' => $sort,
                    'is_done' => $done, 'is_success' => $success, 'color' => $color, 'active' => 1,
                ]);
            }
        }
    }

    private function customers(): void
    {
        $name = 'Precision Ink R&D';
        $norm = self::normalizeName($name);
        if ($this->db->value('SELECT id FROM chroma_customers WHERE name_normalized = ?', [$norm]) === null) {
            $this->db->insert('chroma_customers', [
                'name' => $name, 'name_normalized' => $norm, 'cms_customer_id' => null, 'active' => 1,
                'source' => 'system', 'notes' => 'Internal customer used for R&D projects.',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }
    }

    public static function normalizeName(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = preg_replace('/[^a-z0-9]+/u', ' ', $n) ?? $n;
        return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
    }
}
