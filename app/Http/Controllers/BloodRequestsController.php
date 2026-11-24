<?php

namespace App\Http\Controllers;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\InventoryStock;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BloodRequestsController extends Controller
{
    /**
     * Display the blood requests overview dashboard and filterable requests table.
     */
    public function index(Request $request): View
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403);
        }

        $canFulfill = $user->hasPermission('requests.fulfill');
        $isHospitalCoordinator = $user->hasRole('hospital') && $user->hospitalUser()->where('is_active', true)->exists();

        if (! $canFulfill && ! $isHospitalCoordinator) {
            abort(403, 'You are not authorized to view blood requests.');
        }

        $baseQuery = BloodRequest::query();

        if ($isHospitalCoordinator && ! $canFulfill) {
            $hospitalId = $user->hospitalUser()->where('is_active', true)->value('hospital_id');
            if (! $hospitalId) {
                abort(403, 'Active hospital affiliation required.');
            }
            $baseQuery->where('hospital_id', $hospitalId);
        }

        $summary = [
            'totalRequests' => (clone $baseQuery)->count(),
            'pendingRequests' => (clone $baseQuery)->where('status', 'Pending')->count(),
            'urgentRequests' => (clone $baseQuery)->whereIn('priority', ['High', 'Critical'])->count(),
            'fulfilledRequests' => (clone $baseQuery)->where('status', 'Fulfilled')->count(),
            'totalUnitsRequested' => (int) (clone $baseQuery)->sum('requested_units'),
            'totalUnitsFulfilled' => (int) (clone $baseQuery)->sum('fulfilled_units'),
        ];

        $query = clone $baseQuery;

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search): void {
                $q->where('request_code', 'like', "%{$search}%")
                    ->orWhereHas('hospital', function ($hq) use ($search): void {
                        $hq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('blood_group') && $request->input('blood_group') !== 'all') {
            $query->where('blood_group', $request->input('blood_group'));
        }

        if ($request->filled('component') && $request->input('component') !== 'all') {
            $component = $request->input('component');
            $query->where(function ($q) use ($component): void {
                $q->where('blood_component_id', $component)
                    ->orWhereHas('bloodComponent', function ($cq) use ($component): void {
                        $cq->where('code', $component)
                            ->orWhere('name', $component);
                    });
            });
        }

        if ($request->filled('priority') && $request->input('priority') !== 'all') {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('request_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('request_date', '<=', $request->input('date_to'));
        }

        $records = $query->with(['hospital', 'bloodComponent', 'requestedBy'])
            ->latest('id')
            ->get();

        $requests = $records->map(fn (BloodRequest $item): array => $this->formatRequestListItem($item))->all();

        return view('requests.index', [
            'requests' => $requests,
            'summary' => $summary,
            'bloodGroups' => $this->bloodGroupOptions(),
            'priorities' => $this->priorityOptions(),
            'statuses' => $this->statusOptions(),
        ]);
    }

    /**
     * Display the new hospital blood request creation form.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', BloodRequest::class);

        /** @var User|null $user */
        $user = $request->user();
        $isStaffOrAdmin = $user && ($user->hasRole('admin') || $user->hasRole('staff') || $user->hasPermission('requests.fulfill'));

        $coordinatorLink = ! $isStaffOrAdmin
            ? $user?->hospitalUser()->where('is_active', true)->with('hospital')->first()
            : null;
        $hospital = $coordinatorLink?->hospital;

        $prefill = [];
        if ($hospital) {
            $prefill = [
                'hospitalId' => $hospital->id,
                'hospitalName' => $hospital->name,
                'contactPerson' => $coordinatorLink->contact_name ?? $hospital->primary_contact_name ?? $user->name,
                'contactNumber' => $coordinatorLink->work_phone ?? $hospital->primary_phone ?? $user->phone ?? '',
                'email' => $coordinatorLink->work_email ?? $hospital->primary_email ?? $user->email ?? '',
                'hospitalAddress' => $hospital->address ?? '',
            ];
        }

        $hospitals = Hospital::query()
            ->where('is_active', true)
            ->where('verification_status', 'Verified')
            ->orderBy('name')
            ->get();

        return view('requests.create', [
            'bloodGroups' => $this->bloodGroupOptions(),
            'priorities' => $this->priorityOptions(),
            'prefill' => $prefill,
            'hospital' => $hospital,
            'hospitals' => $hospitals,
            'components' => BloodComponent::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Display full blood request details, fulfillment progress, history, and staff workflow.
     */
    public function show(Request $request, string $requestId): View
    {
        if ($requestId === 'create') {
            return $this->create($request);
        }

        $bloodRequest = BloodRequest::query()
            ->with([
                'hospital',
                'requestedBy',
                'bloodComponent',
                'reviewedBy',
                'cancelledBy',
                'statusHistories.actor',
                'fulfillments.dispatchedBy',
                'fulfillments.bloodComponent',
                'allocations.bloodUnit',
            ])
            ->where('id', $requestId)
            ->orWhere('request_code', $requestId)
            ->firstOrFail();

        Gate::authorize('view', $bloodRequest);

        $stockLevels = [];
        foreach (BloodRequest::BLOOD_GROUPS as $group) {
            $stockLevels[$group] = 0;
        }

        $dbStocks = InventoryStock::query()
            ->selectRaw('blood_group, sum(available_units) as total_available')
            ->groupBy('blood_group')
            ->pluck('total_available', 'blood_group')
            ->all();

        foreach ($dbStocks as $grp => $qty) {
            $stockLevels[$grp] = (int) $qty;
        }

        return view('requests.show', [
            'bloodRequest' => $this->formatRequestForDetail($bloodRequest),
            'bloodRequestRecord' => $bloodRequest,
            'bloodGroups' => $this->bloodGroupOptions(),
            'sampleInventory' => $stockLevels,
            'components' => BloodComponent::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Display the edit request form prefilled with live persisted data.
     */
    public function edit(Request $request, string $requestId): View|RedirectResponse
    {
        if ($requestId === 'create') {
            return $this->create($request);
        }

        $bloodRequest = BloodRequest::query()
            ->with(['hospital', 'requestedBy', 'bloodComponent'])
            ->where('id', $requestId)
            ->orWhere('request_code', $requestId)
            ->firstOrFail();

        Gate::authorize('update', $bloodRequest);

        if (in_array($bloodRequest->status, ['Rejected', 'Cancelled', 'Fulfilled'], true)) {
            return redirect()
                ->route('requests.show', $bloodRequest->request_code)
                ->with('error', "Blood request {$bloodRequest->request_code} is in terminal state '{$bloodRequest->status}' and cannot be edited.");
        }

        return view('requests.edit', [
            'bloodRequest' => $this->formatRequestForDetail($bloodRequest),
            'bloodRequestRecord' => $bloodRequest,
            'bloodGroups' => $this->bloodGroupOptions(),
            'priorities' => $this->priorityOptions(),
            'components' => BloodComponent::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Standard ABO/Rh blood groups.
     *
     * @return array<int, string>
     */
    private function bloodGroupOptions(): array
    {
        return BloodRequest::BLOOD_GROUPS;
    }

    /**
     * Clinical urgency levels for blood requests.
     *
     * @return array<int, string>
     */
    private function priorityOptions(): array
    {
        return BloodRequest::PRIORITIES;
    }

    /**
     * Supported lifecycle statuses for blood requests.
     *
     * @return array<int, string>
     */
    private function statusOptions(): array
    {
        return BloodRequest::STATUSES;
    }

    /**
     * Format a blood request model for the overview table and Alpine directory.
     *
     * @return array<string, mixed>
     */
    private function formatRequestListItem(BloodRequest $req): array
    {
        return [
            'id' => $req->request_code,
            'record_id' => $req->id,
            'hospitalName' => $req->hospital?->name ?? 'Unknown Hospital',
            'contactPerson' => $req->contact_person_snapshot ?? $req->requestedBy?->name ?? $req->hospital?->primary_contact_name ?? 'Coordinator',
            'bloodGroup' => $req->blood_group,
            'component' => $req->bloodComponent?->name,
            'requestedUnits' => (int) $req->requested_units,
            'fulfilledUnits' => (int) $req->fulfilled_units,
            'remainingUnits' => max(0, (int) $req->requested_units - (int) $req->fulfilled_units),
            'requestDate' => $req->request_date?->format('Y-m-d') ?? $req->created_at?->format('Y-m-d') ?? '',
            'requestDateFormatted' => $req->request_date?->format('M d, Y') ?? $req->created_at?->format('M d, Y') ?? '—',
            'requiredDate' => $req->required_date?->format('Y-m-d') ?? '',
            'requiredDateFormatted' => $req->required_date?->format('M d, Y') ?? '—',
            'priority' => $req->priority,
            'status' => $req->status,
        ];
    }

    /**
     * Format a blood request model with detailed history and fulfillment allocations for show/edit views.
     *
     * @return array<string, mixed>
     */
    private function formatRequestForDetail(BloodRequest $bloodRequest): array
    {
        return [
            'id' => $bloodRequest->request_code,
            'record_id' => $bloodRequest->id,
            'hospitalName' => $bloodRequest->hospital?->name ?? 'Unknown Hospital',
            'contactPerson' => $bloodRequest->contact_person_snapshot ?? $bloodRequest->requestedBy?->name ?? $bloodRequest->hospital?->primary_contact_name ?? 'Coordinator',
            'contactNumber' => $bloodRequest->contact_phone_snapshot ?? $bloodRequest->hospital?->primary_phone ?? '—',
            'email' => $bloodRequest->contact_email_snapshot ?? $bloodRequest->requestedBy?->email ?? $bloodRequest->hospital?->primary_email ?? '—',
            'hospitalAddress' => $bloodRequest->department_or_ward ?? $bloodRequest->hospital?->address ?? '—',
            'requesterName' => $bloodRequest->requestedBy?->name ?? '—',
            'requesterEmail' => $bloodRequest->requestedBy?->email ?? '—',
            'bloodGroup' => $bloodRequest->blood_group,
            'bloodComponentId' => $bloodRequest->blood_component_id,
            'componentName' => $bloodRequest->bloodComponent?->name,
            'componentCode' => $bloodRequest->bloodComponent?->code,
            'requestedUnits' => (int) $bloodRequest->requested_units,
            'reservedUnits' => (int) $bloodRequest->reserved_units,
            'fulfilledUnits' => (int) $bloodRequest->fulfilled_units,
            'remainingUnits' => max(0, (int) $bloodRequest->requested_units - (int) $bloodRequest->fulfilled_units),
            'priority' => $bloodRequest->priority,
            'status' => $bloodRequest->status,
            'requestDate' => $bloodRequest->request_date?->format('Y-m-d') ?? $bloodRequest->created_at?->format('Y-m-d') ?? '',
            'requestDateFormatted' => $bloodRequest->request_date?->format('M d, Y') ?? $bloodRequest->created_at?->format('M d, Y') ?? '—',
            'requiredDate' => $bloodRequest->required_date?->format('Y-m-d') ?? '',
            'requiredDateFormatted' => $bloodRequest->required_date?->format('M d, Y') ?? '—',
            'requestReason' => $bloodRequest->request_reason,
            'additionalNotes' => $bloodRequest->additional_notes,
            'reviewedBy' => $bloodRequest->reviewedBy?->name,
            'reviewedAt' => $bloodRequest->reviewed_at?->format('M d, Y • H:i'),
            'rejectionReason' => $bloodRequest->rejection_reason,
            'cancelledBy' => $bloodRequest->cancelledBy?->name,
            'cancelledAt' => $bloodRequest->cancelled_at?->format('M d, Y • H:i'),
            'cancellationReason' => $bloodRequest->cancellation_reason,
            'history' => $bloodRequest->statusHistories->sortByDesc('occurred_at')->values()->map(fn ($history): array => [
                'date' => $history->occurred_at?->format('M d, Y • H:i') ?? '',
                'event' => $history->event_name,
                'status' => $history->new_status,
                'units' => (int) $history->units_affected,
                'actor' => $history->actor_name ?? $history->actor?->name ?? 'System',
                'notes' => $history->notes ?? '',
            ])->all(),
            'fulfillments' => $bloodRequest->fulfillments->sortByDesc('id')->values()->map(fn ($f): array => [
                'code' => $f->fulfillment_code,
                'units' => (int) $f->units_dispatched,
                'group' => $f->dispatched_blood_group,
                'component' => $f->bloodComponent?->name,
                'courier' => $f->courier_reference,
                'date' => $f->dispatch_date?->format('M d, Y') ?? $f->dispatched_at?->format('M d, Y • H:i') ?? '—',
                'dispatchedBy' => $f->dispatchedBy?->name ?? 'Staff',
                'notes' => $f->notes,
            ])->all(),
            'allocations' => $bloodRequest->allocations->sortByDesc('id')->values()->map(fn ($a): array => [
                'id' => $a->id,
                'code' => $a->allocation_code,
                'units' => (int) $a->units_allocated,
                'status' => $a->allocation_status,
                'unitNumber' => $a->bloodUnit?->unit_number,
                'bloodGroup' => $a->bloodUnit?->blood_group,
                'date' => $a->allocated_at?->format('M d, Y • H:i') ?? '',
            ])->all(),
        ];
    }
}
