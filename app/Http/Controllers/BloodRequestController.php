<?php

namespace App\Http\Controllers;

use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\User;
use App\Services\FulfillmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class BloodRequestController extends Controller
{
    public function __construct(
        private FulfillmentService $fulfillmentService
    ) {}

    /**
     * Store a new partner hospital blood request through FulfillmentService.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', BloodRequest::class);

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'hospital_id' => [
                Rule::requiredIf(fn (): bool => ! $actor->hospitalUser()->where('is_active', true)->exists()),
                'nullable',
                'integer',
                'exists:hospitals,id',
            ],
            'blood_group' => ['required', 'string', Rule::in(BloodRequest::BLOOD_GROUPS)],
            'blood_component_id' => ['nullable', 'integer', 'min:1'],
            'blood_component_code' => ['nullable', 'string', 'max:32'],
            'requested_units' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', 'string', Rule::in(BloodRequest::PRIORITIES)],
            'request_date' => ['nullable', 'date'],
            'required_date' => ['nullable', 'date'],
            'request_reason' => ['required', 'string', 'max:2000'],
            'additional_notes' => ['nullable', 'string', 'max:2000'],
            'department_or_ward' => ['nullable', 'string', 'max:150'],
            'contact_person_snapshot' => ['nullable', 'string', 'max:150'],
            'contact_phone_snapshot' => ['nullable', 'string', 'max:32'],
            'contact_email_snapshot' => ['nullable', 'email', 'max:191'],
        ]);

        $hospital = $this->resolveHospitalForStore(
            $actor,
            isset($validated['hospital_id']) ? (int) $validated['hospital_id'] : null
        );

        Gate::authorize('create', [BloodRequest::class, $hospital]);

        $attributes = Arr::only($validated, [
            'blood_group',
            'blood_component_id',
            'blood_component_code',
            'requested_units',
            'priority',
            'request_date',
            'required_date',
            'request_reason',
            'additional_notes',
            'department_or_ward',
            'contact_person_snapshot',
            'contact_phone_snapshot',
            'contact_email_snapshot',
        ]);

        $bloodRequest = $this->fulfillmentService->createRequest($hospital, $actor, $attributes);

        return $this->redirectSuccess("Blood request {$bloodRequest->request_code} created successfully.");
    }

    /**
     * Approve a Pending blood request for allocation and dispatch.
     */
    public function approve(Request $request, BloodRequest $bloodRequest): RedirectResponse
    {
        Gate::authorize('review', $bloodRequest);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->fulfillmentService->approveRequest(
            $bloodRequest,
            $request->user(),
            $validated['notes'] ?? null
        );

        return $this->redirectSuccess("Blood request {$updated->request_code} approved.");
    }

    /**
     * Reject an eligible blood request with a mandatory clinical rationale.
     */
    public function reject(Request $request, BloodRequest $bloodRequest): RedirectResponse
    {
        Gate::authorize('review', $bloodRequest);

        $validated = $request->validate($this->rationaleRules('rejection_reason'));

        $updated = $this->fulfillmentService->rejectRequest(
            $bloodRequest,
            $request->user(),
            $this->extractRationale($validated, 'rejection_reason')
        );

        return $this->redirectSuccess("Blood request {$updated->request_code} rejected.");
    }

    /**
     * Update mutable details or quantities on a non-terminal blood request.
     */
    public function update(Request $request, BloodRequest $bloodRequest): RedirectResponse
    {
        Gate::authorize('update', $bloodRequest);

        $validated = $request->validate([
            'requested_units' => ['sometimes', 'required', 'integer', 'min:1'],
            'blood_group' => ['sometimes', 'required', 'string', Rule::in(BloodRequest::BLOOD_GROUPS)],
            'blood_component_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'blood_component_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'priority' => ['sometimes', 'required', 'string', Rule::in(BloodRequest::PRIORITIES)],
            'required_date' => ['sometimes', 'required', 'date'],
            'request_reason' => ['sometimes', 'required', 'string', 'max:2000'],
            'additional_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'department_or_ward' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $attributes = Arr::only($validated, [
            'requested_units',
            'blood_group',
            'blood_component_id',
            'blood_component_code',
            'priority',
            'required_date',
            'request_reason',
            'additional_notes',
            'department_or_ward',
            'notes',
        ]);

        $updated = $this->fulfillmentService->updateRequest($bloodRequest, $request->user(), $attributes);

        return $this->redirectSuccess("Blood request {$updated->request_code} updated.");
    }

    /**
     * Reserve compatible Available blood units for an Approved or Partially Fulfilled request.
     */
    public function reserve(Request $request, BloodRequest $bloodRequest): RedirectResponse
    {
        Gate::authorize('allocate', $bloodRequest);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'source_blood_group' => ['nullable', 'string', Rule::in(BloodRequest::BLOOD_GROUPS)],
            'dispatched_blood_group' => ['nullable', 'string', Rule::in(BloodRequest::BLOOD_GROUPS)],
            'blood_component_id' => ['nullable', 'integer', 'min:1'],
            'blood_component_code' => ['nullable', 'string', 'max:32'],
            'blood_unit_ids' => ['nullable', 'array', 'min:1'],
            'blood_unit_ids.*' => ['integer', 'min:1'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = Arr::only($validated, [
            'source_blood_group',
            'dispatched_blood_group',
            'blood_component_id',
            'blood_component_code',
            'blood_unit_ids',
            'override_reason',
            'notes',
        ]);

        $allocations = $this->fulfillmentService->reserveUnits(
            $bloodRequest,
            $request->user(),
            (int) $validated['quantity'],
            $attributes
        );

        return $this->redirectSuccess("Reserved {$allocations->count()} unit(s) for {$bloodRequest->request_code}.");
    }

    /**
     * Release all or selected Reserved unit allocations back to Available inventory.
     */
    public function release(Request $request, BloodRequest $bloodRequest): RedirectResponse
    {
        Gate::authorize('releaseAllocation', $bloodRequest);

        $validated = $request->validate([
            'allocation_ids' => ['nullable', 'array', 'min:1'],
            'allocation_ids.*' => ['integer', 'min:1'],
            'reason' => ['nullable', 'string', 'min:5', 'max:2000'],
        ]);

        $allocationIds = array_key_exists('allocation_ids', $validated) && is_array($validated['allocation_ids'])
            ? array_map(static fn (int|string $id): int => (int) $id, $validated['allocation_ids'])
            : null;

        $updated = $this->fulfillmentService->releaseReservedAllocations(
            $bloodRequest,
            $request->user(),
            $allocationIds,
            $validated['reason'] ?? null
        );

        return $this->redirectSuccess("Released reserved allocation(s) for {$updated->request_code}.");
    }

    /**
     * Dispatch compatible units against an Approved or Partially Fulfilled blood request.
     */
    public function dispatch(Request $request, BloodRequest $bloodRequest): RedirectResponse
    {
        Gate::authorize('dispatch', $bloodRequest);

        $validated = $request->validate([
            'quantity' => ['required_without:units_dispatched', 'nullable', 'integer', 'min:1'],
            'units_dispatched' => ['nullable', 'integer', 'min:1'],
            'dispatched_blood_group' => ['nullable', 'string', Rule::in(BloodRequest::BLOOD_GROUPS)],
            'blood_component_id' => ['nullable', 'integer', 'min:1'],
            'blood_component_code' => ['nullable', 'string', 'max:32'],
            'dispatch_date' => ['nullable', 'date'],
            'courier_reference' => ['nullable', 'string', 'max:64'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = Arr::only($validated, [
            'quantity',
            'units_dispatched',
            'dispatched_blood_group',
            'blood_component_id',
            'blood_component_code',
            'dispatch_date',
            'courier_reference',
            'override_reason',
            'notes',
        ]);

        $fulfillment = $this->fulfillmentService->dispatchUnits($bloodRequest, $request->user(), $attributes);

        return $this->redirectSuccess(
            "Dispatched {$fulfillment->units_dispatched} unit(s) for {$bloodRequest->request_code} ({$fulfillment->fulfillment_code})."
        );
    }

    /**
     * Cancel an eligible blood request and release any remaining Reserved holds.
     */
    public function cancel(Request $request, BloodRequest $bloodRequest): RedirectResponse
    {
        Gate::authorize('cancel', $bloodRequest);

        $validated = $request->validate($this->rationaleRules('cancellation_reason'));

        $updated = $this->fulfillmentService->cancelRequest(
            $bloodRequest,
            $request->user(),
            $this->extractRationale($validated, 'cancellation_reason')
        );

        return $this->redirectSuccess("Blood request {$updated->request_code} cancelled.");
    }

    /**
     * Resolve the target Hospital for request creation using validated input or active coordinator affiliation.
     */
    private function resolveHospitalForStore(User $actor, ?int $hospitalId): Hospital
    {
        if ($hospitalId !== null) {
            return Hospital::query()->findOrFail($hospitalId);
        }

        $coordinatorLink = $actor->hospitalUser()
            ->where('is_active', true)
            ->with('hospital')
            ->firstOrFail();

        return $coordinatorLink->hospital;
    }

    /**
     * Build shared validation rules for required clinical rationales accepting a primary key or `reason` alias.
     *
     * @return array<string, list<string>>
     */
    private function rationaleRules(string $primaryKey): array
    {
        return [
            $primaryKey => ['required_without:reason', 'nullable', 'string', 'min:5', 'max:2000'],
            'reason' => ['nullable', 'string', 'min:5', 'max:2000'],
        ];
    }

    /**
     * Extract a validated rationale string from either the primary field or `reason` alias.
     *
     * @param  array<string, mixed>  $validated
     */
    private function extractRationale(array $validated, string $primaryKey): string
    {
        return (string) ($validated[$primaryKey] ?? $validated['reason'] ?? '');
    }

    /**
     * Redirect to the requests overview with a standard Blade flash success message.
     */
    private function redirectSuccess(string $message): RedirectResponse
    {
        return redirect()
            ->route('requests.index')
            ->with('success', $message);
    }
}
