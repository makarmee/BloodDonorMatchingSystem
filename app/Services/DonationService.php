<?php

namespace App\Services;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DonationService
{
    /**
     * Allowed initial collection statuses when creating a new donation record.
     *
     * @var list<string>
     */
    private const array INITIAL_COLLECTION_STATUSES = ['Scheduled', 'Completed'];

    public function __construct(
        private DonorEligibilityService $eligibilityService,
        private ReferenceCodeGenerator $codeGenerator,
        private ServiceInputNormalizer $inputNormalizer
    ) {}

    /**
     * Register a new scheduled or immediately completed donation for an eligible donor.
     *
     * @param  array{
     *     blood_component_id?: int|string|null,
     *     blood_component_code?: string|null,
     *     blood_group?: string|null,
     *     quantity_ml?: int|string,
     *     collection_status?: string,
     *     donation_date?: CarbonInterface|string|null,
     *     collected_at?: CarbonInterface|string|null,
     *     center_id?: int|string|null,
     *     camp_id?: int|string|null,
     *     appointment_id?: int|string|null,
     *     collection_location_name?: string|null,
     *     staff_member_name?: string|null,
     *     collection_notes?: string|null,
     *     storage_location?: string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function registerDonation(Donor $donor, ?User $actor, array $attributes): Donation
    {
        $this->authorizeAction($actor, 'create', Donation::class);

        $validated = $this->validateRegistrationAttributes($attributes, $actor);

        $persistedDonation = DB::transaction(function () use ($donor, $actor, $validated): Donation {
            // Always lock the parent donor row first to keep lock ordering identical across services.
            $lockedDonor = Donor::query()
                ->whereKey($donor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureDonorCanDonate($lockedDonor);

            $bloodGroup = $this->resolveAndValidateBloodGroup($lockedDonor, $validated['blood_group']);
            $component = $this->resolveActiveComponent(
                $validated['blood_component_id'],
                $validated['blood_component_code']
            );
            $location = $this->resolveCollectionLocation(
                $validated['center_id'],
                $validated['camp_id'],
                $validated['collection_location_name']
            );
            $lockedAppointment = $this->resolveAndLockAppointment(
                $validated['appointment_id'],
                $lockedDonor
            );

            $collectionStatus = $validated['collection_status'];
            $collectedAt = $collectionStatus === 'Completed'
                ? ($validated['collected_at'] ?? CarbonImmutable::now())
                : null;

            $donation = Donation::query()->create([
                'donation_code' => $this->codeGenerator->generate(Donation::class, 'donation_code', 'DON-'),
                'donor_id' => $lockedDonor->id,
                'appointment_id' => $lockedAppointment?->id,
                'camp_id' => $location['camp_id'],
                'center_id' => $location['center_id'],
                'collection_location_name' => $location['collection_location_name'],
                'recorded_by_user_id' => $actor->getKey(),
                'staff_member_name' => $validated['staff_member_name'],
                'blood_group' => $bloodGroup,
                'blood_component_id' => $component->id,
                'donation_date' => $validated['donation_date'],
                'collected_at' => $collectedAt,
                'quantity_ml' => $validated['quantity_ml'],
                'collection_status' => $collectionStatus,
                'screening_status' => 'Pending',
                'collection_notes' => $validated['collection_notes'],
                'screening_notes' => null,
                'released_to_inventory_at' => null,
                'approved_by_user_id' => null,
            ]);

            if ($collectionStatus === 'Completed') {
                $this->finalizeCompletedCollection(
                    donation: $donation,
                    lockedDonor: $lockedDonor,
                    component: $component,
                    lockedAppointment: $lockedAppointment,
                    storageLocation: $validated['storage_location']
                );
            }

            return $donation;
        }, 3);

        $donor->refresh();

        return $persistedDonation->fresh([
            'donor',
            'bloodComponent',
            'primaryBloodUnit',
            'center',
            'camp',
            'appointment',
        ]) ?? $persistedDonation;
    }

    /**
     * Transition a scheduled donation to `Completed`, generating its single primary quarantined unit.
     *
     * @param  array{
     *     quantity_ml?: int|string,
     *     collected_at?: CarbonInterface|string|null,
     *     staff_member_name?: string|null,
     *     collection_notes?: string|null,
     *     storage_location?: string|null
     * }  $attributes
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function completeDonation(Donation $donation, ?User $actor, array $attributes = []): Donation
    {
        $this->authorizeAction($actor, 'complete', $donation);

        $quantityOverride = array_key_exists('quantity_ml', $attributes)
            ? $this->parseQuantityMl($attributes['quantity_ml'])
            : null;
        $collectedAtOverride = $this->inputNormalizer->parseOptionalDateTime($attributes['collected_at'] ?? null, 'collected_at');
        $storageLocation = $this->inputNormalizer->normalizeOptionalText($attributes['storage_location'] ?? null, 120, 'storage_location');
        $staffMemberName = array_key_exists('staff_member_name', $attributes)
            ? $this->inputNormalizer->normalizeRequiredText($attributes['staff_member_name'], 150, 'staff_member_name', 'Phlebotomist or staff member name is required.')
            : null;
        $collectionNotes = array_key_exists('collection_notes', $attributes)
            ? $this->inputNormalizer->normalizeOptionalText($attributes['collection_notes'], null, 'collection_notes')
            : null;

        $completedDonation = DB::transaction(function () use (
            $donation,
            $quantityOverride,
            $collectedAtOverride,
            $storageLocation,
            $staffMemberName,
            $collectionNotes
        ): Donation {
            $lockedDonor = Donor::query()
                ->whereKey($donation->donor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDonation = Donation::query()
                ->whereKey($donation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDonation->collection_status !== 'Scheduled') {
                throw ValidationException::withMessages([
                    'collection_status' => 'Only scheduled donations can be transitioned to Completed.',
                ]);
            }

            $this->ensureDonorCanDonate($lockedDonor);

            $component = BloodComponent::query()
                ->whereKey($lockedDonation->blood_component_id)
                ->firstOrFail();

            if (! $component->is_active) {
                throw ValidationException::withMessages([
                    'blood_component_id' => 'The selected blood component is inactive and cannot be collected.',
                ]);
            }

            $lockedAppointment = $lockedDonation->appointment_id !== null
                ? DonorAppointment::query()->whereKey($lockedDonation->appointment_id)->lockForUpdate()->first()
                : null;

            $lockedDonation->collection_status = 'Completed';
            $lockedDonation->collected_at = $collectedAtOverride ?? $lockedDonation->collected_at ?? CarbonImmutable::now();

            if ($quantityOverride !== null) {
                $lockedDonation->quantity_ml = $quantityOverride;
            }

            if ($staffMemberName !== null) {
                $lockedDonation->staff_member_name = $staffMemberName;
            }

            if ($collectionNotes !== null) {
                $lockedDonation->collection_notes = $collectionNotes;
            }

            $lockedDonation->save();

            $this->finalizeCompletedCollection(
                donation: $lockedDonation,
                lockedDonor: $lockedDonor,
                component: $component,
                lockedAppointment: $lockedAppointment,
                storageLocation: $storageLocation
            );

            return $lockedDonation;
        }, 3);

        return $completedDonation->fresh(['donor', 'bloodComponent', 'primaryBloodUnit', 'appointment']) ?? $completedDonation;
    }

    /**
     * Cancel a scheduled donation prior to physical blood draw without erasing history.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancelDonation(Donation $donation, ?User $actor, ?string $reason = null): Donation
    {
        $this->authorizeAction($actor, 'cancel', $donation);

        $trimmedReason = is_string($reason) ? trim($reason) : null;

        $cancelled = DB::transaction(function () use ($donation, $trimmedReason): Donation {
            Donor::query()
                ->whereKey($donation->donor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDonation = Donation::query()
                ->whereKey($donation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDonation->collection_status !== 'Scheduled') {
                throw ValidationException::withMessages([
                    'collection_status' => 'Only scheduled donations can be cancelled.',
                ]);
            }

            $lockedDonation->collection_status = 'Cancelled';
            $lockedDonation->collected_at = null;

            if ($trimmedReason !== null && $trimmedReason !== '') {
                $lockedDonation->collection_notes = $trimmedReason;
            }

            $lockedDonation->save();

            return $lockedDonation;
        }, 3);

        return $cancelled->fresh(['donor', 'bloodComponent']) ?? $cancelled;
    }

    /**
     * Create the single primary quarantined BloodUnit for a completed donation (1 donation -> 1 primary unit).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function createPrimaryBloodUnit(
        Donation $donation,
        ?User $actor,
        ?string $storageLocation = null,
        ?int $volumeMl = null
    ): BloodUnit {
        $this->authorizeAction($actor, 'createUnit', $donation);

        $normalizedLocation = $this->inputNormalizer->normalizeOptionalText($storageLocation, 120, 'storage_location');

        if ($volumeMl !== null && ($volumeMl < 150 || $volumeMl > 800)) {
            throw ValidationException::withMessages([
                'volume_ml' => 'Blood unit volume must be between 150 ml and 800 ml.',
            ]);
        }

        return DB::transaction(function () use ($donation, $normalizedLocation, $volumeMl): BloodUnit {
            Donor::query()
                ->whereKey($donation->donor_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDonation = Donation::query()
                ->whereKey($donation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDonation->collection_status !== 'Completed') {
                throw ValidationException::withMessages([
                    'collection_status' => 'Primary blood units can only be created for Completed donations.',
                ]);
            }

            if ($lockedDonation->screening_status === 'Failed' || $lockedDonation->released_to_inventory_at !== null) {
                throw ValidationException::withMessages([
                    'donation' => 'Cannot create a quarantined unit for a failed or already released donation.',
                ]);
            }

            $component = BloodComponent::query()
                ->whereKey($lockedDonation->blood_component_id)
                ->firstOrFail();

            return $this->createPrimaryUnitForLockedDonation(
                donation: $lockedDonation,
                component: $component,
                storageLocation: $normalizedLocation,
                volumeMl: $volumeMl
            );
        }, 3);
    }

    /**
     * Authorize the actor against DonationPolicy after ensuring an authenticated User instance.
     *
     * @throws AuthorizationException
     */
    private function authorizeAction(?User $actor, string $ability, mixed $arguments): void
    {
        if (! $actor instanceof User) {
            throw new AuthorizationException('An authenticated and authorized user is required for donation operations.');
        }

        Gate::forUser($actor)->authorize($ability, $arguments);
    }

    /**
     * Enforce donor account activity, complete required profile fields, and authoritative eligibility.
     *
     * @throws ValidationException
     */
    private function ensureDonorCanDonate(Donor $lockedDonor): void
    {
        if ($lockedDonor->account_status !== 'Active') {
            throw ValidationException::withMessages([
                'donor' => 'Inactive donor accounts cannot register or complete blood donations.',
            ]);
        }

        $missingFields = [];

        foreach (Donor::REQUIRED_PROFILE_FIELDS as $field) {
            $value = $lockedDonor->getAttribute($field);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missingFields[] = $field;
            }
        }

        if (! $lockedDonor->profile_completed || $missingFields !== []) {
            throw ValidationException::withMessages([
                'profile_completed' => 'Donor profile must be complete before registering or completing a donation.',
            ]);
        }

        if (! $this->eligibilityService->isCurrentlyEligible($lockedDonor)) {
            throw ValidationException::withMessages([
                'eligibility_status' => 'Donor must hold current verified Eligible clearance before donating.',
            ]);
        }
    }

    /**
     * Finalize donor donation metrics, linked appointment state, and primary quarantined unit creation.
     *
     * @throws ValidationException
     */
    private function finalizeCompletedCollection(
        Donation $donation,
        Donor $lockedDonor,
        BloodComponent $component,
        ?DonorAppointment $lockedAppointment,
        ?string $storageLocation
    ): void {
        $this->createPrimaryUnitForLockedDonation($donation, $component, $storageLocation);

        $donationDateString = $donation->donation_date->toDateString();
        $existingLastDate = $lockedDonor->last_donation_date?->toDateString();

        if ($existingLastDate === null || $donationDateString > $existingLastDate) {
            $lockedDonor->last_donation_date = $donationDateString;
        }

        $lockedDonor->total_completed_donations = $lockedDonor->total_completed_donations + 1;
        $lockedDonor->save();

        if ($lockedAppointment instanceof DonorAppointment && in_array($lockedAppointment->status, ['Pending', 'Confirmed'], true)) {
            $lockedAppointment->status = 'Completed';
            $lockedAppointment->save();
        }
    }

    /**
     * Create the single unsplit primary `Quarantined` blood unit under donation lock, blocking duplicates.
     *
     * @throws ValidationException
     */
    private function createPrimaryUnitForLockedDonation(
        Donation $donation,
        BloodComponent $component,
        ?string $storageLocation = null,
        ?int $volumeMl = null
    ): BloodUnit {
        $alreadyExists = BloodUnit::query()
            ->where('donation_id', $donation->id)
            ->whereNull('parent_unit_id')
            ->lockForUpdate()
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'blood_unit' => 'A primary blood unit has already been created for this donation.',
            ]);
        }

        $collectedAt = $donation->collected_at?->toImmutable() ?? CarbonImmutable::now();
        $unitCodePrefix = 'UNIT-'.$collectedAt->format('Y').'-';

        return BloodUnit::query()->create([
            'unit_code' => $this->codeGenerator->generate(BloodUnit::class, 'unit_code', $unitCodePrefix),
            'donation_id' => $donation->id,
            'parent_unit_id' => null,
            'blood_group' => $donation->blood_group,
            'blood_component_id' => $component->id,
            'volume_ml' => $volumeMl ?? $donation->quantity_ml,
            'storage_location' => $storageLocation,
            'unit_status' => 'Quarantined',
            'collected_at' => $collectedAt,
            'expires_at' => $collectedAt->addDays($component->shelf_life_days),
            'released_at' => null,
            'released_by_user_id' => null,
        ]);
    }

    /**
     * Validate and normalize donation registration payload prior to acquiring database row locks.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     blood_component_id: ?int,
     *     blood_component_code: ?string,
     *     blood_group: ?string,
     *     quantity_ml: int,
     *     collection_status: string,
     *     donation_date: string,
     *     collected_at: ?CarbonImmutable,
     *     center_id: ?int,
     *     camp_id: ?int,
     *     appointment_id: ?int,
     *     collection_location_name: ?string,
     *     staff_member_name: string,
     *     collection_notes: ?string,
     *     storage_location: ?string
     * }
     *
     * @throws ValidationException
     */
    private function validateRegistrationAttributes(array $attributes, User $actor): array
    {
        $collectionStatus = $attributes['collection_status'] ?? 'Completed';

        if (! is_string($collectionStatus) || ! in_array($collectionStatus, self::INITIAL_COLLECTION_STATUSES, true)) {
            throw ValidationException::withMessages([
                'collection_status' => 'Initial donation collection status must be Scheduled or Completed.',
            ]);
        }

        $componentId = isset($attributes['blood_component_id']) && $attributes['blood_component_id'] !== ''
            ? (int) $attributes['blood_component_id']
            : null;
        $componentCode = isset($attributes['blood_component_code']) && is_string($attributes['blood_component_code'])
            ? trim($attributes['blood_component_code'])
            : null;

        if ($componentId === null && ($componentCode === null || $componentCode === '')) {
            throw ValidationException::withMessages([
                'blood_component_id' => 'A valid blood component is required for donation registration.',
            ]);
        }

        $quantityMl = $this->parseQuantityMl($attributes['quantity_ml'] ?? null);

        $bloodGroup = $attributes['blood_group'] ?? null;

        if ($bloodGroup !== null && (! is_string($bloodGroup) || ! in_array($bloodGroup, Donation::BLOOD_GROUPS, true))) {
            throw ValidationException::withMessages([
                'blood_group' => 'Blood group must be one of the 8 valid ABO/Rh groups.',
            ]);
        }

        $centerId = isset($attributes['center_id']) && $attributes['center_id'] !== '' ? (int) $attributes['center_id'] : null;
        $campId = isset($attributes['camp_id']) && $attributes['camp_id'] !== '' ? (int) $attributes['camp_id'] : null;

        if ($centerId !== null && $campId !== null) {
            throw ValidationException::withMessages([
                'center_id' => 'A donation cannot target both a center and a camp simultaneously.',
            ]);
        }

        $appointmentId = isset($attributes['appointment_id']) && $attributes['appointment_id'] !== ''
            ? (int) $attributes['appointment_id']
            : null;

        $donationDate = $this->inputNormalizer->parseRequiredOrDefaultDate($attributes['donation_date'] ?? null, 'donation_date');
        $collectedAt = $this->inputNormalizer->parseOptionalDateTime($attributes['collected_at'] ?? null, 'collected_at');

        $staffMemberName = array_key_exists('staff_member_name', $attributes)
            ? $this->inputNormalizer->normalizeRequiredText($attributes['staff_member_name'], 150, 'staff_member_name', 'Phlebotomist or staff member name is required.')
            : $this->inputNormalizer->normalizeRequiredText($actor->name, 150, 'staff_member_name', 'Phlebotomist or staff member name is required.');

        $locationName = $this->inputNormalizer->normalizeOptionalText($attributes['collection_location_name'] ?? null, 191, 'collection_location_name');

        if ($centerId === null && $campId === null && $locationName === null) {
            throw ValidationException::withMessages([
                'collection_location_name' => 'A collection center, camp, or location name is required.',
            ]);
        }

        return [
            'blood_component_id' => $componentId,
            'blood_component_code' => $componentCode !== '' ? $componentCode : null,
            'blood_group' => $bloodGroup,
            'quantity_ml' => $quantityMl,
            'collection_status' => $collectionStatus,
            'donation_date' => $donationDate,
            'collected_at' => $collectedAt,
            'center_id' => $centerId,
            'camp_id' => $campId,
            'appointment_id' => $appointmentId,
            'collection_location_name' => $locationName,
            'staff_member_name' => $staffMemberName,
            'collection_notes' => $this->inputNormalizer->normalizeOptionalText($attributes['collection_notes'] ?? null, null, 'collection_notes'),
            'storage_location' => $this->inputNormalizer->normalizeOptionalText($attributes['storage_location'] ?? null, 120, 'storage_location'),
        ];
    }

    /**
     * Resolve and verify that the requested blood group matches the donor's verified blood group.
     *
     * @throws ValidationException
     */
    private function resolveAndValidateBloodGroup(Donor $lockedDonor, ?string $requestedBloodGroup): string
    {
        if (! is_string($lockedDonor->blood_group) || ! in_array($lockedDonor->blood_group, Donation::BLOOD_GROUPS, true)) {
            throw ValidationException::withMessages([
                'blood_group' => 'Donor must have a valid recorded blood group prior to donation.',
            ]);
        }

        if ($requestedBloodGroup !== null && $requestedBloodGroup !== $lockedDonor->blood_group) {
            throw ValidationException::withMessages([
                'blood_group' => 'Donation blood group must match the donor profile blood group.',
            ]);
        }

        return $lockedDonor->blood_group;
    }

    /**
     * Resolve an active blood component by ID or code.
     *
     * @throws ValidationException
     */
    private function resolveActiveComponent(?int $componentId, ?string $componentCode): BloodComponent
    {
        $query = BloodComponent::query();

        if ($componentId !== null) {
            $component = $query->whereKey($componentId)->first();
        } else {
            $component = $query->where('code', $componentCode)->first();
        }

        if (! $component instanceof BloodComponent || ! $component->is_active) {
            throw ValidationException::withMessages([
                'blood_component_id' => 'Selected blood component does not exist or is inactive.',
            ]);
        }

        return $component;
    }

    /**
     * Resolve donation center/camp foreign keys and display location snapshot.
     *
     * @return array{center_id: ?int, camp_id: ?int, collection_location_name: string}
     *
     * @throws ValidationException
     */
    private function resolveCollectionLocation(?int $centerId, ?int $campId, ?string $locationName): array
    {
        if ($centerId !== null) {
            $center = DonationCenter::query()->whereKey($centerId)->first();

            if (! $center instanceof DonationCenter) {
                throw ValidationException::withMessages([
                    'center_id' => 'Selected donation center does not exist.',
                ]);
            }

            return [
                'center_id' => $center->id,
                'camp_id' => null,
                'collection_location_name' => $locationName ?? $center->name,
            ];
        }

        if ($campId !== null) {
            $camp = DonationCamp::query()->whereKey($campId)->first();

            if (! $camp instanceof DonationCamp) {
                throw ValidationException::withMessages([
                    'camp_id' => 'Selected donation camp does not exist.',
                ]);
            }

            return [
                'center_id' => null,
                'camp_id' => $camp->id,
                'collection_location_name' => $locationName ?? $camp->venue_name,
            ];
        }

        return [
            'center_id' => null,
            'camp_id' => null,
            'collection_location_name' => (string) $locationName,
        ];
    }

    /**
     * Lock and validate an optional donor appointment before linking it to a donation.
     *
     * @throws ValidationException
     */
    private function resolveAndLockAppointment(?int $appointmentId, Donor $lockedDonor): ?DonorAppointment
    {
        if ($appointmentId === null) {
            return null;
        }

        $appointment = DonorAppointment::query()
            ->whereKey($appointmentId)
            ->lockForUpdate()
            ->first();

        if (! $appointment instanceof DonorAppointment || (int) $appointment->donor_id !== (int) $lockedDonor->id) {
            throw ValidationException::withMessages([
                'appointment_id' => 'Appointment does not belong to the selected donor.',
            ]);
        }

        if ($appointment->status === 'Cancelled') {
            throw ValidationException::withMessages([
                'appointment_id' => 'Cancelled appointments cannot fulfill a blood donation.',
            ]);
        }

        if (Donation::query()->where('appointment_id', $appointment->id)->exists()) {
            throw ValidationException::withMessages([
                'appointment_id' => 'This appointment has already been linked to another donation.',
            ]);
        }

        return $appointment;
    }

    /**
     * Validate integer blood collection volume within documented database bounds (`150`–`800` ml).
     *
     * @throws ValidationException
     */
    private function parseQuantityMl(mixed $value): int
    {
        if ($value === null || $value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([
                'quantity_ml' => 'Collection quantity in ml must be an integer between 150 and 800.',
            ]);
        }

        $intValue = (int) $value;

        if ($intValue < 150 || $intValue > 800) {
            throw ValidationException::withMessages([
                'quantity_ml' => 'Collection quantity in ml must be between 150 and 800.',
            ]);
        }

        return $intValue;
    }
}
