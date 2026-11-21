<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorMedicalProfile;
use App\Models\User;
use App\Services\DonorEligibilityService;
use App\Services\ReferenceCodeGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DonorsController extends Controller
{
    public function __construct(
        private DonorEligibilityService $donorEligibilityService,
        private ReferenceCodeGenerator $referenceCodeGenerator,
    ) {}

    /**
     * Display the donors directory overview, statistics, and filterable list.
     */
    public function index(Request $request): View
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403);
        }

        $summary = [
            'totalDonors' => Donor::query()->count(),
            'activeDonors' => Donor::query()->where('account_status', 'Active')->count(),
            'eligibleDonors' => Donor::query()->where('eligibility_status', 'Eligible')->count(),
            'newThisMonth' => Donor::query()
                ->where('registered_at', '>=', CarbonImmutable::today()->startOfMonth()->toDateString())
                ->count(),
        ];

        $query = Donor::query()
            ->with(['currentEligibilityRecord'])
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('full_name', 'like', "%{$term}%")
                    ->orWhere('donor_code', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if ($request->filled('blood_group') && $request->query('blood_group') !== 'all') {
            $query->where('blood_group', (string) $request->query('blood_group'));
        }

        if ($request->filled('eligibility_status') && $request->query('eligibility_status') !== 'all') {
            $query->where('eligibility_status', (string) $request->query('eligibility_status'));
        }

        if ($request->filled('account_status') && $request->query('account_status') !== 'all') {
            $query->where('account_status', (string) $request->query('account_status'));
        }

        $donors = $query->get()->map(function (Donor $donor): array {
            $status = $donor->currentEligibilityRecord?->eligibility_status ?? $donor->eligibility_status ?? 'Pending Review';

            return [
                'id' => $donor->donor_code,
                'name' => $donor->full_name,
                'email' => $donor->email,
                'phone' => $donor->phone ?? '—',
                'gender' => $donor->gender ?? '—',
                'bloodGroup' => $donor->blood_group ?? '—',
                'lastDonationDate' => $donor->last_donation_date?->format('M d, Y'),
                'eligibilityStatus' => $status,
                'accountStatus' => $donor->account_status,
                'registeredThisMonth' => $donor->registered_at && $donor->registered_at->isCurrentMonth(),
            ];
        })->all();

        return view('donors.index', [
            'donors' => $donors,
            'summary' => $summary,
            'bloodGroups' => self::bloodGroupOptions(),
        ]);
    }

    /**
     * Display the donor registration form.
     */
    public function create(Request $request): View
    {
        $this->authorizeDonorManagement($request);

        return view('donors.create', [
            'bloodGroups' => self::bloodGroupOptions(),
        ]);
    }

    /**
     * Store a newly created walk-in donor profile.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->authorizeDonorManagement($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:191', 'unique:donors,email'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'gender' => ['required', 'string', Rule::in(Donor::GENDERS)],
            'date_of_birth' => [
                'required',
                'date',
                'before_or_equal:'.CarbonImmutable::today()->subYears(16)->toDateString(),
                'after_or_equal:'.CarbonImmutable::today()->subYears(110)->toDateString(),
            ],
            'blood_group' => ['required', 'string', Rule::in(Donor::BLOOD_GROUPS)],
            'address' => ['required', 'string', 'min:5', 'max:1000'],
            'emergency_contact' => ['required', 'string', 'min:5', 'max:150'],
            'availability_status' => ['required', 'string', Rule::in(Donor::AVAILABILITY_STATUSES)],
            'medical_notes' => ['nullable', 'string', 'max:1000'],
            'weight_kg' => ['nullable', 'numeric', 'min:30', 'max:300'],
            'last_hemoglobin_g_dl' => ['nullable', 'numeric', 'min:5', 'max:25'],
        ], [
            'date_of_birth.before_or_equal' => 'Donor must be at least 16 years old to register.',
            'date_of_birth.after_or_equal' => 'Enter a valid date of birth.',
        ]);

        $donor = DB::transaction(function () use ($validated, $user): Donor {
            $donorCode = $this->referenceCodeGenerator->generate(Donor::class, 'donor_code', 'DNR-');

            $donor = new Donor;
            $donor->donor_code = $donorCode;
            $donor->user_id = null;
            $donor->full_name = trim($validated['name']);
            $donor->email = strtolower(trim($validated['email']));
            $donor->phone = trim($validated['phone']);
            $donor->gender = $validated['gender'];
            $donor->date_of_birth = $validated['date_of_birth'];
            $donor->blood_group = $validated['blood_group'];
            $donor->address = trim($validated['address']);
            $donor->emergency_contact = trim($validated['emergency_contact']);
            $donor->availability_status = $validated['availability_status'];
            $donor->account_status = 'Active';
            $donor->registered_at = CarbonImmutable::today()->toDateString();
            $donor->profile_completed = $donor->hasCompletedRequiredProfile();
            $donor->save();

            $canViewMedical = Gate::forUser($user)->allows('viewMedical', $donor);

            if ($canViewMedical && (! empty($validated['medical_notes']) || ! empty($validated['weight_kg']) || ! empty($validated['last_hemoglobin_g_dl']))) {
                DonorMedicalProfile::query()->create([
                    'donor_id' => $donor->id,
                    'medical_notes' => $validated['medical_notes'] ?? null,
                    'weight_kg' => $validated['weight_kg'] ?? null,
                    'last_hemoglobin_g_dl' => $validated['last_hemoglobin_g_dl'] ?? null,
                    'updated_by_user_id' => $user->id,
                ]);
            }

            $this->donorEligibilityService->initializeOnboardingEligibility(
                donor: $donor,
                actor: null,
                status: 'Pending Review',
                reason: 'Initial donor walk-in registration awaiting clinical eligibility review.',
                clearanceSource: 'Staff Walk-in Registration'
            );

            return $donor;
        }, 3);

        return redirect()
            ->route('donors.show', $donor)
            ->with('success', 'Donor registered successfully.');
    }

    /**
     * Display a detailed donor profile with donation history.
     */
    public function show(Request $request, Donor $donor): View
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403);
        }

        $donor->load([
            'currentEligibilityRecord.reviewedBy',
            'medicalProfile',
            'donations' => fn ($query) => $query->with(['bloodComponent', 'center', 'camp'])->orderByDesc('donation_date')->orderByDesc('id'),
        ]);

        $canViewMedical = Gate::forUser($request->user())->allows('viewMedical', $donor);

        $sample = collect(self::sampleDonorsList())->firstWhere('id', $donor->donor_code);

        $donationHistory = $donor->donations->map(function (Donation $donation): array {
            $location = $donation->center?->name
                ?? $donation->camp?->name
                ?? $donation->collection_location_name
                ?? 'Main Donation Center';

            return [
                'reference' => $donation->donation_code,
                'date' => $donation->donation_date?->format('M d, Y') ?? $donation->created_at->format('M d, Y'),
                'center' => $location,
                'component' => $donation->bloodComponent?->name ?? 'Whole Blood',
                'volume' => $donation->quantity_ml ? "{$donation->quantity_ml} ml" : '450 ml',
                'status' => in_array($donation->collection_status, ['Completed', 'Cleared'], true) ? 'Cleared' : $donation->collection_status,
            ];
        })->all();

        if (empty($donationHistory) && $sample && ! empty($sample['donationHistory'])) {
            $donationHistory = $sample['donationHistory'];
        }

        $medicalNotes = $canViewMedical
            ? ($donor->medicalProfile?->medical_notes ?? ($sample['medicalNotes'] ?? ''))
            : '[Protected clinical information - restricted to authorized medical personnel]';

        $eligibilityNote = $donor->currentEligibilityRecord?->reason
            ?? ($sample['eligibilityNote'] ?? 'Initial donor intake awaiting medical screening verification.');

        $donorData = [
            'id' => $donor->donor_code,
            'name' => $donor->full_name,
            'email' => $donor->email,
            'phone' => $donor->phone ?? '',
            'gender' => $donor->gender ?? '',
            'dateOfBirth' => $donor->date_of_birth?->format('Y-m-d') ?? '',
            'age' => $donor->date_of_birth ? (int) $donor->date_of_birth->age : 0,
            'bloodGroup' => $donor->blood_group ?? '',
            'address' => $donor->address ?? '',
            'emergencyContact' => $donor->emergency_contact ?? '',
            'medicalNotes' => $medicalNotes,
            'availabilityStatus' => $donor->availability_status,
            'lastDonationDate' => $donor->last_donation_date?->format('M d, Y'),
            'totalDonations' => (int) $donor->total_completed_donations,
            'eligibilityStatus' => $donor->currentEligibilityRecord?->eligibility_status ?? $donor->eligibility_status ?? 'Pending Review',
            'eligibilityNote' => $eligibilityNote,
            'accountStatus' => $donor->account_status,
            'donationHistory' => $donationHistory,
        ];

        return view('donors.show', [
            'donor' => $donorData,
            'donorModel' => $donor,
            'canViewMedical' => $canViewMedical,
        ]);
    }

    /**
     * Display the edit form prefilled with the selected donor's data.
     */
    public function edit(Request $request, Donor $donor): View
    {
        $this->authorizeDonorManagement($request);

        $donor->load('medicalProfile');
        $canViewMedical = Gate::forUser($request->user())->allows('viewMedical', $donor);

        $donorData = [
            'id' => $donor->donor_code,
            'name' => $donor->full_name,
            'email' => $donor->email,
            'phone' => $donor->phone ?? '',
            'gender' => $donor->gender ?? '',
            'dateOfBirth' => $donor->date_of_birth?->format('Y-m-d') ?? '',
            'bloodGroup' => $donor->blood_group ?? '',
            'address' => $donor->address ?? '',
            'emergencyContact' => $donor->emergency_contact ?? '',
            'medicalNotes' => $canViewMedical ? ($donor->medicalProfile?->medical_notes ?? '') : '',
            'availabilityStatus' => $donor->availability_status,
            'eligibilityStatus' => $donor->currentEligibilityRecord?->eligibility_status ?? $donor->eligibility_status ?? 'Pending Review',
        ];

        return view('donors.edit', [
            'donor' => $donorData,
            'donorModel' => $donor,
            'bloodGroups' => self::bloodGroupOptions(),
            'canViewMedical' => $canViewMedical,
        ]);
    }

    /**
     * Update the specified donor record.
     */
    public function update(Request $request, Donor $donor): RedirectResponse
    {
        $user = $this->authorizeDonorManagement($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:191', Rule::unique('donors', 'email')->ignore($donor->id)],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'gender' => ['required', 'string', Rule::in(Donor::GENDERS)],
            'date_of_birth' => [
                'required',
                'date',
                'before_or_equal:'.CarbonImmutable::today()->subYears(16)->toDateString(),
                'after_or_equal:'.CarbonImmutable::today()->subYears(110)->toDateString(),
            ],
            'blood_group' => ['required', 'string', Rule::in(Donor::BLOOD_GROUPS)],
            'address' => ['required', 'string', 'min:5', 'max:1000'],
            'emergency_contact' => ['required', 'string', 'min:5', 'max:150'],
            'availability_status' => ['required', 'string', Rule::in(Donor::AVAILABILITY_STATUSES)],
            'account_status' => ['nullable', 'string', Rule::in(Donor::ACCOUNT_STATUSES)],
            'medical_notes' => ['nullable', 'string', 'max:1000'],
            'weight_kg' => ['nullable', 'numeric', 'min:30', 'max:300'],
            'last_hemoglobin_g_dl' => ['nullable', 'numeric', 'min:5', 'max:25'],
        ], [
            'date_of_birth.before_or_equal' => 'Donor must be at least 16 years old to register.',
            'date_of_birth.after_or_equal' => 'Enter a valid date of birth.',
        ]);

        DB::transaction(function () use ($validated, $donor, $user, $request): void {
            $donor->full_name = trim($validated['name']);
            $donor->email = strtolower(trim($validated['email']));
            $donor->phone = trim($validated['phone']);
            $donor->gender = $validated['gender'];
            $donor->date_of_birth = $validated['date_of_birth'];
            $donor->blood_group = $validated['blood_group'];
            $donor->address = trim($validated['address']);
            $donor->emergency_contact = trim($validated['emergency_contact']);
            $donor->availability_status = $validated['availability_status'];

            if (! empty($validated['account_status'])) {
                $donor->account_status = $validated['account_status'];
            }

            $donor->profile_completed = $donor->hasCompletedRequiredProfile();
            $donor->save();

            $canViewMedical = Gate::forUser($user)->allows('viewMedical', $donor);

            if ($canViewMedical && $request->has('medical_notes')) {
                $medicalProfile = $donor->medicalProfile()->firstOrNew();
                $medicalProfile->medical_notes = $validated['medical_notes'] ?? null;
                if ($request->filled('weight_kg')) {
                    $medicalProfile->weight_kg = $validated['weight_kg'];
                }
                if ($request->filled('last_hemoglobin_g_dl')) {
                    $medicalProfile->last_hemoglobin_g_dl = $validated['last_hemoglobin_g_dl'];
                }
                $medicalProfile->updated_by_user_id = $user->id;
                $medicalProfile->save();
            }
        }, 3);

        return redirect()
            ->route('donors.show', $donor)
            ->with('success', 'Donor updated successfully.');
    }

    /**
     * Record an authoritative clinical eligibility assessment.
     */
    public function assessEligibility(Request $request, Donor $donor): RedirectResponse
    {
        Gate::authorize('assessEligibility', $donor);

        $validated = $request->validate([
            'eligibility_status' => ['required', 'string', Rule::in(['Eligible', 'Not Eligible', 'Pending Review'])],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'clearance_source' => ['nullable', 'string', 'max:191'],
            'reviewed_at' => ['nullable', 'date'],
            'next_eligible_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $this->donorEligibilityService->recordAssessment(
            donor: $donor,
            actor: $request->user(),
            attributes: [
                'eligibility_status' => $validated['eligibility_status'],
                'reason' => $validated['reason'],
                'clearance_source' => $validated['clearance_source'] ?? 'Medical Officer Clinical Assessment',
                'reviewed_at' => $validated['reviewed_at'] ?? CarbonImmutable::today()->toDateString(),
                'next_eligible_date' => $validated['next_eligible_date'] ?? null,
            ]
        );

        return redirect()
            ->route('donors.show', $donor)
            ->with('success', 'Donor eligibility assessment recorded successfully.');
    }

    /**
     * Authorize that the acting user is an active staff or administrator.
     */
    private function authorizeDonorManagement(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->is_active || ! $user->hasAnyRole(['admin', 'staff'])) {
            abort(403, 'Unauthorized access to donors management.');
        }

        return $user;
    }

    /**
     * Standard ABO/Rh blood group options keyed for select components.
     *
     * @return array<string, string>
     */
    public static function bloodGroupOptions(): array
    {
        return [
            'A+' => 'A+',
            'A-' => 'A-',
            'B+' => 'B+',
            'B-' => 'B-',
            'AB+' => 'AB+',
            'AB-' => 'AB-',
            'O+' => 'O+',
            'O-' => 'O-',
        ];
    }

    /**
     * Sample donor dataset for compatibility with downstream un-migrated modules (e.g. DonorPortal).
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     email: string,
     *     phone: string,
     *     gender: string,
     *     dateOfBirth: string,
     *     age: int,
     *     bloodGroup: string,
     *     address: string,
     *     emergencyContact: string,
     *     medicalNotes: string,
     *     availabilityStatus: string,
     *     lastDonationDate: string,
     *     totalDonations: int,
     *     eligibilityStatus: string,
     *     eligibilityNote: string,
     *     accountStatus: string,
     *     registeredThisMonth: bool,
     *     donationHistory: array<int, array{reference: string, date: string, center: string, component: string, volume: string, status: string}>
     * }>
     */
    public function sampleDonors(): array
    {
        return self::sampleDonorsList();
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     email: string,
     *     phone: string,
     *     gender: string,
     *     dateOfBirth: string,
     *     age: int,
     *     bloodGroup: string,
     *     address: string,
     *     emergencyContact: string,
     *     medicalNotes: string,
     *     availabilityStatus: string,
     *     lastDonationDate: string,
     *     totalDonations: int,
     *     eligibilityStatus: string,
     *     eligibilityNote: string,
     *     accountStatus: string,
     *     registeredThisMonth: bool,
     *     donationHistory: array<int, array{reference: string, date: string, center: string, component: string, volume: string, status: string}>
     * }>
     */
    public static function sampleDonorsList(): array
    {
        return [
            [
                'id' => 'DNR-1041',
                'name' => 'Elena Rostova',
                'email' => 'elena.rostova@example.org',
                'phone' => '+1 (555) 234-8812',
                'gender' => 'Female',
                'dateOfBirth' => '1996-04-18',
                'age' => 30,
                'bloodGroup' => 'O-',
                'address' => '412 Oakridge Ave, Apt 4B, Central District',
                'emergencyContact' => 'Victor Rostov — +1 (555) 234-8890',
                'medicalNotes' => 'Cleared on prior physical & hemoglobin screening (13.9 g/dL). Universal RBC donor.',
                'availabilityStatus' => 'Available',
                'lastDonationDate' => 'Jul 22, 2026',
                'totalDonations' => 8,
                'eligibilityStatus' => 'Eligible',
                'eligibilityNote' => 'Verified by clinical screening criteria; ready for scheduled collection.',
                'accountStatus' => 'Active',
                'registeredThisMonth' => false,
                'donationHistory' => [
                    ['reference' => 'DON-4012', 'date' => 'Jul 22, 2026', 'center' => 'Central Medical Plaza', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                    ['reference' => 'DON-3720', 'date' => 'Apr 08, 2026', 'center' => 'Central Medical Plaza', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                    ['reference' => 'DON-3411', 'date' => 'Jan 15, 2026', 'center' => 'Northside Community Drive', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                ],
            ],
            [
                'id' => 'DNR-1042',
                'name' => 'Marcus Vance',
                'email' => 'marcus.vance@example.org',
                'phone' => '+1 (555) 391-4420',
                'gender' => 'Male',
                'dateOfBirth' => '1991-09-03',
                'age' => 35,
                'bloodGroup' => 'A+',
                'address' => '88 Harbor View Rd, Eastside',
                'emergencyContact' => 'Claire Vance — +1 (555) 391-0014',
                'medicalNotes' => 'Recent whole blood donation under routine post-collection recovery window; awaiting final lab clearance.',
                'availabilityStatus' => 'Temporarily Unavailable',
                'lastDonationDate' => 'Oct 28, 2026',
                'totalDonations' => 5,
                'eligibilityStatus' => 'Not Eligible',
                'eligibilityNote' => 'Currently ineligible per verified post-donation clinical deferral rule.',
                'accountStatus' => 'Active',
                'registeredThisMonth' => false,
                'donationHistory' => [
                    ['reference' => 'DON-4310', 'date' => 'Oct 28, 2026', 'center' => 'University Health Campus', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                    ['reference' => 'DON-3904', 'date' => 'Jun 19, 2026', 'center' => 'Central Medical Plaza', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                ],
            ],
            [
                'id' => 'DNR-1043',
                'name' => 'Priya Sharma',
                'email' => 'priya.sharma@example.org',
                'phone' => '+1 (555) 612-7745',
                'gender' => 'Female',
                'dateOfBirth' => '1999-11-12',
                'age' => 26,
                'bloodGroup' => 'B+',
                'address' => '19 Cedar Crest Blvd, West End',
                'emergencyContact' => 'Rohan Sharma — +1 (555) 612-7700',
                'medicalNotes' => 'Initial donor intake questionnaire submitted; awaiting pre-donation hemoglobin & physician review.',
                'availabilityStatus' => 'Available',
                'lastDonationDate' => 'Never',
                'totalDonations' => 0,
                'eligibilityStatus' => 'Pending Review',
                'eligibilityNote' => 'First-time registrant awaiting initial medical screening verification.',
                'accountStatus' => 'Active',
                'registeredThisMonth' => true,
                'donationHistory' => [],
            ],
            [
                'id' => 'DNR-1044',
                'name' => 'David Kim',
                'email' => 'david.kim@example.org',
                'phone' => '+1 (555) 784-1933',
                'gender' => 'Male',
                'dateOfBirth' => '1988-02-27',
                'age' => 38,
                'bloodGroup' => 'AB-',
                'address' => '705 Maplewood Ln, Midtown',
                'emergencyContact' => 'Grace Kim — +1 (555) 784-1901',
                'medicalNotes' => 'Regular platelet & AB- plasma contributor; verified vitals and negative serology panel.',
                'availabilityStatus' => 'Available',
                'lastDonationDate' => 'Aug 05, 2026',
                'totalDonations' => 11,
                'eligibilityStatus' => 'Eligible',
                'eligibilityNote' => 'Verified eligible for whole blood or apheresis donation.',
                'accountStatus' => 'Active',
                'registeredThisMonth' => false,
                'donationHistory' => [
                    ['reference' => 'DON-4088', 'date' => 'Aug 05, 2026', 'center' => 'Central Medical Plaza', 'component' => 'Platelets', 'volume' => '250 ml', 'status' => 'Cleared'],
                    ['reference' => 'DON-3862', 'date' => 'May 14, 2026', 'center' => 'Central Medical Plaza', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                ],
            ],
            [
                'id' => 'DNR-1045',
                'name' => 'Amina Hassan',
                'email' => 'amina.hassan@example.org',
                'phone' => '+1 (555) 845-3091',
                'gender' => 'Female',
                'dateOfBirth' => '1994-06-30',
                'age' => 32,
                'bloodGroup' => 'O+',
                'address' => '234 Willow St, Southgate',
                'emergencyContact' => 'Tariq Hassan — +1 (555) 845-3004',
                'medicalNotes' => 'Recent travel screening documented; pending physician sign-off prior to next collection.',
                'availabilityStatus' => 'Available',
                'lastDonationDate' => 'Jun 11, 2026',
                'totalDonations' => 4,
                'eligibilityStatus' => 'Pending Review',
                'eligibilityNote' => 'Medical officer review required for updated travel questionnaire.',
                'accountStatus' => 'Active',
                'registeredThisMonth' => false,
                'donationHistory' => [
                    ['reference' => 'DON-3885', 'date' => 'Jun 11, 2026', 'center' => 'Southgate Clinic', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                ],
            ],
            [
                'id' => 'DNR-1046',
                'name' => 'Lucas Bennett',
                'email' => 'lucas.bennett@example.org',
                'phone' => '+1 (555) 490-2218',
                'gender' => 'Male',
                'dateOfBirth' => '1985-12-09',
                'age' => 40,
                'bloodGroup' => 'A-',
                'address' => '16 Pinecrest Terrace, North Hills',
                'emergencyContact' => 'Hannah Bennett — +1 (555) 490-2299',
                'medicalNotes' => 'Temporary iron deficiency noted during August screening; re-evaluation scheduled.',
                'availabilityStatus' => 'Inactive',
                'lastDonationDate' => 'Mar 19, 2026',
                'totalDonations' => 3,
                'eligibilityStatus' => 'Not Eligible',
                'eligibilityNote' => 'Temporary medical deferral recorded; repeat hemoglobin evaluation needed.',
                'accountStatus' => 'Inactive',
                'registeredThisMonth' => false,
                'donationHistory' => [
                    ['reference' => 'DON-3590', 'date' => 'Mar 19, 2026', 'center' => 'Northside Community Drive', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                ],
            ],
            [
                'id' => 'DNR-1047',
                'name' => 'Sofia Alvarez',
                'email' => 'sofia.alvarez@example.org',
                'phone' => '+1 (555) 923-5510',
                'gender' => 'Female',
                'dateOfBirth' => '2001-01-24',
                'age' => 25,
                'bloodGroup' => 'AB+',
                'address' => '640 Riverwalk Way, Downtown',
                'emergencyContact' => 'Mateo Alvarez — +1 (555) 923-5588',
                'medicalNotes' => 'Completed baseline intake and blood typing; cleared by attending nurse.',
                'availabilityStatus' => 'Available',
                'lastDonationDate' => 'Sep 02, 2026',
                'totalDonations' => 2,
                'eligibilityStatus' => 'Eligible',
                'eligibilityNote' => 'Verified eligible per medical screening record.',
                'accountStatus' => 'Active',
                'registeredThisMonth' => true,
                'donationHistory' => [
                    ['reference' => 'DON-4195', 'date' => 'Sep 02, 2026', 'center' => 'University Health Campus', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                    ['reference' => 'DON-3940', 'date' => 'May 20, 2026', 'center' => 'Central Medical Plaza', 'component' => 'Whole Blood', 'volume' => '450 ml', 'status' => 'Cleared'],
                ],
            ],
            [
                'id' => 'DNR-1048',
                'name' => 'Omar Al-Mansoor',
                'email' => 'omar.mansoor@example.org',
                'phone' => '+1 (555) 673-8044',
                'gender' => 'Male',
                'dateOfBirth' => '1993-08-15',
                'age' => 33,
                'bloodGroup' => 'B-',
                'address' => '918 Crescent Park, Westgate',
                'emergencyContact' => 'Layla Al-Mansoor — +1 (555) 673-8090',
                'medicalNotes' => 'Newly registered rare Rh-negative donor; verified medical clearance on Nov 03.',
                'availabilityStatus' => 'Available',
                'lastDonationDate' => 'Never',
                'totalDonations' => 0,
                'eligibilityStatus' => 'Eligible',
                'eligibilityNote' => 'Cleared at baseline intake screening.',
                'accountStatus' => 'Active',
                'registeredThisMonth' => true,
                'donationHistory' => [],
            ],
        ];
    }
}
