<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __construct(
        private PublicPortalController $publicPortal,
    ) {}

    /**
     * Render the public BDMS landing page backed by synchronized sample data.
     */
    public function __invoke(): View
    {
        $bloodStock = $this->publicPortal->publicBloodGroups();
        $stockSummary = $this->publicPortal->publicStockSummary($bloodStock);
        $publicCamps = $this->publicPortal->publicCamps();
        $campSummary = $this->publicPortal->publicCampSummary($publicCamps);

        return view('pages.home', [
            'bloodStock' => $bloodStock,
            'stockSummary' => $stockSummary,
            'upcomingCamps' => array_slice($publicCamps, 0, 3),
            'campSummary' => $campSummary,
            'donationSteps' => $this->publicPortal->donationProcessSteps(),
            'faqs' => $this->publicPortal->publicFaqs(),
            'contactInfo' => $this->publicPortal->publicContactInfo(),
            'todayIso' => PublicPortalController::TODAY_ISO,
        ]);
    }
}
