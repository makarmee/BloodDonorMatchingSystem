<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class DonationsController extends Controller
{
    /**
     * Display the donations overview metrics and filterable donations log.
     */
    public function index(): View
    {
        $donations = $this->sampleDonations();

        $completedDonations = array_filter(
            $donations,
            fn (array $donation): bool => $donation['collectionStatus'] === 'Completed'
        );

        $summary = [
            'totalDonations' => count($donations),
            'donationsThisMonth' => count(array_filter(
                $donations,
                fn (array $donation): bool => str_starts_with($donation['donationDate'], '2026-11-')
            )),
            'totalUnitsCollected' => count($completedDonations),
            'totalVolumeMl' => array_sum(array_column($completedDonations, 'quantityMl')),
            'pendingScreening' => count(array_filter(
                $donations,
                fn (array $donation): bool => $donation['screeningStatus'] === 'Pending'
            )),
        ];

        return view('donations.index', [
            'donations' => $donations,
            'summary' => $summary,
            'bloodGroups' => $this->bloodGroupOptions(),
        ]);
    }

    /**
     * Display the new donation recording form.
     */
    public function create(): View
    {
        return view('donations.create', [
            'donors' => array_values($this->sampleDonorsById()),
            'bloodGroups' => $this->bloodGroupOptions(),
            'locations' => $this->collectionLocations(),
            'staffMembers' => $this->staffMemberSuggestions(),
        ]);
    }

    /**
     * Display detailed screening, collection, and donor details for a donation.
     */
    public function show(string $donation): View
    {
        $donationRecord = $this->findDonationOrFail($donation);

        return view('donations.show', [
            'donation' => $donationRecord,
        ]);
    }

    /**
     * Display the donation edit form prefilled with the selected sample record.
     */
    public function edit(string $donation): View
    {
        $donationRecord = $this->findDonationOrFail($donation);

        return view('donations.edit', [
            'donation' => $donationRecord,
            'donors' => array_values($this->sampleDonorsById()),
            'bloodGroups' => $this->bloodGroupOptions(),
            'locations' => $this->collectionLocations(),
            'staffMembers' => $this->staffMemberSuggestions(),
        ]);
    }

    /**
     * Find a donation record by reference ID or abort with a 404 response.
     *
     * @return array<string, mixed>
     */
    private function findDonationOrFail(string $donationId): array
    {
        foreach ($this->sampleDonations() as $record) {
            if (strcasecmp($record['id'], $donationId) === 0) {
                return $record;
            }
        }

        abort(404);
    }

    /**
     * Standard ABO/Rh blood groups keyed for select inputs and filters.
     *
     * @return array<int, string>
     */
    private function bloodGroupOptions(): array
    {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }

    /**
     * Suggested collection facilities matching existing sample donation centers.
     *
     * @return array<int, string>
     */
    private function collectionLocations(): array
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
    private function staffMemberSuggestions(): array
    {
        return [
            'Nurse Hannah Reed',
            'Dr. Maya Lin',
            'Dr. Tariq Nassar',
            'Nurse Clara Diaz',
        ];
    }

    /**
     * Valid sample donors synchronized with DonorsController records (DNR-1041 through DNR-1048).
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
    private function sampleDonorsById(): array
    {
        $records = [
            [
                'id' => 'DNR-1041',
                'name' => 'Elena Rostova',
                'bloodGroup' => 'O-',
                'email' => 'elena.rostova@example.org',
                'phone' => '+1 (555) 234-8812',
                'eligibilityStatus' => 'Eligible',
            ],
            [
                'id' => 'DNR-1042',
                'name' => 'Marcus Vance',
                'bloodGroup' => 'A+',
                'email' => 'marcus.vance@example.org',
                'phone' => '+1 (555) 391-4420',
                'eligibilityStatus' => 'Not Eligible',
            ],
            [
                'id' => 'DNR-1043',
                'name' => 'Priya Sharma',
                'bloodGroup' => 'B+',
                'email' => 'priya.sharma@example.org',
                'phone' => '+1 (555) 612-7745',
                'eligibilityStatus' => 'Pending Review',
            ],
            [
                'id' => 'DNR-1044',
                'name' => 'David Kim',
                'bloodGroup' => 'AB-',
                'email' => 'david.kim@example.org',
                'phone' => '+1 (555) 784-1933',
                'eligibilityStatus' => 'Eligible',
            ],
            [
                'id' => 'DNR-1045',
                'name' => 'Amina Hassan',
                'bloodGroup' => 'O+',
                'email' => 'amina.hassan@example.org',
                'phone' => '+1 (555) 845-3091',
                'eligibilityStatus' => 'Pending Review',
            ],
            [
                'id' => 'DNR-1046',
                'name' => 'Lucas Bennett',
                'bloodGroup' => 'A-',
                'email' => 'lucas.bennett@example.org',
                'phone' => '+1 (555) 490-2218',
                'eligibilityStatus' => 'Not Eligible',
            ],
            [
                'id' => 'DNR-1047',
                'name' => 'Sofia Alvarez',
                'bloodGroup' => 'AB+',
                'email' => 'sofia.alvarez@example.org',
                'phone' => '+1 (555) 923-5510',
                'eligibilityStatus' => 'Eligible',
            ],
            [
                'id' => 'DNR-1048',
                'name' => 'Omar Al-Mansoor',
                'bloodGroup' => 'B-',
                'email' => 'omar.mansoor@example.org',
                'phone' => '+1 (555) 673-8044',
                'eligibilityStatus' => 'Eligible',
            ],
        ];

        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record['id']] = $record;
        }

        return $indexed;
    }

    /**
     * Build realistic sample donation records referencing valid sample donors.
     *
     * @return array<int, array{
     *     id: string,
     *     donorId: string,
     *     donorName: string,
     *     donorEmail: string,
     *     donorPhone: string,
     *     donorEligibilityStatus: string,
     *     bloodGroup: string,
     *     donationDate: string,
     *     donationDateFormatted: string,
     *     quantityMl: int,
     *     donationType: string,
     *     collectionLocation: string,
     *     staffMember: string,
     *     screeningStatus: string,
     *     screeningNotes: string,
     *     collectionStatus: string,
     *     collectionNotes: string
     * }>
     */
    private function sampleDonations(): array
    {
        $donors = $this->sampleDonorsById();

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
                'donorId' => 'DNR-1048',
                'donationDate' => '2026-11-10',
                'donationDateFormatted' => 'Nov 10, 2026',
                'quantityMl' => 600,
                'donationType' => 'Plasma',
                'collectionLocation' => 'Westgate Donor Suite',
                'staffMember' => 'Dr. Tariq Nassar',
                'screeningStatus' => 'Pending',
                'screeningNotes' => 'Awaiting baseline protein and antibody titer confirmation prior to apheresis run.',
                'collectionStatus' => 'Scheduled',
                'collectionNotes' => 'Afternoon apheresis chair #3 reserved; donor pre-hydration reminder sent.',
            ],
            [
                'id' => 'DON-4403',
                'donorId' => 'DNR-1044',
                'donationDate' => '2026-11-08',
                'donationDateFormatted' => 'Nov 08, 2026',
                'quantityMl' => 250,
                'donationType' => 'Platelets',
                'collectionLocation' => 'Central Medical Plaza',
                'staffMember' => 'Nurse Hannah Reed',
                'screeningStatus' => 'Passed',
                'screeningNotes' => 'Platelet count 265 x10^9/L; NAT and serology panels negative across all markers.',
                'collectionStatus' => 'Completed',
                'collectionNotes' => 'Single-donor apheresis completed without citrate reaction; released to cold storage.',
            ],
            [
                'id' => 'DON-4402',
                'donorId' => 'DNR-1043',
                'donationDate' => '2026-11-06',
                'donationDateFormatted' => 'Nov 06, 2026',
                'quantityMl' => 450,
                'donationType' => 'Whole Blood',
                'collectionLocation' => 'University Health Campus',
                'staffMember' => 'Dr. Maya Lin',
                'screeningStatus' => 'Pending',
                'screeningNotes' => 'First-time donor clinical questionnaire under medical officer verification.',
                'collectionStatus' => 'Scheduled',
                'collectionNotes' => 'Collection appointment queued pending initial hemoglobin and screening clearance.',
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
