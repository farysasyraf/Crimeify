<?php

namespace Modules\Map\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Map\Entities\CrimeDataEdit;
use Modules\Map\Entities\CrimeStat;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class CrimeDataPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A small crime file whose totals add up, as data.gov.my's do: two Johor districts and Labuan, the Johor and
     * Sabah totals, and the country's.
     */
    private const Figures = [
        'state,district,category,type,date,crimes',
        'Johor,Batu Pahat,assault,murder,2023-01-01,2',
        'Johor,Batu Pahat,assault,causing_injury,2023-01-01,10',
        'Johor,Batu Pahat,assault,all,2023-01-01,12',
        'Johor,Batu Pahat,property,break_in,2023-01-01,25',
        'Johor,Batu Pahat,property,theft_other,2023-01-01,15',
        'Johor,Batu Pahat,property,all,2023-01-01,40',
        'Johor,Kluang,assault,murder,2023-01-01,1',
        'Johor,Kluang,assault,all,2023-01-01,1',
        'Johor,Kluang,property,break_in,2023-01-01,5',
        'Johor,Kluang,property,all,2023-01-01,5',
        'Johor,All,assault,murder,2023-01-01,3',
        'Johor,All,assault,causing_injury,2023-01-01,10',
        'Johor,All,assault,all,2023-01-01,13',
        'Johor,All,property,break_in,2023-01-01,30',
        'Johor,All,property,theft_other,2023-01-01,15',
        'Johor,All,property,all,2023-01-01,45',
        'Sabah,W.P. Labuan,assault,murder,2023-01-01,1',
        'Sabah,W.P. Labuan,assault,all,2023-01-01,1',
        'Sabah,All,assault,murder,2023-01-01,1',
        'Sabah,All,assault,all,2023-01-01,1',
        'Malaysia,All,assault,murder,2023-01-01,4',
        'Malaysia,All,assault,causing_injury,2023-01-01,10',
        'Malaysia,All,assault,all,2023-01-01,14',
        'Malaysia,All,property,break_in,2023-01-01,30',
        'Malaysia,All,property,theft_other,2023-01-01,15',
        'Malaysia,All,property,all,2023-01-01,45',
        'Johor,Batu Pahat,assault,murder,2022-01-01,1',
        'Johor,Batu Pahat,assault,all,2022-01-01,1',
        'Johor,All,assault,murder,2022-01-01,1',
        'Johor,All,assault,all,2022-01-01,1',
        'Malaysia,All,assault,murder,2022-01-01,1',
        'Malaysia,All,assault,all,2022-01-01,1',
    ];

    /**
     * Load the figures above with the import, as "php artisan map:import-crime" does.
     */
    private function importFigures(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", self::Figures)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $this->artisan('map:import-crime', ['file' => $path])->assertSuccessful();
    }

    /**
     * A figure's count, or null if there's no such figure.
     */
    private function crimes(string $state, string $district, string $category, string $type, int $year = 2023): ?int
    {
        return CrimeStat::query()->where(['State' => $state, 'District' => $district, 'Category' => $category, 'Type' => $type, 'Year' => $year])->value('Crimes');
    }

    private function figure(string $district, string $type, int $year = 2023): CrimeStat
    {
        return CrimeStat::query()->where(['District' => $district, 'Type' => $type, 'Year' => $year])->firstOrFail();
    }

    /**
     * An Excel file with these rows under the columns the page reads, as an upload.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function excelFile(array $rows, array $header = ['state', 'district', 'category', 'type', 'year', 'crimes'], string $name = 'crime.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'crime').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Every editable figure as the Excel file has them.
     *
     * @return list<list<mixed>>
     */
    private function everyFigure(): array
    {
        return CrimeStat::query()->where('State', '!=', 'Malaysia')->where('District', '!=', 'All')->where('Type', '!=', 'all')
            ->orderBy('Id')->get()
            ->map(fn (CrimeStat $figure) => [$figure->State, $figure->District, $figure->Category, $figure->Type, $figure->Year, $figure->Crimes])
            ->all();
    }

    public function test_only_administrators_can_open_the_page(): void
    {
        $this->get('/crime-data')->assertRedirect('/login');

        // Logged in, but without the ADMIN role, even typing the address, and whether or not the figure exists.
        $this->importFigures();
        $figure = $this->figure('Kluang', 'murder')->Id;
        $user = $this->signIn(admin: false);
        $token = '0b1f6f3e-8a4c-4d0e-9d7a-2f1c3b5a6e70';
        foreach ([
            'get' => ['/crime-data', '/crime-data/download', "/crime-data/delete/{$figure}", '/crime-data/delete/999'],
            'put' => ['/crime-data/update'],
            'post' => ['/crime-data/store', '/crime-data/upload', "/crime-data/apply/{$token}"],
            'delete' => ["/crime-data/destroy/{$figure}", '/crime-data/destroy/999', "/crime-data/cancel/{$token}"],
        ] as $method => $addresses) {
            foreach ($addresses as $address) {
                $this->{$method}($address)->assertForbidden();
            }
        }
        $this->get('/crime-data')->assertSee('Only users with the ADMIN role can open this page.');
        $this->assertSame(1, $this->crimes('Johor', 'Kluang', 'assault', 'murder'));

        $user->roles()->attach(Role::firstOrCreate(['Name' => 'ADMIN'])->Id);
        $this->get('/crime-data')
            ->assertOk()
            ->assertSee('<title>Crime data · '.config('app.name').'</title>', false)
            ->assertSee('<h1>Crime data</h1>', false);
    }

    public function test_the_page_lists_the_districts_figures_but_not_the_totals(): void
    {
        $this->signIn();
        $this->importFigures();

        $page = $this->get('/crime-data')->assertOk();

        // By state, district, newest year first, then crime type.
        $page->assertSee('The 8 figures behind the map and the dashboard')
            ->assertSeeInOrder([
                '<td>Batu Pahat</td>', '<td class="num">2023</td>', 'Causing injury',
                '<td>Batu Pahat</td>', '<td class="num">2023</td>', 'Murder',
                '<td>Batu Pahat</td>', '<td class="num">2023</td>', 'Break-in',
                '<td>Batu Pahat</td>', '<td class="num">2022</td>', 'Murder',
                '<td>Kluang</td>', '<td>W.P. Labuan</td>',
            ], false)
            ->assertSee('name="crimes['.$this->figure('Batu Pahat', 'murder')->Id.']" value="2"', false)
            ->assertDontSee('name="crimes['.$this->figure('All', 'murder')->Id.']"', false)
            ->assertDontSee('name="crimes['.$this->figure('Batu Pahat', 'all')->Id.']"', false)
            ->assertSee('None since the last import from data.gov.my.')
            ->assertDontSee('been edited here since the last import');

        // Chosen by state, district, year and crime type, or a whole category.
        $this->get('/crime-data?state=Sabah')->assertSee('<td>W.P. Labuan</td>', false)->assertDontSee('<td>Kluang</td>', false);
        $this->get('/crime-data?district=Kluang&type=property')->assertSee('1–1 of 1');
        $this->get('/crime-data?year=2022&type=assault.murder')->assertSee('1–1 of 1');
        $this->get('/crime-data?year=1999')->assertSee('No figures match these choices.');
    }

    public function test_saving_a_count_adds_up_the_totals_above_it_again_and_records_the_edit(): void
    {
        $user = $this->signIn();
        $this->importFigures();
        $murder = $this->figure('Batu Pahat', 'murder');
        $injury = $this->figure('Batu Pahat', 'causing_injury');

        // Only the count that differs from what the page showed is saved.
        $this->from('/crime-data?state=Johor')
            ->put('/crime-data/update', [
                'crimes' => [$murder->Id => '5', $injury->Id => '10'],
                'original' => [$murder->Id => '2', $injury->Id => '10'],
            ])
            ->assertRedirect('/crime-data?state=Johor')
            ->assertSessionHas('message', 'Saved the new count for Murder in Batu Pahat, Johor, 2023, and the totals above it.');

        $this->assertSame(5, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
        $this->assertSame(15, $this->crimes('Johor', 'Batu Pahat', 'assault', 'all'));
        $this->assertSame(6, $this->crimes('Johor', 'All', 'assault', 'murder'));
        $this->assertSame(16, $this->crimes('Johor', 'All', 'assault', 'all'));
        $this->assertSame(7, $this->crimes('Malaysia', 'All', 'assault', 'murder'));
        $this->assertSame(17, $this->crimes('Malaysia', 'All', 'assault', 'all'));
        // Nothing else moves: another category, another year, another state.
        $this->assertSame(45, $this->crimes('Malaysia', 'All', 'property', 'all'));
        $this->assertSame(1, $this->crimes('Malaysia', 'All', 'assault', 'all', 2022));
        $this->assertSame(1, $this->crimes('Sabah', 'All', 'assault', 'all'));

        $edit = CrimeDataEdit::sole();
        $this->assertSame(
            [$user->Id, $user->Name, 'changed', 'page', 'Johor', 'Batu Pahat', 'assault', 'murder', 2023, 2, 5],
            [$edit->UserId, $edit->UserName, $edit->Action, $edit->Source, $edit->State, $edit->District, $edit->Category, $edit->Type, $edit->Year, $edit->OldCrimes, $edit->NewCrimes],
        );

        $this->get('/crime-data')
            ->assertSee('1 figure has been edited here since the last import from data.gov.my.')
            ->assertSeeInOrder(['Update Logs by User', $user->Name, 'on this page', 'Murder in Batu Pahat, Johor, 2023', '2 → 5']);

        // The same number typed differently, or nothing changed, saves nothing.
        $this->put('/crime-data/update', ['crimes' => [$murder->Id => '05'], 'original' => [$murder->Id => '5']])
            ->assertSessionHas('message', 'Nothing to save: no count was changed.');
        $this->assertSame(1, CrimeDataEdit::count());
    }

    public function test_a_count_must_be_a_whole_number_and_not_changed_by_someone_else_meanwhile(): void
    {
        $this->signIn();
        $this->importFigures();
        $murder = $this->figure('Batu Pahat', 'murder');
        $injury = $this->figure('Batu Pahat', 'causing_injury');

        foreach (['-1', '2.5', 'many', ''] as $count) {
            $this->put('/crime-data/update', ['crimes' => [$murder->Id => $count], 'original' => [$murder->Id => '2']])
                ->assertSessionHasErrors(["crimes.{$murder->Id}" => 'The count for Murder in Batu Pahat, Johor, 2023 must be a whole number, 0 or more.']);
        }

        // The page showed 9, but the figure is now 10: nothing is saved, not even the other count.
        $this->put('/crime-data/update', [
            'crimes' => [$murder->Id => '3', $injury->Id => '11'],
            'original' => [$murder->Id => '2', $injury->Id => '9'],
        ])->assertSessionHasErrors(["crimes.{$injury->Id}" => 'The count for Causing injury in Batu Pahat, Johor, 2023 was changed to 10 by someone else while you were editing. Check it and save again.']);

        $this->assertSame(2, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
        $this->assertSame(0, CrimeDataEdit::count());

        // A total isn't a figure the page edits.
        $total = $this->figure('Batu Pahat', 'all');
        $this->put('/crime-data/update', ['crimes' => [$total->Id => '99'], 'original' => [$total->Id => '12']])
            ->assertSessionHasErrors("crimes.{$total->Id}");
        $this->assertSame(12, $this->crimes('Johor', 'Batu Pahat', 'assault', 'all'));
    }

    public function test_adding_a_figure_adds_it_to_the_totals_and_makes_totals_for_a_new_year(): void
    {
        $this->signIn();
        $this->importFigures();

        $this->post('/crime-data/store', ['district' => 'Johor|Kluang', 'type' => 'assault.causing_injury', 'year' => '2023', 'crimes' => '4'])
            ->assertRedirect('/crime-data?state=Johor&district=Kluang&year=2023')
            ->assertSessionHas('message', 'Added Causing injury in Kluang, Johor, 2023, and the totals above it.');

        $this->assertSame(5, $this->crimes('Johor', 'Kluang', 'assault', 'all'));
        $this->assertSame(14, $this->crimes('Johor', 'All', 'assault', 'causing_injury'));
        $this->assertSame(17, $this->crimes('Johor', 'All', 'assault', 'all'));
        $this->assertSame(14, $this->crimes('Malaysia', 'All', 'assault', 'causing_injury'));
        $this->assertSame(18, $this->crimes('Malaysia', 'All', 'assault', 'all'));
        $this->assertSame('added', CrimeDataEdit::sole()->Action);

        // A year with no figures yet gets its totals made.
        $this->post('/crime-data/store', ['district' => 'Sabah|W.P. Labuan', 'type' => 'property.break_in', 'year' => '2024', 'crimes' => '7'])->assertSessionHasNoErrors();
        foreach ([['Sabah', 'W.P. Labuan', 'all'], ['Sabah', 'All', 'break_in'], ['Sabah', 'All', 'all'], ['Malaysia', 'All', 'break_in'], ['Malaysia', 'All', 'all']] as [$state, $district, $type]) {
            $this->assertSame(7, $this->crimes($state, $district, 'property', $type, 2024), "{$state} {$district} {$type}");
        }

        // A figure that's already there is changed in the list instead.
        $this->post('/crime-data/store', ['district' => 'Johor|Kluang', 'type' => 'assault.murder', 'year' => '2023', 'crimes' => '9'])
            ->assertSessionHasErrors(['type' => 'There\'s already a figure for Murder in Kluang, Johor, 2023: 1. Change it in the list instead.']);
        $this->post('/crime-data/store', ['district' => 'Johor|Nowhere', 'type' => 'assault.kidnap', 'year' => '20', 'crimes' => '-3'])
            ->assertSessionHasErrors([
                'district' => 'Choose a police district from the list.',
                'type' => 'Choose a crime type from the list.',
                'year', 'crimes',
            ]);
        $this->assertSame(1, $this->crimes('Johor', 'Kluang', 'assault', 'murder'));
    }

    public function test_the_list_shows_10_figures_a_page_or_50_100_or_all_as_chosen(): void
    {
        $this->signIn();
        // 25 figures: Kangar's murders over 25 years, newest first.
        CrimeStat::insert(array_map(fn (int $n) => [
            'State' => 'Perlis', 'District' => 'Kangar', 'Category' => 'assault', 'Type' => 'murder', 'Year' => 1990 + $n, 'Crimes' => $n,
        ], range(1, 25)));
        $rows = fn ($page) => substr_count($page->getContent(), 'name="crimes[');

        // 10 at first, with the choices as DataTables' lengthMenu, and the page numbers.
        $page = $this->get('/crime-data')
            ->assertSee('1–10 of 25')
            ->assertSee('<input type="hidden" name="rows" value="10" data-rows />', false)
            ->assertSeeInOrder([
                '<label for="filter-length">Rows per page</label>',
                '<option value="10" selected>10</option>', '<option value="50" >50</option>',
                '<option value="100" >100</option>', '<option value="-1" >All</option>',
            ], false)
            ->assertSeeInOrder([
                'Previous', '<span class="btn btn-sm pager-current" aria-current="page"><span class="visually-hidden">Page </span>1</span>',
                'href="'.url('/crime-data?page=2').'"', 'href="'.url('/crime-data?page=3').'"', 'Next',
            ], false);
        $this->assertSame(10, $rows($page));

        foreach (['50' => 25, '100' => 25, '-1' => 25, '7' => 10, 'all' => 10] as $length => $count) {
            $this->assertSame($count, $rows($this->get("/crime-data?length={$length}")), "length={$length}");
        }
        $this->get('/crime-data?length=-1')
            ->assertSee('1–25 of 25')
            ->assertSee('<option value="-1" selected>All</option>', false)
            ->assertDontSee('pager-current', false);

        // The choice stays with the filters and the pages, and Clear keeps it.
        $this->get('/crime-data?state=Perlis&length=10&page=2')
            ->assertSee('11–20 of 25')
            ->assertSee('href="'.url('/crime-data?state=Perlis&amp;length=10&amp;page=3').'"', false);
        $this->get('/crime-data?state=Perlis&length=50')->assertSee('<a class="btn btn-ghost" href="'.url('/crime-data?length=50').'">Clear</a>', false);
    }

    public function test_the_update_log_shows_10_edits_a_page_or_50_100_or_all_on_its_own(): void
    {
        $this->signIn();
        // 25 edits, a minute apart: Kangar's murders in 1991 to 2015, changed from n to n + 1.
        CrimeDataEdit::insert(array_map(fn (int $n) => [
            'UserId' => null, 'UserName' => 'Siti Aminah', 'Action' => 'changed', 'Source' => 'page', 'State' => 'Perlis', 'District' => 'Kangar',
            'Category' => 'assault', 'Type' => 'murder', 'Year' => 1990 + $n, 'OldCrimes' => $n, 'NewCrimes' => $n + 1,
            'CreatedAt' => now()->subMinutes(30 - $n),
        ], range(1, 25)));
        $rows = fn ($page) => substr_count($page->getContent(), 'Murder in Kangar, Perlis');

        // 10 at first, newest first, with the choices as the figures have them, and the page numbers, which lead back
        // down to the log.
        $page = $this->get('/crime-data')
            ->assertSeeInOrder([
                '<h2 id="edits-heading">Update Logs by User</h2>',
                '<label for="edits-length">Rows per page</label>', '<select id="edits-length" name="edits_length">',
                '<option value="10" selected>10</option>', '<option value="50" >50</option>', '<option value="100" >100</option>', '<option value="-1" >All</option>',
                'Murder in Kangar, Perlis, 2015', '24 → 25', 'Murder in Kangar, Perlis, 2006',
                '<nav class="pager" aria-label="Pages of the update log">', '1–10 of 25',
                'href="'.url('/crime-data?edits_page=2#edits-heading').'"', 'href="'.url('/crime-data?edits_page=3#edits-heading').'"', 'Next',
            ], false)
            ->assertDontSee('Murder in Kangar, Perlis, 2005');
        $this->assertSame(10, $rows($page));

        $this->get('/crime-data?edits_page=3')->assertSee('21–25 of 25')->assertSee('Murder in Kangar, Perlis, 1991');
        foreach (['50' => 25, '100' => 25, '-1' => 25, '7' => 10, 'all' => 10] as $length => $count) {
            $this->assertSame($count, $rows($this->get("/crime-data?edits_length={$length}")), "edits_length={$length}");
        }

        // On its own: paging the log keeps the figures' choices and page, and the other way round.
        $this->get('/crime-data?state=Perlis&length=50&edits_page=2')
            ->assertSee('<input type="hidden" name="state" value="Perlis" />', false)
            ->assertSee('<input type="hidden" name="length" value="50" />', false)
            ->assertDontSee('<input type="hidden" name="edits_page"', false)
            ->assertSee('href="'.url('/crime-data?state=Perlis&amp;length=50&amp;edits_page=3#edits-heading').'"', false);
        $this->get('/crime-data?edits_length=50')
            ->assertSee('<input type="hidden" name="edits_length" value="50" />', false)
            ->assertSee('<option value="50" selected>50</option>', false);

        // Each list refreshes in place on its own (live-table.js): the figures with their filters, the log with its own.
        $this->get('/crime-data')->assertSeeInOrder([
            '<section class="card crime-list" aria-labelledby="figures-heading" data-live-region="figures" data-live-params="state district year type length page">',
            '<section class="card crime-edits" aria-labelledby="edits-heading" data-live-region="edits" data-live-params="edits_length edits_page">',
            '<script src="'.versioned_asset('js/live-table.js').'" defer></script>',
        ], false);

        // Nothing edited since the last import: nothing to page.
        CrimeDataEdit::query()->delete();
        $this->get('/crime-data')->assertSee('None since the last import from data.gov.my.')->assertDontSee('edits-length', false);
    }

    public function test_a_save_that_lost_some_counts_on_the_way_saves_nothing(): void
    {
        $this->signIn();
        $this->importFigures();
        $murder = $this->figure('Batu Pahat', 'murder');

        // The page sent 3 rows, but only 1 arrived, as when PHP drops what's past max_input_vars.
        $this->put('/crime-data/update', ['rows' => 3, 'crimes' => [$murder->Id => '5'], 'original' => [$murder->Id => '2']])
            ->assertSessionHasErrors(['crimes' => 'Not every count reached the server: the list sent more than it takes at once. Show fewer figures per page, or change fewer at a time, and save again.']);
        $this->assertSame(2, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
        $this->get('/crime-data')->assertSee('Not every count reached the server');

        // Only the changed count, as the page's script sends it, saves.
        $this->put('/crime-data/update', ['rows' => 1, 'crimes' => [$murder->Id => '5'], 'original' => [$murder->Id => '2']])->assertSessionHasNoErrors();
        $this->assertSame(5, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
    }

    public function test_deleting_a_figure_takes_it_out_of_the_totals(): void
    {
        $this->signIn();
        $this->importFigures();
        $murder = $this->figure('Kluang', 'murder');

        // Without JavaScript, a page asks first.
        $this->get("/crime-data/delete/{$murder->Id}")->assertOk()->assertSee('Are you sure you want to delete Murder in Kluang, Johor, 2023?');
        $this->get('/crime-data')
            ->assertSee('data-confirm-form="delete-figure" data-confirm-action="'.url("/crime-data/destroy/{$murder->Id}").'"', false)
            ->assertSee('<form method="post" action="" id="delete-figure" hidden>', false);

        $this->from('/crime-data?state=Johor')->delete("/crime-data/destroy/{$murder->Id}")
            ->assertRedirect('/crime-data?state=Johor')
            ->assertSessionHas('message', 'Deleted Murder in Kluang, Johor, 2023, and updated the totals above it.');

        $this->assertNull($this->crimes('Johor', 'Kluang', 'assault', 'murder'));
        // Kluang has no violent crime figures left, so it has no violent crime total.
        $this->assertNull($this->crimes('Johor', 'Kluang', 'assault', 'all'));
        $this->assertSame(2, $this->crimes('Johor', 'All', 'assault', 'murder'));
        $this->assertSame(12, $this->crimes('Johor', 'All', 'assault', 'all'));
        $this->assertSame(3, $this->crimes('Malaysia', 'All', 'assault', 'murder'));
        $this->assertSame(13, $this->crimes('Malaysia', 'All', 'assault', 'all'));
        $this->assertSame(['deleted', 1, null], [CrimeDataEdit::sole()->Action, CrimeDataEdit::sole()->OldCrimes, CrimeDataEdit::sole()->NewCrimes]);

        // Totals are added up, not deleted on their own.
        $this->delete('/crime-data/destroy/'.$this->figure('All', 'murder')->Id)->assertNotFound();
        $this->get('/crime-data/delete/'.$this->figure('All', 'murder')->Id)->assertNotFound();
    }

    public function test_download_gives_every_figure_as_an_excel_file(): void
    {
        $this->signIn();
        $this->importFigures();

        $response = $this->get('/crime-data/download')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('crime-figures-'.now()->format('Y-m-d').'.xlsx');

        $sheets = [];
        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['Crime figures', 'Read me'], array_keys($sheets));
        $this->assertSame(['state', 'district', 'category', 'type', 'year', 'crimes', 'type name'], $sheets['Crime figures'][0]);
        $this->assertCount(9, $sheets['Crime figures']);
        $this->assertSame(['Johor', 'Batu Pahat', 'assault', 'murder', 2022, 1, 'Murder'], $sheets['Crime figures'][1]);
        $this->assertSame(['Sabah', 'W.P. Labuan', 'assault', 'murder', 2023, 1, 'Murder'], $sheets['Crime figures'][8]);
        $this->assertContains(['property', 'theft_vehicle_motorcycle', 'Motorcycle theft'], $sheets['Read me']);
    }

    public function test_an_uploaded_file_shows_its_changes_before_they_are_applied(): void
    {
        Storage::fake('local');
        $user = $this->signIn();
        $this->importFigures();

        // One count changed, one figure new, and Labuan's murder left out, so deleted.
        $rows = array_values(array_filter($this->everyFigure(), fn (array $row) => $row[1] !== 'W.P. Labuan'));
        foreach ($rows as &$row) {
            if ($row[1] === 'Batu Pahat' && $row[3] === 'murder' && $row[4] === 2023) {
                $row[5] = 6;
            }
        }
        unset($row);
        $rows[] = ['Johor', 'Kluang', 'property', 'theft_other', 2023, 8];

        $page = $this->post('/crime-data/upload', ['file' => $this->excelFile($rows, name: 'edited.xlsx')])->assertOk();

        $page->assertSee('<title>Check the upload · '.config('app.name').'</title>', false)
            ->assertSee('edited.xlsx has 8 figures.')
            ->assertSeeInOrder(['<strong>1</strong> changed', '<strong>1</strong> added', '<strong>1</strong> deleted'], false)
            ->assertSee('1 figure isn\'t in the file, so applying it deletes it.')
            ->assertSeeInOrder(['Changed', 'Batu Pahat', 'Murder', '2', '6'])
            ->assertSeeInOrder(['Added', 'Kluang', 'Other theft', '–', '8'])
            ->assertSeeInOrder(['Deleted', 'W.P. Labuan', 'Murder', '1', '–']);

        // Nothing has changed yet.
        $this->assertSame(2, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
        $this->assertSame(0, CrimeDataEdit::count());

        preg_match('~action="'.preg_quote(url('/crime-data/apply'), '~').'/([0-9a-f-]{36})"~', $page->getContent(), $match);
        $this->assertStringContainsString('action="'.url("/crime-data/cancel/{$match[1]}").'"', $page->getContent());
        $token = $match[1];

        // Only the user who uploaded it can apply it.
        $other = User::create(['Name' => 'Other Admin', 'Email' => 'other@example.com']);
        $other->roles()->attach(Role::firstWhere('Name', 'ADMIN')->Id);
        $this->actingAs($other)->post("/crime-data/apply/{$token}")->assertNotFound();
        $this->actingAs($user);

        $this->post("/crime-data/apply/{$token}")
            ->assertRedirect('/crime-data')
            ->assertSessionHas('message', 'Updated the figures from edited.xlsx: 1 changed, 1 added, 1 deleted. The totals above them are added up again.');

        $this->assertSame(6, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
        $this->assertSame(8, $this->crimes('Johor', 'Kluang', 'property', 'theft_other'));
        $this->assertNull($this->crimes('Sabah', 'W.P. Labuan', 'assault', 'murder'));
        $this->assertSame(53, $this->crimes('Johor', 'All', 'property', 'all'));
        $this->assertSame(17, $this->crimes('Malaysia', 'All', 'assault', 'all'));
        $this->assertNull($this->crimes('Sabah', 'All', 'assault', 'all'));
        $this->assertEqualsCanonicalizing(['changed', 'added', 'deleted'], CrimeDataEdit::query()->pluck('Action')->all());
        $this->assertSame(['upload'], CrimeDataEdit::query()->distinct()->pluck('Source')->all());

        // Applied once only.
        $this->post("/crime-data/apply/{$token}")->assertNotFound();
    }

    public function test_an_upload_is_checked_before_anything_changes(): void
    {
        Storage::fake('local');
        $this->signIn();
        $this->importFigures();

        // The same figures change nothing.
        $this->post('/crime-data/upload', ['file' => $this->excelFile($this->everyFigure(), name: 'same.xlsx')])
            ->assertRedirect('/crime-data')
            ->assertSessionHas('message', 'same.xlsx has the same figures as the page already has, so there\'s nothing to change.');

        $this->post('/crime-data/upload', ['file' => $this->excelFile([['Johor', 'Kluang', 'assault', 'murder', 2023, 1]], ['name', 'count'])])
            ->assertSessionHasErrors(['file' => 'The first sheet\'s first row should be the headings state, district, category, type, year, crimes, as in a file downloaded from this page.']);

        $this->post('/crime-data/upload', ['file' => $this->excelFile([
            ['Johor', 'Kluang', 'assault', 'murder', 2023, -1],
            ['Johor', 'All', 'assault', 'murder', 2023, 3],
            ['Johor', 'Kluang', 'violent', 'murder', 2023, 1],
            ['Johor', 'Kluang', 'assault', 'murder', 'last year', 1],
            ['Johor', 'Kluang', 'assault', 'murder', 2023, 1.5],
            ['Johor', 'Kluang', 'property', 'break_in', 2023, 5],
            ['Johor', 'Kluang', 'property', 'break_in', 2023, 6],
        ])])->assertSessionHasErrors('file');
        $this->assertSame([
            'Row 2 has crimes "-1": it should be a whole number, 0 or more.',
            'Row 3 is a total: totals are added up from the districts, so leave them out.',
            'Row 4 has category "violent": it should be assault or property.',
            'Row 5 has year "last year": it should be a year like 2023.',
            'Row 6 has crimes "1.5": it should be a whole number, 0 or more.',
            'Row 8 repeats Kluang, Johor: Break-in in 2023. Each should be in the file once.',
        ], session('errors')->get('file'));

        $csv = UploadedFile::fake()->createWithContent('crime.csv', implode("\n", self::Figures));
        $this->post('/crime-data/upload', ['file' => $csv])
            ->assertSessionHasErrors(['file' => 'Upload an Excel workbook (.xlsx), like the one Download Excel gives.']);

        $notExcel = UploadedFile::fake()->createWithContent('crime.xlsx', 'not a workbook');
        $this->post('/crime-data/upload', ['file' => $notExcel])
            ->assertSessionHasErrors(['file' => 'That file couldn\'t be read as an Excel workbook. Save it from Excel as .xlsx and try again.']);

        $this->assertSame(0, CrimeDataEdit::count());
        $this->assertSame(2, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
    }

    public function test_an_upload_warns_about_what_the_map_cant_show_and_can_be_cancelled(): void
    {
        Storage::fake('local');
        $this->signIn();
        $this->importFigures();

        $rows = [...$this->everyFigure(), ['Johor', 'Batu Pahatt', 'assault', 'murder', 2023, 3], ['Johor', 'Kluang', 'assault', 'kidnap', 2023, 2]];
        $page = $this->post('/crime-data/upload', ['file' => $this->excelFile($rows)])->assertOk()
            ->assertSee('No map pin for Batu Pahatt (Johor).')
            ->assertSee('No name for the crime types assault/kidnap');

        preg_match('~/crime-data/cancel/([0-9a-f-]{36})~', $page->getContent(), $match);
        $this->assertCount(1, Storage::disk('local')->files('crime-uploads'));

        $this->delete("/crime-data/cancel/{$match[1]}")
            ->assertRedirect('/crime-data')
            ->assertSessionHas('message', 'Cancelled: nothing in crime.xlsx was applied.');

        $this->assertCount(0, Storage::disk('local')->files('crime-uploads'));
        $this->assertNull($this->crimes('Johor', 'Batu Pahatt', 'assault', 'murder'));
    }

    public function test_an_upload_is_not_applied_if_the_figures_changed_after_it_was_checked(): void
    {
        Storage::fake('local');
        $this->signIn();
        $this->importFigures();
        $murder = $this->figure('Batu Pahat', 'murder');

        $rows = array_map(fn (array $row) => $row[1] === 'Batu Pahat' && $row[3] === 'murder' && $row[4] === 2023 ? [...array_slice($row, 0, 5), 6] : $row, $this->everyFigure());
        $page = $this->post('/crime-data/upload', ['file' => $this->excelFile($rows)])->assertOk();
        preg_match('~/crime-data/apply/([0-9a-f-]{36})~', $page->getContent(), $match);

        // Someone saves another count for it on the page meanwhile.
        $this->put('/crime-data/update', ['crimes' => [$murder->Id => '4'], 'original' => [$murder->Id => '2']])->assertSessionHasNoErrors();

        $this->post("/crime-data/apply/{$match[1]}")
            ->assertRedirect('/crime-data')
            ->assertSessionHasErrors(['file' => 'The figures changed after crime.xlsx was checked, so nothing was changed. Upload it again to see what it would change now.']);

        $this->assertSame(4, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
    }

    public function test_the_import_stops_rather_than_replace_edits_unless_forced(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 28)->setTime(16, 5));
        $this->signIn();
        $this->importFigures();
        $murder = $this->figure('Batu Pahat', 'murder');
        $this->put('/crime-data/update', ['crimes' => [$murder->Id => '5'], 'original' => [$murder->Id => '2']]);

        $path = tempnam(sys_get_temp_dir(), 'crime');
        file_put_contents($path, implode("\n", self::Figures)."\n");
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $this->artisan('map:import-crime', ['file' => $path])
            ->expectsOutput('1 figure has been edited on the Crime data page since the last import, most recently by Signed In on 28 Sep 2026, 16:05. '
                .'Importing would replace it, so nothing was changed. To replace it anyway, run this again with --force.')
            ->assertFailed();
        $this->assertSame(5, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));

        $this->artisan('map:import-crime', ['file' => $path, '--force' => true])
            ->expectsOutputToContain('Loaded ')
            ->expectsOutput('Replaced 1 figure edited on the Crime data page.')
            ->assertSuccessful();
        $this->assertSame(2, $this->crimes('Johor', 'Batu Pahat', 'assault', 'murder'));
        $this->assertSame(0, CrimeDataEdit::count());
    }
}
