<?php

namespace Tests\Feature;

use App\Http\Middleware\SetPublicLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Tests\TestCase;

class PublicLanguageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two years of figures, for the dashboard and the map's figures.
     */
    private function figures(): void
    {
        PoliceDistrict::create(['State' => 'Johor', 'Name' => 'Batu Pahat', 'Region' => 'MY-01', 'Latitude' => 1.85, 'Longitude' => 102.93]);
        foreach ([2022 => [10, 50], 2023 => [12, 40]] as $year => [$assault, $property]) {
            foreach (['assault' => $assault, 'property' => $property] as $category => $crimes) {
                foreach ([['Malaysia', 'All'], ['Johor', 'All'], ['Johor', 'Batu Pahat']] as [$state, $district]) {
                    CrimeStat::create(['State' => $state, 'District' => $district, 'Category' => $category, 'Type' => 'all', 'Year' => $year, 'Crimes' => $crimes]);
                }
            }
        }
    }

    public function test_the_public_pages_are_in_english_with_a_switch_to_bahasa_melayu(): void
    {
        $this->figures();

        $this->get('/public/dashboard?year=2022')->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertSee('<h1>Dashboard</h1>', false)
            ->assertSeeInOrder([
                '<nav class="lang-switch" aria-label="Language">',
                '<svg class="lang-flag" viewBox="0 0 28 14"',
                '<div class="lang-toggle" data-chosen="en">',
                // To this page in the other language, keeping what's chosen on it.
                'href="'.url('/public/dashboard?year=2022&amp;lang=ms').'" hreflang="ms" lang="ms"', 'aria-current="false" title="Bahasa Melayu"',
                '<span aria-hidden="true">BM</span><span class="visually-hidden">Bahasa Melayu</span>',
                'href="'.url('/public/dashboard?year=2022&amp;lang=en').'" hreflang="en" lang="en"', 'aria-current="true" title="English"',
                '<span aria-hidden="true">EN</span>',
                '<svg class="lang-flag" viewBox="0 0 60 30"',
            ], false)
            ->assertCookieMissing(SetPublicLocale::Cookie);
    }

    public function test_choosing_bahasa_melayu_shows_the_dashboard_in_it_and_keeps_it(): void
    {
        $this->figures();

        $this->get('/public/dashboard?lang=ms')->assertOk()
            ->assertSee('<html lang="ms">', false)
            ->assertSee('<div class="lang-toggle" data-chosen="ms">', false)
            ->assertSeeInOrder(['Papan Pemuka', 'Peta', 'Log Masuk'])
            ->assertSee('<h1>Papan Pemuka</h1>', false)
            ->assertSee('Jenayah di Malaysia pada 2023, daripada angka Polis Diraja Malaysia bagi setiap daerah polis.')
            ->assertSeeInOrder(['Jenayah dari tahun ke tahun', 'Jenayah kekerasan', 'Jenayah harta benda', 'Jenayah mengikut negeri, 2023', 'Buka peta'])
            ->assertSeeInOrder(['Semua jenayah', 'Turun 20% berbanding 50 pada 2022'])
            ->assertSee('Naik bermaksud lebih banyak jenayah, turun bermaksud kurang.')
            ->assertDontSee('<h2 id="trend-heading">Crime over the years</h2>', false)
            // The charts' scripts get their words in it too.
            ->assertSee('"All crime":"Semua jenayah"', false)
            ->assertCookie(SetPublicLocale::Cookie, 'ms');

        // The cookie keeps it, on the map too.
        $this->withCookie(SetPublicLocale::Cookie, 'ms')->get('/public/map')->assertOk()
            ->assertSee('<h1>Peta Visualisasi Jenayah</h1>', false)
            ->assertSeeInOrder(['Angka jenayah bagi', 'Pilih negeri di peta atau daripada senarai.', 'Papar seluruh Malaysia'])
            ->assertSee('aria-label="Negeri dan wilayah persekutuan"', false)
            ->assertSee('data-kind="Negeri"', false)
            ->assertSeeInOrder(['Cari balai polis', 'Pilih negeri, kemudian salah satu balai polisnya, untuk alamat dan nombor telefonnya.'])
            // Its figures come in it, even without the cookie.
            ->assertSee('data-crime="'.url('/public/map/crime?lang=ms').'"', false);

        // And back to English.
        $this->withCookie(SetPublicLocale::Cookie, 'ms')->get('/public/map?lang=en')
            ->assertSee('<h1>Crime Visualization Map</h1>', false)
            ->assertCookie(SetPublicLocale::Cookie, 'en');
    }

    public function test_the_maps_figures_come_in_the_pages_language(): void
    {
        $this->figures();

        $figures = $this->getJson('/public/map/crime?lang=ms')->assertOk()->json();
        $this->assertSame(['assault' => 'Jenayah kekerasan', 'property' => 'Jenayah harta benda'], $figures['categories']);
        $this->assertSame('Bunuh', $figures['types']['assault']['murder']);
        $this->assertSame('Data jenayah: PDRM & DOSM (CC BY 4.0)', $figures['credit']);

        $this->assertSame('Violent crime', $this->getJson('/public/map/crime')->json('categories.assault'));
    }

    public function test_anything_but_the_two_languages_is_english_and_the_app_stays_english(): void
    {
        $this->get('/public/dashboard?lang=fr')->assertSee('<html lang="en">', false)->assertCookieMissing(SetPublicLocale::Cookie);
        $this->withCookie(SetPublicLocale::Cookie, 'xx')->get('/public/dashboard')->assertSee('<html lang="en">', false);

        // The app itself isn't translated yet, whatever the public pages were left in.
        $this->signIn();
        $this->figures();
        $this->withCookie(SetPublicLocale::Cookie, 'ms')->get('/dashboard?lang=ms')->assertOk()
            ->assertSee('<h1>Dashboard</h1>', false)
            ->assertSee('Crime over the years')
            ->assertDontSee('lang-switch', false);
    }

    public function test_every_text_the_public_pages_translate_is_in_bahasa_melayu(): void
    {
        $malay = json_decode(file_get_contents(lang_path('ms.json')), true, flags: JSON_THROW_ON_ERROR);

        $files = [
            'resources/views/layouts/public.blade.php', 'resources/views/layouts/_language-switch.blade.php',
            'Modules/Dashboard/Resources/views/index.blade.php', 'Modules/Map/Resources/views/index.blade.php',
            'Modules/Dashboard/Http/Controllers/DashboardController.php', 'Modules/Map/Http/Controllers/MapController.php',
            'Modules/Map/Support/CrimeRates.php',
            'public/modules/map/map.js', 'public/modules/map/station-finder.js', 'public/modules/dashboard/dashboard.js',
            'public/js/select2-init.js',
        ];
        $literal = '([\'"])((?:\\\\.|(?!\\1).)*)\\1';
        $patterns = [
            // __('text'), t('text'), and the two sides of __(a ? 'this' : 'that') or t(…)
            '/(?<![\w$.>])(?:__|t)\(\s*'.$literal.'/',
            '/(?<![\w$.>])(?:__|t)\([^\'"()]*\?\s*'.$literal.'\s*:\s*'.$literal.'/',
            // tn(count, 'one', 'many')
            '/\btn\([^,]+,\s*'.$literal.'\s*,\s*'.$literal.'/',
        ];

        $keys = [];
        foreach ($files as $file) {
            $source = file_get_contents(base_path($file));
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    for ($group = 2; isset($match[$group]); $group += 2) {
                        $keys[stripslashes($match[$group])] = $file;
                    }
                }
            }
        }

        // The labels from the config: crime categories and types, the dashboard's cards, the credits, the headings.
        foreach ([
            ...array_values(config('map.crime.categories')),
            ...array_merge(...array_values(array_map('array_values', config('map.crime.types')))),
            ...array_merge(...array_values(array_map('array_values', config('map.by_state.types')))),
            ...array_column(config('dashboard.cards'), 'label'),
            config('map.crime.credit'), config('map.by_state.source'), config('map.population.credit'), 'States', 'Federal territories',
        ] as $label) {
            $keys[$label] = 'config';
        }

        $this->assertGreaterThan(80, count($keys));
        $missing = array_keys(array_diff_key($keys, $malay));
        $this->assertSame([], $missing, 'No Bahasa Melayu in lang/ms.json for these');

        // Each keeps its :placeholders.
        foreach ($malay as $english => $translated) {
            $this->assertNotSame('', trim($translated), $english);
            preg_match_all('/:(\w+)/', $english, $from);
            preg_match_all('/:(\w+)/', $translated, $to);
            $this->assertEqualsCanonicalizing(array_unique($from[1]), array_unique($to[1]), "Placeholders of \"{$english}\"");
        }
    }
}
