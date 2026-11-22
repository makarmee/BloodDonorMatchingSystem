<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Supported application locales.
     *
     * @var list<string>
     */
    public const array SUPPORTED_LOCALES = ['en', 'fa'];

    /**
     * Handle an incoming request and apply the user's selected language preference.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sessionLocale = $request->hasSession() ? $request->session()->get('locale') : null;

        if (is_string($sessionLocale) && in_array($sessionLocale, self::SUPPORTED_LOCALES, true)) {
            $locale = $sessionLocale;
        } else {
            $locale = config('app.locale', 'en');
        }

        app()->setLocale($locale);

        // Gregorian dates remain authoritative; set Carbon locale for natural weekday/month labels
        try {
            Carbon::setLocale($locale === 'fa' ? 'fa' : 'en');
        } catch (\Throwable) {
            // Keep default if Carbon locale is unavailable in the environment
        }

        return $next($request);
    }
}

