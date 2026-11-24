<?php

namespace App\Http\Controllers;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    /**
     * Render the Blood Inventory Management page for the authenticated user's role.
     */
    public function __invoke(Request $request): View
    {
        return $this->index($request);
    }

    /**
     * Display the primary inventory overview, serialized unit directory, and transaction ledger.
     */
    public function index(Request $request): View
    {
        $activeRole = $request->user()?->primaryRoleSlug();

        if ($activeRole === null || ! in_array($activeRole, ['admin', 'staff', 'hospital'], true)) {
            abort(403);
        }

        $selectedComponentId = $request->query('component_id') !== null && $request->query('component_id') !== ''
            ? (int) $request->query('component_id')
            : null;

        $components = BloodComponent::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $bloodGroups = $this->getBloodGroupsData($selectedComponentId);
        $summary = $this->buildSummary($bloodGroups);
        $history = $this->getRecentHistoryData();

        // Serialized Blood Units directory query with filtering and pagination
        $unitsQuery = BloodUnit::query()
            ->with(['bloodComponent', 'donation.donor', 'releasedBy', 'activeAllocation.bloodRequest']);

        if ($search = $request->query('unit_search')) {
            $unitsQuery->where(function ($q) use ($search): void {
                $q->where('unit_code', 'like', "%{$search}%")
                    ->orWhere('storage_location', 'like', "%{$search}%");
            });
        }

        if ($group = $request->query('unit_group')) {
            if ($group !== 'all') {
                $unitsQuery->where('blood_group', $group);
            }
        }

        if ($unitCompId = $request->query('unit_component_id')) {
            if ($unitCompId !== 'all') {
                $unitsQuery->where('blood_component_id', (int) $unitCompId);
            }
        }

        if ($status = $request->query('unit_status')) {
            if ($status !== 'all' && in_array($status, BloodUnit::UNIT_STATUSES, true)) {
                $unitsQuery->where('unit_status', $status);
            }
        }

        if ($request->boolean('expiring_soon')) {
            $unitsQuery->where('unit_status', 'Available')
                ->where('expires_at', '<=', now()->addDays(7));
        }

        $units = $unitsQuery->orderBy('expires_at')->paginate(15, ['*'], 'units_page')->withQueryString();

        // Transaction ledger query with filtering and pagination
        $txnsQuery = InventoryTransaction::query()
            ->with(['inventoryStock.bloodComponent', 'bloodUnit', 'performedBy']);

        if ($opType = $request->query('txn_operation')) {
            if ($opType !== 'all') {
                $txnsQuery->where('operation_type', $opType);
            }
        }

        if ($txnGroup = $request->query('txn_group')) {
            if ($txnGroup !== 'all') {
                $txnsQuery->whereHas('inventoryStock', fn ($q) => $q->where('blood_group', $txnGroup));
            }
        }

        if ($txnSearch = $request->query('txn_search')) {
            $txnsQuery->where(function ($q) use ($txnSearch): void {
                $q->where('transaction_code', 'like', "%{$txnSearch}%")
                    ->orWhere('description', 'like', "%{$txnSearch}%");
            });
        }

        $transactions = $txnsQuery->orderByDesc('occurred_at')->orderByDesc('id')->paginate(15, ['*'], 'txns_page')->withQueryString();

        // Active inventory stock buckets for adjustment modal
        $stockBuckets = InventoryStock::query()
            ->with('bloodComponent')
            ->orderBy('blood_group')
            ->orderBy('blood_component_id')
            ->get();

        return view('inventory.index', [
            'activeRole' => $activeRole,
            'bloodGroups' => $bloodGroups,
            'summary' => $summary,
            'history' => $history,
            'units' => $units,
            'transactions' => $transactions,
            'components' => $components,
            'selectedComponentId' => $selectedComponentId,
            'stockBuckets' => $stockBuckets,
            'filters' => [
                'unit_search' => $request->query('unit_search', ''),
                'unit_group' => $request->query('unit_group', 'all'),
                'unit_component_id' => $request->query('unit_component_id', 'all'),
                'unit_status' => $request->query('unit_status', 'all'),
                'expiring_soon' => $request->boolean('expiring_soon'),
                'txn_operation' => $request->query('txn_operation', 'all'),
                'txn_group' => $request->query('txn_group', 'all'),
                'txn_search' => $request->query('txn_search', ''),
            ],
        ]);
    }

    /**
     * Display a serialized blood unit's complete lifecycle and provenance history.
     */
    public function showUnit(Request $request, string $bloodUnit): View|JsonResponse
    {
        $activeRole = $request->user()?->primaryRoleSlug();

        if ($activeRole === null || ! in_array($activeRole, ['admin', 'staff', 'hospital'], true)) {
            abort(403);
        }

        $unit = BloodUnit::query()
            ->with([
                'bloodComponent',
                'donation.donor',
                'donation.center',
                'releasedBy',
                'parentUnit',
                'childUnits',
                'allocations.bloodRequest.hospital',
                'transactions.performedBy',
            ])
            ->where('id', $bloodUnit)
            ->orWhere('unit_code', $bloodUnit)
            ->firstOrFail();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'id' => $unit->id,
                'unit_code' => $unit->unit_code,
                'blood_group' => $unit->blood_group,
                'component_name' => $unit->bloodComponent?->name,
                'component_code' => $unit->bloodComponent?->code,
                'volume_ml' => $unit->volume_ml,
                'storage_location' => $unit->storage_location,
                'unit_status' => $unit->unit_status,
                'collected_at' => $unit->collected_at?->format('M d, Y • H:i'),
                'expires_at' => $unit->expires_at?->format('M d, Y • H:i'),
                'is_expired' => $unit->expires_at ? $unit->expires_at->isPast() : false,
                'days_until_expiry' => $unit->expires_at ? (int) ceil(now()->diffInHours($unit->expires_at, false) / 24) : null,
                'released_at' => $unit->released_at?->format('M d, Y • H:i'),
                'released_by' => $unit->releasedBy?->name,
                'donation_code' => $unit->donation?->donation_code,
                'donor_blood_group' => $unit->donation?->donor?->blood_group,
                'allocations' => $unit->allocations->map(fn ($a): array => [
                    'code' => $a->allocation_code,
                    'status' => $a->allocation_status,
                    'request_code' => $a->bloodRequest?->request_code,
                    'hospital_name' => $a->bloodRequest?->hospital?->name,
                    'allocated_at' => $a->allocated_at?->format('M d, Y • H:i'),
                ]),
                'transactions' => $unit->transactions->map(fn ($t): array => [
                    'code' => $t->transaction_code,
                    'operation' => $t->operation_type,
                    'units_delta' => $t->units_delta,
                    'description' => $t->description,
                    'actor' => $t->actor_name,
                    'occurred_at' => $t->occurred_at?->format('M d, Y • H:i'),
                ]),
            ]);
        }

        return view('inventory.unit-show', [
            'unit' => $unit,
            'activeRole' => $request->user()?->primaryRoleSlug() ?? 'staff',
        ]);
    }

    /**
     * Perform an authorized threshold update or stock decrement on an inventory bucket.
     * Manual positive adjustments are rejected with HTTP 422 under the Critical Stock Integrity Rule.
     */
    public function adjustStock(Request $request, InventoryStock $inventoryStock): RedirectResponse
    {
        Gate::authorize('adjust', $inventoryStock);

        $validated = $request->validate([
            'minimum_level' => ['nullable', 'integer', 'min:1'],
            'target_capacity' => ['nullable', 'integer', 'min:1'],
            'units_delta' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'min:3', 'max:2000'],
            'target_unit_status' => ['nullable', 'string', 'in:Discarded,Expired,Dispatched,Transfused'],
            'blood_unit_ids' => ['nullable', 'array'],
            'blood_unit_ids.*' => ['integer', 'exists:blood_units,id'],
        ]);

        if (isset($validated['units_delta']) && (int) $validated['units_delta'] > 0) {
            throw ValidationException::withMessages([
                'units_delta' => 'Positive stock adjustments are not permitted without screened BloodUnit release provenance via ScreeningService.',
            ]);
        }

        if (isset($validated['units_delta']) && (int) $validated['units_delta'] < 0 && empty($validated['description'])) {
            throw ValidationException::withMessages([
                'description' => 'A justification description of at least 3 characters is required for stock decrements.',
            ]);
        }

        if (empty($validated['description'])) {
            $validated['description'] = 'Inventory stock bucket thresholds updated.';
        }

        $this->inventoryService->adjustStock($inventoryStock, $request->user(), $validated);

        return redirect()->route('inventory.index')->with('success', 'Inventory stock adjusted successfully.');
    }

    /**
     * Discard an eligible serialized blood unit with mandatory rationale.
     */
    public function discardUnit(Request $request, BloodUnit $bloodUnit): RedirectResponse
    {
        Gate::authorize('discard', $bloodUnit);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $this->inventoryService->discardUnit($bloodUnit, $request->user(), [
            'reason' => $validated['reason'],
        ]);

        return redirect()->route('inventory.index')->with('success', "Blood unit {$bloodUnit->unit_code} has been discarded.");
    }

    /**
     * Mark an expired blood unit as Expired.
     * Prevents marking units whose expiration boundary is in the future.
     */
    public function markExpired(Request $request, BloodUnit $bloodUnit): RedirectResponse
    {
        Gate::authorize('markExpired', $bloodUnit);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($bloodUnit->expires_at->gt(now())) {
            throw ValidationException::withMessages([
                'expires_at' => 'Cannot mark a non-expired blood unit as Expired.',
            ]);
        }

        $this->inventoryService->markUnitExpired($bloodUnit, $request->user(), [
            'reason' => $validated['reason'] ?? null,
        ]);

        return redirect()->route('inventory.index')->with('success', "Blood unit {$bloodUnit->unit_code} marked as Expired.");
    }

    /**
     * Mark an eligible blood unit as Transfused.
     */
    public function markTransfused(Request $request, BloodUnit $bloodUnit): RedirectResponse
    {
        Gate::authorize('adjust', $bloodUnit);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->inventoryService->markUnitTransfused($bloodUnit, $request->user(), [
            'reason' => $validated['reason'] ?? 'Blood unit administered for transfusion.',
        ]);

        return redirect()->route('inventory.units.show', $bloodUnit->id)
            ->with('success', "Blood unit {$bloodUnit->unit_code} marked as Transfused.");
    }

    /**
     * Perform read-only reconciliation across stock buckets, serialized units, and ledger entries.
     */
    public function reconcile(Request $request): View|JsonResponse
    {
        Gate::authorize('adjust', InventoryStock::class);

        $stocks = InventoryStock::query()->with('bloodComponent')->orderBy('blood_group')->get();
        $reports = [];

        foreach ($stocks as $stock) {
            $raw = $this->inventoryService->reconcileStock($stock);
            $reports[] = array_merge(
                $raw,
                [
                    'stock_id' => $raw['inventory_stock_id'],
                    'stock_available' => $raw['stock_available_units'],
                    'stock_reserved' => $raw['stock_reserved_units'],
                    'component_name' => $stock->bloodComponent?->name,
                    'component_code' => $stock->bloodComponent?->code,
                ]
            );
        }

        $allReconciled = collect($reports)->every(fn (array $r): bool => $r['is_reconciled'] === true);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'all_reconciled' => $allReconciled,
                'bucket_count' => count($reports),
                'reports' => $reports,
            ]);
        }

        return view('inventory.reconcile', [
            'reports' => $reports,
            'allReconciled' => $allReconciled,
            'activeRole' => $request->user()?->primaryRoleSlug() ?? 'staff',
        ]);
    }

    /**
     * Build 8-group stock status data from actual InventoryStock records.
     * When unseeded, defaults to 0 on-hand units across standard blood groups.
     *
     * @return array<int, array{
     *     group: string,
     *     available: int,
     *     reserved: int,
     *     minLevel: int,
     *     capacity: int,
     *     status: string,
     *     lastUpdated: string
     * }>
     */
    public function getBloodGroupsData(?int $componentId = null): array
    {
        $query = InventoryStock::query();

        if ($componentId !== null) {
            $query->where('blood_component_id', $componentId);
        }

        $stocks = $query->get();

        $grouped = [];
        foreach (BloodUnit::BLOOD_GROUPS as $group) {
            $matching = $stocks->where('blood_group', $group);

            $available = (int) $matching->sum('available_units');
            $reserved = (int) $matching->sum('reserved_units');
            $minLevel = (int) ($matching->sum('minimum_level') ?: 20);
            $capacity = (int) ($matching->sum('target_capacity') ?: 100);

            $latestAdjusted = $matching->max('last_adjusted_at');
            $lastUpdated = $latestAdjusted
                ? Carbon::parse($latestAdjusted)->format('M d, Y • H:i')
                : 'No adjustments';

            $grouped[] = [
                'group' => $group,
                'available' => $available,
                'reserved' => $reserved,
                'minLevel' => $minLevel,
                'capacity' => $capacity,
                'status' => $this->resolveStatus($available, $minLevel),
                'lastUpdated' => $lastUpdated,
            ];
        }

        return $grouped;
    }

    /**
     * Build high-level summary KPIs.
     *
     * @param  array<int, array<string, mixed>>  $bloodGroups
     * @return array{totalUnits: int, totalGroups: int, lowStockGroups: int, criticalStockGroups: int, expiringSoonUnits: int, expiredUnits: int}
     */
    public function buildSummary(array $bloodGroups): array
    {
        $hasUnits = BloodUnit::query()->exists();

        $expiringSoonUnits = $hasUnits
            ? BloodUnit::query()->available()->where('expires_at', '<=', now()->addDays(7))->count()
            : 0;

        $expiredUnits = $hasUnits
            ? BloodUnit::query()->where('unit_status', 'Expired')->count()
            : 0;

        return [
            'totalUnits' => array_sum(array_column($bloodGroups, 'available')),
            'totalGroups' => count($bloodGroups),
            'lowStockGroups' => count(array_filter(
                $bloodGroups,
                fn (array $item): bool => $item['status'] === 'low'
            )),
            'criticalStockGroups' => count(array_filter(
                $bloodGroups,
                fn (array $item): bool => $item['status'] === 'critical'
            )),
            'expiringSoonUnits' => $expiringSoonUnits,
            'expiredUnits' => $expiredUnits,
        ];
    }

    /**
     * Build recent history from actual InventoryTransaction records.
     * Returns an empty array when no transactions exist.
     *
     * @return array<int, array{
     *     id: int,
     *     group: string,
     *     operation: string,
     *     units: int,
     *     date: string,
     *     user: string,
     *     description: string
     * }>
     */
    public function getRecentHistoryData(): array
    {
        return InventoryTransaction::query()
            ->with(['inventoryStock.bloodComponent'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (InventoryTransaction $txn): array {
                $group = $txn->inventoryStock?->blood_group ?? 'O+';
                $operation = match ($txn->operation_type) {
                    'Stock In' => 'Added',
                    'Reserve' => 'Reserved',
                    'Release Reservation' => 'Released',
                    'Dispatch', 'Removed', 'Discarded', 'Expired' => 'Removed',
                    default => $txn->operation_type,
                };

                return [
                    'id' => $txn->id,
                    'group' => $group,
                    'operation' => $operation,
                    'units' => abs($txn->units_delta),
                    'date' => $txn->occurred_at?->format('M d, Y • H:i') ?? '',
                    'user' => $txn->actor_name,
                    'description' => $txn->description,
                ];
            })
            ->all();
    }

    /**
     * Derive inventory stock status from available units and minimum threshold.
     */
    public function resolveStatus(int $available, int $minLevel): string
    {
        $criticalThreshold = (int) floor($minLevel * 0.6);

        if ($available <= $criticalThreshold) {
            return 'critical';
        }

        if ($available <= $minLevel) {
            return 'low';
        }

        return 'normal';
    }

    /**
     * Sample 8 standard ABO/Rh blood group records for fallback and public previews.
     *
     * @return array<int, array{
     *     group: string,
     *     available: int,
     *     reserved: int,
     *     minLevel: int,
     *     capacity: int,
     *     status: string,
     *     lastUpdated: string
     * }>
     */
    public function sampleBloodGroups(): array
    {
        $definitions = [
            ['group' => 'A+', 'available' => 64, 'reserved' => 8, 'minLevel' => 30, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:45'],
            ['group' => 'A-', 'available' => 24, 'reserved' => 4, 'minLevel' => 25, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 12:30'],
            ['group' => 'B+', 'available' => 52, 'reserved' => 6, 'minLevel' => 25, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 11:50'],
            ['group' => 'B-', 'available' => 19, 'reserved' => 3, 'minLevel' => 20, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 10:15'],
            ['group' => 'AB+', 'available' => 45, 'reserved' => 5, 'minLevel' => 20, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 09:40'],
            ['group' => 'AB-', 'available' => 12, 'reserved' => 4, 'minLevel' => 20, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:10'],
            ['group' => 'O+', 'available' => 78, 'reserved' => 12, 'minLevel' => 35, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:55'],
            ['group' => 'O-', 'available' => 11, 'reserved' => 5, 'minLevel' => 30, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:50'],
        ];

        return array_map(function (array $item): array {
            $item['status'] = $this->resolveStatus($item['available'], $item['minLevel']);

            return $item;
        }, $definitions);
    }
}
