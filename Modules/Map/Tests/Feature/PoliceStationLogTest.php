<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Entities\PoliceStationEdit;
use Tests\TestCase;

class PoliceStationLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The newest edit.
     */
    private function latest(): PoliceStationEdit
    {
        return PoliceStationEdit::query()->orderByDesc('Id')->firstOrFail();
    }

    public function test_adding_editing_and_deleting_a_station_are_logged_with_who_did_it_and_when(): void
    {
        $user = $this->signIn();
        $this->travelTo(Carbon::create(2026, 10, 1, 9, 30));

        $this->post('/police-stations/store', ['region' => 'MY-01', 'district' => 'Muar', 'name' => 'Balai Polis Muar', 'address' => '', 'phone' => '06-952 1222'])
            ->assertSessionHas('message', 'Added Balai Polis Muar.');
        $station = PoliceStation::sole();
        $added = PoliceStationEdit::sole();
        $this->assertSame(
            [$user->Id, $user->Name, 'added', 'page', $station->Id, null, ['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'Balai Polis Muar', 'Address' => null, 'Phone' => '06-952 1222'], '2026-10-01 09:30'],
            [$added->UserId, $added->UserName, $added->Action, $added->Source, $added->StationId, $added->OldDetails, $added->NewDetails, $added->CreatedAt->format('Y-m-d H:i')],
        );

        // Renamed, with an address typed over two lines. Saving it again as it is changes nothing and logs nothing, nor
        // does a save the form turns down.
        $this->travel(5)->minutes();
        $edit = fn (array $fields = []) => $this->put("/police-stations/update/{$station->Id}", $fields + [
            'region' => 'MY-01', 'district' => 'Muar', 'name' => 'IPD Muar', 'address' => "Jalan Petri,\r\n84000 Muar", 'phone' => '06-952 1222',
        ]);
        $edit()->assertSessionHas('message', 'Saved changes to IPD Muar.');
        $edit()->assertSessionHas('message', 'Nothing to save: no detail of IPD Muar was changed.');
        $edit(['phone' => 'call us'])->assertSessionHasErrors('phone');
        $this->assertSame(2, PoliceStationEdit::count());
        $changed = $this->latest();
        $this->assertSame(
            ['changed', $station->Id, ['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'Balai Polis Muar', 'Address' => null, 'Phone' => '06-952 1222'], ['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'IPD Muar', 'Address' => "Jalan Petri,\n84000 Muar", 'Phone' => '06-952 1222']],
            [$changed->Action, $changed->StationId, $changed->OldDetails, $changed->NewDetails],
        );

        // Deleted: what it had is kept with its edit.
        $this->travel(5)->minutes();
        $this->delete("/police-stations/destroy/{$station->Id}")->assertSessionHas('message', 'Deleted IPD Muar.');
        $deleted = $this->latest();
        $this->assertSame(
            ['deleted', $station->Id, ['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'IPD Muar', 'Address' => "Jalan Petri,\n84000 Muar", 'Phone' => '06-952 1222'], null],
            [$deleted->Action, $deleted->StationId, $deleted->OldDetails, $deleted->NewDetails],
        );

        // Under the list, newest first: when, who and where, and what changed, before → after.
        $this->get('/police-stations')->assertOk()->assertSeeInOrder([
            '<section class="card station-edits" aria-labelledby="edits-heading" data-live-region="edits" data-live-params="edits_length edits_page">',
            '<h2 id="edits-heading">Update Logs by User</h2>',
            '<th>When</th>', '<th>Who</th>', '<th>Police station</th>', '<th>Change</th>',
            '01 Oct 2026, 09:40', 'Signed In <span class="station-source muted">on this page</span>', 'IPD Muar', 'Muar police district · Johor',
            '<span class="station-edit-action">Deleted</span>', '<dt>Address</dt><dd class="muted">Jalan Petri,', '<dt>Phone</dt><dd class="muted">06-952 1222</dd>',
            '01 Oct 2026, 09:35', 'IPD Muar',
            '<dt>Name</dt><dd><span class="muted">Balai Polis Muar</span> → IPD Muar</dd>', '<dt>Address</dt><dd><span class="muted">—</span> → Jalan Petri,',
            '01 Oct 2026, 09:30', 'Balai Polis Muar',
            '<span class="station-edit-action">Added</span>', '<dt>Address</dt><dd>—</dd>', '<dt>Phone</dt><dd>06-952 1222</dd>',
        ], false);
    }

    public function test_a_change_and_its_log_are_saved_together_or_not_at_all(): void
    {
        $this->signIn();
        // The log can't be written, so the station isn't added either.
        Schema::drop('PoliceStationEdits');

        try {
            $this->withoutExceptionHandling()->post('/police-stations/store', ['region' => 'MY-01', 'district' => 'Muar', 'name' => 'Balai Polis Muar']);
            $this->fail('Adding a station without its log should fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('PoliceStationEdits', $exception->getMessage());
        }
        $this->assertDatabaseCount('PoliceStations', 0);
    }

    public function test_the_update_log_shows_10_changes_a_page_or_50_100_or_all_on_its_own(): void
    {
        $this->signIn();
        // 25 changes, a minute apart: Balai Polis Kangar's phone number from 04-976 1001 to 04-976 1002, and so on.
        $details = fn (int $n) => json_encode(['Region' => 'MY-09', 'District' => 'Kangar', 'Name' => 'Balai Polis Kangar', 'Address' => null, 'Phone' => '04-976 '.(1000 + $n)]);
        PoliceStationEdit::insert(array_map(fn (int $n) => [
            'UserId' => null, 'UserName' => 'Siti Aminah', 'Action' => 'changed', 'Source' => 'page', 'StationId' => 1,
            'OldDetails' => $details($n), 'NewDetails' => $details($n + 1), 'CreatedAt' => now()->subMinutes(30 - $n),
        ], range(1, 25)));
        $rows = fn ($page) => substr_count($page->getContent(), '<dt>Phone</dt>');

        // 10 at first, newest first, with the choices as the stations have them, and the page numbers, which lead back
        // down to the log.
        $page = $this->get('/police-stations')
            ->assertSeeInOrder([
                '<h2 id="edits-heading">Update Logs by User</h2>',
                '<label for="edits-length">Rows per page</label>', '<select id="edits-length" name="edits_length">',
                '<option value="10" selected>10</option>', '<option value="50" >50</option>', '<option value="100" >100</option>', '<option value="-1" >All</option>',
                '04-976 1025</span> → 04-976 1026', '04-976 1016</span> → 04-976 1017',
                '<nav class="pager" aria-label="Pages of the update log">', '1–10 of 25',
                'href="'.url('/police-stations?edits_page=2#edits-heading').'"', 'href="'.url('/police-stations?edits_page=3#edits-heading').'"', 'Next',
            ], false)
            ->assertDontSee('04-976 1015');
        $this->assertSame(10, $rows($page));

        $this->get('/police-stations?edits_page=3')->assertSee('21–25 of 25')->assertSee('04-976 1001');
        foreach (['50' => 25, '100' => 25, '-1' => 25, '7' => 10, 'all' => 10] as $length => $count) {
            $this->assertSame($count, $rows($this->get("/police-stations?edits_length={$length}")), "edits_length={$length}");
        }

        // On its own: paging the log keeps the list's choices and page, and the other way round.
        $this->get('/police-stations?state=MY-09&length=50&edits_page=2')
            ->assertSee('<input type="hidden" name="state" value="MY-09" />', false)
            ->assertSee('<input type="hidden" name="length" value="50" />', false)
            ->assertDontSee('<input type="hidden" name="edits_page"', false)
            ->assertSee('href="'.url('/police-stations?state=MY-09&amp;length=50&amp;edits_page=3#edits-heading').'"', false);
        $this->get('/police-stations?state=MY-09&edits_length=50')
            ->assertSee('<input type="hidden" name="edits_length" value="50" />', false)
            ->assertSee('<option value="50" selected>50</option>', false)
            ->assertSee('<a class="btn btn-ghost" href="'.url('/police-stations?edits_length=50').'">Clear</a>', false);

        // Each list refreshes in place on its own (live-table.js): the stations with their filters, the log with its own.
        $this->get('/police-stations')->assertSeeInOrder([
            '<section class="card station-list" aria-labelledby="stations-heading" data-live-region="stations" data-live-params="state search missing length page">',
            '<section class="card station-edits" aria-labelledby="edits-heading" data-live-region="edits" data-live-params="edits_length edits_page">',
            '<script src="'.versioned_asset('js/live-table.js').'" defer></script>',
        ], false);

        // Nothing changed yet: nothing to page.
        PoliceStationEdit::query()->delete();
        $this->get('/police-stations')
            ->assertSee('None yet. Each station added, edited or deleted here, one at a time or from a file, is listed with who did it and when.')
            ->assertDontSee('edits-length', false);
    }
}
