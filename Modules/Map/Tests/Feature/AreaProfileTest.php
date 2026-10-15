<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Support\AreaProfile;
use Modules\Map\Support\DistrictPreview;
use Tests\TestCase;

class AreaProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * data.gov.my's file, cut down to 2022 and 2023: Johor's Batu Pahat (100, then 90 crimes) and Muar (200, then
     * 160), so Johor as a whole has 300, then 250; Labuan (33, then 28) and Kota Kinabalu (67, then 72) in Sabah, which
     * has 100 both years; and Malaysia, 6,000 then 6,100.
     */
    private const Figures = [
        'state,district,category,type,date,crimes',
        'Malaysia,All,assault,all,2022-01-01,1000',
        'Malaysia,All,property,all,2022-01-01,5000',
        'Malaysia,All,assault,all,2023-01-01,900',
        'Malaysia,All,property,all,2023-01-01,5200',
        'Johor,Batu Pahat,assault,murder,2022-01-01,2',
        'Johor,Batu Pahat,assault,causing_injury,2022-01-01,18',
        'Johor,Batu Pahat,property,break_in,2022-01-01,30',
        'Johor,Batu Pahat,property,theft_vehicle_motorcycle,2022-01-01,50',
        'Johor,Batu Pahat,assault,murder,2023-01-01,1',
        'Johor,Batu Pahat,assault,causing_injury,2023-01-01,14',
        'Johor,Batu Pahat,property,break_in,2023-01-01,45',
        'Johor,Batu Pahat,property,theft_vehicle_motorcycle,2023-01-01,30',
        'Johor,Muar,assault,causing_injury,2022-01-01,30',
        'Johor,Muar,property,break_in,2022-01-01,20',
        'Johor,Muar,property,theft_vehicle_motorcycle,2022-01-01,150',
        'Johor,Muar,assault,causing_injury,2023-01-01,30',
        'Johor,Muar,property,break_in,2023-01-01,10',
        'Johor,Muar,property,theft_vehicle_motorcycle,2023-01-01,120',
        'Sabah,W.P. Labuan,assault,all,2022-01-01,3',
        'Sabah,W.P. Labuan,property,all,2022-01-01,30',
        'Sabah,W.P. Labuan,assault,all,2023-01-01,3',
        'Sabah,W.P. Labuan,property,all,2023-01-01,25',
        'Sabah,Kota Kinabalu,assault,all,2022-01-01,10',
        'Sabah,Kota Kinabalu,property,all,2022-01-01,57',
        'Sabah,Kota Kinabalu,assault,all,2023-01-01,12',
        'Sabah,Kota Kinabalu,property,all,2023-01-01,60',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", self::Figures)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        $this->artisan('map:import-crime', ['file' => $path])->assertSuccessful();
    }

    private function profile(string $region, string $district): AreaProfile
    {
        return AreaProfile::of(PoliceDistrict::findByPath($region, $district));
    }

    public function test_a_profile_says_what_the_figures_do_beside_its_state(): void
    {
        $profile = $this->profile('johor', 'batu-pahat');

        $this->assertSame([[2022, 2023], 'Johor'], [$profile->years(), $profile->area]);
        $this->assertSame([
            '90 violent and property crimes in 2023, down 10% from 2022.',
            "From 2022 to 2023, crime here fell 10%. Across Johor it fell 17%, and across Malaysia it rose 2%. That's a smaller fall than across Johor.",
            'The most common crimes here are break-in (50%) and motorcycle theft (33%).',
            'Compared with Johor, more of the crime here is break-in: 50%, against 22%.',
        ], $profile->summary());

        // Most first, with the state's share of each: Johor's 250 crimes were 55 break-ins, 150 motorcycle thefts…
        $this->assertSame([
            ['break_in', 45, 50.0, 22.0], ['theft_vehicle_motorcycle', 30, 33.3, 60.0],
            ['causing_injury', 14, 15.6, 17.6], ['murder', 1, 1.1, 0.4],
        ], array_map(fn (array $type) => [$type['type'], $type['count'], $type['share'], $type['areaShare']], $profile->mix()));

        // Each year against the first, for comparing places of any size.
        $this->assertSame([100.0, 90.0], $profile->indexed([2022, 2023]));
        $this->assertSame([100.0, 101.7], $profile->nationalIndexed([2022, 2023]));
    }

    public function test_labuan_is_compared_with_sabah_which_the_data_files_it_under(): void
    {
        $profile = $this->profile('labuan', 'wp-labuan');

        // Labuan's region has no police district but its own, so its area is the state it's filed under. Sabah's crime
        // didn't change, so there's no saying which fell more.
        $this->assertSame('Sabah', $profile->area);
        $this->assertSame('From 2022 to 2023, crime here fell 15%. Across Sabah it stayed the same, and across Malaysia it rose 2%.', $profile->summary()[1]);

        // Without crime types in the figures, there's no mix to give.
        $this->assertSame([], $profile->mix());
        $this->assertCount(2, $profile->summary());
    }

    public function test_a_small_change_is_under_1_percent_and_only_the_same_count_is_no_change(): void
    {
        $this->assertSame(['under 1%', '1%', '7%'], [AreaProfile::size(0.4), AreaProfile::size(-0.6), AreaProfile::size(-7.2)]);
        $this->assertEqualsWithDelta(0.25, AreaProfile::change(400, 401), 0.0001);
        $this->assertSame(0.0, AreaProfile::change(400, 400));
        $this->assertNull(AreaProfile::change(0, 4));
    }

    public function test_the_profile_page_has_the_figures_the_chart_and_the_stations(): void
    {
        PoliceStation::create(['Region' => 'MY-01', 'District' => 'Batu Pahat', 'Name' => 'IPD Batu Pahat', 'Address' => 'Jalan Bakau Condong', 'Phone' => '07-434 4444']);
        $preview = DistrictPreview::of(PoliceDistrict::firstWhere('Name', 'Batu Pahat'));

        $page = $this->get('/public/areas/johor/batu-pahat')->assertOk()
            ->assertSee('<title>Batu Pahat · Check an area · '.config('app.name').'</title>', false)
            ->assertSee('<h1>Batu Pahat</h1>', false)
            ->assertSee('Police district in Johor · Crime 2022–2023')
            ->assertSee("That's a smaller fall than across Johor.")
            ->assertSee('Compared with Johor, more of the crime here is break-in: 50%, against 22%.')
            ->assertSeeInOrder(['Crimes reported', '90', '↓ 10%', 'from 2022'], false)
            ->assertSee('IPD Batu Pahat')
            ->assertSee('href="tel:', false)
            ->assertSee('About these figures')
            // Where to go next: other areas to compare it with, and its pin on the map.
            ->assertSee('href="'.url('/public/areas?with=johor%2Fbatu-pahat').'"', false)
            ->assertSee('href="'.url('/public/map/johor/batu-pahat').'"', false)
            // A shared link shows its chart, as its map link does.
            ->assertSee('<meta property="og:url" content="'.url('/public/areas/johor/batu-pahat').'" />', false)
            ->assertSee('<meta property="og:image" content="'.url('/public/map/johor/batu-pahat/preview.png?v='.$preview->version()).'" />', false);

        // The chart's figures for areas.js, and a table of them for screen readers.
        preg_match('~<script type="application/json" id="area-data">(.*?)</script>~s', $page->getContent(), $data);
        $chart = json_decode($data[1], true);
        $this->assertSame(['profile', [2022, 2023], [80, 75], [20, 15]], [$chart['kind'], $chart['years'], $chart['series'][0]['counts'], $chart['series'][1]['counts']]);
        $page->assertSee('<tr><th scope="row">2023</th><td>15</td><td>75</td><td>90</td></tr>', false);
    }

    public function test_two_or_three_areas_side_by_side(): void
    {
        $page = $this->get('/public/areas/compare?areas[]=johor/batu-pahat&areas[]=johor/muar')->assertOk()
            ->assertSee('<title>Compare areas · '.config('app.name').'</title>', false)
            ->assertSeeInOrder(['Batu Pahat', 'Muar'])
            ->assertSeeInOrder(['Crimes reported', '<strong>90</strong>', '<strong>160</strong>'], false)
            ->assertSeeInOrder(['Most common crime', 'Break-in (50%)', 'Motorcycle theft (75%)'], false)
            // Both still chosen in the lists, to change them.
            ->assertSee('<option value="johor/batu-pahat" selected>', false)
            ->assertSee('<option value="johor/muar" selected>', false);

        preg_match('~<script type="application/json" id="area-data">(.*?)</script>~s', $page->getContent(), $data);
        $chart = json_decode($data[1], true);
        $this->assertSame([[100, 90], [100, 80]], array_column($chart['series'], 'values'));
        $this->assertSame([100, 101.7], $chart['national']['values']);

        // At most three, each once; with only one there, its profile; with none, the page to choose them.
        $this->get('/public/areas/compare?areas[]=johor/muar&areas[]=johor/muar&areas[]=labuan/wp-labuan&areas[]=sabah/kota-kinabalu&areas[]=johor/batu-pahat')
            ->assertOk()->assertSee('Muar · W.P. Labuan · Kota Kinabalu')->assertDontSee('Batu Pahat ·');
        $this->get('/public/areas/compare?areas[]=johor/muar&areas[]=nowhere/at-all')->assertRedirect('/public/areas/johor/muar');
        $this->get('/public/areas/compare?areas[]=nowhere/at-all')->assertRedirect('/public/areas');
        $this->get('/public/areas/compare?areas=johor/muar')->assertRedirect('/public/areas/johor/muar');
        $this->get('/public/areas/compare?areas[][]=x')->assertRedirect('/public/areas');
    }

    public function test_the_page_to_choose_lists_every_police_district_by_state(): void
    {
        $this->get('/public/areas')->assertOk()
            ->assertSee('Check an area')
            ->assertSeeInOrder(['<optgroup label="Johor">', '<option value="johor/batu-pahat" >Batu Pahat</option>'], false)
            ->assertSee('<a href="'.url('/public/areas/labuan/wp-labuan').'">W.P. Labuan</a>', false);

        // From a profile's "Compare with other areas", that one is already chosen; one there isn't, isn't.
        $this->get('/public/areas?with=johor/batu-pahat')->assertSee('<option value="johor/batu-pahat" selected>', false);
        $this->get('/public/areas?with=johor/nowhere')->assertDontSee('selected>', false);

        // Every public page links to it.
        $this->get('/public/map')->assertSee('href="'.url('/public/areas').'"', false);
    }

    public function test_a_district_there_isnt_isnt_found(): void
    {
        $this->get('/public/areas/johor/nowhere')->assertNotFound();
        $this->get('/public/areas/atlantis/batu-pahat')->assertNotFound();
    }

    public function test_in_bahasa_melayu_with_links_that_keep_it(): void
    {
        $this->get('/public/areas/johor/batu-pahat?lang=ms')->assertOk()
            ->assertSee('Dari 2022 hingga 2023, jenayah di sini turun 10%. Di seluruh Johor jenayah turun 17%, dan di seluruh Malaysia naik 2%. Itu penurunan yang lebih kecil berbanding di seluruh Johor.')
            ->assertSee('href="'.url('/public/areas?with=johor%2Fbatu-pahat&amp;lang=ms').'"', false);

        $this->get('/public/areas?lang=ms')->assertSee('<input type="hidden" name="lang" value="ms" />', false);
        $this->get('/public/areas/compare?areas[]=johor/muar&lang=ms')->assertRedirect('/public/areas/johor/muar?lang=ms');
    }
}
