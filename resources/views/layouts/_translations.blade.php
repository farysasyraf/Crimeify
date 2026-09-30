{{-- The page's language for its own scripts, as __() gives it on the server, from lang/{locale}.json:
     t('Up :size from :count', { size, count }) and, choosing by a count, tn(count, ':count station', ':count stations').
     In English, the page's text is as written. --}}
@php
    $translations = app()->getLocale() === 'en' ? [] : app('translator')->getLoader()->load(app()->getLocale(), '*', '*');
@endphp
<script>
    window.translations = @json((object) $translations);
    window.t = (text, replace = {}) => Object.keys(replace)
        .sort((a, b) => b.length - a.length)
        .reduce((result, key) => result.replaceAll(`:${key}`, replace[key]), window.translations[text] ?? text);
    window.tn = (count, one, many, replace = {}) => window.t(count === 1 ? one : many, { count, ...replace });
</script>
