<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class ScreeningController extends Controller
{
    /**
     * Display the lab screening overview statistics and filterable sample queue.
     */
    public function index(): View
    {
        $samples = $this->sampleScreenings();

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

        return view('screening.index', [
            'samples' => $samples,
            'summary' => $summary,
            'bloodGroups' => $this->bloodGroupOptions(),
            'testTypes' => $this->testTypeOptions(),
        ]);
    }

    /**
     * Display the lab screening test recording form linked to sample donations.
     */
    public function create(): View
    {
        return view('screening.create', [
            'donations' => array_values($this->sampleDonationsById()),
            'testTypes' => $this->testTypeOptions(),
            'labStaffSuggestions' => $this->labStaffSuggestions(),
        ]);
    }

    /**
     * Display detailed screening results, linked donation details, donor data, and audit timestamps.
     */
    public function show(string $screening): View
    {
        $sample = $this->findScreeningOrFail($screening);

        return view('screening.show', [
            'sample' => $sample,
        ]);
    }

    /**
     * Locate a sample screening record by code or abort with 404.
     *
     * @return array<string, mixed>
     */
    private function findScreeningOrFail(string $screeningId): array
    {
        foreach ($this->sampleScreenings() as $record) {
            if (strcasecmp($record['id'], $screeningId) === 0) {
                return $record;
            }
        }

        abort(404);
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
    private function sampleDonationsById(): array
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
                'donorId' => 'DNR-1048',
                'donorName' => 'Omar Al-Mansoor',
                'donorEmail' => 'omar.mansoor@example.org',
                'donorPhone' => '+1 (555) 673-8044',
                'bloodGroup' => 'B-',
                'collectionDate' => '2026-11-10',
                'collectionDateFormatted' => 'Nov 10, 2026',
                'donationType' => 'Plasma',
                'quantityMl' => 600,
                'collectionLocation' => 'Westgate Donor Suite',
                'collectionStatus' => 'Scheduled',
            ],
            [
                'id' => 'DON-4403',
                'donorId' => 'DNR-1044',
                'donorName' => 'David Kim',
                'donorEmail' => 'david.kim@example.org',
                'donorPhone' => '+1 (555) 784-1933',
                'bloodGroup' => 'AB-',
                'collectionDate' => '2026-11-08',
                'collectionDateFormatted' => 'Nov 08, 2026',
                'donationType' => 'Platelets',
                'quantityMl' => 250,
                'collectionLocation' => 'Central Medical Plaza',
                'collectionStatus' => 'Completed',
            ],
            [
                'id' => 'DON-4402',
                'donorId' => 'DNR-1043',
                'donorName' => 'Priya Sharma',
                'donorEmail' => 'priya.sharma@example.org',
                'donorPhone' => '+1 (555) 612-7745',
                'bloodGroup' => 'B+',
                'collectionDate' => '2026-11-06',
                'collectionDateFormatted' => 'Nov 06, 2026',
                'donationType' => 'Whole Blood',
                'quantityMl' => 450,
                'collectionLocation' => 'University Health Campus',
                'collectionStatus' => 'Scheduled',
            ],
            [
                'id' => 'DON-4401',
                'donorId' => 'DNR-1045',
                'donorName' => 'Amina Hassan',
                'donorEmail' => 'amina.hassan@example.org',
                'donorPhone' => '+1 (555) 845-3091',
                'bloodGroup' => 'O+',
                'collectionDate' => '2026-11-03',
                'collectionDateFormatted' => 'Nov 03, 2026',
                'donationType' => 'Plasma',
                'quantityMl' => 550,
                'collectionLocation' => 'Southgate Clinic',
                'collectionStatus' => 'Cancelled',
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

    /**
     * Build realistic sample laboratory screening records referencing valid donations and donors.
     *
     * @return array<int, array{
     *     id: string,
     *     donationId: string,
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
     *     collectionStatus: string,
     *     testDate: ?string,
     *     testDateFormatted: string,
     *     testType: string,
     *     screeningResult: string,
     *     labStaff: string,
     *     assayMethod: string,
     *     specimenTube: string,
     *     testNotes: string,
     *     collectedAt: string,
     *     loggedAt: string,
     *     resultRecordedAt: string
     * }>
     */
    public function sampleScreenings(): array
    {
        $donations = $this->sampleDonationsById();

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
