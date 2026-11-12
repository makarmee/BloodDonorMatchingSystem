<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Render the role-aware BDMS dashboard for the authenticated user.
     */
    public function __invoke(Request $request): View
    {
        $activeRole = $request->user()?->primaryRoleSlug() ?? 'staff';

        $portalData = $this->portalPayload($activeRole);

        return view('dashboard.index', [
            'activeRole' => $activeRole,
            'portalData' => $portalData,
        ]);
    }

    /**
     * Build structured preview metrics and table rows for each BDMS portal role.
     *
     * @return array{
     *     pageTitle: string,
     *     subtitle: string,
     *     kpis: array<int, array{label: string, value: string, trend: string, trendTone: string, icon: string, accent: string}>,
     *     inventorySnapshot: array<int, array{group: string, units: int, capacity: int}>,
     *     recentActivity: array<int, array{reference: string, subject: string, group: string, quantity: string, status: string, time: string}>
     * }
     */
    private function portalPayload(string $role): array
    {
        $inventorySnapshot = [
            ['group' => 'O-', 'units' => 14, 'capacity' => 100],
            ['group' => 'AB-', 'units' => 18, 'capacity' => 100],
            ['group' => 'A-', 'units' => 28, 'capacity' => 100],
            ['group' => 'O+', 'units' => 76, 'capacity' => 100],
        ];

        return match ($role) {
            'donor' => [
                'pageTitle' => 'Donor Portal Overview',
                'subtitle' => 'Track donation eligibility, upcoming appointments, and blood drive history.',
                'kpis' => [
                    [
                        'label' => 'Blood Group',
                        'value' => 'O+',
                        'trend' => 'Universal red cell donor compatibility (Rh+)',
                        'trendTone' => 'neutral',
                        'icon' => 'droplet',
                        'accent' => 'crimson',
                    ],
                    [
                        'label' => 'Eligibility Status',
                        'value' => 'Eligible',
                        'trend' => 'Next safe donation window open now',
                        'trendTone' => 'up',
                        'icon' => 'shield-check',
                        'accent' => 'emerald',
                    ],
                    [
                        'label' => 'Completed Donations',
                        'value' => '6',
                        'trend' => '+1 donation in the last 90 days',
                        'trendTone' => 'up',
                        'icon' => 'heart-pulse',
                        'accent' => 'sky',
                    ],
                    [
                        'label' => 'Next Appointment',
                        'value' => 'Nov 18',
                        'trend' => 'Central Medical Plaza • 10:30 AM',
                        'trendTone' => 'neutral',
                        'icon' => 'calendar',
                        'accent' => 'amber',
                    ],
                ],
                'inventorySnapshot' => $inventorySnapshot,
                'recentActivity' => [
                    [
                        'reference' => 'DON-4081',
                        'subject' => 'Central Medical Plaza Drive',
                        'group' => 'O+',
                        'quantity' => '450 ml Whole Blood',
                        'status' => 'completed',
                        'time' => 'Aug 12, 2026',
                    ],
                    [
                        'reference' => 'DON-3819',
                        'subject' => 'University Health Campus',
                        'group' => 'O+',
                        'quantity' => '450 ml Whole Blood',
                        'status' => 'completed',
                        'time' => 'May 04, 2026',
                    ],
                    [
                        'reference' => 'APT-1190',
                        'subject' => 'Upcoming Scheduled Visit',
                        'group' => 'O+',
                        'quantity' => 'Confirmed Slot',
                        'status' => 'pending',
                        'time' => 'Nov 18, 2026',
                    ],
                ],
            ],
            'hospital' => [
                'pageTitle' => 'Hospital Requester Portal',
                'subtitle' => 'Monitor blood request fulfillment, emergency dispatch ETA, and regional supply.',
                'kpis' => [
                    [
                        'label' => 'Active Blood Requests',
                        'value' => '7',
                        'trend' => '2 emergency requests in dispatch',
                        'trendTone' => 'down',
                        'icon' => 'clipboard-list',
                        'accent' => 'crimson',
                    ],
                    [
                        'label' => 'Units Fulfilled (30d)',
                        'value' => '142',
                        'trend' => '98.6% on-time crossmatch rate',
                        'trendTone' => 'up',
                        'icon' => 'check-circle',
                        'accent' => 'emerald',
                    ],
                    [
                        'label' => 'Avg Dispatch Time',
                        'value' => '34 min',
                        'trend' => 'Priority ambulance courier active',
                        'trendTone' => 'up',
                        'icon' => 'clock',
                        'accent' => 'sky',
                    ],
                    [
                        'label' => 'Partner Blood Banks',
                        'value' => '4',
                        'trend' => 'All regional hubs online',
                        'trendTone' => 'neutral',
                        'icon' => 'building-hospital',
                        'accent' => 'amber',
                    ],
                ],
                'inventorySnapshot' => $inventorySnapshot,
                'recentActivity' => [
                    [
                        'reference' => 'REQ-1042',
                        'subject' => 'ICU Trauma Unit — Ward 3B',
                        'group' => 'AB-',
                        'quantity' => '4 Units Packed RBC',
                        'status' => 'critical',
                        'time' => '12 mins ago',
                    ],
                    [
                        'reference' => 'REQ-1039',
                        'subject' => 'Obstetrics Surgical Suite',
                        'group' => 'O-',
                        'quantity' => '2 Units Whole Blood',
                        'status' => 'urgent',
                        'time' => '48 mins ago',
                    ],
                    [
                        'reference' => 'REQ-1035',
                        'subject' => 'Oncology Infusion Clinic',
                        'group' => 'A+',
                        'quantity' => '6 Units Platelets',
                        'status' => 'fulfilled',
                        'time' => '3 hours ago',
                    ],
                ],
            ],
            'lab_officer' => [
                'pageTitle' => 'Laboratory Screening Dashboard',
                'subtitle' => 'Review pending infectious disease screening queues, confirmatory tests, and quarantine releases.',
                'kpis' => [
                    [
                        'label' => 'Pending Screening Queue',
                        'value' => '14',
                        'trend' => 'Units awaiting initial serology review',
                        'trendTone' => 'neutral',
                        'icon' => 'shield-check',
                        'accent' => 'amber',
                    ],
                    [
                        'label' => 'Cleared Today',
                        'value' => '22',
                        'trend' => 'Ready for verified blood bank stock',
                        'trendTone' => 'up',
                        'icon' => 'check-circle',
                        'accent' => 'emerald',
                    ],
                    [
                        'label' => 'Quarantined Units',
                        'value' => '3',
                        'trend' => 'Held pending confirmatory testing',
                        'trendTone' => 'down',
                        'icon' => 'activity',
                        'accent' => 'crimson',
                    ],
                    [
                        'label' => 'Donor Records Accessible',
                        'value' => '1,284',
                        'trend' => 'Medical eligibility and screening history',
                        'trendTone' => 'neutral',
                        'icon' => 'users',
                        'accent' => 'sky',
                    ],
                ],
                'inventorySnapshot' => $inventorySnapshot,
                'recentActivity' => [
                    [
                        'reference' => 'SCR-2048',
                        'subject' => 'TTI Serology Panel — Batch B14',
                        'group' => 'O+',
                        'quantity' => '6 Units',
                        'status' => 'pending',
                        'time' => '18 mins ago',
                    ],
                    [
                        'reference' => 'SCR-2045',
                        'subject' => 'Confirmatory NAT Assay',
                        'group' => 'A-',
                        'quantity' => '2 Units',
                        'status' => 'urgent',
                        'time' => '55 mins ago',
                    ],
                    [
                        'reference' => 'SCR-2041',
                        'subject' => 'Inventory Release Clearance',
                        'group' => 'B+',
                        'quantity' => '8 Units',
                        'status' => 'completed',
                        'time' => '2 hours ago',
                    ],
                ],
            ],
            default => [
                'pageTitle' => 'Blood Bank Operations Dashboard',
                'subtitle' => 'Real-time blood inventory levels, donor intake, and hospital fulfillment queue.',
                'kpis' => [
                    [
                        'label' => 'Total Available Units',
                        'value' => '341',
                        'trend' => '+18 screened units added today',
                        'trendTone' => 'up',
                        'icon' => 'droplet',
                        'accent' => 'crimson',
                    ],
                    [
                        'label' => 'Registered Donors',
                        'value' => '1,284',
                        'trend' => '42 new registrations this week',
                        'trendTone' => 'up',
                        'icon' => 'users',
                        'accent' => 'sky',
                    ],
                    [
                        'label' => 'Pending Requests',
                        'value' => '9',
                        'trend' => '2 critical priority requests',
                        'trendTone' => 'down',
                        'icon' => 'clipboard-list',
                        'accent' => 'amber',
                    ],
                    [
                        'label' => 'Expiring Within 7d',
                        'value' => '11',
                        'trend' => 'Prioritize FIFO dispatch rotation',
                        'trendTone' => 'neutral',
                        'icon' => 'activity',
                        'accent' => 'emerald',
                    ],
                ],
                'inventorySnapshot' => $inventorySnapshot,
                'recentActivity' => [
                    [
                        'reference' => 'REQ-1042',
                        'subject' => 'City General Hospital',
                        'group' => 'AB-',
                        'quantity' => '4 Units',
                        'status' => 'critical',
                        'time' => '12 mins ago',
                    ],
                    [
                        'reference' => 'REQ-1039',
                        'subject' => 'St. Jude Maternity Center',
                        'group' => 'O-',
                        'quantity' => '2 Units',
                        'status' => 'urgent',
                        'time' => '48 mins ago',
                    ],
                    [
                        'reference' => 'REQ-1038',
                        'subject' => 'Regional Trauma Clinic',
                        'group' => 'B+',
                        'quantity' => '3 Units',
                        'status' => 'routine',
                        'time' => '2 hours ago',
                    ],
                    [
                        'reference' => 'REQ-1035',
                        'subject' => 'Mercy Oncology Wing',
                        'group' => 'A+',
                        'quantity' => '6 Units',
                        'status' => 'fulfilled',
                        'time' => '3 hours ago',
                    ],
                ],
            ],
        };
    }
}
