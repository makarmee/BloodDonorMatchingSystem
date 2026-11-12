<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    /**
     * Render the Blood Inventory Management page for the authenticated user's role.
     */
    public function __invoke(Request $request): View
    {
        $activeRole = $request->user()?->primaryRoleSlug() ?? 'staff';

        $bloodGroups = $this->sampleBloodGroups();

        $summary = [
            'totalUnits' => array_sum(array_column($bloodGroups, 'available')),
            'totalGroups' => count($bloodGroups),
            'lowStockGroups' => count(array_filter(
                $bloodGroups,
                fn (array $item): bool => $item['status'] === 'low'
            )),
            'criticalStockGroups' => count(array_filter(
                $bloodGroups,
                fn (array $item): bool => $item['status'] === 'critical'
            )),
        ];

        $history = $this->sampleHistory();

        return view('inventory.index', [
            'activeRole' => $activeRole,
            'bloodGroups' => $bloodGroups,
            'summary' => $summary,
            'history' => $history,
        ]);
    }

    /**
     * Build the 8 standard ABO/Rh blood group inventory records.
     *
     * @return array<int, array{
     *     group: string,
     *     available: int,
     *     reserved: int,
     *     minLevel: int,
     *     capacity: int,
     *     status: string,
     *     lastUpdated: string
     * }>
     */
    public function sampleBloodGroups(): array
    {
        $definitions = [
            ['group' => 'A+', 'available' => 64, 'reserved' => 8, 'minLevel' => 30, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:45'],
            ['group' => 'A-', 'available' => 24, 'reserved' => 4, 'minLevel' => 25, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 12:30'],
            ['group' => 'B+', 'available' => 52, 'reserved' => 6, 'minLevel' => 25, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 11:50'],
            ['group' => 'B-', 'available' => 19, 'reserved' => 3, 'minLevel' => 20, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 10:15'],
            ['group' => 'AB+', 'available' => 45, 'reserved' => 5, 'minLevel' => 20, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 09:40'],
            ['group' => 'AB-', 'available' => 12, 'reserved' => 4, 'minLevel' => 20, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:10'],
            ['group' => 'O+', 'available' => 78, 'reserved' => 12, 'minLevel' => 35, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:55'],
            ['group' => 'O-', 'available' => 11, 'reserved' => 5, 'minLevel' => 30, 'capacity' => 100, 'lastUpdated' => 'Nov 11, 2026 • 13:50'],
        ];

        return array_map(function (array $item): array {
            $item['status'] = $this->resolveStatus($item['available'], $item['minLevel']);

            return $item;
        }, $definitions);
    }

    /**
     * Derive the inventory stock status from available units and minimum threshold.
     */
    public function resolveStatus(int $available, int $minLevel): string
    {
        $criticalThreshold = (int) floor($minLevel * 0.6);

        if ($available <= $criticalThreshold) {
            return 'critical';
        }

        if ($available <= $minLevel) {
            return 'low';
        }

        return 'normal';
    }

    /**
     * Build recent inventory audit history entries.
     *
     * @return array<int, array{
     *     id: int,
     *     group: string,
     *     operation: string,
     *     units: int,
     *     date: string,
     *     user: string,
     *     description: string
     * }>
     */
    private function sampleHistory(): array
    {
        return [
            [
                'id' => 106,
                'group' => 'O+',
                'operation' => 'Added',
                'units' => 14,
                'date' => 'Nov 11, 2026 • 13:55',
                'user' => 'Dr. Maya Lin',
                'description' => 'Cleared morning donation batch #DB-2611 from Central Plaza Drive.',
            ],
            [
                'id' => 105,
                'group' => 'AB-',
                'operation' => 'Reserved',
                'units' => 4,
                'date' => 'Nov 11, 2026 • 13:10',
                'user' => 'Samuel Ortega',
                'description' => 'Allocated for City General Hospital ICU Trauma Unit (#REQ-1042).',
            ],
            [
                'id' => 104,
                'group' => 'O-',
                'operation' => 'Removed',
                'units' => 3,
                'date' => 'Nov 11, 2026 • 12:15',
                'user' => 'Nurse Elena Vance',
                'description' => 'Dispatched emergency packed red blood cells to St. Jude Maternity.',
            ],
            [
                'id' => 103,
                'group' => 'A+',
                'operation' => 'Added',
                'units' => 10,
                'date' => 'Nov 11, 2026 • 10:40',
                'user' => 'Dr. Maya Lin',
                'description' => 'Verified serology screening and transferred units to Cold Vault A1.',
            ],
            [
                'id' => 102,
                'group' => 'B+',
                'operation' => 'Reserved',
                'units' => 3,
                'date' => 'Nov 10, 2026 • 17:25',
                'user' => 'Samuel Ortega',
                'description' => 'Scheduled elective cardiac surgery crossmatch hold (#REQ-1038).',
            ],
            [
                'id' => 101,
                'group' => 'A-',
                'operation' => 'Removed',
                'units' => 2,
                'date' => 'Nov 10, 2026 • 15:05',
                'user' => 'Lab Tech Priya Nair',
                'description' => 'Retired 2 units approaching 42-day shelf-life limit per FIFO policy.',
            ],
        ];
    }
}
