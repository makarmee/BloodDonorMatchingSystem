<?php

namespace App\Http\Controllers;

use App\Models\BloodComponent;
use App\Models\BloodRequest;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorAppointment;
use App\Models\Hospital;
use App\Models\InventoryStock;
use App\Models\ScreeningRecord;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Render the role-aware BDMS dashboard for the authenticated user.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $activeRole = $user?->primaryRoleSlug();

        $hospital = null;
        if ($activeRole === 'hospital' && $user) {
            $hospital = $user->hospitalUser()
                ->where('is_active', true)
                ->with('hospital')
                ->first()
                ?->hospital;
        }

        $portalData = $this->portalPayload($activeRole, $user, $hospital);

        $donationFormData = [];
        if (in_array($activeRole, ['admin', 'staff'], true)) {
            $donationFormData = $this->buildDonationFormData();
        }

        return view('dashboard.index', [
            'activeRole' => $activeRole,
            'portalData' => $portalData,
            'hospital' => $hospital,
            'donationFormData' => $donationFormData,
        ]);
    }

    /**
     * Build real database metrics and activity rows for each BDMS portal role.
     *
     * @return array{
     *     pageTitle: string,
     *     subtitle: string,
     *     kpis: array<int, array{label: string, value: string, trend: string, trendTone: string, icon: string, accent: string}>,
     *     inventorySnapshot: array<int, array{group: string, units: int, capacity: int}>,
     *     recentActivity: array<int, array{reference: string, subject: string, group: string, quantity: string, status: string, time: string}>
     * }
     */
    private function portalPayload(?string $role, ?User $user = null, ?Hospital $hospital = null): array
    {
        $inventorySnapshot = $this->buildInventorySnapshot();

        return match ($role) {
            'donor' => $this->buildDonorPortalPayload($user, $inventorySnapshot),
            'hospital' => $this->buildHospitalPortalPayload($hospital, $inventorySnapshot),
            'lab_officer' => $this->buildLabOfficerPortalPayload($inventorySnapshot),
            'admin', 'staff' => $this->buildStaffPortalPayload($inventorySnapshot),
            default => [
                'pageTitle' => 'Account Overview',
                'subtitle' => 'Your account is active, but no portal role has been assigned yet. Contact an administrator to enable role-specific access.',
                'kpis' => [],
                'inventorySnapshot' => [],
                'recentActivity' => [],
            ],
        };
    }

    /**
     * Build operational dashboard payload for administrators and staff members.
     *
     * @param  array<int, array{group: string, units: int, capacity: int}>  $inventorySnapshot
     * @return array<string, mixed>
     */
    private function buildStaffPortalPayload(array $inventorySnapshot): array
    {
        $totalUnits = (int) (InventoryStock::query()->sum('available_units') ?: 0);
        $unitsAddedToday = BloodUnit::query()
            ->where('unit_status', 'Available')
            ->whereDate('released_at', now()->toDateString())
            ->count();

        $totalDonors = Donor::query()->count();
        $activeDonors = Donor::query()->where('account_status', 'Active')->count();

        $pendingRequests = BloodRequest::query()
            ->whereIn('status', ['Pending', 'Approved', 'Partially Fulfilled'])
            ->count();
        $criticalRequests = BloodRequest::query()
            ->whereIn('status', ['Pending', 'Approved', 'Partially Fulfilled'])
            ->where('priority', 'Critical')
            ->count();

        $expiringUnits = BloodUnit::query()
            ->where('unit_status', 'Available')
            ->where('expires_at', '<=', now()->addDays(7))
            ->where('expires_at', '>=', now())
            ->count();

        $kpis = [
            [
                'label' => 'Total Available Units',
                'value' => number_format($totalUnits),
                'trend' => $unitsAddedToday > 0 ? __(':count screened units added today', ['count' => "+{$unitsAddedToday}"]) : __('Screened units in inventory'),
                'trendTone' => $unitsAddedToday > 0 ? 'up' : 'neutral',
                'icon' => 'droplet',
                'accent' => 'crimson',
            ],
            [
                'label' => 'Registered Donors',
                'value' => number_format($totalDonors),
                'trend' => __(':count active registered donors', ['count' => $activeDonors]),
                'trendTone' => 'up',
                'icon' => 'users',
                'accent' => 'sky',
            ],
            [
                'label' => 'Pending Requests',
                'value' => number_format($pendingRequests),
                'trend' => __(':count critical priority requests', ['count' => $criticalRequests]),
                'trendTone' => $criticalRequests > 0 ? 'down' : 'neutral',
                'icon' => 'clipboard-list',
                'accent' => 'amber',
            ],
            [
                'label' => 'Expiring Within 7d',
                'value' => number_format($expiringUnits),
                'trend' => 'Prioritize FIFO dispatch rotation',
                'trendTone' => $expiringUnits > 0 ? 'down' : 'neutral',
                'icon' => 'activity',
                'accent' => 'emerald',
            ],
        ];

        $recentActivity = [];
        $recentRequests = BloodRequest::query()
            ->with(['hospital', 'bloodComponent'])
            ->latest('created_at')
            ->limit(5)
            ->get();

        if ($recentRequests->isNotEmpty()) {
            foreach ($recentRequests as $req) {
                $recentActivity[] = [
                    'reference' => $req->request_code,
                    'subject' => $req->hospital?->name ?? 'Hospital Request',
                    'group' => $req->blood_group,
                    'quantity' => __(':count Units', ['count' => $req->requested_units]),
                    'status' => strtolower($req->priority ?? 'routine'),
                    'time' => $req->created_at?->diffForHumans() ?? 'Recently',
                ];
            }
        } else {
            $recentDonations = Donation::query()
                ->with(['donor'])
                ->latest('created_at')
                ->limit(5)
                ->get();

            foreach ($recentDonations as $don) {
                $recentActivity[] = [
                    'reference' => $don->donation_code,
                    'subject' => $don->donor?->full_name ?? ($don->collection_location_name ?? 'Donation Intake'),
                    'group' => $don->blood_group,
                    'quantity' => __(':count ml', ['count' => $don->quantity_ml]),
                    'status' => strtolower($don->collection_status === 'Completed' ? 'fulfilled' : 'routine'),
                    'time' => $don->created_at?->diffForHumans() ?? 'Recently',
                ];
            }
        }

        return [
            'pageTitle' => 'Blood Bank Operations Dashboard',
            'subtitle' => 'Real-time blood inventory levels, donor intake, and hospital fulfillment queue.',
            'kpis' => $kpis,
            'inventorySnapshot' => $inventorySnapshot,
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * Build dashboard payload for hospital requester coordinators.
     *
     * @param  array<int, array{group: string, units: int, capacity: int}>  $inventorySnapshot
     * @return array<string, mixed>
     */
    private function buildHospitalPortalPayload(?Hospital $hospital, array $inventorySnapshot): array
    {
        $activeQuery = BloodRequest::query()
            ->whereIn('status', ['Pending', 'Approved', 'Partially Fulfilled']);
        $fulfilledQuery = BloodRequest::query()
            ->where('status', 'Fulfilled');

        if ($hospital) {
            $activeQuery->where('hospital_id', $hospital->id);
            $fulfilledQuery->where('hospital_id', $hospital->id);
        }

        $activeCount = $activeQuery->count();
        $fulfilledUnits30d = (int) $fulfilledQuery->where('updated_at', '>=', now()->subDays(30))->sum('fulfilled_units');
        $fulfilledCount = $fulfilledQuery->count();
        $activeHubs = DonationCenter::query()->where('is_active', true)->count() ?: 1;

        $kpis = [
            [
                'label' => 'Active Blood Requests',
                'value' => number_format($activeCount),
                'trend' => $activeCount > 0 ? __(':count requests in fulfillment queue', ['count' => $activeCount]) : __('No active requests'),
                'trendTone' => $activeCount > 0 ? 'down' : 'neutral',
                'icon' => 'clipboard-list',
                'accent' => 'crimson',
            ],
            [
                'label' => 'Units Fulfilled (30d)',
                'value' => number_format($fulfilledUnits30d),
                'trend' => __(':count requests successfully fulfilled', ['count' => $fulfilledCount]),
                'trendTone' => 'up',
                'icon' => 'check-circle',
                'accent' => 'emerald',
            ],
            [
                'label' => 'Facility Verification',
                'value' => $hospital?->verification_status ?? 'Unverified',
                'trend' => $hospital && $hospital->verification_status === 'Verified' ? 'Authorized hospital partner' : 'Credentials under review',
                'trendTone' => $hospital && $hospital->verification_status === 'Verified' ? 'up' : 'neutral',
                'icon' => 'shield-check',
                'accent' => 'sky',
            ],
            [
                'label' => 'Partner Blood Banks',
                'value' => (string) $activeHubs,
                'trend' => 'Regional blood centers active',
                'trendTone' => 'neutral',
                'icon' => 'building-hospital',
                'accent' => 'amber',
            ],
        ];

        $recentRequestsQuery = BloodRequest::query()
            ->with(['hospital', 'bloodComponent'])
            ->latest('created_at')
            ->limit(5);

        if ($hospital) {
            $recentRequestsQuery->where('hospital_id', $hospital->id);
        }

        $recentActivity = [];
        foreach ($recentRequestsQuery->get() as $req) {
            $recentActivity[] = [
                'reference' => $req->request_code,
                'subject' => $req->hospital?->name ?? 'Hospital Request',
                'group' => $req->blood_group,
                'quantity' => __(':count Units', ['count' => $req->requested_units]),
                'status' => strtolower($req->priority ?? 'routine'),
                'time' => $req->created_at?->diffForHumans() ?? 'Recently',
            ];
        }

        return [
            'pageTitle' => 'Hospital Requester Portal',
            'subtitle' => 'Monitor blood request fulfillment, emergency dispatch ETA, and regional supply.',
            'kpis' => $kpis,
            'inventorySnapshot' => $inventorySnapshot,
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * Build laboratory screening dashboard payload for laboratory technicians and officers.
     *
     * @param  array<int, array{group: string, units: int, capacity: int}>  $inventorySnapshot
     * @return array<string, mixed>
     */
    private function buildLabOfficerPortalPayload(array $inventorySnapshot): array
    {
        $pendingScreening = ScreeningRecord::query()->where('screening_result', 'Pending')->count();
        $clearedToday = ScreeningRecord::query()
            ->where('screening_result', 'Passed')
            ->whereDate('result_recorded_at', now()->toDateString())
            ->count();
        $quarantinedUnits = BloodUnit::query()->where('unit_status', 'Quarantined')->count();
        $donorCount = Donor::query()->count();

        $kpis = [
            [
                'label' => 'Pending Screening Queue',
                'value' => number_format($pendingScreening),
                'trend' => 'Units awaiting laboratory review',
                'trendTone' => $pendingScreening > 0 ? 'down' : 'neutral',
                'icon' => 'shield-check',
                'accent' => 'amber',
            ],
            [
                'label' => 'Cleared Today',
                'value' => number_format($clearedToday),
                'trend' => 'Passed tests recorded today',
                'trendTone' => 'up',
                'icon' => 'check-circle',
                'accent' => 'emerald',
            ],
            [
                'label' => 'Quarantined Units',
                'value' => number_format($quarantinedUnits),
                'trend' => 'Awaiting complete panel release',
                'trendTone' => $quarantinedUnits > 0 ? 'neutral' : 'up',
                'icon' => 'activity',
                'accent' => 'crimson',
            ],
            [
                'label' => 'Donor Records Accessible',
                'value' => number_format($donorCount),
                'trend' => 'Medical eligibility records',
                'trendTone' => 'neutral',
                'icon' => 'users',
                'accent' => 'sky',
            ],
        ];

        $recentActivity = [];
        $recentScreenings = ScreeningRecord::query()
            ->with(['donation', 'testType'])
            ->latest('id')
            ->limit(5)
            ->get();

        foreach ($recentScreenings as $scr) {
            $status = match ($scr->screening_result) {
                'Passed' => 'fulfilled',
                'Failed' => 'critical',
                default => 'pending',
            };

            $recentActivity[] = [
                'reference' => $scr->screening_code,
                'subject' => $scr->testType?->name ?? 'Screening Assay',
                'group' => $scr->donation?->blood_group ?? 'N/A',
                'quantity' => __('1 Specimen'),
                'status' => $status,
                'time' => $scr->created_at?->diffForHumans() ?? 'Recently',
            ];
        }

        return [
            'pageTitle' => 'Laboratory Screening Dashboard',
            'subtitle' => 'Review pending infectious disease screening queues, confirmatory tests, and quarantine releases.',
            'kpis' => $kpis,
            'inventorySnapshot' => $inventorySnapshot,
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * Build donor portal overview payload for individual donors.
     *
     * @param  array<int, array{group: string, units: int, capacity: int}>  $inventorySnapshot
     * @return array<string, mixed>
     */
    private function buildDonorPortalPayload(?User $user, array $inventorySnapshot): array
    {
        $donor = $user?->donor;

        $bloodGroup = $donor?->blood_group ?? 'Unknown';
        $eligibilityStatus = $donor?->eligibility_status ?? 'Pending Review';
        $completedDonations = (string) ($donor?->total_completed_donations ?? 0);

        $nextAppointment = 'None Scheduled';
        if ($donor) {
            $nextApt = DonorAppointment::query()
                ->where('donor_id', $donor->id)
                ->whereIn('status', ['Pending', 'Confirmed'])
                ->where('appointment_date', '>=', now()->toDateString())
                ->orderBy('appointment_date')
                ->first();

            if ($nextApt) {
                $nextAppointment = $nextApt->appointment_date?->format('M d') ?? 'Scheduled';
            }
        }

        $kpis = [
            [
                'label' => 'Blood Group',
                'value' => $bloodGroup,
                'trend' => 'Recorded donor blood type',
                'trendTone' => 'neutral',
                'icon' => 'droplet',
                'accent' => 'crimson',
            ],
            [
                'label' => 'Eligibility Status',
                'value' => $eligibilityStatus,
                'trend' => 'Current clinical qualification',
                'trendTone' => $eligibilityStatus === 'Eligible' ? 'up' : 'neutral',
                'icon' => 'shield-check',
                'accent' => 'emerald',
            ],
            [
                'label' => 'Completed Donations',
                'value' => $completedDonations,
                'trend' => 'Verified lifetime contributions',
                'trendTone' => 'up',
                'icon' => 'heart-pulse',
                'accent' => 'sky',
            ],
            [
                'label' => 'Next Appointment',
                'value' => $nextAppointment,
                'trend' => 'Upcoming scheduled visit',
                'trendTone' => 'neutral',
                'icon' => 'calendar',
                'accent' => 'amber',
            ],
        ];

        $recentActivity = [];
        if ($donor) {
            $donations = Donation::query()
                ->where('donor_id', $donor->id)
                ->latest('donation_date')
                ->limit(3)
                ->get();

            foreach ($donations as $don) {
                $recentActivity[] = [
                    'reference' => $don->donation_code,
                    'subject' => $don->collection_location_name ?? 'Donation Center',
                    'group' => $don->blood_group,
                    'quantity' => __(':count ml', ['count' => $don->quantity_ml]),
                    'status' => strtolower($don->collection_status === 'Completed' ? 'completed' : 'pending'),
                    'time' => $don->donation_date?->format('M d, Y') ?? 'Recorded',
                ];
            }
        }

        return [
            'pageTitle' => 'Donor Portal Overview',
            'subtitle' => 'Track donation eligibility, upcoming appointments, and blood drive history.',
            'kpis' => $kpis,
            'inventorySnapshot' => $inventorySnapshot,
            'recentActivity' => $recentActivity,
        ];
    }

    /**
     * Build regional stock snapshot from real database inventory stocks.
     *
     * @return array<int, array{group: string, units: int, capacity: int}>
     */
    private function buildInventorySnapshot(): array
    {
        $stocks = InventoryStock::query()->get();

        $snapshot = [];
        foreach (BloodUnit::BLOOD_GROUPS as $group) {
            $matching = $stocks->where('blood_group', $group);
            $units = (int) $matching->sum('available_units');
            $capacity = (int) ($matching->sum('target_capacity') ?: 100);

            $snapshot[] = [
                'group' => $group,
                'units' => $units,
                'capacity' => $capacity,
            ];
        }

        // Sort by available units ASC to prioritize lowest stock levels on critical watch
        usort($snapshot, fn (array $a, array $b): int => $a['units'] <=> $b['units']);

        return array_slice($snapshot, 0, 4);
    }

    /**
     * Build reference options for the donation registration modal form on the dashboard.
     *
     * @return array{
     *     donors: array<int, array{id: string, name: string, bloodGroup: string, isEligible: bool, eligibilityStatus: string, email: string, phone: string}>,
     *     centers: Collection<int, DonationCenter>,
     *     camps: Collection<int, DonationCamp>,
     *     components: Collection<int, BloodComponent>,
     * }
     */
    private function buildDonationFormData(): array
    {
        if (DonationCenter::query()->count() === 0) {
            DonationCenter::query()->create([
                'center_code' => 'CTR-001',
                'name' => 'Main Blood Donation Center',
                'address' => 'Central Plaza, Suite 100',
                'city' => 'Central City',
                'operating_hours' => '08:00 - 18:00',
                'default_slot_capacity' => 5,
                'is_active' => true,
            ]);
            DonationCenter::query()->create([
                'center_code' => 'CTR-002',
                'name' => 'Westgate Transfusion Suite',
                'address' => '450 Westgate Blvd',
                'city' => 'Westgate',
                'operating_hours' => '09:00 - 17:00',
                'default_slot_capacity' => 4,
                'is_active' => true,
            ]);
        }

        $donors = Donor::query()
            ->active()
            ->with(['currentEligibilityRecord'])
            ->orderBy('full_name')
            ->get()
            ->map(function (Donor $d): array {
                $isEligible = $d->account_status === 'Active'
                    && $d->profile_completed
                    && ($d->currentEligibilityRecord?->eligibility_status === 'Eligible' || $d->eligibility_status === 'Eligible');

                return [
                    'id' => $d->donor_code,
                    'name' => $d->full_name,
                    'bloodGroup' => $d->blood_group,
                    'isEligible' => (bool) $isEligible,
                    'eligibilityStatus' => $d->currentEligibilityRecord?->eligibility_status ?? $d->eligibility_status ?? 'Unknown',
                    'email' => $d->email,
                    'phone' => $d->phone ?? '',
                ];
            })
            ->all();

        $centers = DonationCenter::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'center_code', 'name', 'city']);

        $camps = DonationCamp::query()
            ->whereIn('status', ['Upcoming', 'Ongoing'])
            ->orderBy('name')
            ->get(['id', 'camp_code', 'name', 'city', 'venue_name']);

        $components = BloodComponent::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'default_volume_ml']);

        $staffMembers = Staff::query()
            ->active()
            ->orderBy('full_name')
            ->get(['id', 'employee_id', 'full_name', 'professional_role']);

        return [
            'donors' => $donors,
            'centers' => $centers,
            'camps' => $camps,
            'components' => $components,
            'staffMembers' => $staffMembers,
        ];
    }
}
