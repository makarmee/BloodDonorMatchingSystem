<?php

namespace App\Http\Controllers;

use App\Models\Donation;
use App\Models\ScreeningRecord;
use App\Models\ScreeningTestType;
use App\Models\User;
use App\Services\ScreeningService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScreeningController extends Controller
{
    public function __construct(
        private ScreeningService $screeningService,
    ) {}

    /**
     * Display the lab screening overview statistics and filterable sample queue.
     */
    public function index(Request $request): View
    {
        $this->authorizeScreeningAccess($request);

        $totalCount = ScreeningRecord::query()->count();

        if ($totalCount === 0) {
            $samples = self::sampleScreeningList();

            $summary = [
                'totalSamples' => count($samples),
                'pendingTests' => count(array_filter(
                    $samples,
                    fn (array $sample): bool => $sample['screeningResult'] === 'Pending'
                )),
                'passedSamples' => count(array_filter(
                    $samples,
                    fn (array $sample): bool => $sample['screeningResult'] === 'Passed'
                )),
                'failedSamples' => count(array_filter(
                    $samples,
                    fn (array $sample): bool => $sample['screeningResult'] === 'Failed'
                )),
            ];
        } else {
            $summary = [
                'totalSamples' => $totalCount,
                'pendingTests' => ScreeningRecord::query()->where('screening_result', 'Pending')->count(),
                'passedSamples' => ScreeningRecord::query()->where('screening_result', 'Passed')->count(),
                'failedSamples' => ScreeningRecord::query()->where('screening_result', 'Failed')->count(),
            ];

            $query = ScreeningRecord::query()
                ->with(['donation.donor', 'donation.bloodComponent', 'donation.primaryBloodUnit', 'testType', 'performedBy'])
                ->orderByDesc('test_date')
                ->orderByDesc('id');

            if ($request->filled('search')) {
                $term = trim((string) $request->query('search'));
                $query->where(function (Builder $b) use ($term): void {
                    $b->where('screening_code', 'like', "%{$term}%")
                        ->orWhere('lab_staff_name', 'like', "%{$term}%")
                        ->orWhereHas('donation', function (Builder $d) use ($term): void {
                            $d->where('donation_code', 'like', "%{$term}%")
                                ->orWhereHas('donor', function (Builder $dn) use ($term): void {
                                    $dn->where('full_name', 'like', "%{$term}%")
                                        ->orWhere('donor_code', 'like', "%{$term}%");
                                });
                        });
                });
            }

            if ($request->filled('blood_group') && $request->query('blood_group') !== 'all') {
                $bg = (string) $request->query('blood_group');
                $query->whereHas('donation', fn (Builder $d) => $d->where('blood_group', $bg));
            }

            $resultFilter = $request->query('result') ?? $request->query('screening_result');
            if ($resultFilter && $resultFilter !== 'all') {
                $query->where('screening_result', (string) $resultFilter);
            }

            if ($request->filled('test_type') && $request->query('test_type') !== 'all') {
                $tt = (string) $request->query('test_type');
                $query->whereHas('testType', fn (Builder $t) => $t->where('name', $tt));
            }

            if ($request->filled('date_from')) {
                $query->where('test_date', '>=', (string) $request->query('date_from'));
            }

            if ($request->filled('date_to')) {
                $query->where('test_date', '<=', (string) $request->query('date_to'));
            }

            $samples = $query->get()
                ->map(fn (ScreeningRecord $record): array => $this->formatScreeningRecord($record))
                ->all();
        }

        return view('screening.index', [
            'samples' => $samples,
            'summary' => $summary,
            'bloodGroups' => $this->bloodGroupOptions(),
            'testTypes' => $this->testTypeOptions(),
            'isSamplePreview' => $totalCount === 0,
        ]);
    }

    /**
     * Display the lab screening test recording form linked to sample donations.
     */
    public function create(Request $request): View
    {
        $this->authorizeScreeningAccess($request);

        $donations = Donation::query()
            ->with(['donor', 'bloodComponent'])
            ->where('collection_status', 'Completed')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Donation $d): array => [
                'id' => $d->donation_code,
                'donorId' => $d->donor?->donor_code ?? '—',
                'donorName' => $d->donor?->full_name ?? '—',
                'donorEmail' => $d->donor?->email ?? '',
                'donorPhone' => $d->donor?->phone ?? '',
                'bloodGroup' => $d->blood_group,
                'collectionDate' => $d->donation_date?->format('Y-m-d') ?? '',
                'collectionDateFormatted' => $d->donation_date?->format('M d, Y') ?? '',
                'donationType' => $d->bloodComponent?->name ?? 'Whole Blood',
                'quantityMl' => (int) $d->quantity_ml,
                'collectionLocation' => $d->collection_location_name ?? 'Main Center',
                'collectionStatus' => $d->collection_status,
            ])
            ->all();

        $hasPersistedDonations = ! empty($donations);

        if (! $hasPersistedDonations) {
            $donations = array_values($this->sampleDonationsById());
        }

        $testTypes = ScreeningTestType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if (empty($testTypes)) {
            $testTypes = $this->testTypeOptions();
        }

        return view('screening.create', [
            'donations' => $donations,
            'hasPersistedDonations' => $hasPersistedDonations,
            'testTypes' => $testTypes,
            'assayMethods' => ScreeningRecord::ASSAY_METHODS,
            'defaultAssayMethod' => ScreeningRecord::DEFAULT_ASSAY_METHOD,
            'labStaffSuggestions' => $this->labStaffSuggestions(),
        ]);
    }

    /**
     * Display a donation's complete screening panel, linked donation/donor details, and release readiness.
     */
    public function show(Request $request, Donation $donation): View
    {
        $this->authorizeScreeningAccess($request);

        $isPersistedDonation = $donation->donor_id !== null || array_key_exists('created_at', $donation->getAttributes());

        if ($isPersistedDonation) {
            $donation->load([
                'donor',
                'bloodComponent',
                'primaryBloodUnit',
                'approvedBy',
                'center',
                'camp',
                'screeningRecords' => fn ($query) => $query->with(['testType', 'performedBy'])->orderBy('id'),
            ]);

            $donor = $donation->donor;
            $screeningModels = $donation->screeningRecords;
            $records = $screeningModels
                ->map(fn (ScreeningRecord $record): array => $this->formatScreeningRecord($record, $donation))
                ->values()
                ->all();

            $primaryScreeningModel = $screeningModels->firstWhere('screening_result', 'Pending')
                ?? $screeningModels->first();

            $firstPendingOrFirst = $primaryScreeningModel
                ? $this->formatScreeningRecord($primaryScreeningModel, $donation)
                : null;

            $location = $donation->collection_location_name
                ?? $donation->center?->name
                ?? $donation->camp?->name
                ?? 'Main Center';

            $sample = [
                'recordId' => $firstPendingOrFirst['recordId'] ?? null,
                'id' => $firstPendingOrFirst['id'] ?? $donation->donation_code,
                'donationId' => $donation->donation_code,
                'donorId' => $donor?->donor_code ?? '—',
                'donorName' => $donor?->full_name ?? '—',
                'donorEmail' => $donor?->email ?? '',
                'donorPhone' => $donor?->phone ?? '',
                'bloodGroup' => $donation->blood_group ?? 'O+',
                'collectionDate' => $donation->donation_date?->format('Y-m-d') ?? '',
                'collectionDateFormatted' => $donation->donation_date?->format('M d, Y') ?? '',
                'donationType' => $donation->bloodComponent?->name ?? 'Whole Blood',
                'quantityMl' => (int) ($donation->quantity_ml ?? 450),
                'collectionLocation' => $location,
                'collectionStatus' => $donation->collection_status ?? 'Completed',
                'overallScreeningStatus' => $donation->screening_status ?? 'Pending',
                'primaryUnitCode' => $donation->primaryBloodUnit?->unit_code,
                'primaryUnitStatus' => $donation->primaryBloodUnit?->unit_status,
                'testTypeId' => $firstPendingOrFirst['testTypeId'] ?? null,
                'testDate' => $firstPendingOrFirst['testDate'] ?? null,
                'testDateFormatted' => $firstPendingOrFirst['testDateFormatted'] ?? 'Awaiting test',
                'testType' => $firstPendingOrFirst['testType'] ?? 'Pending Initialization',
                'screeningResult' => $firstPendingOrFirst['screeningResult'] ?? ($donation->screening_status ?? 'Pending'),
                'labStaff' => $firstPendingOrFirst['labStaff'] ?? ($donation->staff_member_name ?? 'Laboratory Staff'),
                'assayMethod' => $firstPendingOrFirst['assayMethod'] ?? ScreeningRecord::DEFAULT_ASSAY_METHOD,
                'specimenTube' => $firstPendingOrFirst['specimenTube'] ?? ($donation->primaryBloodUnit?->unit_code ?? 'S-PENDING'),
                'testNotes' => $firstPendingOrFirst['testNotes'] ?? '',
                'collectedAt' => $firstPendingOrFirst['collectedAt'] ?? ($donation->collected_at?->format('M d, Y • H:i') ?? '—'),
                'loggedAt' => $firstPendingOrFirst['loggedAt'] ?? '—',
                'resultRecordedAt' => $firstPendingOrFirst['resultRecordedAt'] ?? 'Pending verification',
            ];

            $readiness = $this->screeningService->evaluateReleaseReadiness($donation);

            return view('screening.show', [
                'sample' => $sample,
                'records' => $records,
                'screeningRecord' => $primaryScreeningModel,
                'donationModel' => $donation,
                'readiness' => $readiness,
                'sisterRecords' => $screeningModels,
                'assayMethods' => ScreeningRecord::ASSAY_METHODS,
            ]);
        }

        $sampleRecords = array_values(array_filter(
            self::sampleScreeningList(),
            fn (array $entry): bool => strcasecmp($entry['donationId'], (string) $donation->donation_code) === 0
        ));

        if ($sampleRecords === []) {
            abort(404);
        }

        $primarySample = $sampleRecords[0];
        $primarySample['overallScreeningStatus'] = $primarySample['screeningResult'];

        return view('screening.show', [
            'sample' => $primarySample,
            'records' => $sampleRecords,
            'screeningRecord' => null,
            'donationModel' => null,
            'readiness' => null,
            'sisterRecords' => collect(),
            'assayMethods' => ScreeningRecord::ASSAY_METHODS,
        ]);
    }

    /**
     * Record a new screening test result or update an existing pending record.
     */
    public function storeRecord(Request $request): RedirectResponse
    {
        $actor = $this->authorizeScreeningAccess($request);
        Gate::forUser($actor)->authorize('recordResult', ScreeningRecord::class);

        $validated = $request->validate([
            'donation_id' => ['required', 'string'],
            'test_type' => ['nullable', 'string'],
            'test_type_id' => ['nullable', 'integer'],
            'screening_result' => ['required', 'string', Rule::in(['Pending', 'Passed', 'Failed'])],
            'is_confirmatory' => ['nullable', 'boolean'],
            'test_date' => ['nullable', 'date'],
            'lab_staff' => ['nullable', 'string', 'max:150'],
            'lab_staff_name' => ['nullable', 'string', 'max:150'],
            'assay_method' => ['nullable', 'string', Rule::in(ScreeningRecord::ASSAY_METHODS)],
            'specimen_tube' => ['nullable', 'string', 'max:64'],
            'specimen_tube_code' => ['nullable', 'string', 'max:64'],
            'test_notes' => ['nullable', 'string', 'max:1000'],
            'screening_record_id' => ['nullable'],
        ]);

        $donation = Donation::query()
            ->where('donation_code', $validated['donation_id'])
            ->when(is_numeric($validated['donation_id']), fn ($q) => $q->orWhere('id', (int) $validated['donation_id']))
            ->firstOrFail();

        $testTypeId = $validated['test_type_id'] ?? null;
        if (! $testTypeId && ! empty($validated['test_type'])) {
            $testTypeId = ScreeningTestType::query()
                ->where('name', $validated['test_type'])
                ->orWhere('code', $validated['test_type'])
                ->value('id');
        }

        $attributes = [
            'screening_record_id' => $validated['screening_record_id'] ?? null,
            'test_type_id' => $testTypeId,
            'test_type_code' => empty($testTypeId) ? ($validated['test_type'] ?? null) : null,
            'screening_result' => $validated['screening_result'],
            'is_confirmatory' => (bool) ($validated['is_confirmatory'] ?? false),
            'test_date' => $validated['test_date'] ?? now()->toDateString(),
            'lab_staff_name' => $validated['lab_staff_name'] ?? $validated['lab_staff'] ?? $actor->name,
            'assay_method' => $validated['assay_method'] ?? ScreeningRecord::DEFAULT_ASSAY_METHOD,
            'specimen_tube_code' => $validated['specimen_tube_code'] ?? $validated['specimen_tube'] ?? null,
            'test_notes' => $validated['test_notes'] ?? null,
        ];

        $record = $this->screeningService->recordScreeningResult($donation, $actor, $attributes);

        return redirect()
            ->route('screening.show', $donation)
            ->with('success', __('Screening result recorded as :result for :code.', [
                'result' => __($record->screening_result),
                'code' => $donation->donation_code,
            ]));
    }

    /**
     * Release a passed donation and its primary blood unit into available inventory.
     */
    public function releaseToInventory(Request $request, Donation $donation): RedirectResponse
    {
        $actor = $this->authorizeScreeningAccess($request);
        Gate::forUser($actor)->authorize('releaseToInventory', $donation);

        $validated = $request->validate([
            'screening_notes' => ['nullable', 'string', 'max:1000'],
            'storage_location' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $released = $this->screeningService->releaseToInventory($donation, $actor, $validated);

            return redirect()
                ->route('donations.show', $released)
                ->with('success', __('Donation :code and quarantined blood unit released into available inventory.', [
                    'code' => $released->donation_code,
                ]));
        } catch (ValidationException $e) {
            return redirect()
                ->back()
                ->withErrors($e->errors())
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Authorize that the acting user has laboratory officer or staff rights.
     */
    private function authorizeScreeningAccess(Request $request): User
    {
        /** @var User|null $user */
        $user = $request->user();

        if (
            ! $user
            || ! $user->is_active
            || (! $user->hasAnyRole(['admin', 'staff', 'lab_officer']) && ! $user->hasPermission('screening.record') && ! $user->hasPermission('screening.manage'))
        ) {
            abort(403, 'Unauthorized access to laboratory screening management.');
        }

        return $user;
    }

    /**
     * Format a ScreeningRecord model and its parent donation context for UI rendering.
     *
     * @return array<string, mixed>
     */
    private function formatScreeningRecord(ScreeningRecord $record, ?Donation $donation = null): array
    {
        $donation ??= $record->donation;
        $donor = $donation?->donor;

        $location = $donation?->collection_location_name
            ?? $donation?->center?->name
            ?? $donation?->camp?->name
            ?? 'Main Center';

        return [
            'recordId' => $record->id,
            'id' => $record->screening_code,
            'donationId' => $donation?->donation_code ?? '—',
            'donorId' => $donor?->donor_code ?? '—',
            'donorName' => $donor?->full_name ?? '—',
            'donorEmail' => $donor?->email ?? '',
            'donorPhone' => $donor?->phone ?? '',
            'bloodGroup' => $donation?->blood_group ?? 'O+',
            'collectionDate' => $donation?->donation_date?->format('Y-m-d') ?? '',
            'collectionDateFormatted' => $donation?->donation_date?->format('M d, Y') ?? '',
            'donationType' => $donation?->bloodComponent?->name ?? 'Whole Blood',
            'quantityMl' => (int) ($donation?->quantity_ml ?? 450),
            'collectionLocation' => $location,
            'collectionStatus' => $donation?->collection_status ?? 'Completed',
            'testTypeId' => $record->test_type_id,
            'testDate' => $record->test_date?->format('Y-m-d'),
            'testDateFormatted' => $record->test_date?->format('M d, Y') ?? 'Awaiting test',
            'testType' => $record->testType?->name ?? 'HIV',
            'testTypeCode' => $record->testType?->code ?? '',
            'screeningResult' => $record->screening_result,
            'isConfirmatory' => (bool) $record->is_confirmatory,
            'labStaff' => $record->lab_staff_name ?? $record->performedBy?->name ?? 'Laboratory Staff',
            'assayMethod' => $record->assay_method ?? ScreeningRecord::DEFAULT_ASSAY_METHOD,
            'specimenTube' => $record->specimen_tube_code ?? ($donation?->primaryBloodUnit?->unit_code ?? 'S-PENDING'),
            'testNotes' => $record->test_notes ?? '',
            'collectedAt' => $record->collected_at?->format('M d, Y • H:i') ?? $donation?->collected_at?->format('M d, Y • H:i') ?? '—',
            'loggedAt' => $record->logged_at?->format('M d, Y • H:i') ?? '—',
            'resultRecordedAt' => $record->result_recorded_at?->format('M d, Y • H:i') ?? 'Pending verification',
        ];
    }

    /**
     * Standard ABO/Rh blood group options.
     *
     * @return array<int, string>
     */
    private function bloodGroupOptions(): array
    {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }

    /**
     * Standard blood bank infectious & compatibility test panels.
     *
     * @return array<int, string>
     */
    private function testTypeOptions(): array
    {
        return [
            'HIV',
            'Hepatitis B',
            'Hepatitis C',
            'Syphilis',
            'Blood Group Compatibility',
        ];
    }

    /**
     * Laboratory technicians and medical officers for form suggestions.
     *
     * @return array<int, string>
     */
    private function labStaffSuggestions(): array
    {
        return [
            'Dr. Priya Nair (Serology)',
            'Dr. Elena Vance (Microbiology)',
            'Tech. Samuel Brooks',
            'Tech. Nadia Chen',
        ];
    }

    /**
     * Sample donations synchronized with DonationsController & DonorsController records.
     *
     * @return array<string, array{
     *     id: string,
     *     donorId: string,
     *     donorName: string,
     *     donorEmail: string,
     *     donorPhone: string,
     *     bloodGroup: string,
     *     collectionDate: string,
     *     collectionDateFormatted: string,
     *     donationType: string,
     *     quantityMl: int,
     *     collectionLocation: string,
     *     collectionStatus: string
     * }>
     */
    /**
     * Sample donations synchronized with DonationsController & DonorsController records.
     *
     * @return array<string, array{
     *     id: string,
     *     donorId: string,
     *     donorName: string,
     *     donorEmail: string,
     *     donorPhone: string,
     *     bloodGroup: string,
     *     collectionDate: string,
     *     collectionDateFormatted: string,
     *     donationType: string,
     *     quantityMl: int,
     *     collectionLocation: string,
     *     collectionStatus: string
     * }>
     */
    public static function sampleDonationsById(): array
    {
        $records = [
            [
                'id' => 'DON-4405',
                'donorId' => 'DNR-1041',
                'donorName' => 'Elena Rostova',
                'donorEmail' => 'elena.rostova@example.org',
                'donorPhone' => '+1 (555) 234-8812',
                'bloodGroup' => 'O-',
                'collectionDate' => '2026-11-11',
                'collectionDateFormatted' => 'Nov 11, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'Central Medical Plaza',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4404',
                'donorId' => 'DNR-1042',
                'donorName' => 'Marcus Vance',
                'donorEmail' => 'marcus.vance@example.org',
                'donorPhone' => '+1 (555) 391-4420',
                'bloodGroup' => 'A+',
                'collectionDate' => '2026-10-28',
                'collectionDateFormatted' => 'Oct 28, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'University Health Campus',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4403',
                'donorId' => 'DNR-1044',
                'donorName' => 'David Kim',
                'donorEmail' => 'david.kim@example.org',
                'donorPhone' => '+1 (555) 784-1933',
                'bloodGroup' => 'AB-',
                'collectionDate' => '2026-11-04',
                'collectionDateFormatted' => 'Nov 04, 2026',
                'donationType' => 'Platelets',
                'quantityMl' => 250,
                'collectionLocation' => 'Central Medical Plaza',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4402',
                'donorId' => 'DNR-1045',
                'donorName' => 'Amina Hassan',
                'donorEmail' => 'amina.hassan@example.org',
                'donorPhone' => '+1 (555) 845-3091',
                'bloodGroup' => 'O+',
                'collectionDate' => '2026-11-18',
                'collectionDateFormatted' => 'Nov 18, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'Southgate Clinic',
                'collectionStatus' => 'Scheduled',
            ],
            [
                'id' => 'DON-4401',
                'donorId' => 'DNR-1047',
                'donorName' => 'Sofia Alvarez',
                'donorEmail' => 'sofia.alvarez@example.org',
                'donorPhone' => '+1 (555) 923-5510',
                'bloodGroup' => 'AB+',
                'collectionDate' => '2026-09-02',
                'collectionDateFormatted' => 'Sep 02, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'University Health Campus',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4310',
                'donorId' => 'DNR-1042',
                'donorName' => 'Marcus Vance',
                'donorEmail' => 'marcus.vance@example.org',
                'donorPhone' => '+1 (555) 391-4420',
                'bloodGroup' => 'A+',
                'collectionDate' => '2026-10-28',
                'collectionDateFormatted' => 'Oct 28, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'University Health Campus',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4195',
                'donorId' => 'DNR-1047',
                'donorName' => 'Sofia Alvarez',
                'donorEmail' => 'sofia.alvarez@example.org',
                'donorPhone' => '+1 (555) 923-5510',
                'bloodGroup' => 'AB+',
                'collectionDate' => '2026-09-02',
                'collectionDateFormatted' => 'Sep 02, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'University Health Campus',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4150',
                'donorId' => 'DNR-1046',
                'donorName' => 'Lucas Bennett',
                'donorEmail' => 'lucas.bennett@example.org',
                'donorPhone' => '+1 (555) 490-2218',
                'bloodGroup' => 'A-',
                'collectionDate' => '2026-08-18',
                'collectionDateFormatted' => 'Aug 18, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'Northside Community Drive',
                'collectionStatus' => 'Cancelled',
            ],
            [
                'id' => 'DON-4088',
                'donorId' => 'DNR-1044',
                'donorName' => 'David Kim',
                'donorEmail' => 'david.kim@example.org',
                'donorPhone' => '+1 (555) 784-1933',
                'bloodGroup' => 'AB-',
                'collectionDate' => '2026-08-05',
                'collectionDateFormatted' => 'Aug 05, 2026',
                'donationType' => 'Platelets',
                'quantityMl' => 250,
                'collectionLocation' => 'Central Medical Plaza',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4012',
                'donorId' => 'DNR-1041',
                'donorName' => 'Elena Rostova',
                'donorEmail' => 'elena.rostova@example.org',
                'donorPhone' => '+1 (555) 234-8812',
                'bloodGroup' => 'O-',
                'collectionDate' => '2026-07-22',
                'collectionDateFormatted' => 'Jul 22, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'Central Medical Plaza',
                'collectionStatus' => 'Completed',
            ],
        ];

        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record['id']] = $record;
        }

        return $indexed;
    }

    public function sampleScreenings(): array
    {
        return self::sampleScreeningList();
    }

    public static function sampleScreeningList(): array
    {
        $donations = self::sampleDonationsById();

        $entries = [
            [
                'id' => 'SCR-7105',
                'donationId' => 'DON-4405',
                'testDate' => '2026-11-11',
                'testDateFormatted' => 'Nov 11, 2026',
                'testType' => 'HIV',
                'screeningResult' => 'Pending',
                'labStaff' => 'Dr. Priya Nair (Serology)',
                'assayMethod' => '4th-Gen HIV-1/2 Ag/Ab Chemiluminescent Immunoassay & NAT',
                'specimenTube' => 'EDTA-K2 #T-9941A',
                'testNotes' => 'Sample loaded onto serology analyzer batch #B-412; awaiting NAT multiplex completion.',
                'collectedAt' => 'Nov 11, 2026 • 09:15',
                'loggedAt' => 'Nov 11, 2026 • 10:05',
                'resultRecordedAt' => 'Pending verification',
            ],
            [
                'id' => 'SCR-7104',
                'donationId' => 'DON-4405',
                'testDate' => '2026-11-11',
                'testDateFormatted' => 'Nov 11, 2026',
                'testType' => 'Hepatitis B',
                'screeningResult' => 'Pending',
                'labStaff' => 'Dr. Priya Nair (Serology)',
                'assayMethod' => 'HBsAg / Anti-HBc Total Immunoassay + HBV DNA NAT',
                'specimenTube' => 'EDTA-K2 #T-9941B',
                'testNotes' => 'Queued alongside morning whole blood unit from Central Medical Plaza; final readout pending.',
                'collectedAt' => 'Nov 11, 2026 • 09:15',
                'loggedAt' => 'Nov 11, 2026 • 10:08',
                'resultRecordedAt' => 'Pending verification',
            ],
            [
                'id' => 'SCR-7103',
                'donationId' => 'DON-4404',
                'testDate' => null,
                'testDateFormatted' => 'Awaiting test',
                'testType' => 'Blood Group Compatibility',
                'screeningResult' => 'Pending',
                'labStaff' => 'Tech. Samuel Brooks',
                'assayMethod' => 'Forward/Reverse ABO-Rh Tube Typing & Unexpected Antibody Screen',
                'specimenTube' => 'Serum Separator #S-8830',
                'testNotes' => 'Pre-apheresis confirmation scheduled prior to Plasma unit collection at Westgate Donor Suite.',
                'collectedAt' => 'Nov 10, 2026 • 14:20',
                'loggedAt' => 'Nov 10, 2026 • 14:45',
                'resultRecordedAt' => 'Pending verification',
            ],
            [
                'id' => 'SCR-7102',
                'donationId' => 'DON-4403',
                'testDate' => '2026-11-09',
                'testDateFormatted' => 'Nov 09, 2026',
                'testType' => 'Hepatitis C',
                'screeningResult' => 'Passed',
                'labStaff' => 'Dr. Priya Nair (Serology)',
                'assayMethod' => 'Anti-HCV CMIA & Individual-Donor HCV RNA NAT',
                'specimenTube' => 'EDTA-K2 #T-9822',
                'testNotes' => 'Non-reactive across duplicate immunoassay wells and negative on HCV RNA amplification.',
                'collectedAt' => 'Nov 08, 2026 • 11:30',
                'loggedAt' => 'Nov 08, 2026 • 13:10',
                'resultRecordedAt' => 'Nov 09, 2026 • 08:40',
            ],
            [
                'id' => 'SCR-7101',
                'donationId' => 'DON-4402',
                'testDate' => null,
                'testDateFormatted' => 'Awaiting test',
                'testType' => 'Syphilis',
                'screeningResult' => 'Pending',
                'labStaff' => 'Tech. Nadia Chen',
                'assayMethod' => 'Treponemal IgG/IgM Enzyme Immunoassay',
                'specimenTube' => 'Plain Clot #C-7714',
                'testNotes' => 'Baseline sample accessioned for first-time donor pre-collection clearance.',
                'collectedAt' => 'Nov 06, 2026 • 10:00',
                'loggedAt' => 'Nov 06, 2026 • 10:35',
                'resultRecordedAt' => 'Pending verification',
            ],
            [
                'id' => 'SCR-7100',
                'donationId' => 'DON-4401',
                'testDate' => '2026-11-04',
                'testDateFormatted' => 'Nov 04, 2026',
                'testType' => 'Hepatitis B',
                'screeningResult' => 'Failed',
                'labStaff' => 'Dr. Elena Vance (Microbiology)',
                'assayMethod' => 'HBsAg / Anti-HBc Confirmatory Neutralization Panel',
                'specimenTube' => 'EDTA-K2 #T-9608',
                'testNotes' => 'Repeat reactive Anti-HBc screening signal observed; unit quarantined and flagged for confirmatory medical review.',
                'collectedAt' => 'Nov 03, 2026 • 15:05',
                'loggedAt' => 'Nov 03, 2026 • 16:12',
                'resultRecordedAt' => 'Nov 04, 2026 • 11:25',
            ],
            [
                'id' => 'SCR-7099',
                'donationId' => 'DON-4310',
                'testDate' => '2026-10-29',
                'testDateFormatted' => 'Oct 29, 2026',
                'testType' => 'HIV',
                'screeningResult' => 'Passed',
                'labStaff' => 'Tech. Samuel Brooks',
                'assayMethod' => 'HIV-1/2 Ag/Ab Multiplex CMIA & NAT',
                'specimenTube' => 'EDTA-K2 #T-9419',
                'testNotes' => 'All quality control calibrators passed; sample non-reactive for HIV-1, HIV-2, and p24 antigen.',
                'collectedAt' => 'Oct 28, 2026 • 13:45',
                'loggedAt' => 'Oct 28, 2026 • 15:20',
                'resultRecordedAt' => 'Oct 29, 2026 • 09:10',
            ],
            [
                'id' => 'SCR-7098',
                'donationId' => 'DON-4195',
                'testDate' => '2026-09-03',
                'testDateFormatted' => 'Sep 03, 2026',
                'testType' => 'Syphilis',
                'screeningResult' => 'Passed',
                'labStaff' => 'Tech. Nadia Chen',
                'assayMethod' => 'Automated Treponema Pallidum Particle Agglutination',
                'specimenTube' => 'Serum Separator #S-8120',
                'testNotes' => 'Non-reactive result verified; no prozone interference observed.',
                'collectedAt' => 'Sep 02, 2026 • 12:15',
                'loggedAt' => 'Sep 02, 2026 • 14:00',
                'resultRecordedAt' => 'Sep 03, 2026 • 10:15',
            ],
            [
                'id' => 'SCR-7097',
                'donationId' => 'DON-4150',
                'testDate' => '2026-08-18',
                'testDateFormatted' => 'Aug 18, 2026',
                'testType' => 'Blood Group Compatibility',
                'screeningResult' => 'Failed',
                'labStaff' => 'Dr. Elena Vance (Microbiology)',
                'assayMethod' => 'Atypical Antibody Identification Panel & Pre-Screen Hematology',
                'specimenTube' => 'EDTA-K2 #T-8891',
                'testNotes' => 'Positive indirect antiglobulin screen with concurrent low capillary hemoglobin deferral; unit not cleared for transfusion.',
                'collectedAt' => 'Aug 18, 2026 • 09:40',
                'loggedAt' => 'Aug 18, 2026 • 11:05',
                'resultRecordedAt' => 'Aug 18, 2026 • 16:30',
            ],
            [
                'id' => 'SCR-7096',
                'donationId' => 'DON-4012',
                'testDate' => '2026-07-23',
                'testDateFormatted' => 'Jul 23, 2026',
                'testType' => 'Hepatitis C',
                'screeningResult' => 'Passed',
                'labStaff' => 'Dr. Priya Nair (Serology)',
                'assayMethod' => 'Anti-HCV CMIA & Individual-Donor NAT',
                'specimenTube' => 'EDTA-K2 #T-8504',
                'testNotes' => 'Negative across serological and nucleic acid screening assays; sample verified.',
                'collectedAt' => 'Jul 22, 2026 • 10:30',
                'loggedAt' => 'Jul 22, 2026 • 12:15',
                'resultRecordedAt' => 'Jul 23, 2026 • 09:50',
            ],
        ];

        return array_map(function (array $entry) use ($donations): array {
            $donation = $donations[$entry['donationId']];

            return [
                ...$entry,
                'donorId' => $donation['donorId'],
                'donorName' => $donation['donorName'],
                'donorEmail' => $donation['donorEmail'],
                'donorPhone' => $donation['donorPhone'],
                'bloodGroup' => $donation['bloodGroup'],
                'collectionDate' => $donation['collectionDate'],
                'collectionDateFormatted' => $donation['collectionDateFormatted'],
                'donationType' => $donation['donationType'],
                'quantityMl' => $donation['quantityMl'],
                'collectionLocation' => $donation['collectionLocation'],
                'collectionStatus' => $donation['collectionStatus'],
            ];
        }, $entries);
    }
}
