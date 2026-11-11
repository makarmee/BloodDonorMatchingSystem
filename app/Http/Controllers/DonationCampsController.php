<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class DonationCampsController extends Controller
{
    /**
     * Display the donation camps overview cards and filterable camps listing.
     */
    public function index(): View
    {
        $camps = $this->sampleCamps();

        $locations = collect($camps)
            ->pluck('city')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $summary = [
            'totalCamps' => count($camps),
            'upcomingCamps' => count(array_filter(
                $camps,
                fn (array $camp): bool => $camp['status'] === 'Upcoming'
            )),
            'ongoingCamps' => count(array_filter(
                $camps,
                fn (array $camp): bool => $camp['status'] === 'Ongoing'
            )),
            'completedCamps' => count(array_filter(
                $camps,
                fn (array $camp): bool => $camp['status'] === 'Completed'
            )),
            'totalRegistrations' => array_sum(array_column($camps, 'registeredDonors')),
            'totalCapacity' => array_sum(array_column($camps, 'capacity')),
        ];

        return view('camps.index', [
            'camps' => $camps,
            'summary' => $summary,
            'statuses' => $this->statusOptions(),
            'locations' => $locations,
        ]);
    }

    /**
     * Display the create donation camp form.
     */
    public function create(): View
    {
        return view('camps.create', [
            'statuses' => $this->statusOptions(),
        ]);
    }

    /**
     * Display detailed camp overview, capacity progress, registrations, registration manager, and history.
     */
    public function show(string $camp): View
    {
        $campRecord = $this->findCampOrFail($camp);

        return view('camps.show', [
            'camp' => $campRecord,
            'donors' => $this->sampleDonors(),
            'attendanceStatuses' => $this->attendanceStatusOptions(),
            'campStatuses' => $this->statusOptions(),
        ]);
    }

    /**
     * Display the edit camp form prefilled with sample data while preserving registrations.
     */
    public function edit(string $camp): View
    {
        $campRecord = $this->findCampOrFail($camp);

        return view('camps.edit', [
            'camp' => $campRecord,
            'statuses' => $this->statusOptions(),
        ]);
    }

    /**
     * Find a camp record by code or abort with HTTP 404.
     *
     * @return array<string, mixed>
     */
    private function findCampOrFail(string $campId): array
    {
        foreach ($this->sampleCamps() as $record) {
            if (strcasecmp($record['id'], $campId) === 0) {
                return $record;
            }
        }

        abort(404);
    }

    /**
     * Supported lifecycle statuses for donation camps.
     *
     * @return array<int, string>
     */
    private function statusOptions(): array
    {
        return ['Draft', 'Upcoming', 'Ongoing', 'Completed', 'Cancelled'];
    }

    /**
     * Supported donor attendance statuses per camp registration.
     *
     * @return array<int, string>
     */
    private function attendanceStatusOptions(): array
    {
        return ['Registered', 'Confirmed', 'Attended', 'No-show', 'Cancelled'];
    }

    /**
     * Sample donor dataset aligned with DonorsController (DNR-1041 through DNR-1048).
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     bloodGroup: string,
     *     phone: string,
     *     email: string
     * }>
     */
    private function sampleDonors(): array
    {
        return [
            ['id' => 'DNR-1041', 'name' => 'Elena Rostova', 'bloodGroup' => 'O-', 'phone' => '+1 (555) 234-8812', 'email' => 'elena.rostova@example.org'],
            ['id' => 'DNR-1042', 'name' => 'Marcus Vance', 'bloodGroup' => 'A+', 'phone' => '+1 (555) 391-4420', 'email' => 'marcus.vance@example.org'],
            ['id' => 'DNR-1043', 'name' => 'Priya Sharma', 'bloodGroup' => 'B+', 'phone' => '+1 (555) 612-7745', 'email' => 'priya.sharma@example.org'],
            ['id' => 'DNR-1044', 'name' => 'David Kim', 'bloodGroup' => 'AB-', 'phone' => '+1 (555) 784-1933', 'email' => 'david.kim@example.org'],
            ['id' => 'DNR-1045', 'name' => 'Amina Hassan', 'bloodGroup' => 'O+', 'phone' => '+1 (555) 845-3091', 'email' => 'amina.hassan@example.org'],
            ['id' => 'DNR-1046', 'name' => 'Lucas Bennett', 'bloodGroup' => 'A-', 'phone' => '+1 (555) 490-2218', 'email' => 'lucas.bennett@example.org'],
            ['id' => 'DNR-1047', 'name' => 'Sofia Alvarez', 'bloodGroup' => 'AB+', 'phone' => '+1 (555) 923-5510', 'email' => 'sofia.alvarez@example.org'],
            ['id' => 'DNR-1048', 'name' => 'Omar Al-Mansoor', 'bloodGroup' => 'B-', 'phone' => '+1 (555) 673-8044', 'email' => 'omar.mansoor@example.org'],
        ];
    }

    /**
     * Build 8 realistic sample donation camps across all lifecycle statuses.
     *
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     description: string,
     *     organizerName: string,
     *     organizerContact: string,
     *     organizerEmail: string,
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
     *     additionalNotes: string,
     *     status: string,
     *     registrations: array<int, array{id: string, donorId: string, donorName: string, bloodGroup: string, registrationDate: string, attendanceStatus: string}>,
     *     history: array<int, array{date: string, event: string, actor: string, notes: string}>
     * }>
     */
    private function sampleCamps(): array
    {
        $donors = collect($this->sampleDonors())->keyBy('id');

        $makeRegistration = function (string $regId, string $donorId, string $regDate, string $status) use ($donors): array {
            $donor = $donors->get($donorId);

            return [
                'id' => $regId,
                'donorId' => $donor['id'],
                'donorName' => $donor['name'],
                'bloodGroup' => $donor['bloodGroup'],
                'registrationDate' => $regDate,
                'attendanceStatus' => $status,
            ];
        };

        $entries = [
            [
                'id' => 'CMP-308',
                'name' => 'Central Plaza Community Blood Drive',
                'description' => 'Flagship metro public blood collection drive featuring 6 mobile phlebotomy stations and on-site hemoglobin triage.',
                'organizerName' => 'Elena Martinez',
                'organizerContact' => '+1 (555) 210-8840',
                'organizerEmail' => 'elena.martinez@metrohealth.org',
                'venueName' => 'Central Medical Plaza Pavilion',
                'fullAddress' => '400 Civic Center Way, Concourse B',
                'city' => 'Metro Center',
                'locationNotes' => 'Validated donor parking in North Garage; wheelchair ramp at East entrance.',
                'startDate' => '2026-11-18',
                'startDateFormatted' => 'Nov 18, 2026',
                'endDate' => '2026-11-18',
                'endDateFormatted' => 'Nov 18, 2026',
                'startTime' => '09:00',
                'endTime' => '16:00',
                'capacity' => 5,
                'registrationDeadline' => '2026-11-16',
                'registrationDeadlineFormatted' => 'Nov 16, 2026',
                'allowWalkIn' => true,
                'donorRequirements' => 'Government-issued photo ID required; light meal and 500 ml water recommended prior to intake.',
                'additionalNotes' => 'Priority O-negative and B-negative donor outreach active (1 cancelled slot excludes from capacity).',
                'status' => 'Upcoming',
                'registrations' => [
                    $makeRegistration('REG-801', 'DNR-1041', '2026-11-08', 'Confirmed'),
                    $makeRegistration('REG-802', 'DNR-1044', '2026-11-09', 'Confirmed'),
                    $makeRegistration('REG-803', 'DNR-1047', '2026-11-09', 'Registered'),
                    $makeRegistration('REG-804', 'DNR-1048', '2026-11-10', 'Registered'),
                    $makeRegistration('REG-805', 'DNR-1046', '2026-11-10', 'Cancelled'),
                ],
                'history' => [
                    ['date' => 'Nov 10, 2026 • 14:20', 'event' => 'Donor Registered', 'actor' => 'Omar Al-Mansoor (DNR-1048)', 'notes' => 'Registered for morning phlebotomy wave.'],
                    ['date' => 'Nov 08, 2026 • 09:30', 'event' => 'Registration Opened', 'actor' => 'Elena Martinez', 'notes' => 'Online slot booking opened with 5-slot preview pilot cap.'],
                    ['date' => 'Nov 05, 2026 • 11:00', 'event' => 'Camp Created', 'actor' => 'Dr. Maya Lin', 'notes' => 'Central Plaza Pavilion confirmed and logged in schedule.'],
                ],
            ],
            [
                'id' => 'CMP-307',
                'name' => 'University Health Campus Autumn Drive',
                'description' => 'Two-day collegiate and teaching hospital blood collection drive supporting surgical and trauma supply.',
                'organizerName' => 'Dr. Tariq Nassar',
                'organizerContact' => '+1 (555) 398-4402',
                'organizerEmail' => 'camps@universityhealth.edu',
                'venueName' => 'Student Union Atrium',
                'fullAddress' => '120 University Loop, Main Quad',
                'city' => 'University District',
                'locationNotes' => 'Ground-floor atrium beside Health Sciences shuttle stop.',
                'startDate' => '2026-11-11',
                'startDateFormatted' => 'Nov 11, 2026',
                'endDate' => '2026-11-12',
                'endDateFormatted' => 'Nov 12, 2026',
                'startTime' => '08:30',
                'endTime' => '17:00',
                'capacity' => 40,
                'registrationDeadline' => '2026-11-10',
                'registrationDeadlineFormatted' => 'Nov 10, 2026',
                'allowWalkIn' => true,
                'donorRequirements' => 'Photo ID required; first-time donors complete pre-donation vitals check.',
                'additionalNotes' => 'Walk-in donors welcomed while active bay capacity remains.',
                'status' => 'Ongoing',
                'registrations' => [
                    $makeRegistration('REG-791', 'DNR-1042', '2026-11-04', 'Attended'),
                    $makeRegistration('REG-792', 'DNR-1043', '2026-11-06', 'Confirmed'),
                    $makeRegistration('REG-793', 'DNR-1045', '2026-11-08', 'Registered'),
                    $makeRegistration('REG-794', 'DNR-1048', '2026-11-09', 'No-show'),
                ],
                'history' => [
                    ['date' => 'Nov 11, 2026 • 08:30', 'event' => 'Camp Started', 'actor' => 'Dr. Tariq Nassar', 'notes' => 'Day 1 stations calibrated; walk-in intake enabled.'],
                    ['date' => 'Nov 04, 2026 • 10:15', 'event' => 'Donor Registered', 'actor' => 'Marcus Vance (DNR-1042)', 'notes' => 'First pre-registered campus slot confirmed.'],
                    ['date' => 'Oct 28, 2026 • 15:00', 'event' => 'Camp Created', 'actor' => 'Dr. Tariq Nassar', 'notes' => 'University campus venue approved.'],
                ],
            ],
            [
                'id' => 'CMP-306',
                'name' => 'Westgate Tech Park Express Collection',
                'description' => 'Pre-booked corporate campus collection tailored for scheduled employee appointments.',
                'organizerName' => 'Samuel Brooks',
                'organizerContact' => '+1 (555) 701-1920',
                'organizerEmail' => 'wellness@westgatetech.com',
                'venueName' => 'Westgate Innovation Hall',
                'fullAddress' => '850 Innovation Way, Building C',
                'city' => 'Westgate',
                'locationNotes' => 'Security badge check at Building C lobby reception.',
                'startDate' => '2026-11-11',
                'startDateFormatted' => 'Nov 11, 2026',
                'endDate' => '2026-11-11',
                'endDateFormatted' => 'Nov 11, 2026',
                'startTime' => '10:00',
                'endTime' => '15:00',
                'capacity' => 25,
                'registrationDeadline' => '2026-11-10',
                'registrationDeadlineFormatted' => 'Nov 10, 2026',
                'allowWalkIn' => false,
                'donorRequirements' => 'Confirmed appointment badge required; walk-ins closed for corporate access control.',
                'additionalNotes' => 'Walk-in donations disabled per building security policy.',
                'status' => 'Ongoing',
                'registrations' => [
                    $makeRegistration('REG-781', 'DNR-1041', '2026-11-05', 'Confirmed'),
                    $makeRegistration('REG-782', 'DNR-1044', '2026-11-07', 'Attended'),
                ],
                'history' => [
                    ['date' => 'Nov 11, 2026 • 10:00', 'event' => 'Camp Started', 'actor' => 'Nurse Clara Diaz', 'notes' => 'Appointment-only collection started.'],
                    ['date' => 'Nov 01, 2026 • 12:00', 'event' => 'Registration Opened', 'actor' => 'Samuel Brooks', 'notes' => 'Employee pre-booking portal opened.'],
                    ['date' => 'Oct 25, 2026 • 09:45', 'event' => 'Camp Created', 'actor' => 'Samuel Brooks', 'notes' => 'Scheduled corporate mobile bus deployment.'],
                ],
            ],
            [
                'id' => 'CMP-305',
                'name' => 'Harborfront Full-Capacity Mobile Drive',
                'description' => 'Neighborhood coastal outreach drive currently at maximum scheduled donor allocation.',
                'organizerName' => 'Nurse Hannah Reed',
                'organizerContact' => '+1 (555) 643-9021',
                'organizerEmail' => 'outreach@harborfrontclinic.org',
                'venueName' => 'Harborfront Community Hall',
                'fullAddress' => '75 Pierview Avenue',
                'city' => 'Eastside',
                'locationNotes' => 'Mobile collection coach parked beside East Promenade gate.',
                'startDate' => '2026-11-21',
                'startDateFormatted' => 'Nov 21, 2026',
                'endDate' => '2026-11-21',
                'endDateFormatted' => 'Nov 21, 2026',
                'startTime' => '09:00',
                'endTime' => '15:30',
                'capacity' => 3,
                'registrationDeadline' => '2026-11-20',
                'registrationDeadlineFormatted' => 'Nov 20, 2026',
                'allowWalkIn' => false,
                'donorRequirements' => 'Appointment confirmation and photo ID required.',
                'additionalNotes' => 'Capacity currently full (3/3 slots reserved).',
                'status' => 'Upcoming',
                'registrations' => [
                    $makeRegistration('REG-771', 'DNR-1041', '2026-11-08', 'Confirmed'),
                    $makeRegistration('REG-772', 'DNR-1043', '2026-11-09', 'Registered'),
                    $makeRegistration('REG-773', 'DNR-1047', '2026-11-10', 'Confirmed'),
                ],
                'history' => [
                    ['date' => 'Nov 10, 2026 • 16:00', 'event' => 'Donor Registered', 'actor' => 'Sofia Alvarez (DNR-1047)', 'notes' => 'Final capacity slot filled.'],
                    ['date' => 'Nov 03, 2026 • 09:00', 'event' => 'Registration Opened', 'actor' => 'Nurse Hannah Reed', 'notes' => 'Harborfront drive opened for booking.'],
                    ['date' => 'Oct 29, 2026 • 13:20', 'event' => 'Camp Created', 'actor' => 'Nurse Hannah Reed', 'notes' => 'Mobile coach slot confirmed.'],
                ],
            ],
            [
                'id' => 'CMP-304',
                'name' => 'Southgate Hospital Pre-Deadline Drive',
                'description' => 'Hospital wing donation event whose advance registration window has closed prior to start date.',
                'organizerName' => 'Dr. Maya Lin',
                'organizerContact' => '+1 (555) 845-1200',
                'organizerEmail' => 'drives@southgateclinic.org',
                'venueName' => 'Southgate Outpatient Lobby',
                'fullAddress' => '230 Willow Street, Wing B',
                'city' => 'Southgate',
                'locationNotes' => 'Follow red directional signage from Southgate Main Reception.',
                'startDate' => '2026-11-12',
                'startDateFormatted' => 'Nov 12, 2026',
                'endDate' => '2026-11-12',
                'endDateFormatted' => 'Nov 12, 2026',
                'startTime' => '08:00',
                'endTime' => '14:00',
                'capacity' => 30,
                'registrationDeadline' => '2026-11-09',
                'registrationDeadlineFormatted' => 'Nov 09, 2026',
                'allowWalkIn' => true,
                'donorRequirements' => 'Fasting not permitted; eat a balanced iron-rich breakfast prior to arrival.',
                'additionalNotes' => 'Advance registration deadline passed on Nov 09.',
                'status' => 'Upcoming',
                'registrations' => [
                    $makeRegistration('REG-761', 'DNR-1042', '2026-11-05', 'Confirmed'),
                    $makeRegistration('REG-762', 'DNR-1045', '2026-11-07', 'Registered'),
                ],
                'history' => [
                    ['date' => 'Nov 09, 2026 • 23:59', 'event' => 'Registration Deadline Closed', 'actor' => 'Schedule Audit', 'notes' => 'Pre-registration window closed 72 hours before event start.'],
                    ['date' => 'Nov 02, 2026 • 09:00', 'event' => 'Registration Opened', 'actor' => 'Dr. Maya Lin', 'notes' => 'Booking opened for Southgate staff and neighbors.'],
                    ['date' => 'Oct 27, 2026 • 10:15', 'event' => 'Camp Created', 'actor' => 'Dr. Maya Lin', 'notes' => 'Outpatient lobby reserved.'],
                ],
            ],
            [
                'id' => 'CMP-303',
                'name' => 'Winter Corporate Blood Drive Plan',
                'description' => 'Draft two-day regional holiday reserve drive pending final facility electrical inspection.',
                'organizerName' => 'Victor Hernandez',
                'organizerContact' => '+1 (555) 510-7744',
                'organizerEmail' => 'events@midtowncivic.org',
                'venueName' => 'Midtown Civic Center',
                'fullAddress' => '600 Grand Boulevard, Hall A',
                'city' => 'Midtown',
                'locationNotes' => 'Loading dock access confirmation pending civic center manager sign-off.',
                'startDate' => '2026-12-05',
                'startDateFormatted' => 'Dec 05, 2026',
                'endDate' => '2026-12-06',
                'endDateFormatted' => 'Dec 06, 2026',
                'startTime' => '09:00',
                'endTime' => '16:00',
                'capacity' => 60,
                'registrationDeadline' => '2026-12-03',
                'registrationDeadlineFormatted' => 'Dec 03, 2026',
                'allowWalkIn' => true,
                'donorRequirements' => 'Standard eligibility questionnaire and photo ID verification.',
                'additionalNotes' => 'Draft event — registrations remain locked until published as Upcoming.',
                'status' => 'Draft',
                'registrations' => [],
                'history' => [
                    ['date' => 'Nov 10, 2026 • 16:40', 'event' => 'Camp Created', 'actor' => 'Victor Hernandez', 'notes' => 'Saved as Draft awaiting civic hall inspection checklist.'],
                ],
            ],
            [
                'id' => 'CMP-302',
                'name' => 'Northside Autumn Community Drive',
                'description' => 'Completed October regional drive supporting winter blood bank inventory buildup.',
                'organizerName' => 'Nurse Clara Diaz',
                'organizerContact' => '+1 (555) 490-1122',
                'organizerEmail' => 'northside@bdms-clinic.org',
                'venueName' => 'Northside Community Gymnasium',
                'fullAddress' => '34 Pinecrest Terrace',
                'city' => 'North Hills',
                'locationNotes' => 'Gymnasium east doors open; refrigerated blood courier dispatched hourly.',
                'startDate' => '2026-10-18',
                'startDateFormatted' => 'Oct 18, 2026',
                'endDate' => '2026-10-18',
                'endDateFormatted' => 'Oct 18, 2026',
                'startTime' => '08:00',
                'endTime' => '16:00',
                'capacity' => 35,
                'registrationDeadline' => '2026-10-16',
                'registrationDeadlineFormatted' => 'Oct 16, 2026',
                'allowWalkIn' => true,
                'donorRequirements' => 'Photo ID and routine hemoglobin screening at intake.',
                'additionalNotes' => 'Completed cleanly; attendance records archived for audit.',
                'status' => 'Completed',
                'registrations' => [
                    $makeRegistration('REG-741', 'DNR-1041', '2026-10-11', 'Attended'),
                    $makeRegistration('REG-742', 'DNR-1042', '2026-10-12', 'Attended'),
                    $makeRegistration('REG-743', 'DNR-1046', '2026-10-14', 'No-show'),
                    $makeRegistration('REG-744', 'DNR-1047', '2026-10-15', 'Attended'),
                ],
                'history' => [
                    ['date' => 'Oct 18, 2026 • 16:15', 'event' => 'Camp Completed', 'actor' => 'Nurse Clara Diaz', 'notes' => 'All units logged to component lab; attendance finalized.'],
                    ['date' => 'Oct 18, 2026 • 08:00', 'event' => 'Camp Started', 'actor' => 'Nurse Clara Diaz', 'notes' => 'Phlebotomy beds open for registered donors.'],
                    ['date' => 'Oct 11, 2026 • 10:00', 'event' => 'Donor Registered', 'actor' => 'Elena Rostova (DNR-1041)', 'notes' => 'Pre-booked whole blood collection.'],
                    ['date' => 'Oct 01, 2026 • 09:15', 'event' => 'Camp Created', 'actor' => 'Dr. Maya Lin', 'notes' => 'October community schedule published.'],
                ],
            ],
            [
                'id' => 'CMP-301',
                'name' => 'Riverside Holiday Park Drive',
                'description' => 'Outdoor pavilion mobile drive cancelled due to forecasted storm and utility outage.',
                'organizerName' => 'Mateo Alvarez',
                'organizerContact' => '+1 (555) 923-7001',
                'organizerEmail' => 'parks@downtownmetro.org',
                'venueName' => 'Riverside Amphitheater Annex',
                'fullAddress' => '520 Riverwalk Way',
                'city' => 'Downtown',
                'locationNotes' => 'Outdoor staging area closed for municipal maintenance.',
                'startDate' => '2026-11-25',
                'startDateFormatted' => 'Nov 25, 2026',
                'endDate' => '2026-11-25',
                'endDateFormatted' => 'Nov 25, 2026',
                'startTime' => '09:30',
                'endTime' => '15:00',
                'capacity' => 45,
                'registrationDeadline' => '2026-11-23',
                'registrationDeadlineFormatted' => 'Nov 23, 2026',
                'allowWalkIn' => false,
                'donorRequirements' => 'Rebooking notification sent to registered donors.',
                'additionalNotes' => 'Camp cancelled; existing registration history preserved for donor outreach.',
                'status' => 'Cancelled',
                'registrations' => [
                    $makeRegistration('REG-731', 'DNR-1044', '2026-11-04', 'Registered'),
                    $makeRegistration('REG-732', 'DNR-1045', '2026-11-05', 'Cancelled'),
                ],
                'history' => [
                    ['date' => 'Nov 09, 2026 • 11:30', 'event' => 'Camp Cancelled', 'actor' => 'Mateo Alvarez', 'notes' => 'Cancelled due to municipal pavilion closure; history retained.'],
                    ['date' => 'Nov 04, 2026 • 13:10', 'event' => 'Donor Registered', 'actor' => 'David Kim (DNR-1044)', 'notes' => 'Registered prior to cancellation notice.'],
                    ['date' => 'Oct 29, 2026 • 10:00', 'event' => 'Camp Created', 'actor' => 'Mateo Alvarez', 'notes' => 'Initial schedule logged.'],
                ],
            ],
        ];

        return array_map(function (array $entry): array {
            $activeRegistrations = count(array_filter(
                $entry['registrations'],
                fn (array $reg): bool => $reg['attendanceStatus'] !== 'Cancelled'
            ));

            $entry['registeredDonors'] = $activeRegistrations;
            $entry['availableSlots'] = max(0, $entry['capacity'] - $activeRegistrations);

            return $entry;
        }, $entries);
    }
}
