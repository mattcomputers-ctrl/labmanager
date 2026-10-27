<?php
declare(strict_types=1);

namespace Chroma\Http\Controllers\Codes;

use Chroma\Core\ForbiddenException;
use Chroma\Core\Permissions;
use Chroma\Core\Request;
use Chroma\Core\Response;
use Chroma\Domain\Codes\CodeAssigner;
use Chroma\Domain\Codes\QuickAssign;
use Chroma\Http\Controller;

/**
 * /assign — Expert-mode quick assign: one form, inline result. Easy mode is
 * the quick-assign wizard; both call QuickAssign::run().
 */
final class QuickAssignController extends Controller
{
    private function quick(): QuickAssign
    {
        return new QuickAssign($this->db, new CodeAssigner($this->db, $this->app->audit));
    }

    /** The page opens with either assign permission; the operation checks the one that matches the request. */
    private function requireAny(): void
    {
        if (!$this->auth->canAny(Permissions::CODES_ASSIGN_INTERNAL, Permissions::CODES_ASSIGN_SELL)) {
            throw new ForbiddenException();
        }
    }

    public static function requireFor(\Chroma\Core\Auth $auth, string $want): void
    {
        if ($want !== 'internal' && !$auth->can(Permissions::CODES_ASSIGN_SELL)) {
            throw new ForbiddenException();
        }
        if ($want !== 'sell' && !$auth->can(Permissions::CODES_ASSIGN_INTERNAL)) {
            throw new ForbiddenException();
        }
    }

    public function form(Request $request): Response
    {
        $this->requireAny();
        if (is_easy() && !$request->bool('expert')) {
            return $this->redirect(url('/wizard/quick-assign', array_filter(['product' => $request->str('product') ?: null])));
        }
        $quick = $this->quick();
        $product = $quick->product($request->str('product'));
        return $this->view('codes/assign', [
            'title' => 'Quick assign',
            'prefixes' => $quick->prefixChoices(),
            'reasons' => CodeAssigner::REASONS,
            'product' => $product,
            'defaults' => [
                'product_mode' => $product ? 'existing' : ($request->str('product_mode') ?: 'existing'),
                'want' => $request->str('want') ?: 'sell',
                'prefix_id' => $request->int('prefix_id'),
                'suffix_id' => $request->int('suffix_id'),
                'description' => $request->str('description'),
                'reason' => $request->str('reason'),
                'reason_text' => $request->str('reason_text'),
                'original_customer_id' => $request->int('original_customer_id'),
                'original_customer_label' => $request->str('original_customer_label'),
                'resale' => $request->bool('resale'),
                'vendor_name' => $request->str('vendor_name'),
                'vendor_code' => $request->str('vendor_code'),
                'notes' => $request->str('notes'),
            ],
            'canSell' => $this->auth->can(Permissions::CODES_ASSIGN_SELL),
            'canInternal' => $this->auth->can(Permissions::CODES_ASSIGN_INTERNAL),
            'nextInternal' => 'E' . (new CodeAssigner($this->db, $this->app->audit))->previewInternal(),
            // Easy must open the wizard (never this page again with expert=1); the form's live values travel via data-mode-carry.
            'easyUrl' => url('/mode', ['set' => 'easy', 'return' => url('/wizard/quick-assign', array_filter(['product' => $request->str('product') ?: null, 'product_mode' => $request->str('product_mode') ?: null, 'want' => $request->str('want') ?: null, 'prefix_id' => $request->int('prefix_id'), 'description' => $request->str('description') ?: null, 'reason' => $request->str('reason') ?: null, 'jump' => 1]))]),
        ]);
    }

    public function assign(Request $request): Response
    {
        $this->requireAny();
        $want = $request->str('want');
        self::requireFor($this->auth, $want);
        $res = $this->quick()->run([
            'product_mode' => $request->str('product_mode', 'existing'),
            'product' => $request->str('product'),
            'want' => $want,
            'prefix_id' => $request->int('prefix_id'),
            'suffix_id' => $request->int('suffix_id'),
            'description' => $request->str('description'),
            'reason' => $request->str('reason'),
            'reason_text' => $request->str('reason_text'),
            'original_customer_id' => $request->int('original_customer_id'),
            'resale' => $request->bool('resale'),
            'vendor_name' => $request->str('vendor_name') ?: null,
            'vendor_code' => $request->str('vendor_code') ?: null,
            'notes' => $request->str('notes') ?: null,
        ], $this->userId());
        $codes = [];
        if ($res['internal']) {
            $codes[] = ['code' => $res['internal']['display'], 'label' => 'E number', 'description' => $res['internal']['description'], 'url' => '/codes/e/' . (int) $res['internal']['id']];
        }
        if ($res['sell']) {
            $codes[] = ['code' => $res['sell']['display'], 'label' => 'Sell number', 'description' => $res['sell']['description'], 'url' => '/codes/s/' . (int) $res['sell']['id']];
        }
        $message = implode(' and ', array_map(fn($c) => $c['code'], $codes)) . ' reserved';
        $detail = $res['sell'] && $res['sell']['internal_code_id']
            ? 'Linked to ' . ($res['internal']['display'] ?? $res['product']['internal']['display'] ?? 'its E number') . '.'
            : ($res['linked_sell'] ? $res['linked_sell']['display'] . ' now points at ' . $res['internal']['display'] . '.' : '');
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'codes' => $codes, 'message' => $message, 'detail' => $detail]);
        }
        $this->success($message, $detail ?: null);
        return $this->redirect($codes[count($codes) - 1]['url']);
    }
}
