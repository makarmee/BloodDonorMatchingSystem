<?php

namespace App\Http\Controllers;

use App\Models\BloodComponent;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\User;
use App\Services\DonationService;
use App\Services\ScreeningService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DonationsController extends Controller
{
    public function __construct(
        private DonationService $donationService,
        private ScreeningService $screeningService,
    ) {}

    /**
     * Display the donations overview metrics and filterable donations log.
     */
    public function index(Request $request): View
    {
        $this->authorizeDonationManagement($request);

        $totalDonations = Donation::query()->count();

        if ($totalDonations === 0) {
            $sampleList = self::sampleDonationsList();
            $donations = $sampleList;
            $completed = collect($sampleList)->where('collectionStatus', 'Completed');
            $summary = [
                'totalDonations' => count($sampleList),
                'donationsThisMonth' => count($sampleList),
                'totalUnitsCollected' => $completed->count(),
                'totalVolumeMl' => (int) $completed->sum('quantityMl'),
                'pendingScreening' => collect($sampleList)->where('screeningStatus', 'Pending')->count(),
            ];
        } else {
            $donationsThisMonth = Donation::query()
                ->where('donation_date', '>=', CarbonImmutable::today()->startOfMonth()->toDateString())
                ->count();
            $completedDonationsQuery = Donation::query()->where('collection_status', 'Completed');
            $totalUnitsCollected = (clone $completedDonationsQuery)->count();
            $totalVolumeMl = (int) (clone $completedDonationsQuery)->sum('quantity_ml');
            $pendingScreening = Donation::query()->where('screening_status', 'Pending')->count();

            $summary = [
                'totalDonations' => $totalDonations,
                'donationsThisMonth' => $donationsThisMonth,
                'totalUnitsCollected' => $totalUnitsCollected,
                'totalVolumeMl' => $totalVolumeMl,
                'pendingScreening' => $pendingScreening,
            ];

            $query = Donation::query()
                ->with(['donor', 'bloodComponent', 'primaryBloodUnit', 'center', 'camp'])
                ->orderByDesc('donation_date')
                ->orderByDesc('id');

            if ($request->filled('search')) {
                $term = trim((string) $request->query('search'));
                $query->where(function (Builder $builder) use ($term): void {
                    $builder->where('donation_code', 'like', "%{$term}%")
                        ->orWhere('collection_location_name', 'like', "%{$term}%")
                        ->orWhere('staff_member_name', 'like', "%{$term}%")
                        ->orWhereHas('donor', function (Builder $d) use ($term): void {
                            $d->where('full_name', 'like', "%{$term}%")
                                ->orWhere('donor_code', 'like', "%{$term}%");
                        });
                });
            }

            if ($request->filled('blood_group') && $request->query('blood_group') !== 'all') {
                $query->where('blood_group', (string) $request->query('blood_group'));
            }

            if ($request->filled('donation_type') && $request->query('donation_type') !== 'all') {
                $type = (string) $request->query('donation_type');
                $query->whereHas('bloodComponent', fn (Builder $c) => $c->where('name', $type));
            }

            if ($request->filled('screening_status') && $request->query('screening_status') !== 'all') {
                $query->where('screening_status', (string) $request->query('screening_status'));
            }

            if ($request->filled('collection_status') && $request->query('collection_status') !== 'all') {
                $query->where('collection_status', (string) $request->query('collection_status'));
            }

            if ($request->filled('date_from')) {
                $query->where('donation_date', '>=', (string) $request->query('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->where('donation_date', '<=', (string) $request->query('date_to'));
            }

            $donations = $query->get()->map(function (Donation $donation): array {
                $location = $donation->collection_location_name
                    ?? $donation->center?->name
                    ?? $donation->camp?->name
                    ?? 'Main Center';

                return [
                    'id' => $donation->donation_code,
                    'donorId' => $donation->donor?->donor_code ?? '—',
                    'donorName' => $donation->donor?->full_name ?? '—',
                    'donorEmail' => $donation->donor?->email ?? '',
                    'donorPhone' => $donation->donor?->phone ?? '',
                    'donorEligibilityStatus' => $donation->donor?->eligibility_status ?? 'Pending Review',
                    'bloodGroup' => $donation->blood_group,
                    'donationDate' => $donation->donation_date?->format('Y-m-d') ?? '',
                    'donationDateFormatted' => $donation->donation_date?->format('M d, Y') ?? '',
                    'quantityMl' => (int) $donation->quantity_ml,
                    'donationType' => $donation->bloodComponent?->name ?? 'Whole Blood',
                    'collectionLocation' => $location,
                    'staffMember' => $donation->staff_member_name ?? $donation->recordedBy?->name ?? 'Phlebotomy Staff',
                    'screeningStatus' => $donation->screening_status,
                    'screeningNotes' => $donation->screening_notes ?? '',
                    'collectionStatus' => $donation->collection_status,
                    'collectionNotes' => $donation->collection_notes ?? '',
                ];
            })->all();
        }

        return view('donations.index', [
            'donations' => $donations,
            'summary' => $summary,
            'bloodGroups' => $this->bloodGroupOptions(),
        ]);
    }

    /**
     * Display the new donation recording form.
     */
    public function create(Request $request): View
    {
        $this->authorizeDonationManagement($request);

        $donors = Donor::query()
            ->active()
            ->orderBy('full_name')
            ->get()
            ->map(fn (Donor $d): array => [
                'id' => $d->donor_code,
                'name' => $d->full_name,
                'bloodGroup' => $d->blood_group,
                'email' => $d->email,
                'phone' => $d->phone ?? '',
                'eligibilityStatus' => $d->eligibility_status,
            ])
            ->all();

        if (empty($donors)) {
            $donors = array_values($this->sampleDonorsById());
        }

        return view('donations.create', [
            'donors' => $donors,
            'bloodGroups' => $this->bloodGroupOptions(),
            'locations' => $this->collectionLocations(),
            'staffMembers' => $this->staffMemberSuggestions(),
        ]);
    }

    /**
     * Store a newly created donation record.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $this->authorizeDonationManagement($request);

        $validated = $request->validate([
            'donor_id' => ['required', 'string'],
            'donation_date' => ['required', 'date', 'before_or_equal:today'],
            'donation_type' => ['nullable', 'string'],
            'quantity_ml' => ['required', 'integer', 'min:100', 'max:1000'],
            'collection_location' => ['nullable', 'string', 'max:150'],
            'staff_member' => ['nullable', 'string', 'max:150'],
            'collection_status' => ['required', 'string', Rule::in(['Scheduled', 'Completed'])],
            'collection_notes' => ['nullable', 'string', 'max:1000'],
            'storage_location' => ['nullable', 'string', 'max:120'],
        ]);

        $donor = Donor::where('donor_code', $validated['donor_id'])
            ->when(is_numeric($validated['donor_id']), fn ($q) => $q->orWhere('id', (int) $validated['donor_id']))
            ->first();

        if (! $donor) {
            throw ValidationException::withMessages([
                'donor_id' => 'The selected donor could not be found.',
            ]);
        }

        $component = null;
        if (! empty($validated['donation_type'])) {
            $component = BloodComponent::where('name', $validated['donation_type'])->first();
        }
        if (! $component) {
            $component = BloodComponent::where('code', 'WB-450')->orWhere('name', 'Whole Blood')->first();
        }

        $donation = $this->donationService->registerDonation($donor, $actor, [
            'blood_component_id' => $component?->id,
            'quantity_ml' => $validated['quantity_ml'],
            'donation_date' => $validated['donation_date'],
            'collection_status' => $validated['collection_status'],
            'collection_location_name' => $validated['collection_location'] ?? 'Main Center',
            'staff_member_name' => $validated['staff_member'] ?? $actor->name,
            'collection_notes' => $validated['collection_notes'] ?? null,
            'storage_location' => $validated['storage_location'] ?? null,
        ]);

        if ($donation->collection_status === 'Completed') {
            try {
                $this->screeningService->initializePendingPanel($donation, $actor, [
                    'lab_staff_name' => $actor->name,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()
            ->route('donations.show', $donation)
            ->with('success', 'Donation registered successfully.');
    }

    /**
     * Display detailed screening, collection, and donor details for a donation.
     */
    public function show(Request $request, Donation $donation): View
    {
        $this->authorizeDonationManagement($request);

        $donation->load(['donor', 'bloodComponent', 'primaryBloodUnit', 'screeningRecords.testType', 'center', 'camp']);

        $location = $donation->collection_location_name
            ?? $donation->center?->name
            ?? $donation->camp?->name
            ?? 'Main Center';

        $sample = collect(self::sampleDonationsList())->firstWhere('id', $donation->donation_code);

        $donationRecord = [
            'id' => $donation->donation_code,
            'donorId' => $donation->donor?->donor_code ?? ($sample['donorId'] ?? '—'),
            'donorName' => $donation->donor?->full_name ?? ($sample['donorName'] ?? '—'),
            'donorEmail' => $donation->donor?->email ?? ($sample['donorEmail'] ?? ''),
            'donorPhone' => $donation->donor?->phone ?? ($sample['donorPhone'] ?? ''),
            'donorEligibilityStatus' => $donation->donor?->eligibility_status ?? ($sample['donorEligibilityStatus'] ?? 'Pending Review'),
            'bloodGroup' => $donation->blood_group ?? ($sample['bloodGroup'] ?? 'O+'),
            'donationDate' => $donation->donation_date?->format('Y-m-d') ?? ($sample['donationDate'] ?? ''),
            'donationDateFormatted' => $donation->donation_date?->format('M d, Y') ?? ($sample['donationDateFormatted'] ?? ''),
            'quantityMl' => (int) ($donation->quantity_ml ?? ($sample['quantityMl'] ?? 450)),
            'donationType' => $donation->bloodComponent?->name ?? ($sample['donationType'] ?? 'Whole Blood'),
            'collectionLocation' => $location,
            'staffMember' => $donation->staff_member_name ?? $donation->recordedBy?->name ?? ($sample['staffMember'] ?? 'Phlebotomy Staff'),
            'screeningStatus' => $donation->screening_status ?? ($sample['screeningStatus'] ?? 'Pending'),
            'screeningNotes' => $donation->screening_notes ?? ($sample['screeningNotes'] ?? ''),
            'collectionStatus' => $donation->collection_status ?? ($sample['collectionStatus'] ?? 'Completed'),
            'collectionNotes' => $donation->collection_notes ?? ($sample['collectionNotes'] ?? ''),
        ];

        return view('donations.show', [
            'donation' => $donationRecord,
            'donationModel' => $donation,
        ]);
    }

    /**
     * Display the donation edit form prefilled with the selected donation data.
     */
    public function edit(Request $request, Donation $donation): View
    {
        $this->authorizeDonationManagement($request);

        $donation->load(['donor', 'bloodComponent']);

        $location = $donation->collection_location_name
            ?? $donation->center?->name
            ?? $donation->camp?->name
            ?? 'Main Center';

        $sample = collect(self::sampleDonationsList())->firstWhere('id', $donation->donation_code);

        $donationRecord = [
            'id' => $donation->donation_code,
            'donorId' => $donation->donor?->donor_code ?? ($sample['donorId'] ?? ''),
            'donorName' => $donation->donor?->full_name ?? ($sample['donorName'] ?? ''),
            'bloodGroup' => $donation->blood_group ?? ($sample['bloodGroup'] ?? 'O+'),
            'donationDate' => $donation->donation_date?->format('Y-m-d') ?? ($sample['donationDate'] ?? ''),
            'donationType' => $donation->bloodComponent?->name ?? ($sample['donationType'] ?? 'Whole Blood'),
            'quantityMl' => (int) ($donation->quantity_ml ?? ($sample['quantityMl'] ?? 450)),
            'collectionLocation' => $location,
            'staffMember' => $donation->staff_member_name ?? ($sample['staffMember'] ?? ''),
            'screeningStatus' => $donation->screening_status ?? ($sample['screeningStatus'] ?? 'Pending'),
            'screeningNotes' => $donation->screening_notes ?? ($sample['screeningNotes'] ?? ''),
            'collectionStatus' => $donation->collection_status ?? ($sample['collectionStatus'] ?? 'Scheduled'),
            'collectionNotes' => $donation->collection_notes ?? ($sample['collectionNotes'] ?? ''),
        ];

        $donors = Donor::query()
            ->active()
            ->orderBy('full_name')
            ->get()
            ->map(fn (Donor $d): array => [
                'id' => $d->donor_code,
                'name' => $d->full_name,
                'bloodGroup' => $d->blood_group,
                'email' => $d->email,
                'phone' => $d->phone ?? '',
                'eligibilityStatus' => $d->eligibility_status,
            ])
            ->all();

        if (empty($donors)) {
            $donors = array_values($this->sampleDonorsById());
        }

        return view('donations.edit', [
            'donation' => $donationRecord,
            'donationModel' => $donation,
            'donors' => $donors,
            'bloodGroups' => $this->bloodGroupOptions(),
            'locations' => $this->collectionLocations(),
            'staffMembers' => $this->staffMemberSuggestions(),
        ]);
    }

    /**
     * Update editable donation details (location, notes, staff).
     */
    public function update(Request $request, Donation $donation): RedirectResponse
    {
        $this->authorizeDonationManagement($request);

        $validated = $request->validate([
            'collection_location' => ['nullable', 'string', 'max:150'],
            'staff_member' => ['nullable', 'string', 'max:150'],
            'collection_notes' => ['nullable', 'string', 'max:1000'],
            'screening_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('collection_location', $validated)) {
            $donation->collection_location_name = $validated['collection_location'];
        }
        if (array_key_exists('staff_member', $validated)) {
            $donation->staff_member_name = $validated['staff_member'];
        }
        if (array_key_exists('collection_notes', $validated)) {
            $donation->collection_notes = $validated['collection_notes'];
        }
        if (array_key_exists('screening_notes', $validated)) {
            $donation->screening_notes = $validated['screening_notes'];
        }
        $donation->save();

        return redirect()
            ->route('donations.show', $donation)
            ->with('success', 'Donation details updated successfully.');
    }

    /**
     * Transition a scheduled donation to Completed and initialize the mandatory screening panel.
     */
    public function complete(Request $request, Donation $donation): RedirectResponse
    {
        $actor = $this->authorizeDonationManagement($request);

        $validated = $request->validate([
            'quantity_ml' => ['nullable', 'integer', 'min:100', 'max:1000'],
            'collected_at' => ['nullable', 'date'],
            'staff_member_name' => ['nullable', 'string', 'max:150'],
            'collection_notes' => ['nullable', 'string', 'max:1000'],
            'storage_location' => ['nullable', 'string', 'max:120'],
        ]);

        $completed = $this->donationService->completeDonation($donation, $actor, $validated);

        try {
            $this->screeningService->initializePendingPanel($completed, $actor, [
                'lab_staff_name' => $actor->name,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()
            ->route('donations.show', $completed)
            ->with('success', 'Donation marked as completed and laboratory screening panel initialized.');
    }

    /**
     * Cancel a scheduled donation.
     */
    public function cancel(Request $request, Donation $donation): RedirectResponse
    {
        $actor = $this->authorizeDonationManagement($request);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $cancelled = $this->donationService->cancelDonation($donation, $actor, $validated['reason'] ?? null);

        return redirect()
            ->route('donations.show', $cancelled)
            ->with('success', 'Scheduled donation cancelled successfully.');
    }

    /**
     * Authorize that the acting user is an active staff, admin, or lab officer.
     */
    private function authorizeDonationManagement(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->is_active || ! $user->hasAnyRole(['admin', 'staff', 'lab_officer'])) {
            abort(403, 'Unauthorized access to donations management.');
        }

        return $user;
    }

    /**
     * Standard ABO/Rh blood groups keyed for select inputs and filters.
     *
     * @return array<int, string>
     */
    public function bloodGroupOptions(): array
    {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }

    /**
     * Suggested collection facilities matching existing sample donation centers.
     *
     * @return array<int, string>
     */
    public function collectionLocations(): array
    {
        return [
            'Central Medical Plaza',
            'University Health Campus',
            'Westgate Donor Suite',
            'Southgate Clinic',
            'Northside Community Drive',
        ];
    }

    /**
     * Suggested phlebotomy and laboratory staff members.
     *
     * @return array<int, string>
     */
    public function staffMemberSuggestions(): array
    {
        return [
            'Nurse Hannah Reed',
            'Dr. Maya Lin',
            'Dr. Tariq Nassar',
            'Nurse Clara Diaz',
        ];
    }

    /**
     * Sample donors map for preview compatibility.
     *
     * @return array<string, array{
     *     id: string,
     *     name: string,
     *     bloodGroup: string,
     *     email: string,
     *     phone: string,
     *     eligibilityStatus: string
     * }>
     */
    public function sampleDonorsById(): array
    {
        return [
            'DNR-1041' => [
                'id' => 'DNR-1041',
                'name' => 'Elena Rostova',
                'bloodGroup' => 'O-',
                'email' => 'elena.rostova@example.org',
                'phone' => '+1 (555) 234-8812',
                'eligibilityStatus' => 'Eligible',
            ],
            'DNR-1042' => [
                'id' => 'DNR-1042',
                'name' => 'Marcus Vance',
                'bloodGroup' => 'A+',
                'email' => 'marcus.vance@example.org',
                'phone' => '+1 (555) 391-4420',
                'eligibilityStatus' => 'Not Eligible',
            ],
            'DNR-1043' => [
                'id' => 'DNR-1043',
                'name' => 'Priya Sharma',
                'bloodGroup' => 'B+',
                'email' => 'priya.sharma@example.org',
                'phone' => '+1 (555) 612-7745',
                'eligibilityStatus' => 'Pending Review',
            ],
            'DNR-1044' => [
                'id' => 'DNR-1044',
                'name' => 'David Kim',
                'bloodGroup' => 'AB-',
                'email' => 'david.kim@example.org',
                'phone' => '+1 (555) 784-1933',
                'eligibilityStatus' => 'Eligible',
            ],
            'DNR-1045' => [
                'id' => 'DNR-1045',
                'name' => 'Amina Hassan',
                'bloodGroup' => 'O+',
                'email' => 'amina.hassan@example.org',
                'phone' => '+1 (555) 845-3091',
                'eligibilityStatus' => 'Pending Review',
            ],
            'DNR-1046' => [
                'id' => 'DNR-1046',
                'name' => 'Lucas Bennett',
                'bloodGroup' => 'A-',
                'email' => 'lucas.bennett@example.org',
                'phone' => '+1 (555) 490-2218',
                'eligibilityStatus' => 'Not Eligible',
            ],
            'DNR-1047' => [
                'id' => 'DNR-1047',
                'name' => 'Sofia Alvarez',
                'bloodGroup' => 'AB+',
                'email' => 'sofia.alvarez@example.org',
                'phone' => '+1 (555) 923-5510',
                'eligibilityStatus' => 'Eligible',
            ],
            'DNR-1048' => [
                'id' => 'DNR-1048',
                'name' => 'Omar Al-Mansoor',
                'bloodGroup' => 'B-',
                'email' => 'omar.mansoor@example.org',
                'phone' => '+1 (555) 673-8044',
                'eligibilityStatus' => 'Eligible',
            ],
        ];
    }

    public function sampleDonations(): array
    {
        return self::sampleDonationsList();
    }

    public static function sampleDonationsList(): array
    {
        $donors = [
            'DNR-1041' => [
                'name' => 'Elena Rostova',
                'bloodGroup' => 'O-',
                'email' => 'elena.rostova@example.org',
                'phone' => '+1 (555) 234-8812',
                'eligibilityStatus' => 'Eligible',
            ],
            'DNR-1042' => [
                'name' => 'Marcus Vance',
                'bloodGroup' => 'A+',
                'email' => 'marcus.vance@example.org',
                'phone' => '+1 (555) 391-4420',
                'eligibilityStatus' => 'Not Eligible',
            ],
            'DNR-1043' => [
                'name' => 'Nadia Chen',
                'bloodGroup' => 'B+',
                'email' => 'nadia.chen@example.org',
                'phone' => '+1 (555) 502-7719',
                'eligibilityStatus' => 'Eligible',
            ],
            'DNR-1044' => [
                'name' => 'David Kim',
                'bloodGroup' => 'AB-',
                'email' => 'david.kim@example.org',
                'phone' => '+1 (555) 784-1933',
                'eligibilityStatus' => 'Eligible',
            ],
            'DNR-1045' => [
                'name' => 'Amina Hassan',
                'bloodGroup' => 'O+',
                'email' => 'amina.hassan@example.org',
                'phone' => '+1 (555) 845-3091',
                'eligibilityStatus' => 'Pending Review',
            ],
            'DNR-1046' => [
                'name' => 'Lucas Bennett',
                'bloodGroup' => 'A-',
                'email' => 'lucas.bennett@example.org',
                'phone' => '+1 (555) 490-2218',
                'eligibilityStatus' => 'Not Eligible',
            ],
            'DNR-1047' => [
                'name' => 'Sofia Alvarez',
                'bloodGroup' => 'AB+',
                'email' => 'sofia.alvarez@example.org',
                'phone' => '+1 (555) 923-5510',
                'eligibilityStatus' => 'Eligible',
            ],
            'DNR-1048' => [
                'name' => 'Omar Mansoor',
                'bloodGroup' => 'B-',
                'email' => 'omar.mansoor@example.org',
                'phone' => '+1 (555) 673-8044',
                'eligibilityStatus' => 'Eligible',
            ],
        ];

        $entries = [
            [
                'id' => 'DON-4405',
                'donorId' => 'DNR-1041',
                'donationDate' => '2026-11-11',
                'donationDateFormatted' => 'Nov 11, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'Central Medical Plaza',
                'staffMember' => 'Nurse Hannah Reed',
                'screeningStatus' => 'Pending',
                'screeningNotes' => 'Venous sample sent to serology lab; awaiting infectious disease panel sign-off.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Smooth 9-minute draw with stable vitals; unit sealed in cold-chain bag #CB-918.',
            ],
            [
                'id' => 'DON-4404',
                'donorId' => 'DNR-1043',
                'donationDate' => '2026-11-09',
                'donationDateFormatted' => 'Nov 09, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'University Health Campus',
                'staffMember' => 'Dr. Maya Lin',
                'screeningStatus' => 'Passed',
                'screeningNotes' => 'Completed full ELISA panel negative; confirmatory NAT passed.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Regular whole blood phlebotomy; post-donation recovery window triggered.',
            ],
            [
                'id' => 'DON-4403',
                'donorId' => 'DNR-1044',
                'donationDate' => '2026-11-04',
                'donationDateFormatted' => 'Nov 04, 2026',
                'quantityMl' => 250,
                'donationType' => 'Platelets',
                'collectionLocation' => 'Central Medical Plaza',
                'staffMember' => 'Dr. Tariq Nassar',
                'screeningStatus' => 'Passed',
                'screeningNotes' => 'Apheresis viral marker serology negative; bacterial culture cleared at 24h.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Apheresis cycle complete; yield 3.2 x 10^11 platelets verified.',
            ],
            [
                'id' => 'DON-4402',
                'donorId' => 'DNR-1048',
                'donationDate' => '2026-11-04',
                'donationDateFormatted' => 'Nov 04, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'Westgate Donor Suite',
                'staffMember' => 'Nurse Hannah Reed',
                'screeningStatus' => 'Pending',
                'screeningNotes' => 'Initial rapid screening non-reactive; batch forwarded for laboratory validation.',
                'collectionStatus' => 'Scheduled',
                'collectionNotes' => 'Scheduled donor confirmed attendance; intake station 2 assigned.',
            ],
            [
                'id' => 'DON-4401',
                'donorId' => 'DNR-1045',
                'donationDate' => '2026-11-03',
                'donationDateFormatted' => 'Nov 03, 2026',
                'quantityMl' => 550,
                'donationType' => 'Plasma',
                'collectionLocation' => 'Southgate Clinic',
                'staffMember' => 'Dr. Maya Lin',
                'screeningStatus' => 'Failed',
                'screeningNotes' => 'Temporary deferral triggered by recent travel screening guideline; re-evaluation logged.',
                'collectionStatus' => 'Cancelled',
                'collectionNotes' => 'Phlebotomy session cancelled prior to venipuncture per medical officer directive.',
            ],
            [
                'id' => 'DON-4310',
                'donorId' => 'DNR-1042',
                'donationDate' => '2026-10-28',
                'donationDateFormatted' => 'Oct 28, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'University Health Campus',
                'staffMember' => 'Nurse Clara Diaz',
                'screeningStatus' => 'Passed',
                'screeningNotes' => 'Hemoglobin 15.1 g/dL; serology and crossmatch checks verified clear.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Standard 450 ml whole blood unit collected and transferred to component lab.',
            ],
            [
                'id' => 'DON-4195',
                'donorId' => 'DNR-1047',
                'donationDate' => '2026-09-02',
                'donationDateFormatted' => 'Sep 02, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'University Health Campus',
                'staffMember' => 'Nurse Clara Diaz',
                'screeningStatus' => 'Passed',
                'screeningNotes' => 'Vitals normal (116/74 mmHg); infectious panel non-reactive.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Completed cleanly with post-donation observation completed in 15 minutes.',
            ],
            [
                'id' => 'DON-4150',
                'donorId' => 'DNR-1046',
                'donationDate' => '2026-08-18',
                'donationDateFormatted' => 'Aug 18, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'Northside Community Drive',
                'staffMember' => 'Dr. Tariq Nassar',
                'screeningStatus' => 'Failed',
                'screeningNotes' => 'Pre-donation capillary hemoglobin at 11.8 g/dL (below clinical threshold).',
                'collectionStatus' => 'Cancelled',
                'collectionNotes' => 'Session stopped at intake; donor advised on nutritional follow-up before rescheduling.',
            ],
            [
                'id' => 'DON-4088',
                'donorId' => 'DNR-1044',
                'donationDate' => '2026-08-05',
                'donationDateFormatted' => 'Aug 05, 2026',
                'quantityMl' => 250,
                'donationType' => 'Platelets',
                'collectionLocation' => 'Central Medical Plaza',
                'staffMember' => 'Nurse Hannah Reed',
                'screeningStatus' => 'Passed',
                'screeningNotes' => 'Pre-apheresis CBC and viral screen passed clinical verification.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Dual-arm plateletpheresis yielded 250 ml therapeutic unit.',
            ],
            [
                'id' => 'DON-4012',
                'donorId' => 'DNR-1041',
                'donationDate' => '2026-07-22',
                'donationDateFormatted' => 'Jul 22, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'Central Medical Plaza',
                'staffMember' => 'Dr. Maya Lin',
                'screeningStatus' => 'Passed',
                'screeningNotes' => 'Hemoglobin 13.9 g/dL; negative infectious screening panel cleared for universal RBC issue.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Whole blood draw completed within target window; transferred to quarantine release queue.',
            ],
        ];

        return array_map(function (array $entry) use ($donors): array {
            $donor = $donors[$entry['donorId']];

            return [
                ...$entry,
                'donorName' => $donor['name'],
                'donorEmail' => $donor['email'],
                'donorPhone' => $donor['phone'],
                'donorEligibilityStatus' => $donor['eligibilityStatus'],
                'bloodGroup' => $donor['bloodGroup'],
            ];
        }, $entries);
    }
}
