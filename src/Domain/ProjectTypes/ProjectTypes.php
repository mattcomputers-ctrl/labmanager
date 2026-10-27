<?php
declare(strict_types=1);

namespace Chroma\Domain\ProjectTypes;

use Chroma\Core\Db;

/**
 * Everything that varies by project type lives here, driven by the
 * chroma_project_types row (admin-editable) plus the field catalogue
 * below. Adding a type is a data change: insert a row with fields_json,
 * stages and (optionally) item statuses.
 *
 * Field catalogue: every project-level field the forms/wizards can show,
 * with its label, type and plain-language help. A type's fields_json lists
 * which of these it uses, in order. "title" and "customer" are handled
 * separately (customer visibility comes from has_customer).
 */
final class ProjectTypes
{
    public const FIELD_CATALOGUE = [
        'description' => ['label' => 'What is being asked for?', 'short' => 'Description', 'type' => 'textarea', 'help' => 'A sentence or two in the customer\'s words. The colors themselves are added as items.'],
        'problem_statement' => ['label' => 'What is the problem?', 'short' => 'Problem', 'type' => 'textarea', 'help' => 'What the customer is seeing: print defect, drying, adhesion, a batch complaint… Include press, substrate and when it started.'],
        'goal' => ['label' => 'What is the goal?', 'short' => 'Goal', 'type' => 'textarea', 'help' => 'What success looks like, in one or two sentences.'],
        'date_submitted' => ['label' => 'Date submitted', 'short' => 'Submitted', 'type' => 'date', 'help' => 'When the request came in. Defaults to today.'],
        'target_date' => ['label' => 'Target date', 'short' => 'Target', 'type' => 'date', 'help' => 'When this should be done, if there is a date.'],
        'assigned_user_id' => ['label' => 'Who is working on it?', 'short' => 'Tech / lead', 'type' => 'user', 'help' => 'The technician (or, for R&D, the lead). Defaults to you.'],
        'stage_id' => ['label' => 'Stage', 'short' => 'Stage', 'type' => 'stage', 'help' => 'Where the work is: idea, bench, scale-up, press trial, released or shelved.'],
        'confidential' => ['label' => 'Confidential', 'short' => 'Confidential', 'type' => 'checkbox', 'help' => 'Only the lead, the team, the R&D group and administrators can open it.'],
        'default_ink_series_id' => ['label' => 'Ink series', 'short' => 'Ink series', 'type' => 'ink_series', 'help' => 'Fills in on every new color; can be changed per color.'],
        'default_substrate' => ['label' => 'Substrate', 'short' => 'Substrate', 'type' => 'substrate', 'help' => 'Fills in on every new color; can be changed per color.'],
        'default_coating_id' => ['label' => 'Coating', 'short' => 'Coating', 'type' => 'coating', 'help' => 'Fills in on every new color; can be changed per color.'],
        'root_cause' => ['label' => 'Root cause', 'short' => 'Root cause', 'type' => 'textarea', 'help' => 'What turned out to be causing it. Fill in once known.', 'later' => true],
        'resolution' => ['label' => 'Resolution', 'short' => 'Resolution', 'type' => 'textarea', 'help' => 'What was done about it and what the customer was told.', 'later' => true],
        'outcome' => ['label' => 'Outcome', 'short' => 'Outcome', 'type' => 'textarea', 'help' => 'How it ended: recommended product, result of the trial, next steps.', 'later' => true],
        'notes' => ['label' => 'Notes', 'short' => 'Notes', 'type' => 'textarea', 'help' => 'Anything else worth keeping with the project.'],
    ];

    /** Item kinds and the label used for them. */
    public const ITEM_KINDS = [
        'color' => 'Color',
        'trial' => 'Trial',
        'sample' => 'Sample',
        'existing_product' => 'Existing product',
    ];

    /** @var array<string,array<string,mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** All active types, keyed by code, with 'fields' (list) and 'stages'. @return array<string,array<string,mixed>> */
    public function all(bool $activeOnly = true): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->all('SELECT * FROM chroma_project_types ORDER BY sort_order, label') as $row) {
                $row['fields'] = json_decode((string) ($row['fields_json'] ?? '[]'), true) ?: [];
                $row['stages'] = $this->db->all('SELECT * FROM chroma_project_stages WHERE project_type_id = ? AND active = 1 ORDER BY sort_order', [(int) $row['id']]);
                $row['default_item_kind'] = match ($row['code']) {
                    'rnd' => 'trial',
                    'customer_issue' => 'existing_product',
                    default => 'color',
                };
                $this->cache[(string) $row['code']] = $row;
            }
        }
        return $activeOnly ? array_filter($this->cache, fn($t) => (int) $t['active'] === 1) : $this->cache;
    }

    /** @return array<string,mixed>|null */
    public function byCode(string $code): ?array
    {
        return $this->all(false)[$code] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        foreach ($this->all(false) as $t) {
            if ((int) $t['id'] === $id) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Field specs for a type, in order, merged with the catalogue.
     * @param array<string,mixed> $type
     * @return list<array<string,mixed>>  each has name, label, short, type, help, later(bool)
     */
    public function fields(array $type, bool $includeLater = true): array
    {
        $out = [];
        foreach ($type['fields'] as $name) {
            if (!isset(self::FIELD_CATALOGUE[$name])) {
                continue;
            }
            $spec = self::FIELD_CATALOGUE[$name] + ['name' => $name, 'later' => false];
            if (!$includeLater && $spec['later']) {
                continue;
            }
            if ($name === 'stage_id' && empty($type['has_stages'])) {
                continue;
            }
            if ($name === 'confidential' && empty($type['can_be_confidential'])) {
                continue;
            }
            $out[] = $spec;
        }
        return $out;
    }

    /** Item statuses for a type: type-specific if any, else the global list. @return list<array<string,mixed>> */
    public function itemStatuses(int $typeId): array
    {
        $own = $this->db->all('SELECT * FROM chroma_item_statuses WHERE project_type_id = ? AND active = 1 ORDER BY sort_order', [$typeId]);
        if ($own) {
            return $own;
        }
        return $this->db->all('SELECT * FROM chroma_item_statuses WHERE project_type_id IS NULL AND active = 1 ORDER BY sort_order');
    }

    /** The default (first, not-done) status id for new items of a type. */
    public function defaultItemStatusId(int $typeId): ?int
    {
        foreach ($this->itemStatuses($typeId) as $s) {
            if ((int) $s['is_done'] === 0) {
                return (int) $s['id'];
            }
        }
        return null;
    }
}
