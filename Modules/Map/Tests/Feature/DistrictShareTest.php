<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Support\DistrictPreview;
use Tests\TestCase;

class DistrictShareTest extends TestCase
{
    use RefreshDatabase;

    /**
     * data.gov.my's file, cut down to Batu Pahat in 2022 and 2023.
     */
    private const Districts = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Johor,Batu Pahat,assault,all,2022-01-01,10',
        'Johor,Batu Pahat,property,all,2022-01-01,50',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,Batu Pahat,assault,all,2023-01-01,12',
        'Johor,Batu Pahat,property,break_in,2023-01-01,25',
        'Johor,Batu Pahat,property,theft_other,2023-01-01,15',
    ];

    /**
     * A file for an import to read, deleted after the test.
     *
     * @param  list<string>  $lines
     */
    private function file(array $lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($path, implode("\n", $lines)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    /**
     * Batu Pahat's figures, and crime by state for 2024, which has no police district pins.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['map.by_state.file' => $this->file(['state,category,type,year,crimes', 'Johor,assault,murder,2024,3'])]);
        $this->artisan('map:import-crime', ['file' => $this->file(self::Districts)])->assertSuccessful();
    }

    public function test_a_police_districts_link_opens_the_public_map_at_its_pin_with_a_preview(): void
    {
        $preview = DistrictPreview::of(PoliceDistrict::firstWhere('Name', 'Batu Pahat'));
        $page = $this->get('/public/map/johor/batu-pahat')->assertOk();

        $description = "52 violent and property crimes in 2023, down 13% from 2022. Each year from 2022 to 2023 on Crimeify's map, from the Royal Malaysia Police's figures.";
        $page->assertSee('<title>Batu Pahat · Map · '.config('app.name').'</title>', false)
            ->assertSee('<meta name="description" content="'.e($description).'" />', false)
            ->assertSee('<meta property="og:title" content="Batu Pahat police district, Johor: crime 2022–2023" />', false)
            ->assertSee('<meta property="og:description" content="'.e($description).'" />', false)
            ->assertSee('<meta property="og:url" content="'.url('/public/map/johor/batu-pahat').'" />', false)
            ->assertSee('<meta property="og:image" content="'.url('/public/map/johor/batu-pahat/preview.png?v='.$preview->version()).'" />', false)
            ->assertSee('<meta property="og:image:width" content="1200" />', false)
            ->assertSee('<meta property="og:image:height" content="630" />', false)
            ->assertSee('<meta property="og:image:alt" content="'.e("Bar chart of Batu Pahat's violent and property crime each year from 2022 to 2023.").'" />', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image" />', false);
        // Only the district's description, not the site's as well.
        $this->assertSame(1, substr_count($page->getContent(), '<meta name="description"'));

        // The map opens its pin, in the latest year with pins: 2023, as 2024 is by state.
        $page->assertSee('data-open="'.e(json_encode(['kind' => 'district', 'region' => 'MY-01', 'name' => 'Batu Pahat', 'label' => 'Batu Pahat'])).'"', false)
            ->assertSeeInOrder(['<option value="2024" >2024</option>', '<option value="2023" selected>2023</option>'], false);

        // Its popup links to it, in the app too.
        $this->assertSame(url('/public/map/johor/batu-pahat'), $this->getJson('/public/map/crime?year=2023')->json('districts.0.share'));
    }

    public function test_the_preview_is_an_image_of_the_districts_chart(): void
    {
        $response = $this->get('/public/map/johor/batu-pahat/preview.png')->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));

        $image = getimagesizefromstring($response->getContent());
        $this->assertSame([1200, 630, 'image/png'], [$image[0], $image[1], $image['mime']]);

        // Its address changes with the figures, so WhatsApp and the like don't keep showing the old chart.
        $before = DistrictPreview::of(PoliceDistrict::firstWhere('Name', 'Batu Pahat'))->version();
        $changed = str_replace('Johor,Batu Pahat,assault,all,2023-01-01,12', 'Johor,Batu Pahat,assault,all,2023-01-01,13', self::Districts);
        $this->artisan('map:import-crime', ['file' => $this->file($changed)])->assertSuccessful();
        $this->assertNotSame($before, DistrictPreview::of(PoliceDistrict::firstWhere('Name', 'Batu Pahat'))->version());
    }

    public function test_a_district_or_region_there_isnt_isnt_found(): void
    {
        foreach (['/public/map/johor/nowhere', '/public/map/atlantis/batu-pahat', '/public/map/kedah/batu-pahat', '/public/map/johor/nowhere/preview.png'] as $address) {
            $this->get($address)->assertNotFound();
        }
    }

    public function test_a_link_shared_in_bahasa_melayu_keeps_it(): void
    {
        $page = $this->get('/public/map/johor/batu-pahat?lang=ms')->assertOk()
            ->assertSee('<meta property="og:title" content="Daerah polis Batu Pahat, Johor: jenayah 2022–2023" />', false)
            ->assertSee('<meta property="og:url" content="'.url('/public/map/johor/batu-pahat?lang=ms').'" />', false);
        $this->assertMatchesRegularExpression('~<meta property="og:image" content="'.preg_quote(url('/public/map/johor/batu-pahat/preview.png'), '~').'\?lang=ms&amp;v=\w{12}" />~', $page->getContent());

        $this->assertSame(url('/public/map/johor/batu-pahat?lang=ms'), $this->getJson('/public/map/crime?year=2023&lang=ms')->json('districts.0.share'));
    }

    public function test_the_apps_map_opens_a_district_or_station_from_its_address(): void
    {
        $this->signIn(admin: false);
        $station = PoliceStation::create(['Region' => 'MY-01', 'District' => 'Batu Pahat', 'Name' => 'IPD Batu Pahat', 'Address' => 'Jalan Bakau Condong', 'Phone' => '07-434 4444']);

        $this->get('/map?district=johor/batu-pahat')->assertOk()
            ->assertSee('<title>Batu Pahat · Map · '.config('app.name').'</title>', false)
            ->assertSee('data-open="'.e(json_encode(['kind' => 'district', 'region' => 'MY-01', 'name' => 'Batu Pahat', 'label' => 'Batu Pahat'])).'"', false)
            ->assertSee('<option value="2023" selected>2023</option>', false)
            // The app's map has no preview: its links need a login.
            ->assertDontSee('og:title', false);

        $this->get("/map?station={$station->Id}")->assertOk()
            ->assertSee('data-open="'.e(json_encode(['kind' => 'station', 'region' => 'MY-01', 'id' => $station->Id, 'label' => 'IPD Batu Pahat'])).'"', false);

        // One there isn't opens the map as usual, at the latest year.
        foreach (['/map?district=johor/nowhere', '/map?station=999', '/map?district[]=x'] as $address) {
            $this->get($address)->assertOk()->assertDontSee('data-open', false)->assertSee('<option value="2024" selected>2024</option>', false);
        }
    }
}
