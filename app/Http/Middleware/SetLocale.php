<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->language
            ?? $request->session()->get('locale')
            ?? substr((string) $request->getPreferredLanguage(['ru', 'kk']), 0, 2);

        if (in_array($locale, ['ru', 'kk'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
