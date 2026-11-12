<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Donor;
use App\Services\AccountRegistrationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    private const int MAX_REGISTRATION_ATTEMPTS = 5;

    private const int LOCKOUT_SECONDS = 60;

    public function __construct(
        private AccountRegistrationService $registrationService
    ) {}

    /**
     * Display the public BDMS registration form.
     */
    public function create(): View
    {
        return view('auth.register', [
            'bloodGroups' => Donor::BLOOD_GROUPS,
        ]);
    }

    /**
     * Validate and process a self-registration submission for Stage A2.1 (Donor only).
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $throttleKey = $this->throttleKey((string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_REGISTRATION_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many registration attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

        if (is_string($request->input('email'))) {
            $request->merge([
                'email' => Str::lower(trim($request->input('email'))),
            ]);
        }

        $minDob = CarbonImmutable::today()->subYears(110)->toDateString();
        $maxDob = CarbonImmutable::today()->subYears(16)->toDateString();

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(['donor'])],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'blood_group' => ['exclude_unless:role,donor', 'nullable', 'string', Rule::in(Donor::BLOOD_GROUPS)],
            'date_of_birth' => [
                'exclude_unless:role,donor',
                'nullable',
                'date',
                "after_or_equal:{$minDob}",
                "before_or_equal:{$maxDob}",
            ],
            'password' => ['required', 'string', Password::min(8), 'confirmed'],
        ], [
            'role.in' => $request->input('role') === 'hospital'
                ? 'Hospital partner registration is not enabled yet. Please select Blood Donor or check back in the next stage.'
                : 'Please select a valid registration account type.',
            'date_of_birth.after_or_equal' => 'Donor age must not exceed 110 years.',
            'date_of_birth.before_or_equal' => 'Donors must be at least 16 years old to register.',
        ]);

        $user = $this->registrationService->registerDonor(Arr::only($validated, [
            'name',
            'email',
            'blood_group',
            'date_of_birth',
            'password',
        ]));

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()
            ->route($user->dashboardRouteName())
            ->with('status', 'Donor account created. Your profile is pending clinical eligibility review.');
    }

    /**
     * Build the per-IP rate-limiting key for public registration requests.
     */
    private function throttleKey(string $ipAddress): string
    {
        return 'register|'.Str::transliterate($ipAddress);
    }
}
