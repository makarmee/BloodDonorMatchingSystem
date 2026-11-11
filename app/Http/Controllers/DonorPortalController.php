<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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
        $donor = $this->resolveSampleDonor($request->query('donor'));
        $profile = $this->buildProfileSummary($donor);
        $eligibility = $this->resolveEligibilitySummary($donor);
        $donations = $this->buildDonorDonations($donor);
        $appointments = $this->buildDonorAppointments($donor['id']);
        $camps = $this->buildDonorCamps($donor['id']);

        $upcomingAppointments = array_values(array_filter(
            $appointments,
            fn (array $apt): bool => in_array($apt['status'], ['Pending', 'Confirmed'], true)
                && $apt['date'] >= self::TODAY_ISO
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
            'todayIso' => self::TODAY_ISO,
        ]);
    }

    /**
     * Display donor appointments directory with upcoming/past views, filters, and preview cancellation.
     */
    public function appointments(Request $request): View
    {
        $donor = $this->resolveSampleDonor($request->query('donor'));
        $profile = $this->buildProfileSummary($donor);
        $appointments = $this->buildDonorAppointments($donor['id']);

        return view('donor-portal.appointments.index', [
            'donorProfile' => $profile,
            'appointments' => $appointments,
            'statuses' => ['Pending', 'Confirmed', 'Completed', 'Cancelled'],
            'todayIso' => self::TODAY_ISO,
        ]);
    }

    /**
     * Display the donor appointment booking form connected to sample camps, centers, and time slots.
     */
    public function createAppointment(Request $request): View
    {
        $donor = $this->resolveSampleDonor($request->query('donor'));
        $profile = $this->buildProfileSummary($donor);
        $eligibility = $this->resolveEligibilitySummary($donor);
        $appointments = $this->buildDonorAppointments($donor['id']);
        $camps = $this->buildDonorCamps($donor['id']);
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
            'todayIso' => self::TODAY_ISO,
        ]);
    }

    /**
     * Display donor-safe donation history with search, filtering, and linked screening summary.
     */
    public function history(Request $request): View
    {
        $donor = $this->resolveSampleDonor($request->query('donor'));
        $profile = $this->buildProfileSummary($donor);
        $donations = $this->buildDonorDonations($donor);

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
        $donor = $this->resolveSampleDonor($request->query('donor'));
        $profile = $this->buildProfileSummary($donor);
        $eligibility = $this->resolveEligibilitySummary($donor);

        return view('donor-portal.eligibility', [
            'donorProfile' => $profile,
            'eligibility' => $eligibility,
        ]);
    }

    /**
     * Normalize donor eligibility status strictly from explicit sample attributes without auto-calculating clearance.
     *
     * @param  array<string, mixed>  $donor
     * @param  array<string, mixed>  $meta
     * @return array{
     *     status: string,
     *     lastReviewedDate: ?string,
     *     nextEligibleDate: ?string,
     *     reason: string,
     *     medicalConfirmationRequired: string,
     *     administrativeFacts: array<int, array{label: string, value: string}>
     * }
     */
    public function normalizeEligibilitySummary(array $donor, array $meta = []): array
    {
        $allowedStatuses = ['Eligible', 'Not Eligible', 'Pending Review', 'Unknown'];
        $rawStatus = $donor['eligibilityStatus'] ?? null;
        $status = is_string($rawStatus) && in_array($rawStatus, $allowedStatuses, true)
            ? $rawStatus
            : 'Unknown';

        $defaultReasons = [
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

        return [
            'status' => $status,
            'lastReviewedDate' => $meta['lastReviewedDate'] ?? null,
            'nextEligibleDate' => $meta['nextEligibleDate'] ?? null,
            'reason' => $reason,
            'medicalConfirmationRequired' => 'Final donation eligibility must be confirmed on-site by authorized blood bank medical staff (vital signs, hemoglobin check, and health screening).',
            'administrativeFacts' => [
                ['label' => 'Donor Account Status', 'value' => (string) ($donor['accountStatus'] ?? 'Unknown')],
                ['label' => 'Logged Completed Donations', 'value' => (string) ($donor['totalDonations'] ?? 0)],
                ['label' => 'Last Recorded Donation Date', 'value' => (string) ($donor['lastDonationDate'] ?? 'Unavailable')],
                ['label' => 'Clearance Source', 'value' => $meta['clearanceSource'] ?? ($status === 'Unknown' ? 'No medical clearance record available' : 'Synchronized BDMS Donor Record')],
            ],
        ];
    }

    /**
     * Resolve active sample donor (defaults to DNR-1041) or abort 404 for unknown donor IDs.
     *
     * @return array<string, mixed>
     */
    private function resolveSampleDonor(?string $requestedDonorId): array
    {
        $targetId = is_string($requestedDonorId) && trim($requestedDonorId) !== ''
            ? trim($requestedDonorId)
            : self::DEFAULT_DONOR_ID;

        foreach ($this->donorsController->sampleDonors() as $record) {
            if (strcasecmp($record['id'], $targetId) === 0) {
                return $record;
            }
        }

        abort(404);
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
     * @param  array<string, mixed>  $donor
     * @param  array<int, array<string, mixed>>  $donations
     */
    private function resolveLastDonationDisplay(array $donor, array $donations): string
    {
        foreach ($donations as $donation) {
            if ($donation['collectionStatus'] === 'Completed') {
                return (string) $donation['donationDateFormatted'];
            }
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
     * Build realistic sample time slots (12:00 PM pre-filled to 3/3 to model full-slot rule enforcement).
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
                    'prepSummary' => 'Cancelled slots do not count against facility capacity.',
                ],
            ],
            'DNR-1042' => [
                [
                    'id' => 'APT-2012',
                    'donorId' => 'DNR-1042',
                    'donationType' => 'Whole Blood',
                    'date' => '2026-10-28',
                    'dateFormatted' => 'Oct 28, 2026',
                    'time' => '10:30 AM',
                    'location' => 'University Health Campus',
                    'locationId' => 'CMP-307',
                    'relatedCampId' => null,
                    'relatedCampName' => null,
                    'status' => 'Completed',
                    'notes' => 'Completed scheduled donation visit (DON-4310).',
                    'prepSummary' => 'Completed visit archived.',
                ],
            ],
            'DNR-1043' => [
                [
                    'id' => 'APT-2039',
                    'donorId' => 'DNR-1043',
                    'donationType' => 'Whole Blood',
                    'date' => '2026-11-12',
                    'dateFormatted' => 'Nov 12, 2026',
                    'time' => '09:00 AM',
                    'location' => 'Student Union Atrium (University District)',
                    'locationId' => 'CMP-307',
                    'relatedCampId' => 'CMP-307',
                    'relatedCampName' => 'University Health Campus Autumn Drive',
                    'status' => 'Pending',
                    'notes' => 'First-time appointment pending on-site eligibility verification.',
                    'prepSummary' => 'Arrive 15 minutes early for initial donor screening verification.',
                ],
            ],
        ];

        return $catalog[$donorId] ?? [];
    }

    private function donorSafeDonationSummary(string $collectionStatus, string $screeningStatus): string
    {
        if ($collectionStatus === 'Cancelled') {
            return 'This scheduled donation session was stopped prior to release. Contact medical staff if guidance is needed.';
        }

        if ($screeningStatus === 'Passed') {
            return 'Collection completed and standard laboratory safety checks passed.';
        }

        return 'Collection recorded; routine post-donation laboratory screening is currently pending.';
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        [$local, $domain] = $parts;
        $prefix = substr($local, 0, min(2, strlen($local)));

        return "{$prefix}***@{$domain}";
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '***';
        }

        $lastFour = substr($digits, -4);

        return '+1 (***) ***-'.$lastFour;
    }
}
