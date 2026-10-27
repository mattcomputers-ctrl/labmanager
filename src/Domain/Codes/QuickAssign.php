<?php
declare(strict_types=1);

namespace Chroma\Domain\Codes;

use Chroma\Core\Db;
use Chroma\Core\ValidationException;

/**
 * "Assign a code without a project": the one operation behind the Quick
 * assign wizard (Easy mode) and the /assign page (Expert mode).
 *
 * The main case is a sell-number alias for an existing product (a relabel,
 * a customer-specific code, a new version): the new sell number links to
 * the product's E number. It also covers a brand-new E number and/or sell
 * number for items that never go through the lab (resale, purchased).
 * Everything happens in one transaction through CodeAssigner.
 */
final class QuickAssign
{
    public const WANTS = [
        'sell' => 'A sell number',
        'internal' => 'An E number',
        'both' => 'Both an E number and a sell number',
    ];

    public function __construct(private readonly Db $db, private readonly CodeAssigner $codes)
    {
    }

    /**
     * Resolve a product reference from the typeahead ("E12" = internal code
     * id 12, "S34" = sell number id 34) into what the form needs.
     * @return array<string,mixed>|null
     */
    public function product(?string $ref): ?array
    {
        $ref = strtoupper(trim((string) $ref));
        if (!preg_match('/^([ES])(\d+)$/', $ref, $m)) {
            return null;
        }
        $search = new CodeSearch($this->db);
        $id = (int) $m[2];
        if ($m[1] === 'E') {
            $i = $search->internalRows([$id])[$id] ?? null;
            if ($i === null) {
                return null;
            }
            $sells = $search->sellRows('s.internal_code_id = ?', [$id]);
            return [
                'ref' => 'E' . $id, 'type' => 'internal', 'internal' => $i, 'sell' => null, 'sells' => $sells,
                'internal_code_id' => $id, 'sell_number_id' => null,
                'label' => $i['display'], 'description' => $i['description'],
                'customer_id' => $i['original_customer_id'] ? (int) $i['original_customer_id'] : null, 'customer' => $i['customer'],
                'voided' => (int) $i['voided'] === 1,
            ];
        }
        $s = $search->sellRows('s.id = ?', [$id])[0] ?? null;
        if ($s === null) {
            return null;
        }
        $internal = $s['internal_code_id'] ? ($search->internalRows([(int) $s['internal_code_id']])[(int) $s['internal_code_id']] ?? null) : null;
        $sells = $internal ? $search->sellRows('s.internal_code_id = ?', [(int) $internal['id']]) : [$s];
        return [
            'ref' => 'S' . $id, 'type' => 'sell', 'internal' => $internal, 'sell' => $s, 'sells' => $sells,
            'internal_code_id' => $internal ? (int) $internal['id'] : null, 'sell_number_id' => $id,
            'label' => $s['display'] . ($internal ? ' · ' . $internal['display'] : ''),
            'description' => $s['description'] !== '' ? $s['description'] : ($internal['description'] ?? ''),
            'customer_id' => $s['original_customer_id'] ? (int) $s['original_customer_id'] : ($internal && $internal['original_customer_id'] ? (int) $internal['original_customer_id'] : null),
            'customer' => $s['customer'] ?? ($internal['customer'] ?? null),
            'voided' => (int) $s['voided'] === 1,
        ];
    }

    /**
     * Which of WANTS make sense for a product (null = brand-new product).
     * @param array<string,mixed>|null $product
     * @return array<string,string>
     */
    public static function wantsFor(?array $product): array
    {
        if ($product === null) {
            return self::WANTS;
        }
        if ($product['internal_code_id'] !== null) {
            return ['sell' => 'Another sell number for ' . $product['internal']['display']];
        }
        return [
            'sell' => 'Another sell number (alias of ' . $product['sell']['display'] . ')',
            'internal' => 'An E number for ' . $product['sell']['display'] . ', which has none yet',
            'both' => 'Both: an E number for ' . $product['sell']['display'] . ' and another sell number',
        ];
    }

    /**
     * Do it. Validates the shape of the request here; CodeAssigner checks
     * the counter, prefix, suffix and reason rules.
     *
     * @param array<string,mixed> $data keys: product_mode (existing|new), product (E12/S34), want (sell|internal|both),
     *        prefix_id, suffix_id, description, reason, reason_text, original_customer_id, resale, vendor_name, vendor_code, notes
     * @return array{product:array<string,mixed>|null,internal:array<string,mixed>|null,sell:array<string,mixed>|null,linked_sell:array<string,mixed>|null}
     */
    public function run(array $data, ?int $actorId): array
    {
        $mode = (string) ($data['product_mode'] ?? 'existing');
        $product = null;
        if ($mode === 'existing') {
            $product = $this->product(isset($data['product']) ? (string) $data['product'] : null);
            if ($product === null) {
                throw ValidationException::single('product', 'Search for the existing product and pick it from the list, or choose "a brand-new product".');
            }
        } elseif ($mode !== 'new') {
            throw ValidationException::single('product_mode', 'Say whether this is for an existing product or a brand-new one.');
        }
        $want = (string) ($data['want'] ?? '');
        $allowed = self::wantsFor($product);
        if (!isset($allowed[$want])) {
            if ($product !== null && $product['internal_code_id'] !== null && in_array($want, ['internal', 'both'], true)) {
                throw ValidationException::single('want', sprintf('%s already has an E number (%s). Choose a sell number instead, or pick "brand-new product" if this is a different formula.', $product['label'], $product['internal']['display']));
            }
            throw ValidationException::single('want', 'Choose what you need: a sell number, an E number, or both.');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' && $product !== null) {
            $description = (string) $product['description'];
        }
        if ($description === '') {
            throw ValidationException::single('description', 'Give the code a short description so people can find it later.');
        }
        $wantSell = $want !== 'internal';
        $wantInternal = $want !== 'sell';
        if ($wantSell && $product !== null && trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::single('reason', 'Say why this product needs another sell number. Pick a reason from the list.');
        }
        $customerId = isset($data['original_customer_id']) && (int) $data['original_customer_id'] > 0 ? (int) $data['original_customer_id'] : ($product['customer_id'] ?? null);
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;

        return $this->db->transaction(function () use ($product, $wantSell, $wantInternal, $description, $data, $customerId, $notes, $actorId) {
            $internal = null;
            $linked = null;
            $internalId = $product['internal_code_id'] ?? null;
            if ($wantInternal) {
                $internal = $this->codes->assignInternal([
                    'description' => $description,
                    'resale' => !empty($data['resale']),
                    'vendor_name' => $data['vendor_name'] ?? null,
                    'vendor_code' => $data['vendor_code'] ?? null,
                    'original_customer_id' => $customerId,
                    'notes' => $notes ?: null,
                ], $actorId);
                $internalId = (int) $internal['id'];
                if ($product !== null && $product['sell_number_id'] !== null && $product['internal_code_id'] === null) {
                    // The existing sell number had no E number: it gets this one.
                    $this->codes->linkSellToInternal((int) $product['sell_number_id'], $internalId, $actorId);
                    $linked = $this->codes->sellRow((int) $product['sell_number_id']);
                }
            }
            $sell = null;
            if ($wantSell) {
                $sell = $this->codes->assignSell([
                    'prefix_id' => (int) ($data['prefix_id'] ?? 0),
                    'suffix_id' => isset($data['suffix_id']) && $data['suffix_id'] !== '' ? (int) $data['suffix_id'] : null,
                    'description' => $description,
                    'internal_code_id' => $internalId,
                    'original_customer_id' => $customerId,
                    'reason' => isset($data['reason']) && $data['reason'] !== '' ? (string) $data['reason'] : null,
                    'reason_text' => isset($data['reason_text']) && trim((string) $data['reason_text']) !== '' ? trim((string) $data['reason_text']) : null,
                    'notes' => $notes ?: null,
                ], $actorId);
            }
            return ['product' => $product, 'internal' => $internal, 'sell' => $sell, 'linked_sell' => $linked];
        });
    }

    /**
     * Prefix choices for the forms: active prefixes with the next number and allowed suffixes.
     * @return list<array<string,mixed>>
     */
    public function prefixChoices(): array
    {
        $rows = $this->db->all('SELECT id, code, label, description, min_digits, tag FROM chroma_code_prefixes WHERE retired = 0 ORDER BY sort_order, code');
        $suffixes = $this->db->all('SELECT ps.prefix_id, s.id, s.code, s.label FROM chroma_code_prefix_suffixes ps JOIN chroma_code_suffixes s ON s.id = ps.suffix_id WHERE s.active = 1 ORDER BY s.sort_order, s.code');
        $byPrefix = [];
        foreach ($suffixes as $s) {
            $byPrefix[(int) $s['prefix_id']][] = ['id' => (int) $s['id'], 'code' => $s['code'], 'label' => $s['label']];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'code' => $r['code'], 'label' => $r['label'], 'description' => $r['description'], 'tag' => $r['tag'],
                'next' => CodeFormat::sell($r['code'], $this->codes->previewSell((int) $r['id']), (int) $r['min_digits']),
                'suffixes' => $byPrefix[(int) $r['id']] ?? [],
            ];
        }
        return $out;
    }
}
