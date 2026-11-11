<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PublicPortalController extends Controller
{
    public const TODAY_ISO = '2026-11-11';

    public function __construct(
        private InventoryController $inventoryController,
        private DonationCampsController $campsController,
    ) {}

    /**
     * Display the public blood stock availability lookup page.
     */
    public function bloodStock(): View
    {
        $bloodGroups = $this->publicBloodGroups();
        $summary = $this->publicStockSummary($bloodGroups);

        return view('public.stock', [
            'bloodGroups' => $bloodGroups,
            'summary' => $summary,
            'todayIso' => self::TODAY_ISO,
        ]);
    }

    /**
     * Display the public upcoming and ongoing donation camps directory.
     */
    public function camps(): View
    {
        $camps = $this->publicCamps();
        $cities = array_values(array_unique(array_column($camps, 'city')));
        sort($cities);

        return view('public.camps.index', [
            'camps' => $camps,
            'cities' => $cities,
            'statuses' => ['Upcoming', 'Ongoing'],
            'campSummary' => $this->publicCampSummary($camps),
            'todayIso' => self::TODAY_ISO,
        ]);
    }

    /**
     * Display public details for a single upcoming or ongoing donation camp.
     */
    public function showCamp(string $camp): View
    {
        $camps = $this->publicCamps();
        $selectedCamp = null;

        foreach ($camps as $item) {
            if (strcasecmp($item['id'], $camp) === 0) {
                $selectedCamp = $item;
                break;
            }
        }

        if ($selectedCamp === null) {
            abort(404);
        }

        $otherCamps = array_values(array_filter(
            $camps,
            fn (array $item): bool => $item['id'] !== $selectedCamp['id']
        ));

        return view('public.camps.show', [
            'camp' => $selectedCamp,
            'otherCamps' => array_slice($otherCamps, 0, 2),
            'todayIso' => self::TODAY_ISO,
        ]);
    }

    /**
     * Return public-safe blood inventory metrics derived directly from InventoryController.
     *
     * @return list<array{
     *     group: string,
     *     available: int,
     *     minLevel: int,
     *     capacity: int,
     *     status: string,
     *     statusLabel: string,
     *     lastUpdated: string
     * }>
     */
    public function publicBloodGroups(): array
    {
        return array_map(function (array $row): array {
            $available = (int) $row['available'];
            $minLevel = (int) $row['minLevel'];
            $status = $this->inventoryController->resolveStatus($available, $minLevel);

            return [
                'group' => (string) $row['group'],
                'available' => $available,
                'minLevel' => $minLevel,
                'capacity' => (int) $row['capacity'],
                'status' => $status,
                'statusLabel' => match ($status) {
                    'critical' => 'Critical',
                    'low' => 'Low Stock',
                    default => 'Normal',
                },
                'lastUpdated' => (string) $row['lastUpdated'],
            ];
        }, $this->inventoryController->sampleBloodGroups());
    }

    /**
     * Compute aggregate public blood stock summary metrics.
     *
     * @param  list<array<string, mixed>>  $bloodGroups
     * @return array{
     *     totalUnits: int,
     *     totalGroups: int,
     *     normalCount: int,
     *     lowCount: int,
     *     criticalCount: int,
     *     urgentGroups: list<string>
     * }
     */
    public function publicStockSummary(array $bloodGroups): array
    {
        $totalUnits = array_sum(array_map(fn (array $row): int => (int) $row['available'], $bloodGroups));
        $normalCount = count(array_filter($bloodGroups, fn (array $row): bool => $row['status'] === 'normal'));
        $lowCount = count(array_filter($bloodGroups, fn (array $row): bool => $row['status'] === 'low'));
        $criticalRows = array_values(array_filter($bloodGroups, fn (array $row): bool => $row['status'] === 'critical'));

        return [
            'totalUnits' => $totalUnits,
            'totalGroups' => count($bloodGroups),
            'normalCount' => $normalCount,
            'lowCount' => $lowCount,
            'criticalCount' => count($criticalRows),
            'urgentGroups' => array_map(fn (array $row): string => (string) $row['group'], $criticalRows),
        ];
    }

    /**
     * Return sanitized Upcoming and Ongoing camps only, excluding Draft, Completed, and Cancelled drives.
     *
     * @return list<array<string, mixed>>
     */
    public function publicCamps(): array
    {
        $filtered = array_values(array_filter(
            $this->campsController->sampleCamps(),
            fn (array $camp): bool => in_array($camp['status'], ['Upcoming', 'Ongoing'], true)
        ));

        return array_map(fn (array $camp): array => $this->sanitizePublicCamp($camp), $filtered);
    }

    /**
     * Compute summary counts across public Upcoming and Ongoing camps.
     *
     * @param  list<array<string, mixed>>  $camps
     * @return array{
     *     totalCamps: int,
     *     upcomingCount: int,
     *     ongoingCount: int,
     *     totalAvailableSlots: int,
     *     openRegistrationCount: int
     * }
     */
    public function publicCampSummary(array $camps): array
    {
        $upcomingCount = count(array_filter($camps, fn (array $camp): bool => $camp['status'] === 'Upcoming'));
        $ongoingCount = count(array_filter($camps, fn (array $camp): bool => $camp['status'] === 'Ongoing'));
        $totalAvailableSlots = array_sum(array_map(fn (array $camp): int => (int) $camp['availableSlots'], $camps));
        $openRegistrationCount = count(array_filter($camps, fn (array $camp): bool => (bool) $camp['isBookingOpen']));

        return [
            'totalCamps' => count($camps),
            'upcomingCount' => $upcomingCount,
            'ongoingCount' => $ongoingCount,
            'totalAvailableSlots' => $totalAvailableSlots,
            'openRegistrationCount' => $openRegistrationCount,
        ];
    }

    /**
     * Return step-by-step overview of the public blood donation journey.
     *
     * @return list<array{step: string, title: string, duration: string, description: string, icon: string}>
     */
    public function donationProcessSteps(): array
    {
        return [
            [
                'step' => '01',
                'title' => 'Eligibility Check & Booking',
                'duration' => '2 mins',
                'description' => 'Confirm basic readiness guidelines and reserve a timeslot at an open community camp or donation center via Donor Portal.',
                'icon' => 'shield-check',
            ],
            [
                'step' => '02',
                'title' => 'Registration & Mini-Physical',
                'duration' => '10 mins',
                'description' => 'Present photo ID at check-in for confidential health screening, blood pressure, temperature, and hemoglobin verification.',
                'icon' => 'clipboard-list',
            ],
            [
                'step' => '03',
                'title' => 'Guided Blood Collection',
                'duration' => '10-15 mins',
                'description' => 'Donate comfortably under licensed phlebotomy care using sterile, single-use collection kits.',
                'icon' => 'droplet',
            ],
            [
                'step' => '04',
                'title' => 'Recovery & Lab Screening',
                'duration' => '15 mins rest',
                'description' => 'Enjoy post-donation hydration and snacks while laboratory screening verifies unit safety before inventory dispatch.',
                'icon' => 'heart-pulse',
            ],
        ];
    }

    /**
     * Return public FAQ items covering donation eligibility, process, and demo behavior.
     *
     * @return list<array{id: string, question: string, answer: string}>
     */
    public function publicFaqs(): array
    {
        return [
            [
                'id' => 'faq-interval',
                'question' => 'How often can eligible donors give whole blood?',
                'answer' => 'Under the standard sample recovery rule displayed across BDMS, eligible whole-blood donors wait at least 56 days between donations and must meet routine hemoglobin, weight, and general wellness requirements.',
            ],
            [
                'id' => 'faq-stock',
                'question' => 'How are public blood stock statuses calculated?',
                'answer' => 'All public availability counts and Critical, Low Stock, or Normal badges are synchronized directly with the Inventory Management thresholds. Reserved hospital allocations and internal audit logs remain hidden from public views.',
            ],
            [
                'id' => 'faq-camps',
                'question' => 'Which donation camps appear in the public directory?',
                'answer' => 'Only published Upcoming and Ongoing community drives are visible publicly. Draft plans, completed drives, cancelled drives, and registered donor lists are never exposed on public pages.',
            ],
            [
                'id' => 'faq-walkin',
                'question' => 'Can I attend an ongoing camp without pre-booking?',
                'answer' => 'Only ongoing camps flagged as accepting walk-ins permit on-site triage while slots remain. Camps marked Pre-Booked Only require a confirmed appointment badge for venue security.',
            ],
            [
                'id' => 'faq-prepare',
                'question' => 'What should I bring and do before arriving at a camp?',
                'answer' => 'Bring a valid government-issued photo ID, drink plenty of water (around 500 ml) beforehand, eat an iron-rich meal, and avoid fasting immediately prior to your appointment.',
            ],
            [
                'id' => 'faq-preview',
                'question' => 'Does submitting a form on this portal write to a live hospital database?',
                'answer' => 'No. This release is a frontend preview powered by curated sample datasets. Booking CTAs guide you into the Donor Portal preview flow without persisting records to a production database.',
            ],
        ];
    }

    /**
     * Return explicitly marked demo placeholder contact details for public inquiries.
     *
     * @return array<string, string>
     */
    public function publicContactInfo(): array
    {
        return [
            'notice' => 'Demo Placeholder Details — Sample coordination channels only; no real-world phone numbers or live mailboxes are connected.',
            'phone' => '[Demo Placeholder] +0 (000) 000-0100 — Donor Coordination Desk',
            'email' => '[Demo Placeholder] support@demo-bdms.invalid',
            'address' => '[Demo Placeholder] Central Blood Coordination Hub, Suite 100 (Preview Environment)',
            'hours' => '[Demo Placeholder] Mon–Sat, 08:00 – 18:00 (Sample Operations Window)',
        ];
    }

    /**
     * Strip private staff/donor arrays and compute safe public booking indicators for a camp.
     *
     * @param  array<string, mixed>  $camp
     * @return array<string, mixed>
     */
    private function sanitizePublicCamp(array $camp): array
    {
        $availableSlots = (int) $camp['availableSlots'];
        $capacity = (int) $camp['capacity'];
        $status = (string) $camp['status'];
        $allowWalkIn = (bool) $camp['allowWalkIn'];
        $deadlinePassed = (string) $camp['registrationDeadline'] < self::TODAY_ISO;

        if ($availableSlots <= 0) {
            $availabilityState = 'full';
            $availabilityLabel = 'Capacity Full';
            $availabilityNote = 'All scheduled slots are filled.';
            $isBookingOpen = false;
        } elseif ($status === 'Upcoming' && $deadlinePassed) {
            $availabilityState = 'deadline_passed';
            $availabilityLabel = 'Registration Closed';
            $availabilityNote = 'Advance booking closed on '.$camp['registrationDeadlineFormatted'].'.';
            $isBookingOpen = false;
        } elseif ($status === 'Ongoing' && ! $allowWalkIn) {
            $availabilityState = 'walk_in_closed';
            $availabilityLabel = 'Pre-Booked Only';
            $availabilityNote = 'Active drive is limited to confirmed appointments; walk-ins closed.';
            $isBookingOpen = false;
        } elseif ($status === 'Ongoing') {
            $availabilityState = 'walk_in_open';
            $availabilityLabel = 'Walk-ins Open';
            $availabilityNote = 'Active collection drive welcoming walk-in and scheduled donors.';
            $isBookingOpen = true;
        } else {
            $availabilityState = 'open';
            $availabilityLabel = 'Open for Registration';
            $availabilityNote = 'Booking open through '.$camp['registrationDeadlineFormatted'].'.';
            $isBookingOpen = true;
        }

        $scheduleSummary = $camp['startDate'] === $camp['endDate']
            ? (string) $camp['startDateFormatted']
            : "{$camp['startDateFormatted']} – {$camp['endDateFormatted']}";

        return [
            'id' => (string) $camp['id'],
            'name' => (string) $camp['name'],
            'description' => (string) $camp['description'],
            'venueName' => (string) $camp['venueName'],
            'fullAddress' => (string) $camp['fullAddress'],
            'city' => (string) $camp['city'],
            'locationNotes' => (string) $camp['locationNotes'],
            'startDate' => (string) $camp['startDate'],
            'startDateFormatted' => (string) $camp['startDateFormatted'],
            'endDate' => (string) $camp['endDate'],
            'endDateFormatted' => (string) $camp['endDateFormatted'],
            'scheduleSummary' => $scheduleSummary,
            'startTime' => (string) $camp['startTime'],
            'endTime' => (string) $camp['endTime'],
            'capacity' => $capacity,
            'registeredDonors' => (int) $camp['registeredDonors'],
            'availableSlots' => $availableSlots,
            'registrationDeadline' => (string) $camp['registrationDeadline'],
            'registrationDeadlineFormatted' => (string) $camp['registrationDeadlineFormatted'],
            'allowWalkIn' => $allowWalkIn,
            'donorRequirements' => (string) $camp['donorRequirements'],
            'additionalNotes' => (string) $camp['additionalNotes'],
            'status' => $status,
            'isBookingOpen' => $isBookingOpen,
            'availabilityState' => $availabilityState,
            'availabilityLabel' => $availabilityLabel,
            'availabilityNote' => $availabilityNote,
        ];
    }
}
