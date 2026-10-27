<?php

namespace App\Support\Wallet;

use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\WalletCashCountSubmission;
use App\Models\WalletCashMovement;
use App\Models\WalletCashOpeningAudit;
use App\Models\WalletCashReconciliation;
use App\Models\WalletShiftReopenLog;

/**
 * A store's cash drawer on one business day: opening, cash sales, cash in and out, expected and
 * counted cash, whether the shift is closed, the check against the last count, and the day's
 * count and opening-change history. Today's drawer and Drawer history are built on it.
 */
class CashControlSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Domain $domain, InventoryLocation $location, string $businessDate, int $viewerUserId): array
    {
        $recon = WalletCashReconciliation::query()
            ->forWalletContext($domain->name_slug, $location->id)
            ->whereDate('business_date', $businessDate)
            ->with('countedBy:id,name')
            ->first();

        $openingIsSaved = $recon !== null;
        $openingSuggestion = null;
        $suggestionSourceDate = null;

        if ($openingIsSaved) {
            $openingCash = (float) $recon->opening_cash;
        } else {
            $prev = WalletCashReconciliation::query()
                ->forWalletContext($domain->name_slug, $location->id)
                ->whereDate('business_date', '<', $businessDate)
                ->whereCashKnown()
                ->orderByDesc('business_date')
                ->first();

            $openingSuggestion = $prev?->cashLeft();
            $suggestionSourceDate = $prev?->business_date?->toDateString();
            $openingCash = (float) ($openingSuggestion ?? 0);
        }

        $dailyExpected = WalletCashDailyExpected::compute(
            $domain->name_slug,
            (int) $location->id,
            $businessDate,
            $recon
        );
        $paidCashSales = $dailyExpected['paid_cash_sales'];
        $manualIn = $dailyExpected['manual_in'];
        $manualOut = $dailyExpected['manual_out'];
        $expectedCash = $dailyExpected['expected_cash'];

        $countedCash = $recon?->counted_at !== null ? (float) $recon->counted_cash : null;
        $variance = $countedCash === null ? null : round($countedCash - $expectedCash, 2);

        $bridge = WalletCashBridgeExpected::compute(
            $domain->name_slug,
            (int) $location->id,
            $businessDate,
            $countedCash
        );

        $countedByUser = null;
        if ($recon?->counted_at !== null && $recon->counted_by) {
            $cb = $recon->relationLoaded('countedBy') ? $recon->countedBy : User::query()->find((int) $recon->counted_by);
            if ($cb) {
                $countedByUser = [
                    'id' => (int) $cb->id,
                    'name' => (string) $cb->name,
                ];
            }
        }

        $openingLastUpdatedByUser = null;
        $openingLastUpdatedAt = null;

        if ($recon !== null) {
            $lastOpeningAudit = WalletCashOpeningAudit::query()
                ->where('reconciliation_id', $recon->id)
                ->with('changedBy:id,name')
                ->orderByDesc('changed_at')
                ->first();

            if ($lastOpeningAudit?->changedBy !== null) {
                $openingLastUpdatedByUser = [
                    'id' => (int) $lastOpeningAudit->changedBy->id,
                    'name' => (string) $lastOpeningAudit->changedBy->name,
                ];
                $openingLastUpdatedAt = $lastOpeningAudit->changed_at?->toIso8601String();
            }

            if ($openingLastUpdatedByUser === null) {
                $openingMovement = WalletCashMovement::query()
                    ->forWalletContext($domain->name_slug, (int) $location->id)
                    ->whereDate('movement_date', $businessDate)
                    ->where('notes', WalletCashBridgeExpected::NOTE_OPENING)
                    ->with('user:id,name')
                    ->first();

                if ($openingMovement?->user !== null) {
                    $openingLastUpdatedByUser = [
                        'id' => (int) $openingMovement->user->id,
                        'name' => (string) $openingMovement->user->name,
                    ];
                    $openingLastUpdatedAt = $openingMovement->updated_at?->toIso8601String()
                        ?? $openingMovement->created_at?->toIso8601String();
                }
            }
        }

        $openingAuditHistory = [];
        if ($recon !== null) {
            $openingAuditHistory = WalletCashOpeningAudit::query()
                ->where('reconciliation_id', $recon->id)
                ->with('changedBy:id,name')
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->limit(25)
                ->get()
                ->map(static function (WalletCashOpeningAudit $a): array {
                    return [
                        'id' => $a->id,
                        'old_opening_cash' => $a->old_opening_cash !== null ? (float) $a->old_opening_cash : null,
                        'new_opening_cash' => (float) $a->new_opening_cash,
                        'delta_amount' => (float) $a->delta_amount,
                        'reason' => $a->reason,
                        'changed_at' => $a->changed_at?->toIso8601String(),
                        'changed_by_user' => $a->changedBy !== null
                            ? [
                                'id' => (int) $a->changedBy->id,
                                'name' => (string) $a->changedBy->name,
                            ]
                            : null,
                    ];
                })
                ->values()
                ->all();
        }

        $countSubmissionHistory = WalletCashCountSubmission::query()
            ->forWalletContext($domain->name_slug, (int) $location->id)
            ->whereDate('business_date', $businessDate)
            ->with('countedBy:id,name')
            ->orderByDesc('counted_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static function (WalletCashCountSubmission $row): array {
                return [
                    'id' => $row->id,
                    'counted_cash' => (float) $row->counted_cash,
                    'expected_cash_snapshot' => $row->expected_cash_snapshot !== null ? (float) $row->expected_cash_snapshot : null,
                    'variance_snapshot' => $row->variance_snapshot !== null ? (float) $row->variance_snapshot : null,
                    'notes' => $row->notes,
                    'counted_at' => $row->counted_at?->toIso8601String(),
                    'counted_by_user' => $row->countedBy !== null
                        ? [
                            'id' => (int) $row->countedBy->id,
                            'name' => (string) $row->countedBy->name,
                        ]
                        : null,
                ];
            })
            ->values()
            ->all();

        $viewer = $recon?->is_closed ? User::query()->find($viewerUserId) : null;
        $lastReopen = null;
        if ($recon !== null) {
            $log = WalletShiftReopenLog::query()
                ->where('reconciliation_id', $recon->id)
                ->with('reopenedBy:id,name')
                ->latest('id')
                ->first();
            $lastReopen = $log ? [
                'by' => $log->reopenedBy?->name,
                'at' => $log->created_at?->toIso8601String(),
                'reason' => $log->reason,
            ] : null;
        }

        return [
            'business_date' => $businessDate,
            'opening_cash' => $openingCash,
            'paid_cash_sales' => $paidCashSales,
            'manual_in' => $manualIn,
            'manual_out' => $manualOut,
            'expected_cash' => $expectedCash,
            'counted_cash' => $countedCash,
            'variance' => $variance,
            'status' => $countedCash === null ? 'pending' : 'counted',
            'notes' => $recon?->notes,
            'counted_at' => $recon?->counted_at?->toIso8601String(),
            'counted_by' => ($recon?->counted_at !== null && $recon->counted_by)
                ? (int) $recon->counted_by
                : null,
            'counted_by_user' => $countedByUser,
            'opening_last_updated_by_user' => $openingLastUpdatedByUser,
            'opening_last_updated_at' => $openingLastUpdatedAt,
            'opening_is_saved' => $openingIsSaved,
            'opening_suggestion' => $openingSuggestion,
            'suggestion_source_date' => $suggestionSourceDate,
            'is_closed' => (bool) ($recon?->is_closed ?? false),
            'closed_at' => $recon?->closed_at?->toIso8601String(),
            'closed_by' => $recon?->closed_by ? (int) $recon->closed_by : null,
            // Anyone whose role holds the reopen permission; each reopen is logged with a reason.
            'can_reopen' => $recon?->is_closed
                ? ($viewer !== null && WalletShiftGuard::canReopen($viewer))
                : false,
            'closed_by_name' => $recon?->is_closed ? $recon->closedBy?->name : null,
            'last_reopen' => $lastReopen,
            'bridge_anchor_business_date' => $bridge['bridge_anchor_business_date'],
            'bridge_anchor_counted_cash' => $bridge['bridge_anchor_counted_cash'],
            'bridge_expected_cash' => $bridge['bridge_expected_cash'],
            'bridge_variance' => $bridge['bridge_variance'],
            'bridge_day_span' => $bridge['bridge_day_span'],
            'bridge_span_warning' => $bridge['bridge_span_warning'],
            'count_submission_history' => $countSubmissionHistory,
            'opening_audit_history' => $openingAuditHistory,
        ];
    }
}
