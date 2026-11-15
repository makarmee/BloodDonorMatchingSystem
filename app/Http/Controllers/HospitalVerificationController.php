<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\User;
use App\Services\HospitalVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class HospitalVerificationController extends Controller
{
    public function __construct(
        private HospitalVerificationService $verificationService,
    ) {}

    /**
     * Display the hospital verification queue with Pending hospitals listed first.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Hospital::class);

        $summary = [
            'total' => Hospital::query()->count(),
            'pending' => Hospital::query()->where('verification_status', 'Pending')->count(),
            'verified' => Hospital::query()->where('verification_status', 'Verified')->count(),
            'suspended' => Hospital::query()->where('verification_status', 'Suspended')->count(),
        ];

        $query = Hospital::query()
            ->with(['hospitalUsers.user', 'verifiedBy']);

        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            if (in_array($status, Hospital::VERIFICATION_STATUSES, true)) {
                $query->where('verification_status', $status);
            }
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($sub) use ($search): void {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('hospital_code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('primary_contact_name', 'like', "%{$search}%");
            });
        }

        $hospitals = $query
            ->orderByRaw("CASE WHEN verification_status = 'Pending' THEN 1 WHEN verification_status = 'Verified' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('hospitals.index', [
            'hospitals' => $hospitals,
            'summary' => $summary,
            'currentStatus' => $request->query('status'),
            'search' => $request->query('search'),
        ]);
    }

    /**
     * Display hospital facility details, current status, coordinator contact, and verification audit.
     */
    public function show(Hospital $hospital): View
    {
        Gate::authorize('view', $hospital);

        $hospital->loadMissing(['hospitalUsers.user', 'verifiedBy']);

        return view('hospitals.show', [
            'hospital' => $hospital,
        ]);
    }

    /**
     * Verify a Pending or Suspended partner hospital.
     */
    public function verify(Request $request, Hospital $hospital): RedirectResponse
    {
        Gate::authorize('verify', $hospital);

        /** @var User $actor */
        $actor = $request->user();

        $this->verificationService->verifyHospital($hospital, $actor);

        return redirect()
            ->route('hospitals.show', $hospital)
            ->with('success', "Partner hospital [{$hospital->name}] has been successfully verified.");
    }

    /**
     * Suspend an active, Verified partner hospital.
     */
    public function suspend(Request $request, Hospital $hospital): RedirectResponse
    {
        Gate::authorize('suspend', $hospital);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $this->verificationService->suspendHospital($hospital, $actor, $validated['reason'] ?? null);

        return redirect()
            ->route('hospitals.show', $hospital)
            ->with('success', "Partner hospital [{$hospital->name}] has been suspended.");
    }
}
