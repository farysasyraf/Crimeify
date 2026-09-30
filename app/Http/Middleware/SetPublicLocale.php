<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

// The public pages' language: Bahasa Melayu or English. ?lang=ms or ?lang=en chooses it, from the switch at the top
// (layouts/_language-switch), and a cookie keeps it for a year, so the dashboard, the map and the map's figures stay in
// it. Without either, it's English (defaultLocale()). Their text in Malay is in lang/ms.json.
class SetPublicLocale
{
    /**
     * The languages the public pages come in.
     */
    public const Locales = ['en', 'ms'];

    /**
     * The cookie that keeps the language chosen.
     */
    public const Cookie = 'locale';

    /**
     * The language without a choice: English. From app.fallback_locale, as setLocale() changes app.locale too.
     */
    public static function defaultLocale(): string
    {
        return config('app.fallback_locale', 'en');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = $this->known($request->query('lang'));
        App::setLocale($chosen ?? $this->known($request->cookie(self::Cookie)) ?? self::defaultLocale());

        $response = $next($request);

        if ($chosen !== null) {
            $response->headers->setCookie(cookie()->forever(self::Cookie, $chosen));
        }

        return $response;
    }

    private function known(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, self::Locales, true) ? $locale : null;
    }
}
