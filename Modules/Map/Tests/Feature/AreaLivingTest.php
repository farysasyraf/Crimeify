<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\AreaLiving;
use Tests\TestCase;

class AreaLivingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A police district's pin, as the crime import loads them, with the name and state the data has.
     */
    private function district(string $state, string $name, string $region): PoliceDistrict
    {
        return PoliceDistrict::create(['State' => $state, 'Name' => $name, 'Region' => $region, 'Latitude' => 3, 'Longitude' => 101]);
    }

    /**
     * The module's police district pins, from its own file.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pins(): array
    {
        $lines = file(module_path('Map', config('map.crime.districts')), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_map(fn (string $line) => array_slice(str_getcsv($line, escape: ''), 0, 2), array_slice($lines, 1));
    }

    public function test_every_police_district_but_padawan_has_an_administrative_district_with_figures(): void
    {
        foreach ($this->pins() as [$state, $name]) {
            $living = AreaLiving::of(new PoliceDistrict(['State' => $state, 'Name' => $name]));

            if ($name === 'Padawan') {
                $this->assertNull($living);

                continue;
            }

            $this->assertNotNull($living, "{$name} has no administrative district");

            foreach (AreaLiving::Compared as $measure) {
                $this->assertNotNull($living->value($measure), "{$name}'s {$living->area} has no {$measure}");
            }
        }
    }

    public function test_it_gives_the_district_its_pin_is_in_beside_the_state_and_malaysia(): void
    {
        $living = AreaLiving::of(new PoliceDistrict(['State' => 'Selangor', 'Name' => 'Kajang']));

        $this->assertSame(['Hulu Langat', 'Selangor', ['Ampang Jaya'], false], [$living->name(), $living->stateName(), $living->sharedWith, $living->isWholeState()]);
        $this->assertSame([2024, 11404.0, 10726.0, 7017.0], [
            $living->year('income_median'), $living->value('income_median'), $living->stateValue('income_median'), $living->nationalValue('income_median'),
        ]);
        $this->assertSame(['RM 11,404', '0.1%', '2.3%'], [
            AreaLiving::format('income_median', $living->value('income_median')),
            AreaLiving::format('poverty', $living->value('poverty')),
            AreaLiving::format('unemployment', $living->value('unemployment')),
        ]);

        // Malaysia's spending isn't published in these files.
        $this->assertNull($living->nationalValue('expenditure_mean'));
        $this->assertSame('–', AreaLiving::format('expenditure_mean', null));
    }

    public function test_the_territories_are_their_own_and_a_state_of_one_district_compares_only_with_malaysia(): void
    {
        $putrajaya = AreaLiving::of(new PoliceDistrict(['State' => 'W.P. Kuala Lumpur', 'Name' => 'W.P. Putrajaya']));
        $labuan = AreaLiving::of(new PoliceDistrict(['State' => 'Sabah', 'Name' => 'W.P. Labuan']));
        $brickfields = AreaLiving::of(new PoliceDistrict(['State' => 'W.P. Kuala Lumpur', 'Name' => 'Brickfields']));

        $this->assertSame(['Putrajaya', true], [$putrajaya->name(), $putrajaya->isWholeState()]);
        $this->assertSame(['Labuan', true], [$labuan->name(), $labuan->isWholeState()]);
        $this->assertSame(['Kuala Lumpur', ['Cheras', 'Dang Wangi', 'Sentul', 'Wangsa Maju']], [$brickfields->name(), $brickfields->sharedWith]);

        // The jobs file has only the state, which is the district.
        $this->assertSame($brickfields->stateValue('unemployment'), $brickfields->value('unemployment'));
    }

    public function test_the_profile_says_which_district_its_figures_are_for(): void
    {
        $this->district('Selangor', 'Kajang', 'MY-10');
        $this->district('Selangor', 'Ampang Jaya', 'MY-10');

        $this->get('/public/areas/selangor/kajang')->assertOk()
            ->assertSee('Living here')
            ->assertSee("For Hulu Langat, the administrative district this police district's pin is in.")
            ->assertSee('It also takes in Ampang Jaya.')
            ->assertSeeInOrder(['Hulu Langat', 'Selangor', 'Malaysia', 'Median monthly household income', 'RM 11,404', 'RM 10,726', 'RM 7,017'])
            ->assertSeeInOrder(['Primary schools', 'Secondary schools', 'Homes with', 'Piped water'])
            ->assertSee("They describe the area, not its crime: they don't explain it.");
    }

    public function test_a_whole_territory_has_no_state_column_and_padawan_has_no_section(): void
    {
        $this->district('W.P. Kuala Lumpur', 'Brickfields', 'MY-14');
        $this->district('Sarawak', 'Padawan', 'MY-13');

        $page = $this->get('/public/areas/kuala-lumpur/brickfields')->assertOk()->assertSee('Living here');
        preg_match('~<section class="area-card area-wide" aria-labelledby="area-living-heading">.*?</thead>~s', $page->getContent(), $head);
        $this->assertSame(2, substr_count($head[0], '<th scope="col">'));

        $this->get('/public/areas/sarawak/padawan')->assertOk()->assertDontSee('Living here');
    }

    public function test_it_comes_in_bahasa_melayu(): void
    {
        $this->district('Selangor', 'Kajang', 'MY-10');

        $this->get('/public/areas/selangor/kajang?lang=ms')->assertOk()
            ->assertSee('Tinggal di sini')
            ->assertSee('Bagi Hulu Langat, daerah pentadbiran tempat pin daerah polis ini berada.')
            ->assertSee('Pendapatan bulanan isi rumah penengah');
    }

    public function test_areas_side_by_side_compare_their_figures_and_say_when_they_share_a_district(): void
    {
        $this->district('Selangor', 'Kajang', 'MY-10');
        $this->district('Selangor', 'Ampang Jaya', 'MY-10');
        $this->district('Johor', 'Ledang', 'MY-01');
        $this->district('Sarawak', 'Padawan', 'MY-13');

        $this->get('/public/areas/compare?areas[]=selangor/kajang&areas[]=johor/ledang')->assertOk()
            ->assertSeeInOrder(['Administrative district', 'Hulu Langat', 'Tangkak', 'Median monthly household income', 'RM 11,404', 'RM 6,374'])
            ->assertDontSee('have the same figures here');

        $this->get('/public/areas/compare?areas[]=selangor/kajang&areas[]=selangor/ampang-jaya')->assertOk()
            ->assertSee('Police districts in the same administrative district have the same figures here.');

        // Padawan has no figures, shown as a dash beside the others.
        $this->get('/public/areas/compare?areas[]=selangor/kajang&areas[]=sarawak/padawan')->assertOk()
            ->assertSeeInOrder(['Administrative district', 'Hulu Langat', '–']);
    }

    public function test_the_command_joins_the_sources_spellings_into_the_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'living');
        $this->beforeApplicationDestroyed(fn () => @unlink($file));
        config(['map.living.file' => $file]);

        $this->fakeSources();

        $this->artisan('map:update-living')->expectsOutputToContain('Check the changes and commit it.')->assertSuccessful();

        $written = file($file, FILE_IGNORE_NEW_LINES);

        // One name for the district, its fullest; the latest two years; tertiary schools added up; only both sexes; and
        // Putrajaya's unemployment from its state's row.
        $this->assertSame('state,district,measure,year,value', $written[0]);
        foreach ([
            'Selangor,Hulu Langat,income_median,2022,100', 'Selangor,Hulu Langat,income_median,2024,200', 'Selangor,Hulu Langat,unemployment,2024,2',
            'Selangor,Hulu Langat,schools_tertiary,2025,3', 'Selangor,All,unemployment,2024,2.5', 'W.P. Putrajaya,W.P. Putrajaya,unemployment,2024,1.5',
            'Malaysia,All,poverty,2024,5.1', 'Selangor,All,piped_water,2024,99',
        ] as $line) {
            $this->assertContains($line, $written);
        }
        $this->assertNotContains('Selangor,Hulu Langat,unemployment,2020,4', $written);
        $this->assertSame([], preg_grep('/Ulu Langat|,2020,/', array_filter($written, fn (string $line) => ! str_contains($line, 'Hulu Langat'))));
    }

    public function test_the_command_changes_nothing_when_a_source_cant_be_read(): void
    {
        $before = file_get_contents(module_path('Map', config('map.living.file')));

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('Service unavailable', 503)]);

        $this->artisan('map:update-living')->expectsOutput("Couldn't download hies_district (HTTP 503).")->assertFailed();

        $this->assertSame($before, file_get_contents(module_path('Map', config('map.living.file'))));
    }

    public function test_the_command_changes_nothing_when_a_figure_isnt_a_number(): void
    {
        $before = file_get_contents(module_path('Map', config('map.living.file')));

        $this->fakeSources(['hies_district' => "date,state,district,income_mean,income_median,expenditure_mean,gini,poverty\n2024-01-01,Selangor,Hulu Langat,1,n/a,60,0.3,0.5"]);

        $this->artisan('map:update-living')->expectsOutput('income_median has "n/a" for Hulu Langat. Nothing was changed.')->assertFailed();

        $this->assertSame($before, file_get_contents(module_path('Map', config('map.living.file'))));
    }

    /**
     * Small copies of the sources, at their addresses.
     *
     * @param  array<string, string>  $changed  a source's text to use instead, by its name
     */
    private function fakeSources(array $changed = []): void
    {
        $sources = [
            'hies_district' => "date,state,district,income_mean,income_median,expenditure_mean,gini,poverty\n2022-01-01,Selangor,Hulu Langat,1,100,50,0.3,1.0\n2024-01-01,Selangor,Ulu Langat,1,200,60,0.3,0.5\n2024-01-01,W.P. Putrajaya,W.P. Putrajaya,1,300,70,0.3,0.1",
            'hies_state' => "date,state,income_mean,income_median,expenditure_mean,gini,poverty\n2024-01-01,Selangor,1,150,55,0.3,0.7",
            'hh_income' => "date,income_mean,income_median\n2020-01-01,1,50\n2024-01-01,1,120",
            'hh_poverty' => "date,poverty_absolute,poverty_hardcore,poverty_relative\n2024-01-01,5.1,0.1,16",
            'lfs_district' => "state,district,date,lf,lf_employed,lf_unemployed,lf_outside,p_rate,u_rate,ep_ratio\nSelangor,Hulu Langat,2020-01-01,1,1,1,1,1,4.0,1\nSelangor,Ulu Langat,2023-01-01,1,1,1,1,1,3.0,1\nSelangor,Ulu Langat,2024-01-01,1,1,1,1,1,2.0,1",
            'lfs_state_sex' => "state,sex,date,lf,lf_employed,lf_unemployed,lf_outside,p_rate,u_rate,ep_ratio\nSelangor,both,2024-01-01,1,1,1,1,1,2.5,1\nSelangor,male,2024-01-01,1,1,1,1,1,9.0,1\nW.P. Putrajaya,both,2024-01-01,1,1,1,1,1,1.5,1",
            'lfs_year' => "date,lf,lf_employed,lf_unemployed,lf_outside,p_rate,ep_ratio,u_rate\n2024-01-01,1,1,1,1,1,1,3.2",
            'hh_access_amenities' => "date,state,district,piped_water,sanitation,electricity\n2024-01-01,Selangor,All Districts,99,98,97\n2024-01-01,Selangor,Hulu Langat,100,99,98",
            'schools_district' => "state,district,stage,type,date,schools\nMalaysia,All Districts,primary,Sekolah Rendah Kerajaan,2025-06-30,7000\nSelangor,Ulu Langat,primary,Sekolah Rendah Kerajaan,2025-06-30,92\nSelangor,Ulu Langat,tertiary,IPTA,2025-01-01,1\nSelangor,Ulu Langat,tertiary,Politeknik,2025-01-01,2",
        ];

        Http::preventStrayRequests();
        Http::fake(collect(config('map.living.sources'))->mapWithKeys(fn (string $url, string $name) => [$url => Http::response($changed[$name] ?? $sources[$name])])->all());
    }
}
