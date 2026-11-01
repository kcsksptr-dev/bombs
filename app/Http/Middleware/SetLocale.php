<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the UI language: the player's saved choice, then this browser session's choice,
 * then the phone's language (Hungarian phones get Hungarian), then the app default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('bombbusters.locales');

        $locale = $request->user()?->locale
            ?? $request->session()->get('locale')
            ?? $request->getPreferredLanguage($supported);

        if (in_array($locale, $supported, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
