<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class BloodRequestsController extends Controller
{
    /**
     * Display the blood requests overview dashboard and filterable requests table.
     */
    public function index(): View
    {
        $requests = $this->sampleRequests();

        $summary = [
            'totalRequests' => count($requests),
            'pendingRequests' => count(array_filter(
                $requests,
                fn (array $item): bool => $item['status'] === 'Pending'
            )),
            'urgentRequests' => count(array_filter(
                $requests,
                fn (array $item): bool => in_array($item['priority'], ['High', 'Critical'], true)
            )),
            'fulfilledRequests' => count(array_filter(
                $requests,
                fn (array $item): bool => $item['status'] === 'Fulfilled'
            )),
            'totalUnitsRequested' => array_sum(array_column($requests, 'requestedUnits')),
            'totalUnitsFulfilled' => array_sum(array_column($requests, 'fulfilledUnits')),
        ];

        return view('requests.index', [
            'requests' => $requests,
            'summary' => $summary,
            'bloodGroups' => $this->bloodGroupOptions(),
            'priorities' => $this->priorityOptions(),
            'statuses' => $this->statusOptions(),
        ]);
    }

    /**
     * Display the new hospital blood request creation form.
     */
    public function create(): View
    {
        return view('requests.create', [
            'bloodGroups' => $this->bloodGroupOptions(),
            'priorities' => $this->priorityOptions(),
        ]);
    }

    /**
     * Display full blood request details, fulfillment progress, history, and staff workflow.
     */
    public function show(string $request): View
    {
        $requestRecord = $this->findRequestOrFail($request);

        return view('requests.show', [
            'bloodRequest' => $requestRecord,
            'bloodGroups' => $this->bloodGroupOptions(),
            'sampleInventory' => $this->sampleInventorySnapshot(),
        ]);
    }

    /**
     * Display the edit request form prefilled with sample data.
     */
    public function edit(string $request): View
    {
        $requestRecord = $this->findRequestOrFail($request);

        return view('requests.edit', [
            'bloodRequest' => $requestRecord,
            'bloodGroups' => $this->bloodGroupOptions(),
            'priorities' => $this->priorityOptions(),
        ]);
    }

    /**
     * Locate a blood request sample record by ID or abort with HTTP 404.
     *
     * @return array<string, mixed>
     */
    private function findRequestOrFail(string $requestId): array
    {
        foreach ($this->sampleRequests() as $record) {
            if (strcasecmp($record['id'], $requestId) === 0) {
                return $record;
            }
        }

        abort(404);
    }

    /**
     * Standard ABO/Rh blood groups.
     *
     * @return array<int, string>
     */
    private function bloodGroupOptions(): array
    {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }

    /**
     * Clinical urgency levels for blood requests.
     *
     * @return array<int, string>
     */
    private function priorityOptions(): array
    {
        return ['Low', 'Normal', 'High', 'Critical'];
    }

    /**
     * Supported lifecycle statuses for blood requests.
     *
     * @return array<int, string>
     */
    private function statusOptions(): array
    {
        return [
            'Pending',
            'Approved',
            'Rejected',
            'Partially Fulfilled',
            'Fulfilled',
            'Cancelled',
        ];
    }

    /**
     * Read-only sample inventory levels aligned with InventoryController (preview reference only).
     *
     * @return array<string, int>
     */
    private function sampleInventorySnapshot(): array
    {
        return [
            'A+' => 64,
            'A-' => 24,
            'B+' => 52,
            'B-' => 19,
            'AB+' => 45,
            'AB-' => 12,
            'O+' => 78,
            'O-' => 11,
        ];
    }

    /**
     * Realistic sample blood request records covering all priorities and statuses.
     *
     * @return array<int, array{
     *     id: string,
     *     hospitalName: string,
     *     contactPerson: string,
     *     contactNumber: string,
     *     hospitalAddress: string,
     *     email: string,
     *     bloodGroup: string,
     *     requestedUnits: int,
     *     fulfilledUnits: int,
     *     remainingUnits: int,
     *     requestDate: string,
     *     requestDateFormatted: string,
     *     requiredDate: string,
     *     requiredDateFormatted: string,
     *     priority: string,
     *     status: string,
     *     requestReason: string,
     *     additionalNotes: string,
     *     history: array<int, array{date: string, event: string, status: string, units: int, actor: string, notes: string}>
     * }>
     */
    private function sampleRequests(): array
    {
        $entries = [
            [
                'id' => 'REQ-1044',
                'hospitalName' => 'Metro General Trauma Center',
                'contactPerson' => 'Dr. Arthur Vance',
                'contactNumber' => '+1 (555) 402-8810',
                'hospitalAddress' => '900 Emergency Plaza, Central Medical District',
                'email' => 'transfusion.lab@metrotrauma.org',
                'bloodGroup' => 'O-',
                'requestedUnits' => 14,
                'fulfilledUnits' => 0,
                'requestDate' => '2026-11-11',
                'requestDateFormatted' => 'Nov 11, 2026',
                'requiredDate' => '2026-11-11',
                'requiredDateFormatted' => 'Nov 11, 2026',
                'priority' => 'Critical',
                'status' => 'Pending',
                'requestReason' => 'Mass casualty emergency surgical protocol requiring universal O-negative packed RBCs.',
                'additionalNotes' => 'Coordinate split dispatch if regional O- reserve falls short of full 14-unit volume.',
                'history' => [
                    [
                        'date' => 'Nov 11, 2026 • 13:15',
                        'event' => 'Request Submitted',
                        'status' => 'Pending',
                        'units' => 0,
                        'actor' => 'Dr. Arthur Vance (Metro General)',
                        'notes' => 'Critical trauma intake opened for 14 units of O- packed red cells.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1043',
                'hospitalName' => 'Harborview Cardiac Institute',
                'contactPerson' => 'Nurse Mei Chen',
                'contactNumber' => '+1 (555) 719-3304',
                'hospitalAddress' => '220 Bayfront Blvd, Suite 400, Eastside',
                'email' => 'bloodbank@harborviewcardiac.org',
                'bloodGroup' => 'B-',
                'requestedUnits' => 8,
                'fulfilledUnits' => 4,
                'requestDate' => '2026-11-10',
                'requestDateFormatted' => 'Nov 10, 2026',
                'requiredDate' => '2026-11-12',
                'requiredDateFormatted' => 'Nov 12, 2026',
                'priority' => 'High',
                'status' => 'Partially Fulfilled',
                'requestReason' => 'Scheduled cardiothoracic bypass surgery standby and post-op transfusion reserve.',
                'additionalNotes' => 'Remaining 4 crossmatched B- units requested before 07:00 AM operating theater prep.',
                'history' => [
                    [
                        'date' => 'Nov 11, 2026 • 09:40',
                        'event' => 'Partial Dispatch Recorded',
                        'status' => 'Partially Fulfilled',
                        'units' => 4,
                        'actor' => 'Nurse Hannah Reed',
                        'notes' => 'Dispatched initial 4 units B- via cold-chain courier #TR-208.',
                    ],
                    [
                        'date' => 'Nov 10, 2026 • 16:20',
                        'event' => 'Request Approved',
                        'status' => 'Approved',
                        'units' => 0,
                        'actor' => 'Dr. Maya Lin',
                        'notes' => 'Crossmatch screening cleared; split allocation authorized.',
                    ],
                    [
                        'date' => 'Nov 10, 2026 • 15:05',
                        'event' => 'Request Submitted',
                        'status' => 'Pending',
                        'units' => 0,
                        'actor' => 'Nurse Mei Chen (Harborview)',
                        'notes' => 'Surgical blood order for 8 units B-.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1042',
                'hospitalName' => 'City General Hospital',
                'contactPerson' => 'Dr. Elena Morales',
                'contactNumber' => '+1 (555) 289-4100',
                'hospitalAddress' => '145 Civic Parkway, Ward 3B, Downtown',
                'email' => 'icu.transfusion@citygeneral.org',
                'bloodGroup' => 'AB-',
                'requestedUnits' => 4,
                'fulfilledUnits' => 0,
                'requestDate' => '2026-11-11',
                'requestDateFormatted' => 'Nov 11, 2026',
                'requiredDate' => '2026-11-11',
                'requiredDateFormatted' => 'Nov 11, 2026',
                'priority' => 'Critical',
                'status' => 'Approved',
                'requestReason' => 'ICU Trauma Unit Ward 3B acute hemorrhage stabilization.',
                'additionalNotes' => 'Priority ambulance courier waiting at loading bay 2.',
                'history' => [
                    [
                        'date' => 'Nov 11, 2026 • 14:15',
                        'event' => 'Request Approved',
                        'status' => 'Approved',
                        'units' => 0,
                        'actor' => 'Dr. Maya Lin',
                        'notes' => 'Emergency AB- allocation approved for immediate crossmatch dispatch.',
                    ],
                    [
                        'date' => 'Nov 11, 2026 • 14:02',
                        'event' => 'Request Submitted',
                        'status' => 'Pending',
                        'units' => 0,
                        'actor' => 'Dr. Elena Morales (City General)',
                        'notes' => 'Urgent request logged for 4 units AB- packed RBC.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1041',
                'hospitalName' => 'Northside Pediatric Clinic',
                'contactPerson' => 'Dr. Samuel Okafor',
                'contactNumber' => '+1 (555) 614-2908',
                'hospitalAddress' => '78 Pinecrest Road, North Hills',
                'email' => 'hematology@northsidepeds.org',
                'bloodGroup' => 'A-',
                'requestedUnits' => 6,
                'fulfilledUnits' => 2,
                'requestDate' => '2026-11-09',
                'requestDateFormatted' => 'Nov 09, 2026',
                'requiredDate' => '2026-11-13',
                'requiredDateFormatted' => 'Nov 13, 2026',
                'priority' => 'Normal',
                'status' => 'Partially Fulfilled',
                'requestReason' => 'Pediatric thalassemia chronic transfusion support schedule.',
                'additionalNotes' => 'Leukoreduced irradiated aliquots preferred where available.',
                'history' => [
                    [
                        'date' => 'Nov 10, 2026 • 11:00',
                        'event' => 'Partial Dispatch Recorded',
                        'status' => 'Partially Fulfilled',
                        'units' => 2,
                        'actor' => 'Nurse Clara Diaz',
                        'notes' => 'Released 2 leukoreduced A- units for outpatient clinic day 1.',
                    ],
                    [
                        'date' => 'Nov 09, 2026 • 14:30',
                        'event' => 'Request Approved',
                        'status' => 'Approved',
                        'units' => 0,
                        'actor' => 'Dr. Tariq Nassar',
                        'notes' => 'Approved 6-unit multi-day pediatric infusion plan.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1040',
                'hospitalName' => 'Westgate Surgical Center',
                'contactPerson' => 'Karen Gallagher',
                'contactNumber' => '+1 (555) 890-5512',
                'hospitalAddress' => '510 Crescent Avenue, Westgate',
                'email' => 'or.logistics@westgatesurgical.org',
                'bloodGroup' => 'O+',
                'requestedUnits' => 10,
                'fulfilledUnits' => 10,
                'requestDate' => '2026-11-07',
                'requestDateFormatted' => 'Nov 07, 2026',
                'requiredDate' => '2026-11-08',
                'requiredDateFormatted' => 'Nov 08, 2026',
                'priority' => 'High',
                'status' => 'Fulfilled',
                'requestReason' => 'Weekend orthopedic & trauma surgical theater replenishment.',
                'additionalNotes' => 'All 10 O+ units signed into Westgate OR cold cabinet.',
                'history' => [
                    [
                        'date' => 'Nov 08, 2026 • 08:25',
                        'event' => 'Full Fulfillment Completed',
                        'status' => 'Fulfilled',
                        'units' => 10,
                        'actor' => 'Nurse Hannah Reed',
                        'notes' => 'All 10 units of O+ delivered and receipted by OR blood coordinator.',
                    ],
                    [
                        'date' => 'Nov 07, 2026 • 13:10',
                        'event' => 'Request Approved',
                        'status' => 'Approved',
                        'units' => 0,
                        'actor' => 'Dr. Maya Lin',
                        'notes' => 'Approved standard O+ regional dispatch.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1039',
                'hospitalName' => 'St. Jude Maternity Center',
                'contactPerson' => 'Dr. Nadia Patel',
                'contactNumber' => '+1 (555) 341-7722',
                'hospitalAddress' => '312 Blossom Way, Obstetrics Wing',
                'email' => 'ob.transfusion@stjudematernity.org',
                'bloodGroup' => 'O-',
                'requestedUnits' => 2,
                'fulfilledUnits' => 0,
                'requestDate' => '2026-11-10',
                'requestDateFormatted' => 'Nov 10, 2026',
                'requiredDate' => '2026-11-12',
                'requiredDateFormatted' => 'Nov 12, 2026',
                'priority' => 'Critical',
                'status' => 'Pending',
                'requestReason' => 'High-risk obstetrics surgical suite standby reserve.',
                'additionalNotes' => 'CMV-negative units requested if available in screened O- inventory.',
                'history' => [
                    [
                        'date' => 'Nov 10, 2026 • 17:45',
                        'event' => 'Request Submitted',
                        'status' => 'Pending',
                        'units' => 0,
                        'actor' => 'Dr. Nadia Patel (St. Jude)',
                        'notes' => 'Emergency obstetrics reserve order for 2 O- units.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1038',
                'hospitalName' => 'Regional Trauma Clinic',
                'contactPerson' => 'Marcus Sterling',
                'contactNumber' => '+1 (555) 552-6019',
                'hospitalAddress' => '104 University Loop, Campus South',
                'email' => 'lab.orders@regionaltrauma.org',
                'bloodGroup' => 'B+',
                'requestedUnits' => 3,
                'fulfilledUnits' => 0,
                'requestDate' => '2026-11-08',
                'requestDateFormatted' => 'Nov 08, 2026',
                'requiredDate' => '2026-11-15',
                'requiredDateFormatted' => 'Nov 15, 2026',
                'priority' => 'Normal',
                'status' => 'Approved',
                'requestReason' => 'Elective vascular procedure crossmatch reserve.',
                'additionalNotes' => 'Scheduled pickup on morning courier run.',
                'history' => [
                    [
                        'date' => 'Nov 09, 2026 • 10:15',
                        'event' => 'Request Approved',
                        'status' => 'Approved',
                        'units' => 0,
                        'actor' => 'Dr. Tariq Nassar',
                        'notes' => 'B+ units reserved pending courier departure.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1037',
                'hospitalName' => 'Valley Community Hospital',
                'contactPerson' => 'Lydia Gomez',
                'contactNumber' => '+1 (555) 664-1190',
                'hospitalAddress' => '880 Valley View Road, Southgate',
                'email' => 'supply@valleycommunity.org',
                'bloodGroup' => 'AB+',
                'requestedUnits' => 5,
                'fulfilledUnits' => 0,
                'requestDate' => '2026-11-05',
                'requestDateFormatted' => 'Nov 05, 2026',
                'requiredDate' => '2026-11-18',
                'requiredDateFormatted' => 'Nov 18, 2026',
                'priority' => 'Low',
                'status' => 'Rejected',
                'requestReason' => 'Routine ward stock buffer transfer request.',
                'additionalNotes' => 'Deferred — Valley Community maintains surplus stock within partner network.',
                'history' => [
                    [
                        'date' => 'Nov 06, 2026 • 09:05',
                        'event' => 'Request Rejected',
                        'status' => 'Rejected',
                        'units' => 0,
                        'actor' => 'Dr. Maya Lin',
                        'notes' => 'Rejected: Duplicate buffer request already covered under regional redistribution #RD-44.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1036',
                'hospitalName' => 'Eastside Orthopedics',
                'contactPerson' => 'Dr. Henrik Lindqvist',
                'contactNumber' => '+1 (555) 930-4481',
                'hospitalAddress' => '45 Harbor View Road, Eastside',
                'email' => 'surgery@eastsideortho.org',
                'bloodGroup' => 'A+',
                'requestedUnits' => 4,
                'fulfilledUnits' => 0,
                'requestDate' => '2026-11-04',
                'requestDateFormatted' => 'Nov 04, 2026',
                'requiredDate' => '2026-11-14',
                'requiredDateFormatted' => 'Nov 14, 2026',
                'priority' => 'Low',
                'status' => 'Cancelled',
                'requestReason' => 'Elective joint arthroplasty preoperative blood order.',
                'additionalNotes' => 'Procedure postponed by hospital scheduling team; order closed.',
                'history' => [
                    [
                        'date' => 'Nov 05, 2026 • 14:12',
                        'event' => 'Request Cancelled',
                        'status' => 'Cancelled',
                        'units' => 0,
                        'actor' => 'Dr. Henrik Lindqvist (Eastside)',
                        'notes' => 'Surgery rescheduled to next quarter; blood units released.',
                    ],
                ],
            ],
            [
                'id' => 'REQ-1035',
                'hospitalName' => 'Mercy Oncology Wing',
                'contactPerson' => 'Sarah Al-Khoury',
                'contactNumber' => '+1 (555) 778-9023',
                'hospitalAddress' => '615 Mercy Campus Drive, Midtown',
                'email' => 'infusion@mercyoncology.org',
                'bloodGroup' => 'A+',
                'requestedUnits' => 6,
                'fulfilledUnits' => 6,
                'requestDate' => '2026-11-02',
                'requestDateFormatted' => 'Nov 02, 2026',
                'requiredDate' => '2026-11-04',
                'requiredDateFormatted' => 'Nov 04, 2026',
                'priority' => 'Normal',
                'status' => 'Fulfilled',
                'requestReason' => 'Oncology infusion unit weekly platelet and red cell support.',
                'additionalNotes' => 'Verified cold-chain delivery complete.',
                'history' => [
                    [
                        'date' => 'Nov 04, 2026 • 10:30',
                        'event' => 'Full Fulfillment Completed',
                        'status' => 'Fulfilled',
                        'units' => 6,
                        'actor' => 'Nurse Clara Diaz',
                        'notes' => 'Delivered final 6 units of A+ to Mercy Infusion pharmacy.',
                    ],
                ],
            ],
        ];

        return array_map(function (array $entry): array {
            $entry['remainingUnits'] = max(0, $entry['requestedUnits'] - $entry['fulfilledUnits']);

            return $entry;
        }, $entries);
    }
}
