<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class DonorsController extends Controller
{
    /**
     * Display the donors directory overview, statistics, and filterable list.
     */
    public function index(): View
    {
        $donors = $this->sampleDonors();

        $summary = [
            'totalDonors' => count($donors),
            'activeDonors' => count(array_filter(
                $donors,
                fn (array $donor): bool => $donor['accountStatus'] === 'Active'
            )),
            'eligibleDonors' => count(array_filter(
                $donors,
                fn (array $donor): bool => $donor['eligibilityStatus'] === 'Eligible'
            )),
            'newThisMonth' => count(array_filter(
                $donors,
                fn (array $donor): bool => $donor['registeredThisMonth']
            )),
        ];

        return view('donors.index', [
            'donors' => $donors,
            'summary' => $summary,
            'bloodGroups' => $this->bloodGroupOptions(),
        ]);
    }

    /**
     * Display the donor registration form.
     */
    public function create(): View
    {
        return view('donors.create', [
            'bloodGroups' => $this->bloodGroupOptions(),
        ]);
    }

    /**
     * Display a detailed donor profile with donation history.
     */
    public function show(string $donor): View
    {
        $donorRecord = $this->findDonorOrFail($donor);

        return view('donors.show', [
            'donor' => $donorRecord,
        ]);
    }

    /**
     * Display the edit form prefilled with the selected donor's sample data.
     */
    public function edit(string $donor): View
    {
        $donorRecord = $this->findDonorOrFail($donor);

        return view('donors.edit', [
            'donor' => $donorRecord,
            'bloodGroups' => $this->bloodGroupOptions(),
        ]);
    }

    /**
     * Locate a sample donor record by code or abort with 404.
     *
     * @return array<string, mixed>
     */
    private function findDonorOrFail(string $donorId): array
    {
        foreach ($this->sampleDonors() as $record) {
            if (strcasecmp($record['id'], $donorId) === 0) {
                return $record;
            }
        }

        abort(404);
    }

    /**
     * Standard ABO/Rh blood group options keyed for select components.
     *
     * @return array<string, string>
     */
    private function bloodGroupOptions(): array
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
     * Realistic sample donor dataset for frontend preview and interactions.
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
    private function sampleDonors(): array
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
