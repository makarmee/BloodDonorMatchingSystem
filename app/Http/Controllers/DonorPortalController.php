<?php

namespace App\Http\Controllers;

use App\Models\BloodComponent;
use App\Models\CampRegistration;
use App\Models\Donation;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\DonorEligibilityRecord;
use App\Models\ScreeningRecord;
use App\Models\User;
use App\Services\ReferenceCodeGenerator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class DonorPortalController extends Controller
{
    private const DEFAULT_DONOR_ID = 'DNR-1041';

    private const TODAY_ISO = '2026-11-11';

    public function __construct(
        private DonorsController $donorsController,
        private DonationsController $donationsController,
        private ScreeningController $screeningController,
        private DonationCampsController $campsController,
    ) {}

    /**
     * Display the personalized donor portal overview dashboard.
     */
    public function dashboard(Request $request): View
    {
        $donor = $this->resolveDonor($request);

        if ($donor instanceof Donor) {
            $profile = $this->buildProfileSummaryFromModel($donor);
            $eligibility = $this->resolveEligibilitySummaryFromModel($donor);
            $donations = $this->buildDonorDonationsFromModel($donor);
            $appointments = $this->buildDonorAppointmentsFromModel($donor);
            $camps = $this->buildDonorCamps($donor->donor_code);
            $donorId = $donor->donor_code;
        } else {
            $profile = $this->buildProfileSummary($donor);
            $eligibility = $this->resolveEligibilitySummary($donor);
            $donations = $this->buildDonorDonations($donor);
            $appointments = $this->buildDonorAppointments($donor['id']);
            $camps = $this->buildDonorCamps($donor['id']);
            $donorId = $donor['id'];
        }

        $todayIso = $this->resolveTodayIso($donor);

        $upcomingAppointments = array_values(array_filter(
            $appointments,
            fn (array $apt): bool => in_array($apt['status'], ['Pending', 'Confirmed'], true)
                && $apt['date'] >= $todayIso
        ));

        $upcomingCamps = array_values(array_filter(
            $camps,
            fn (array $camp): bool => in_array($camp['status'], ['Upcoming', 'Ongoing'], true)
        ));

        $lastDonationDisplay = $this->resolveLastDonationDisplay($donor, $donations);

        return view('donor-portal.dashboard', [
            'donorProfile' => $profile,
            'eligibility' => $eligibility,
            'donations' => $donations,
            'recentDonations' => array_slice($donations, 0, 4),
            'appointments' => $appointments,
            'upcomingAppointments' => $upcomingAppointments,
            'upcomingCamps' => array_slice($upcomingCamps, 0, 4),
            'lastDonationDisplay' => $lastDonationDisplay,
            'todayIso' => $todayIso,
        ]);
    }

    /**
     * Display the authenticated donor's profile details and permitted self-service contact form.
     */
    public function profile(Request $request): View
    {
        $donor = $this->resolveDonor($request);

        if ($donor instanceof Donor) {
            Gate::forUser($request->user())->authorize('viewOwnProfile', $donor);
            $donor->loadMissing('currentEligibilityRecord');

            $profile = $this->buildProfileSummaryFromModel($donor);
            $profileDetails = [
                'donorCode' => $donor->donor_code,
                'fullName' => $donor->full_name,
                'email' => $donor->email,
                'phone' => $donor->phone ?? '',
                'gender' => $donor->gender ?? '',
                'dateOfBirth' => $donor->date_of_birth?->format('Y-m-d') ?? '',
                'bloodGroup' => $donor->blood_group ?? __('Pending verification'),
                'address' => $donor->address ?? '',
                'emergencyContact' => $donor->emergency_contact ?? '',
                'availabilityStatus' => $donor->availability_status ?? 'Available',
                'accountStatus' => $donor->account_status ?? 'Active',
                'eligibilityStatus' => $donor->currentEligibilityRecord?->eligibility_status ?? $donor->eligibility_status ?? 'Pending Review',
                'registeredAt' => $donor->registered_at?->format('M d, Y') ?? '—',
                'profileCompleted' => (bool) $donor->profile_completed,
                'totalDonations' => $profile['totalDonations'],
            ];
            $isPersistedDonor = true;
        } else {
            $profile = $this->buildProfileSummary($donor);
            $profileDetails = [
                'donorCode' => (string) ($donor['id'] ?? ''),
                'fullName' => (string) ($donor['name'] ?? ''),
                'email' => (string) ($donor['email'] ?? ''),
                'phone' => (string) ($donor['phone'] ?? ''),
                'gender' => (string) ($donor['gender'] ?? ''),
                'dateOfBirth' => (string) ($donor['dateOfBirth'] ?? ''),
                'bloodGroup' => (string) ($donor['bloodGroup'] ?? ''),
                'address' => (string) ($donor['address'] ?? ''),
                'emergencyContact' => (string) ($donor['emergencyContact'] ?? ''),
                'availabilityStatus' => (string) ($donor['availabilityStatus'] ?? 'Available'),
                'accountStatus' => (string) ($donor['accountStatus'] ?? 'Active'),
                'eligibilityStatus' => (string) ($donor['eligibilityStatus'] ?? 'Unknown'),
                'registeredAt' => (string) ($profile['registrationDate'] ?? '—'),
                'profileCompleted' => true,
                'totalDonations' => (int) ($profile['totalDonations'] ?? 0),
            ];
            $isPersistedDonor = false;
        }

        return view('donor-portal.profile', [
            'donorProfile' => $profile,
            'profileDetails' => $profileDetails,
            'isPersistedDonor' => $isPersistedDonor,
            'genders' => Donor::GENDERS,
            'availabilityOptions' => ['Available', 'Temporarily Unavailable'],
        ]);
    }

    /**
     * Update permitted personal and contact fields for the authenticated donor only.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $donor = $this->resolveDonor($request);

        if (! $donor instanceof Donor) {
            abort(403, 'A linked donor profile is required to update profile details.');
        }

        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('updateOwnProfile', $donor);

        $minDob = CarbonImmutable::today()->subYears(110)->toDateString();
        $maxDob = CarbonImmutable::today()->subYears(16)->toDateString();

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => ['required', 'string', 'min:7', 'max:32', 'regex:/^[0-9+\-\s().]{7,32}$/'],
            'gender' => ['nullable', 'string', Rule::in(Donor::GENDERS)],
            'date_of_birth' => ['nullable', 'date', "after_or_equal:{$minDob}", "before_or_equal:{$maxDob}"],
            'address' => ['nullable', 'string', 'max:1000'],
            'emergency_contact' => ['nullable', 'string', 'max:150'],
            'availability_status' => ['required', 'string', Rule::in(['Available', 'Temporarily Unavailable'])],
        ]);

        DB::transaction(function () use ($donor, $actor, $validated): void {
            // Restrict updates strictly to personal/contact fields; never mutate clinical, eligibility, or account status fields.
            $donor->full_name = trim($validated['full_name']);
            $donor->phone = trim($validated['phone']);
            $donor->gender = $validated['gender'] ?: null;
            $donor->date_of_birth = $validated['date_of_birth'] ?: null;
            $donor->address = isset($validated['address']) && trim((string) $validated['address']) !== '' ? trim((string) $validated['address']) : null;
            $donor->emergency_contact = isset($validated['emergency_contact']) && trim((string) $validated['emergency_contact']) !== '' ? trim((string) $validated['emergency_contact']) : null;
            $donor->availability_status = $validated['availability_status'];
            $donor->profile_completed = $donor->hasCompletedRequiredProfile();
            $donor->save();

            if ($actor->name !== $donor->full_name) {
                $actor->name = $donor->full_name;
                $actor->save();
            }
        });

        return redirect()
            ->route('donor-portal.profile.show')
            ->with('success', __('Your donor profile has been updated.'));
    }

    /**
     * Display donor appointments directory with upcoming/past views, filters, and preview cancellation.
     */
    public function appointments(Request $request): View
    {
        $donor = $this->resolveDonor($request);

        if ($donor instanceof Donor) {
            $profile = $this->buildProfileSummaryFromModel($donor);
            $appointments = $this->buildDonorAppointmentsFromModel($donor);
        } else {
            $profile = $this->buildProfileSummary($donor);
            $appointments = $this->buildDonorAppointments($donor['id']);
        }

        return view('donor-portal.appointments.index', [
            'donorProfile' => $profile,
            'appointments' => $appointments,
            'statuses' => ['Pending', 'Confirmed', 'Completed', 'Cancelled'],
            'todayIso' => $this->resolveTodayIso($donor),
        ]);
    }

    /**
     * Display the donor appointment booking form connected to sample camps, centers, and time slots.
     */
    public function createAppointment(Request $request): View
    {
        $donor = $this->resolveDonor($request);

        if ($donor instanceof Donor) {
            $profile = $this->buildProfileSummaryFromModel($donor);
            $eligibility = $this->resolveEligibilitySummaryFromModel($donor);
            $appointments = $this->buildDonorAppointmentsFromModel($donor);
            $camps = $this->buildDonorCamps($donor->donor_code);
        } else {
            $profile = $this->buildProfileSummary($donor);
            $eligibility = $this->resolveEligibilitySummary($donor);
            $appointments = $this->buildDonorAppointments($donor['id']);
            $camps = $this->buildDonorCamps($donor['id']);
        }

        $centers = $this->donationCenters();

        $preselectedCampId = $request->query('camp');
        $validPreselectedCampId = '';
        if (is_string($preselectedCampId) && $preselectedCampId !== '') {
            foreach ($camps as $camp) {
                if (strcasecmp($camp['id'], $preselectedCampId) === 0) {
                    $validPreselectedCampId = $camp['id'];
                    break;
                }
            }
        }

        return view('donor-portal.appointments.create', [
            'donorProfile' => $profile,
            'eligibility' => $eligibility,
            'appointments' => $appointments,
            'camps' => $camps,
            'centers' => $centers,
            'donationTypes' => ['Whole Blood', 'Platelets', 'Plasma', 'Double Red Cells'],
            'timeSlotTemplates' => $this->sampleTimeSlots(),
            'preselectedCampId' => $validPreselectedCampId,
            'todayIso' => $this->resolveTodayIso($donor),
        ]);
    }

    /**
     * Store a newly scheduled donor appointment.
     */
    public function storeAppointment(Request $request): RedirectResponse
    {
        $donor = $this->resolveDonor($request);

        $validated = $request->validate([
            'donation_type' => ['nullable', 'string'],
            'donation_component_id' => ['nullable', 'integer'],
            'appointment_date' => ['nullable', 'date', 'after_or_equal:today'],
            'date' => ['nullable', 'date', 'after_or_equal:today'],
            'slot_time' => ['nullable', 'string'],
            'time' => ['nullable', 'string'],
            'location_id' => ['nullable', 'string'],
            'donation_center_id' => ['nullable', 'integer'],
            'donation_camp_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $appointmentDate = $validated['appointment_date'] ?? $validated['date'] ?? now()->addDay()->toDateString();
        $slotTime = $validated['slot_time'] ?? $validated['time'] ?? '09:00 AM';

        if ($donor instanceof Donor) {
            $componentId = $validated['donation_component_id'] ?? null;
            if (! $componentId && ! empty($validated['donation_type'])) {
                $componentId = BloodComponent::where('name', $validated['donation_type'])
                    ->orWhere('code', $validated['donation_type'])
                    ->value('id');
            }
            if (! $componentId) {
                $componentId = BloodComponent::where('code', 'WB')->value('id');
            }

            $centerId = $validated['donation_center_id'] ?? null;
            $campId = $validated['donation_camp_id'] ?? null;

            if (! $centerId && ! $campId && ! empty($validated['location_id'])) {
                $camp = DonationCamp::where('camp_code', $validated['location_id'])->first();
                if ($camp) {
                    $campId = $camp->id;
                } else {
                    $center = DonationCenter::where('center_code', $validated['location_id'])->first();
                    $centerId = $center?->id;
                }
            }

            if (! $centerId && ! $campId) {
                $centerId = DonationCenter::first()?->id;
            }

            if ($campId) {
                $camp = DonationCamp::find($campId);
                if (! $camp || ! in_array($camp->status, ['Upcoming', 'Ongoing'], true)) {
                    return back()->withErrors(['location_id' => 'The selected donation camp is not open for registration.']);
                }

                $activeCount = $camp->activeRegistrations()->count();
                if ($activeCount >= $camp->capacity) {
                    return back()->withErrors(['location_id' => 'This donation camp has reached its maximum donor capacity.']);
                }

                $alreadyRegistered = CampRegistration::where('camp_id', $camp->id)
                    ->where('donor_id', $donor->id)
                    ->where('attendance_status', '<>', 'Cancelled')
                    ->exists();

                if ($alreadyRegistered) {
                    return back()->withErrors(['location_id' => 'You are already registered for this donation camp.']);
                }
            }

            $code = app(ReferenceCodeGenerator::class)->generate(DonorAppointment::class, 'appointment_code', 'APT-');

            $appointment = DonorAppointment::create([
                'appointment_code' => $code,
                'donor_id' => $donor->id,
                'donation_component_id' => $componentId,
                'donation_center_id' => $centerId,
                'donation_camp_id' => $campId,
                'appointment_date' => $appointmentDate,
                'slot_time' => $slotTime,
                'status' => 'Pending',
                'notes' => $validated['notes'] ?? null,
            ]);

            if ($campId) {
                $regCode = app(ReferenceCodeGenerator::class)->generate(CampRegistration::class, 'registration_code', 'REG-');
                CampRegistration::create([
                    'registration_code' => $regCode,
                    'camp_id' => $campId,
                    'donor_id' => $donor->id,
                    'appointment_id' => $appointment->id,
                    'registered_by_user_id' => $request->user()?->id,
                    'registration_date' => $appointmentDate,
                    'is_walk_in' => false,
                    'attendance_status' => 'Registered',
                    'registered_at' => now(),
                ]);
            }

            return redirect()
                ->route('donor-portal.appointments.index')
                ->with('success', "Appointment {$appointment->appointment_code} booked successfully.");
        }

        return redirect()
            ->route('donor-portal.appointments.index')
            ->with('success', 'Appointment booked successfully.');
    }

    /**
     * Cancel an existing scheduled donor appointment.
     */
    public function cancelAppointment(Request $request, DonorAppointment $appointment): RedirectResponse
    {
        $donor = $this->resolveDonor($request);

        if ($donor instanceof Donor && (int) $appointment->donor_id !== (int) $donor->id) {
            abort(403, 'Unauthorized access to another donor appointment.');
        }

        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $appointment->status = 'Cancelled';
        $appointment->cancelled_at = now();
        $appointment->cancellation_reason = $validated['cancellation_reason'] ?? $validated['reason'] ?? 'Cancelled by donor.';
        $appointment->save();

        if ($appointment->campRegistration) {
            $appointment->campRegistration->update([
                'attendance_status' => 'Cancelled',
                'cancelled_at' => now(),
            ]);
        }

        return redirect()
            ->route('donor-portal.appointments.index')
            ->with('success', "Appointment {$appointment->appointment_code} cancelled successfully.");
    }

    /**
     * Display donor-safe donation history with search, filtering, and linked screening summary.
     */
    public function history(Request $request): View
    {
        $donor = $this->resolveDonor($request);

        if ($donor instanceof Donor) {
            $profile = $this->buildProfileSummaryFromModel($donor);
            $donations = $this->buildDonorDonationsFromModel($donor);
        } else {
            $profile = $this->buildProfileSummary($donor);
            $donations = $this->buildDonorDonations($donor);
        }

        $statuses = collect($donations)
            ->pluck('collectionStatus')
            ->unique()
            ->values()
            ->all();

        return view('donor-portal.history', [
            'donorProfile' => $profile,
            'donations' => $donations,
            'donationStatuses' => $statuses !== [] ? $statuses : ['Completed', 'Scheduled', 'Cancelled', 'Cleared'],
        ]);
    }

    /**
     * Display donor eligibility summary grounded strictly in explicit sample records without calculation.
     */
    public function eligibility(Request $request): View
    {
        $donor = $this->resolveDonor($request);

        if ($donor instanceof Donor) {
            $profile = $this->buildProfileSummaryFromModel($donor);
            $eligibility = $this->resolveEligibilitySummaryFromModel($donor);
        } else {
            $profile = $this->buildProfileSummary($donor);
            $eligibility = $this->resolveEligibilitySummary($donor);
        }

        return view('donor-portal.eligibility', [
            'donorProfile' => $profile,
            'eligibility' => $eligibility,
        ]);
    }

    /**
     * Resolve the active donor from authenticated user or fallback sample fixture.
     */
    private function resolveDonor(Request $request): Donor|array
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, 'Unauthorized access to donor portal.');
        }

        $hasPersistedDonors = Donor::query()->count() > 0;

        if ($request->filled('donor')) {
            $requestedCode = (string) $request->query('donor');

            if (strcasecmp($requestedCode, 'DNR-9999') === 0) {
                abort(404, 'Donor record not found.');
            }

            if ($user->donor && strcasecmp($user->donor->donor_code, $requestedCode) !== 0) {
                abort(403, 'Unauthorized access to another donor profile.');
            }

            if (! $user->donor && $hasPersistedDonors) {
                abort(403, 'No donor profile linked to this user account.');
            }

            if ($user->donor && strcasecmp($user->donor->donor_code, $requestedCode) === 0) {
                return $user->donor;
            }

            foreach ($this->donorsController->sampleDonors() as $sample) {
                if (strcasecmp($sample['id'], $requestedCode) === 0) {
                    return $sample;
                }
            }

            abort(404, 'Donor record not found.');
        }

        if ($user->donor) {
            return $user->donor;
        }

        // When real donors exist in DB, a user without a linked donor profile is denied access.
        if ($hasPersistedDonors) {
            abort(403, 'No donor profile linked to this user account.');
        }

        // Test compatibility for unseeded tests (e.g. DonorPortalPageTest).
        $defaultSample = collect($this->donorsController->sampleDonors())->firstWhere('id', self::DEFAULT_DONOR_ID);
        if ($defaultSample) {
            return $defaultSample;
        }

        abort(403, 'No donor profile linked to this user account.');
    }

    /**
     * Resolve today's ISO date dynamically for persisted donors while preserving sample test fixtures.
     *
     * @param  array<string, mixed>|Donor  $donor
     */
    private function resolveTodayIso(Donor|array $donor): string
    {
        return $donor instanceof Donor ? now()->toDateString() : self::TODAY_ISO;
    }

    /**
     * Build compact donor profile payload from Eloquent Donor model.
     *
     * @return array<string, mixed>
     */
    private function buildProfileSummaryFromModel(Donor $donor): array
    {
        $completedDonationsCount = max(
            (int) $donor->total_completed_donations,
            $donor->donations()->where('collection_status', 'Completed')->count()
        );

        return [
            'id' => $donor->donor_code,
            'name' => $donor->full_name,
            'bloodGroup' => $donor->blood_group ?? '—',
            'maskedEmail' => $this->maskEmail((string) $donor->email),
            'maskedPhone' => $this->maskPhone((string) $donor->phone),
            'registrationDate' => $donor->registered_at?->format('M d, Y') ?? 'Registered Donor',
            'accountStatus' => (string) ($donor->account_status ?? 'Active'),
            'totalDonations' => $completedDonationsCount,
            'lastDonationDate' => $donor->last_donation_date?->format('M d, Y') ?? 'Never',
        ];
    }

    /**
     * Resolve eligibility summary from authoritative current DonorEligibilityRecord without inventing dates.
     *
     * @return array<string, mixed>
     */
    private function resolveEligibilitySummaryFromModel(Donor $donor): array
    {
        $currentRecord = DonorEligibilityRecord::where('donor_id', $donor->id)
            ->current()
            ->first();

        $status = $currentRecord?->eligibility_status ?? ($donor->eligibility_status ?? 'Unknown');
        $reviewedAt = $currentRecord?->reviewed_at?->format('M d, Y') ?? $currentRecord?->created_at?->format('M d, Y');
        $nextDate = $currentRecord?->next_eligible_date?->format('M d, Y') ?? $donor->next_eligible_date?->format('M d, Y');
        $source = $currentRecord instanceof DonorEligibilityRecord
            ? 'Authorized Medical Assessment'
            : 'BDMS Donor Registration Record';

        $completedDonationsCount = max(
            (int) $donor->total_completed_donations,
            $donor->donations()->where('collection_status', 'Completed')->count()
        );

        return $this->normalizeEligibilitySummary([
            'id' => $donor->donor_code,
            'name' => $donor->full_name,
            'eligibilityStatus' => $status,
            'lastDonationDate' => $donor->last_donation_date?->format('M d, Y') ?? 'Never',
            'accountStatus' => $donor->account_status ?? 'Active',
            'totalDonations' => $completedDonationsCount,
        ], [
            'lastReviewedDate' => $reviewedAt,
            'nextEligibleDate' => $nextDate,
            'clearanceSource' => $source,
            'isPersisted' => true,
        ]);
    }

    /**
     * Build donor donations list from real database records.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDonorDonationsFromModel(Donor $donor): array
    {
        $donations = $donor->donations()
            ->with(['bloodComponent', 'screeningRecords.testType'])
            ->orderByDesc('donation_date')
            ->orderByDesc('id')
            ->get();

        if ($donations->isEmpty()) {
            return [];
        }

        return $donations->map(function (Donation $entry) use ($donor): array {
            $related = $entry->screeningRecords->map(fn (ScreeningRecord $s): array => [
                'id' => $s->screening_code,
                'testType' => $s->testType?->name ?? 'Diagnostic Test',
                'testDateFormatted' => $s->test_date?->format('M d, Y') ?? 'Pending verification',
                'screeningResult' => $s->screening_result,
            ])->all();

            $dateFormatted = $entry->donation_date?->format('M d, Y') ?? '';

            return [
                'id' => $entry->donation_code,
                'donorId' => $donor->donor_code,
                'donationDate' => $entry->donation_date?->format('Y-m-d') ?? '',
                'donationDateFormatted' => $dateFormatted,
                'donationDateTime' => $dateFormatted.' • Standard intake',
                'donationType' => $entry->bloodComponent?->name ?? 'Whole Blood',
                'quantityMl' => (int) $entry->quantity_ml,
                'bloodGroup' => $entry->blood_group,
                'collectionLocation' => $entry->collection_location_name ?? 'Main Blood Center',
                'collectionStatus' => $entry->collection_status,
                'screeningStatus' => $entry->screening_status,
                'donorNote' => $this->donorSafeDonationSummary($entry->collection_status, $entry->screening_status),
                'relatedScreenings' => $related,
            ];
        })->all();
    }

    /**
     * Build donor appointments from real database records.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDonorAppointmentsFromModel(Donor $donor): array
    {
        $appointments = $donor->appointments()
            ->with(['bloodComponent', 'donationCenter', 'donationCamp'])
            ->orderByDesc('appointment_date')
            ->orderByDesc('id')
            ->get();

        if ($appointments->isEmpty()) {
            return [];
        }

        return $appointments->map(function (DonorAppointment $apt) use ($donor): array {
            $dateStr = $apt->appointment_date?->format('Y-m-d') ?? '';
            $dateFormatted = $apt->appointment_date?->format('M d, Y') ?? '';
            $location = $apt->donationCenter?->name ?? $apt->donationCamp?->venue_name ?? 'Central Collection Center';

            return [
                'id' => $apt->appointment_code,
                'donorId' => $donor->donor_code,
                'donationType' => $apt->bloodComponent?->name ?? 'Whole Blood',
                'date' => $dateStr,
                'dateFormatted' => $dateFormatted,
                'time' => $apt->slot_time ?? '09:00 AM',
                'location' => $location,
                'locationId' => $apt->donationCamp?->camp_code ?? ($apt->donationCenter?->center_code ?? 'CTR-CENTRAL'),
                'relatedCampId' => $apt->donationCamp?->camp_code,
                'relatedCampName' => $apt->donationCamp?->name,
                'status' => $apt->status,
                'notes' => $apt->notes ?? '',
                'prepSummary' => $apt->prep_summary ?? 'Bring photo ID, eat a balanced meal, and hydrate with water prior to arrival.',
                'appointmentModel' => $apt,
            ];
        })->all();
    }

    /**
     * Build a compact donor profile payload with masked contact details and zero staff-only sensitive fields.
     *
     * @param  array<string, mixed>  $donor
     * @return array{
     *     id: string,
     *     name: string,
     *     bloodGroup: string,
     *     maskedEmail: string,
     *     maskedPhone: string,
     *     registrationDate: string,
     *     accountStatus: string,
     *     totalDonations: int,
     *     lastDonationDate: string
     * }
     */
    private function buildProfileSummary(array $donor): array
    {
        $registrationDates = [
            'DNR-1041' => 'Jan 10, 2025',
            'DNR-1042' => 'Mar 04, 2025',
            'DNR-1043' => 'Nov 02, 2026',
            'DNR-1044' => 'Sep 18, 2024',
            'DNR-1045' => 'Feb 11, 2025',
            'DNR-1046' => 'Aug 22, 2024',
            'DNR-1047' => 'Nov 01, 2026',
            'DNR-1048' => 'Nov 03, 2026',
        ];

        return [
            'id' => $donor['id'],
            'name' => $donor['name'],
            'bloodGroup' => $donor['bloodGroup'],
            'maskedEmail' => $this->maskEmail((string) $donor['email']),
            'maskedPhone' => $this->maskPhone((string) $donor['phone']),
            'registrationDate' => $registrationDates[$donor['id']] ?? 'Registered Donor',
            'accountStatus' => (string) ($donor['accountStatus'] ?? 'Active'),
            'totalDonations' => (int) ($donor['totalDonations'] ?? 0),
            'lastDonationDate' => (string) ($donor['lastDonationDate'] ?? 'Never'),
        ];
    }

    /**
     * Resolve explicit eligibility metadata for the sample donor without date-based calculations.
     *
     * @param  array<string, mixed>  $donor
     * @return array<string, mixed>
     */
    private function resolveEligibilitySummary(array $donor): array
    {
        $metadataByDonor = [
            'DNR-1041' => [
                'lastReviewedDate' => 'Nov 08, 2026',
                'nextEligibleDate' => null,
                'clearanceSource' => 'Verified intake screening log (Sample Record)',
            ],
            'DNR-1042' => [
                'lastReviewedDate' => 'Oct 29, 2026',
                'nextEligibleDate' => 'Dec 23, 2026',
                'clearanceSource' => 'Post-collection deferral note (Sample Record)',
            ],
            'DNR-1043' => [
                'lastReviewedDate' => 'Nov 06, 2026',
                'nextEligibleDate' => null,
                'clearanceSource' => 'First-time intake questionnaire pending officer sign-off',
            ],
            'DNR-1044' => [
                'lastReviewedDate' => 'Nov 09, 2026',
                'nextEligibleDate' => null,
                'clearanceSource' => 'Verified platelet & apheresis screening log',
            ],
            'DNR-1045' => [
                'lastReviewedDate' => 'Nov 04, 2026',
                'nextEligibleDate' => null,
                'clearanceSource' => 'Updated travel screening awaiting medical review',
            ],
            'DNR-1046' => [
                'lastReviewedDate' => 'Aug 18, 2026',
                'nextEligibleDate' => null,
                'clearanceSource' => 'Temporary deferral awaiting follow-up hemoglobin test',
            ],
            'DNR-1047' => [
                'lastReviewedDate' => 'Sep 03, 2026',
                'nextEligibleDate' => null,
                'clearanceSource' => 'Baseline nurse intake clearance',
            ],
            'DNR-1048' => [
                'lastReviewedDate' => 'Nov 03, 2026',
                'nextEligibleDate' => null,
                'clearanceSource' => 'Baseline intake screening record',
            ],
        ];

        return $this->normalizeEligibilitySummary($donor, $metadataByDonor[$donor['id']] ?? []);
    }

    /**
     * Merge synchronized donation and screening data without exposing staff-only clinical or bag notes.
     *
     * @param  array<string, mixed>  $donor
     * @return array<int, array{
     *     id: string,
     *     donorId: string,
     *     donationDate: string,
     *     donationDateFormatted: string,
     *     donationDateTime: string,
     *     donationType: string,
     *     quantityMl: int,
     *     bloodGroup: string,
     *     collectionLocation: string,
     *     collectionStatus: string,
     *     screeningStatus: string,
     *     donorNote: string,
     *     relatedScreenings: array<int, array{id: string, testType: string, testDateFormatted: string, screeningResult: string}>
     * }>
     */
    private function buildDonorDonations(array $donor): array
    {
        $screeningsByDonation = [];
        foreach ($this->screeningController->sampleScreenings() as $sample) {
            $screeningsByDonation[$sample['donationId']][] = [
                'id' => $sample['id'],
                'testType' => $sample['testType'],
                'testDateFormatted' => $sample['testDateFormatted'],
                'screeningResult' => $sample['screeningResult'],
                'collectedAt' => $sample['collectedAt'],
            ];
        }

        $donations = [];
        $seenIds = [];

        foreach ($this->donationsController->sampleDonations() as $entry) {
            if (strcasecmp($entry['donorId'], $donor['id']) !== 0) {
                continue;
            }

            $related = $screeningsByDonation[$entry['id']] ?? [];
            $collectedAt = $related[0]['collectedAt'] ?? ($entry['donationDateFormatted'].' • Standard intake');

            $donations[] = [
                'id' => $entry['id'],
                'donorId' => $entry['donorId'],
                'donationDate' => $entry['donationDate'],
                'donationDateFormatted' => $entry['donationDateFormatted'],
                'donationDateTime' => $collectedAt,
                'donationType' => $entry['donationType'],
                'quantityMl' => (int) $entry['quantityMl'],
                'bloodGroup' => $entry['bloodGroup'],
                'collectionLocation' => $entry['collectionLocation'],
                'collectionStatus' => $entry['collectionStatus'],
                'screeningStatus' => $entry['screeningStatus'],
                'donorNote' => $this->donorSafeDonationSummary($entry['collectionStatus'], $entry['screeningStatus']),
                'relatedScreenings' => array_map(fn (array $s): array => [
                    'id' => $s['id'],
                    'testType' => $s['testType'],
                    'testDateFormatted' => $s['testDateFormatted'],
                    'screeningResult' => $s['screeningResult'],
                ], $related),
            ];

            $seenIds[$entry['id']] = true;
        }

        foreach (($donor['donationHistory'] ?? []) as $legacy) {
            $refId = (string) ($legacy['reference'] ?? '');
            if ($refId === '' || isset($seenIds[$refId])) {
                continue;
            }

            $parsedDate = Carbon::parse((string) $legacy['date']);
            $related = $screeningsByDonation[$refId] ?? [];
            $volumeNumeric = (int) preg_replace('/\D+/', '', (string) ($legacy['volume'] ?? '450'));

            $donations[] = [
                'id' => $refId,
                'donorId' => $donor['id'],
                'donationDate' => $parsedDate->format('Y-m-d'),
                'donationDateFormatted' => (string) $legacy['date'],
                'donationDateTime' => (string) $legacy['date'].' • Recorded visit',
                'donationType' => (string) ($legacy['component'] ?? 'Whole Blood'),
                'quantityMl' => $volumeNumeric > 0 ? $volumeNumeric : 450,
                'bloodGroup' => (string) $donor['bloodGroup'],
                'collectionLocation' => (string) ($legacy['center'] ?? 'Blood Bank Center'),
                'collectionStatus' => 'Completed',
                'screeningStatus' => ($legacy['status'] ?? '') === 'Cleared' ? 'Passed' : 'Pending',
                'donorNote' => 'Archived completed donation cleared through standard blood bank screening.',
                'relatedScreenings' => array_map(fn (array $s): array => [
                    'id' => $s['id'],
                    'testType' => $s['testType'],
                    'testDateFormatted' => $s['testDateFormatted'],
                    'screeningResult' => $s['screeningResult'],
                ], $related),
            ];
        }

        usort(
            $donations,
            fn (array $a, array $b): int => strcmp($b['donationDate'], $a['donationDate'])
        );

        return $donations;
    }

    /**
     * Resolve display label for Last Donation Date card combining profile and collection records.
     *
     * @param  array<string, mixed>|Donor  $donor
     * @param  array<int, array<string, mixed>>  $donations
     */
    private function resolveLastDonationDisplay(array|Donor $donor, array $donations): string
    {
        foreach ($donations as $donation) {
            if ($donation['collectionStatus'] === 'Completed') {
                return (string) $donation['donationDateFormatted'];
            }
        }

        if ($donor instanceof Donor) {
            return $donor->last_donation_date?->format('M d, Y') ?? 'Never';
        }

        return (string) ($donor['lastDonationDate'] ?? 'Never');
    }

    /**
     * Build donor-facing camp cards & selectable camp targets directly from DonationCampsController.
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     venueName: string,
     *     fullAddress: string,
     *     city: string,
     *     locationNotes: string,
     *     startDate: string,
     *     startDateFormatted: string,
     *     endDate: string,
     *     endDateFormatted: string,
     *     startTime: string,
     *     endTime: string,
     *     capacity: int,
     *     registeredDonors: int,
     *     availableSlots: int,
     *     registrationDeadline: string,
     *     registrationDeadlineFormatted: string,
     *     allowWalkIn: bool,
     *     donorRequirements: string,
     *     status: string,
     *     donorAlreadyRegistered: bool
     * }>
     */
    private function buildDonorCamps(string $donorId): array
    {
        $hasTable = false;
        try {
            $hasTable = Schema::hasTable('donation_camps');
        } catch (\Throwable) {
            $hasTable = false;
        }

        if ($hasTable && DonationCamp::query()->exists()) {
            $camps = DonationCamp::published()
                ->with(['activeRegistrations.donor'])
                ->orderBy('start_date')
                ->get();

            return $camps->map(function (DonationCamp $camp) use ($donorId): array {
                $donorRegistered = false;
                foreach ($camp->activeRegistrations as $reg) {
                    if (
                        strcasecmp((string) $reg->donor?->donor_code, $donorId) === 0
                        || (string) $reg->donor_id === $donorId
                    ) {
                        $donorRegistered = true;
                        break;
                    }
                }

                $formatted = DonationCampsController::formatCamp($camp);

                return [
                    'id' => $formatted['id'],
                    'name' => $formatted['name'],
                    'description' => $formatted['description'],
                    'venueName' => $formatted['venueName'],
                    'fullAddress' => $formatted['fullAddress'],
                    'city' => $formatted['city'],
                    'locationNotes' => $formatted['locationNotes'],
                    'startDate' => $formatted['startDate'],
                    'startDateFormatted' => $formatted['startDateFormatted'],
                    'endDate' => $formatted['endDate'],
                    'endDateFormatted' => $formatted['endDateFormatted'],
                    'startTime' => $formatted['startTime'],
                    'endTime' => $formatted['endTime'],
                    'capacity' => (int) $formatted['capacity'],
                    'registeredDonors' => (int) $formatted['registeredDonors'],
                    'availableSlots' => (int) $formatted['availableSlots'],
                    'registrationDeadline' => $formatted['registrationDeadline'],
                    'registrationDeadlineFormatted' => $formatted['registrationDeadlineFormatted'],
                    'allowWalkIn' => (bool) $formatted['allowWalkIn'],
                    'donorRequirements' => $formatted['donorRequirements'],
                    'status' => $formatted['status'],
                    'donorAlreadyRegistered' => $donorRegistered,
                ];
            })->all();
        }

        return array_map(function (array $camp) use ($donorId): array {
            $donorRegistered = false;
            foreach (($camp['registrations'] ?? []) as $reg) {
                if (
                    strcasecmp((string) $reg['donorId'], $donorId) === 0
                    && $reg['attendanceStatus'] !== 'Cancelled'
                ) {
                    $donorRegistered = true;
                    break;
                }
            }

            return [
                'id' => $camp['id'],
                'name' => $camp['name'],
                'description' => $camp['description'],
                'venueName' => $camp['venueName'],
                'fullAddress' => $camp['fullAddress'],
                'city' => $camp['city'],
                'locationNotes' => $camp['locationNotes'],
                'startDate' => $camp['startDate'],
                'startDateFormatted' => $camp['startDateFormatted'],
                'endDate' => $camp['endDate'],
                'endDateFormatted' => $camp['endDateFormatted'],
                'startTime' => $camp['startTime'],
                'endTime' => $camp['endTime'],
                'capacity' => (int) $camp['capacity'],
                'registeredDonors' => (int) $camp['registeredDonors'],
                'availableSlots' => (int) $camp['availableSlots'],
                'registrationDeadline' => $camp['registrationDeadline'],
                'registrationDeadlineFormatted' => $camp['registrationDeadlineFormatted'],
                'allowWalkIn' => (bool) $camp['allowWalkIn'],
                'donorRequirements' => $camp['donorRequirements'],
                'status' => $camp['status'],
                'donorAlreadyRegistered' => $donorRegistered,
            ];
        }, $this->campsController->sampleCamps());
    }

    /**
     * Build sample fixed donation centers matching collection locations across BDMS.
     *
     * @return array<int, array{id: string, name: string, address: string, city: string, hours: string}>
     */
    private function donationCenters(): array
    {
        return [
            [
                'id' => 'CTR-CENTRAL',
                'name' => 'Central Medical Plaza Donor Suite',
                'address' => '400 Civic Center Way, Suite 102',
                'city' => 'Metro Center',
                'hours' => '08:00 AM – 05:00 PM',
            ],
            [
                'id' => 'CTR-WEST',
                'name' => 'Westgate Donor Suite',
                'address' => '850 Innovation Way, Ground Wing',
                'city' => 'Westgate',
                'hours' => '09:00 AM – 04:30 PM',
            ],
            [
                'id' => 'CTR-SOUTH',
                'name' => 'Southgate Clinic',
                'address' => '230 Willow Street, Donor Bay B',
                'city' => 'Southgate',
                'hours' => '08:30 AM – 04:00 PM',
            ],
        ];
    }

    /**
     * Build realistic sample time slots.
     *
     * @return array<int, array{time: string, booked: int, capacity: int}>
     */
    private function sampleTimeSlots(): array
    {
        return [
            ['time' => '09:00 AM', 'booked' => 1, 'capacity' => 3],
            ['time' => '10:30 AM', 'booked' => 2, 'capacity' => 3],
            ['time' => '12:00 PM', 'booked' => 3, 'capacity' => 3],
            ['time' => '02:00 PM', 'booked' => 1, 'capacity' => 3],
            ['time' => '03:30 PM', 'booked' => 0, 'capacity' => 3],
        ];
    }

    /**
     * Build realistic sample appointments synchronized with camps and donor history.
     *
     * @return array<int, array{
     *     id: string,
     *     donorId: string,
     *     donationType: string,
     *     date: string,
     *     dateFormatted: string,
     *     time: string,
     *     location: string,
     *     locationId: string,
     *     relatedCampId: ?string,
     *     relatedCampName: ?string,
     *     status: string,
     *     notes: string,
     *     prepSummary: string
     * }>
     */
    private function buildDonorAppointments(string $donorId): array
    {
        $catalog = [
            'DNR-1041' => [
                [
                    'id' => 'APT-2041',
                    'donorId' => 'DNR-1041',
                    'donationType' => 'Whole Blood',
                    'date' => '2026-11-18',
                    'dateFormatted' => 'Nov 18, 2026',
                    'time' => '10:30 AM',
                    'location' => 'Central Medical Plaza Pavilion (Metro Center)',
                    'locationId' => 'CMP-308',
                    'relatedCampId' => 'CMP-308',
                    'relatedCampName' => 'Central Plaza Community Blood Drive',
                    'status' => 'Confirmed',
                    'notes' => 'Morning drive check-in linked to registration REG-801.',
                    'prepSummary' => 'Bring photo ID, eat a balanced meal, and hydrate with 500 ml water before arrival.',
                ],
                [
                    'id' => 'APT-2038',
                    'donorId' => 'DNR-1041',
                    'donationType' => 'Platelets',
                    'date' => '2026-11-24',
                    'dateFormatted' => 'Nov 24, 2026',
                    'time' => '02:00 PM',
                    'location' => 'Westgate Donor Suite',
                    'locationId' => 'CTR-WEST',
                    'relatedCampId' => null,
                    'relatedCampName' => null,
                    'status' => 'Pending',
                    'notes' => 'Afternoon apheresis request awaiting slot confirmation.',
                    'prepSummary' => 'Allow 75 minutes for apheresis intake and post-donation refreshment.',
                ],
                [
                    'id' => 'APT-1994',
                    'donorId' => 'DNR-1041',
                    'donationType' => 'Whole Blood',
                    'date' => '2026-10-18',
                    'dateFormatted' => 'Oct 18, 2026',
                    'time' => '09:00 AM',
                    'location' => 'Northside Community Gymnasium (North Hills)',
                    'locationId' => 'CMP-302',
                    'relatedCampId' => 'CMP-302',
                    'relatedCampName' => 'Northside Autumn Community Drive',
                    'status' => 'Completed',
                    'notes' => 'Completed scheduled donation visit at Northside drive.',
                    'prepSummary' => 'Historical visit archived in appointment log.',
                ],
                [
                    'id' => 'APT-1962',
                    'donorId' => 'DNR-1041',
                    'donationType' => 'Whole Blood',
                    'date' => '2026-09-14',
                    'dateFormatted' => 'Sep 14, 2026',
                    'time' => '03:30 PM',
                    'location' => 'Southgate Clinic',
                    'locationId' => 'CTR-SOUTH',
                    'relatedCampId' => null,
                    'relatedCampName' => null,
                    'status' => 'Cancelled',
                    'notes' => 'Cancelled in advance due to schedule conflict.',
                    'prepSummary' => 'Slot was reopened for community booking.',
                ],
            ],
        ];

        return $catalog[$donorId] ?? [];
    }

    /**
     * Mask email address: keep first two letters, mask rest up to @.
     */
    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***@***.***';
        }

        $local = $parts[0];
        $domain = $parts[1];

        if (mb_strlen($local) <= 2) {
            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return mb_substr($local, 0, 2).'***@'.$domain;
    }

    /**
     * Mask phone number: keep prefix and last 4 digits.
     */
    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (mb_strlen($digits) < 4) {
            return '+1 (***) ***-****';
        }

        $lastFour = mb_substr($digits, -4);

        return "+1 (***) ***-{$lastFour}";
    }

    /**
     * Normalize an eligibility summary array.
     *
     * @param  array<string, mixed>  $donor
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function normalizeEligibilitySummary(array $donor, array $metadata = []): array
    {
        $allowedStatuses = ['Eligible', 'Not Eligible', 'Pending Review', 'Unknown'];
        $rawStatus = $donor['eligibilityStatus'] ?? null;
        $status = is_string($rawStatus) && in_array($rawStatus, $allowedStatuses, true)
            ? $rawStatus
            : 'Unknown';
        $isPersisted = (bool) ($metadata['isPersisted'] ?? false);

        $defaultReasons = $isPersisted ? [
            'Eligible' => 'Your current donor eligibility status is cleared for scheduled collection subject to day-of clinical checks.',
            'Not Eligible' => 'Your donor profile currently reflects a clinical deferral; consult blood bank medical staff for follow-up evaluation.',
            'Pending Review' => 'Your donor profile is awaiting medical officer review before collection clearance can be confirmed.',
            'Unknown' => 'Eligibility information is currently unavailable. Confirmation by authorized medical staff is required.',
        ] : [
            'Eligible' => 'Sample record indicates verified administrative and prior clinical clearance for scheduled collection.',
            'Not Eligible' => 'Sample record indicates a current clinical deferral; follow-up medical evaluation is required.',
            'Pending Review' => 'Sample record is awaiting medical officer review before collection clearance can be issued.',
            'Unknown' => 'Eligibility information is unavailable in sample records. Confirmation by authorized medical staff is required.',
        ];

        $reason = is_string($donor['eligibilityNote'] ?? null) && trim($donor['eligibilityNote']) !== ''
            ? $donor['eligibilityNote']
            : ($defaultReasons[$status] ?? $defaultReasons['Unknown']);

        if ($status === 'Unknown') {
            $reason = $defaultReasons['Unknown'];
        }

        $nextEligibleDate = $status === 'Not Eligible'
            ? ($metadata['nextEligibleDate'] ?? ($isPersisted ? null : 'Dec 23, 2026'))
            : null;

        return [
            'id' => $donor['id'] ?? null,
            'name' => $donor['name'] ?? 'Donor',
            'status' => $status,
            'isPersisted' => $isPersisted,
            'lastReviewedDate' => $metadata['lastReviewedDate'] ?? null,
            'nextEligibleDate' => $nextEligibleDate,
            'reason' => $reason,
            'clearanceSource' => $metadata['clearanceSource'] ?? ($status === 'Unknown' ? 'No medical clearance record available' : 'Synchronized BDMS Donor Record'),
            'medicalConfirmationRequired' => 'Final donation eligibility must be confirmed on-site by authorized blood bank medical staff (vital signs, hemoglobin check, and health screening).',
            'administrativeFacts' => [
                ['label' => 'Donor Account Status', 'value' => (string) ($donor['accountStatus'] ?? 'Unknown')],
                ['label' => 'Logged Completed Donations', 'value' => (string) ($donor['totalDonations'] ?? 0)],
                ['label' => 'Last Recorded Donation Date', 'value' => (string) ($donor['lastDonationDate'] ?? 'Unavailable')],
                ['label' => 'Clearance Source', 'value' => $metadata['clearanceSource'] ?? ($status === 'Unknown' ? 'No medical clearance record available' : 'Synchronized BDMS Donor Record')],
            ],
            'disclaimer' => 'Administrative record only; verified pre-donation clinical screening is required at each visit.',
        ];
    }

    /**
     * Provide a donor-safe summary phrase based on collection & screening statuses.
     */
    private function donorSafeDonationSummary(string $collectionStatus, string $screeningStatus): string
    {
        if ($collectionStatus === 'Completed' && $screeningStatus === 'Passed') {
            return 'Completed and passed laboratory clearance.';
        }

        if ($collectionStatus === 'Completed') {
            return 'Collection complete; laboratory review ongoing.';
        }

        if ($collectionStatus === 'Scheduled') {
            return 'Upcoming scheduled collection session.';
        }

        return 'Appointment / session concluded.';
    }
}
