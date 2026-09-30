{{-- Bahasa Melayu or English, for the public pages (SetPublicLocale): a pill with the chosen language filled in, the
     Malaysian flag beside BM and the UK's beside EN. Each is a link to this page in that language (?lang=), so it
     works without JavaScript; the choice is kept in a cookie. The flags are drawn here, as Windows has no flag emoji. --}}
@php
    $locale = app()->getLocale();
    $languages = ['ms' => ['BM', 'Bahasa Melayu'], 'en' => ['EN', 'English']];
@endphp
<nav class="lang-switch" aria-label="{{ __('Language') }}">
    <svg class="lang-flag" viewBox="0 0 28 14" aria-hidden="true" focusable="false">
        <rect width="28" height="14" fill="#fff" />
        <path fill="#cc0001" d="M0 0h28v1H0zM0 2h28v1H0zM0 4h28v1H0zM0 6h28v1H0zM0 8h28v1H0zM0 10h28v1H0zM0 12h28v1H0z" />
        <rect width="14" height="8" fill="#010066" />
        <circle cx="5.3" cy="4" r="3" fill="#fc0" />
        <circle cx="6.1" cy="4" r="2.55" fill="#010066" />
        <polygon fill="#fc0" points="9.2,1.55 9.42,3.03 10.26,1.79 9.82,3.22 11.12,2.47 10.1,3.57 11.59,3.45 10.2,4 11.59,4.55 10.1,4.43 11.12,5.53 9.82,4.78 10.26,6.21 9.42,4.97 9.2,6.45 8.98,4.97 8.14,6.21 8.58,4.78 7.28,5.53 8.3,4.43 6.81,4.55 8.2,4 6.81,3.45 8.3,3.57 7.28,2.47 8.58,3.22 8.14,1.79 8.98,3.03" />
    </svg>
    <div class="lang-toggle" data-chosen="{{ $locale }}">
        <span class="lang-thumb" aria-hidden="true"></span>
        @foreach ($languages as $code => [$short, $name])
            <a class="lang-option" href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" hreflang="{{ $code }}" lang="{{ $code }}"
                aria-current="{{ $locale === $code ? 'true' : 'false' }}" title="{{ $name }}">
                <span aria-hidden="true">{{ $short }}</span><span class="visually-hidden">{{ $name }}</span>
            </a>
        @endforeach
    </div>
    <svg class="lang-flag" viewBox="0 0 60 30" aria-hidden="true" focusable="false">
        <clipPath id="uk-flag-edge"><path d="M0 0v30h60V0z" /></clipPath>
        <clipPath id="uk-flag-diagonals"><path d="M30 15h30v15zv15H0zH0V0zV0h30z" /></clipPath>
        <g clip-path="url(#uk-flag-edge)">
            <path d="M0 0v30h60V0z" fill="#012169" />
            <path d="M0 0l60 30m0-30L0 30" stroke="#fff" stroke-width="6" />
            <path d="M0 0l60 30m0-30L0 30" clip-path="url(#uk-flag-diagonals)" stroke="#c8102e" stroke-width="4" />
            <path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10" />
            <path d="M30 0v30M0 15h60" stroke="#c8102e" stroke-width="6" />
        </g>
    </svg>
</nav>
