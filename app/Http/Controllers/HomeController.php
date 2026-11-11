<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    /**
     * Render the public BDMS landing page with initial frontend preview data.
     */
    public function __invoke(): View
    {
        $bloodStock = [
            ['group' => 'O+', 'units' => 76, 'capacity' => 100],
            ['group' => 'O-', 'units' => 14, 'capacity' => 100],
            ['group' => 'A+', 'units' => 64, 'capacity' => 100],
            ['group' => 'A-', 'units' => 28, 'capacity' => 100],
            ['group' => 'B+', 'units' => 52, 'capacity' => 100],
            ['group' => 'B-', 'units' => 22, 'capacity' => 100],
            ['group' => 'AB+', 'units' => 45, 'capacity' => 100],
            ['group' => 'AB-', 'units' => 18, 'capacity' => 100],
        ];

        $upcomingCamps = [
            [
                'title' => 'Central Medical Plaza Drive',
                'date' => 'Nov 18, 2026',
                'time' => '09:00 AM – 04:00 PM',
                'location' => 'Main Atrium, Downtown Medical District',
                'slotsLeft' => 24,
            ],
            [
                'title' => 'University Health Campus Campaign',
                'date' => 'Nov 22, 2026',
                'time' => '10:00 AM – 05:00 PM',
                'location' => 'Student Union Hall B',
                'slotsLeft' => 41,
            ],
            [
                'title' => 'Northside Community Blood Drive',
                'date' => 'Nov 29, 2026',
                'time' => '08:30 AM – 02:30 PM',
                'location' => 'Civic Center East Wing',
                'slotsLeft' => 12,
            ],
        ];

        return view('pages.home', [
            'bloodStock' => $bloodStock,
            'upcomingCamps' => $upcomingCamps,
        ]);
    }
}
