<?php

namespace Modules\Map\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Entities\PoliceStationEdit;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class PoliceStationsExcelTest extends TestCase
{
    use RefreshDatabase;

    private PoliceStation $batuPahat;

    private PoliceStation $muar;

    private PoliceStation $sentul;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->batuPahat = PoliceStation::create(['Region' => 'MY-01', 'District' => 'Batu Pahat', 'Name' => 'IPD Batu Pahat', 'Address' => 'Jalan Kluang, 83000 Batu Pahat, Johor', 'Phone' => '07 4363300']);
        $this->muar = PoliceStation::create(['Region' => 'MY-01', 'District' => 'Muar', 'Name' => 'IPD Muar', 'Address' => null, 'Phone' => null]);
        $this->sentul = PoliceStation::create(['Region' => 'MY-14', 'District' => 'Sentul', 'Name' => 'IPD Sentul', 'Address' => null, 'Phone' => '+60340482222']);
    }

    /**
     * An Excel file with these rows under the columns the page reads, as an upload.
     *
     * @param  list<list<mixed>>  $rows
     */
    private function excelFile(array $rows, array $header = ['id', 'state', 'district', 'name', 'address', 'phone']): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stations').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($header));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return new UploadedFile($path, 'stations.xlsx', null, null, true);
    }

    /**
     * Every station as the downloaded file has it, to change.
     *
     * @return array<string, list<mixed>> by name
     */
    private function everyStation(): array
    {
        return PoliceStation::query()->orderBy('Id')->get()->mapWithKeys(fn (PoliceStation $station) => [$station->Name => [
            $station->Id, $station->regionName(), $station->District, $station->Name, (string) $station->Address, (string) $station->Phone,
        ]])->all();
    }

    private function upload(array $rows): TestResponse
    {
        return $this->from('/police-stations')->post('/police-stations/upload', ['file' => $this->excelFile($rows)]);
    }

    private function token(TestResponse $review): string
    {
        preg_match('~police-stations/apply/([0-9a-f-]{36})~', $review->getContent(), $token);

        return $token[1];
    }

    public function test_the_page_offers_the_excel_file_and_its_upload(): void
    {
        $this->signIn();
        $this->get('/police-stations')->assertOk()
            ->assertSee('<a class="btn btn-success" href="'.url('/police-stations/download').'"><span class="material-icon btn-icon" aria-hidden="true">download</span>Download Excel</a>', false)
            ->assertSeeInOrder([
                '<form method="post" action="'.url('/police-stations/upload').'" enctype="multipart/form-data" class="card form station-upload">',
                '<h2>Upload an Excel file</h2>',
                'A station missing from the file is deleted, so upload the whole file, not part of it.',
                '<button type="submit" class="btn btn-primary">Check the file</button>',
            ], false);
    }

    public function test_without_the_admin_role_the_excel_file_is_forbidden(): void
    {
        $this->signIn(admin: false);
        $token = '0b1f6f3e-8a4c-4d0e-9d7a-2f1c3b5a6e70';

        $this->get('/police-stations/download')->assertForbidden();
        $this->post('/police-stations/upload', ['file' => $this->excelFile(array_values($this->everyStation()))])->assertForbidden();
        $this->post("/police-stations/apply/{$token}")->assertForbidden();
        $this->delete("/police-stations/cancel/{$token}")->assertForbidden();
    }

    public function test_download_gives_every_station_as_an_excel_file(): void
    {
        $this->signIn();

        $response = $this->get('/police-stations/download')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('police-stations-'.now()->format('Y-m-d').'.xlsx');

        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        // By state, then name, each with its id; a phone number kept as it was typed.
        $this->assertSame(['Police stations', 'Read me'], array_keys($sheets));
        $this->assertSame([
            ['id', 'state', 'district', 'name', 'address', 'phone'],
            [$this->batuPahat->Id, 'Johor', 'Batu Pahat', 'IPD Batu Pahat', 'Jalan Kluang, 83000 Batu Pahat, Johor', '07 4363300'],
            [$this->muar->Id, 'Johor', 'Muar', 'IPD Muar', '', ''],
            [$this->sentul->Id, 'Kuala Lumpur', 'Sentul', 'IPD Sentul', '', '+60340482222'],
        ], $sheets['Police stations']);

        // How to edit it, and the states' names.
        $readMe = array_map(fn (array $row) => implode(' | ', array_filter($row, fn ($cell) => $cell !== '')), $sheets['Read me']);
        $this->assertContains('Keep the headings in the first row, and each station\'s id, as they are: the id says which station a row is. Leave id empty for a new station.', $readMe);
        $this->assertContains('Kuala Lumpur | MY-14', $readMe);
    }

    public function test_an_upload_shows_what_it_would_change_then_applies_it(): void
    {
        $this->signIn();
        $rows = $this->everyStation();
        $rows['IPD Batu Pahat'][4] = 'Jalan Kluang Baru, 83000 Batu Pahat, Johor';
        $rows['IPD Sentul'][3] = 'Ibu Pejabat Polis Daerah Sentul';
        unset($rows['IPD Muar']);
        // A new station; its phone typed in Excel as a number, which drops the first 0.
        $rows[] = ['', 'selangor', 'Shah Alam', 'IPD Shah Alam', 'Persiaran Kayangan, 40100 Shah Alam', 355202222];

        $review = $this->upload(array_values($rows))->assertOk()
            ->assertSee('<h1>Check the upload</h1>', false)
            ->assertSee('stations.xlsx has 3 police stations. Applying it makes the changes below.')
            ->assertSeeInOrder(['<strong>2</strong> changed', '<strong>1</strong> added', '<strong>1</strong> deleted'], false)
            ->assertSee('1 police station isn&#039;t in the file, so applying it deletes it.', false)
            ->assertSee('Excel kept the phone number in row 4 as a number, which drops its first 0. It was put back: check it below.')
            ->assertSeeInOrder([
                'Changed', 'IPD Batu Pahat', '<dt>Address</dt>', 'Jalan Kluang, 83000 Batu Pahat, Johor', '→ Jalan Kluang Baru, 83000 Batu Pahat, Johor',
                'Changed', 'Ibu Pejabat Polis Daerah Sentul', 'Sentul police district · Kuala Lumpur', '<dt>Name</dt>', 'IPD Sentul', '→ Ibu Pejabat Polis Daerah Sentul',
                'Added', 'IPD Shah Alam', 'Shah Alam police district · Selangor', '<dt>Phone</dt><dd>0355202222</dd>',
                'Deleted', 'IPD Muar',
            ], false);

        // Nothing has changed yet.
        $this->assertSame(3, PoliceStation::count());
        $this->assertSame('IPD Sentul', $this->sentul->fresh()->Name);

        $this->post('/police-stations/apply/'.$this->token($review))
            ->assertRedirect('/police-stations')
            ->assertSessionHas('message', 'Updated the police stations from stations.xlsx: 2 changed, 1 added, 1 deleted.');

        $this->assertSame('Jalan Kluang Baru, 83000 Batu Pahat, Johor', $this->batuPahat->fresh()->Address);
        $this->assertSame('Ibu Pejabat Polis Daerah Sentul', $this->sentul->fresh()->Name);
        $this->assertNull($this->muar->fresh());
        $this->assertDatabaseHas('PoliceStations', ['Region' => 'MY-10', 'District' => 'Shah Alam', 'Name' => 'IPD Shah Alam', 'Phone' => '0355202222']);

        // Each change is logged as from a file, the new station with its id.
        $shahAlam = PoliceStation::query()->where('Name', 'IPD Shah Alam')->sole();
        $this->assertSame(
            [['changed', $this->batuPahat->Id, 'upload'], ['changed', $this->sentul->Id, 'upload'], ['added', $shahAlam->Id, 'upload'], ['deleted', $this->muar->Id, 'upload']],
            PoliceStationEdit::query()->orderBy('Id')->get()->map(fn (PoliceStationEdit $edit) => [$edit->Action, $edit->StationId, $edit->Source])->all(),
        );
        $this->get('/police-stations')->assertSeeInOrder([
            'Update Logs by User', 'Signed In <span class="station-source muted">from a file</span>', 'IPD Muar', 'Deleted',
        ], false);

        // Once only.
        $this->post('/police-stations/apply/'.$this->token($review))->assertNotFound();
        $this->assertSame(4, PoliceStationEdit::count());
    }

    public function test_an_upload_with_problems_changes_nothing_and_lists_them(): void
    {
        $this->signIn();

        $this->from('/police-stations')->post('/police-stations/upload', ['file' => $this->excelFile([[1, 'Johor']], ['number', 'state'])])
            ->assertSessionHasErrors(['file' => 'The first sheet\'s first row should be the headings id, state, district, name, address, phone, as in a file downloaded from this page.']);

        // Every problem at once, on the page (the errors are read there, not from the session, which would use them up).

        $rows = $this->everyStation();
        $this->upload([
            $rows['IPD Batu Pahat'],
            ['', 'Johore', 'Kluang', 'IPD Kluang', '', ''],
            ['', 'Johor', 'Kluang', '', '', ''],
            ['', 'Johor', 'Kluang', 'IPD Kluang', '', 'call us'],
            [999, 'Johor', 'Kluang', 'IPD Kluang', '', ''],
            [$this->batuPahat->Id, 'Johor', 'Batu Pahat', 'IPD Batu Pahat 2', '', ''],
            ['', 'johor', 'Muar', 'ipd batu pahat', '', ''],
        ])->assertRedirect('/police-stations');

        $this->get('/police-stations')->assertSeeInOrder([
            'The file wasn&#039;t used, and nothing changed.',
            'Row 3 has state &quot;Johore&quot;: it should be one of the names on the Read me sheet, like Johor or Kuala Lumpur.',
            'Row 4 needs the police station&#039;s name.',
            'Row 5 has phone &quot;call us&quot;: use digits, spaces and + ( ) - . only, like 07-436 3300.',
            'Row 6 has id 999, which isn&#039;t a station here. Leave id empty for a new station.',
            'Row 7 has the same id as row 2, '.$this->batuPahat->Id.'. Each station should be in the file once.',
            'Row 8 has the same name as row 2 in the same state, ipd batu pahat.',
        ], false);
        $this->assertSame(3, PoliceStation::count());

        $this->from('/police-stations')->post('/police-stations/upload', ['file' => UploadedFile::fake()->createWithContent('stations.xlsx', 'not a workbook')])
            ->assertSessionHasErrors(['file' => 'That file couldn\'t be read as an Excel workbook. Save it from Excel as .xlsx and try again.']);
    }

    public function test_a_downloaded_file_uploaded_as_it_is_changes_nothing(): void
    {
        $this->signIn();
        // An address saved with the \r\n line breaks a textarea sends, which Excel gives back as \n.
        $this->batuPahat->update(['Address' => "Jalan Kluang,\r\n83000 Batu Pahat, Johor"]);

        $path = tempnam(sys_get_temp_dir(), 'stations').'.xlsx';
        copy($this->get('/police-stations/download')->baseResponse->getFile()->getPathname(), $path);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $this->from('/police-stations')->post('/police-stations/upload', ['file' => new UploadedFile($path, 'police-stations.xlsx', null, null, true)])
            ->assertRedirect('/police-stations')
            ->assertSessionHas('message', 'police-stations.xlsx has the same police stations as the page already has, so there\'s nothing to change.');

        // The form saves them as \n to begin with.
        $this->put("/police-stations/update/{$this->muar->Id}", ['region' => 'MY-01', 'district' => 'Muar', 'name' => 'IPD Muar', 'address' => "Jalan Petri,\r\n84000 Muar"])
            ->assertSessionHasNoErrors();
        $this->assertSame("Jalan Petri,\n84000 Muar", $this->muar->fresh()->Address);
    }

    public function test_changes_made_meanwhile_stop_the_upload_and_cancel_applies_nothing(): void
    {
        $this->signIn();
        $rows = $this->everyStation();
        $rows['IPD Muar'][5] = '06-952 1222';

        $review = $this->upload(array_values($rows))->assertOk();

        // Someone saves the station on the page before the upload is applied.
        $this->muar->update(['Phone' => '06-951 0000']);
        $this->post('/police-stations/apply/'.$this->token($review))
            ->assertRedirect('/police-stations')
            ->assertSessionHasErrors(['file' => 'The police stations changed after stations.xlsx was checked, so nothing was changed. Upload it again to see what it would change now.']);
        $this->assertSame('06-951 0000', $this->muar->fresh()->Phone);

        $review = $this->upload(array_values($rows))->assertOk();
        $this->delete('/police-stations/cancel/'.$this->token($review))
            ->assertRedirect('/police-stations')
            ->assertSessionHas('message', 'Cancelled: nothing in stations.xlsx was applied.');
        $this->post('/police-stations/apply/'.$this->token($review))->assertNotFound();
        $this->assertSame('06-951 0000', $this->muar->fresh()->Phone);
        // Neither the refused upload nor the cancelled one is in the log.
        $this->assertSame(0, PoliceStationEdit::count());
    }

    public function test_two_stations_can_swap_names(): void
    {
        $this->signIn();
        $rows = $this->everyStation();
        [$rows['IPD Batu Pahat'][3], $rows['IPD Muar'][3]] = ['IPD Muar', 'IPD Batu Pahat'];

        $review = $this->upload(array_values($rows))->assertOk()->assertSeeInOrder(['<strong>2</strong> changed'], false);
        $this->post('/police-stations/apply/'.$this->token($review))->assertSessionHasNoErrors();

        $this->assertSame(['IPD Muar', 'IPD Batu Pahat'], [$this->batuPahat->fresh()->Name, $this->muar->fresh()->Name]);
        // The log has the two renames, not the name each passes through on the way.
        $this->assertSame(
            [['IPD Batu Pahat', 'IPD Muar'], ['IPD Muar', 'IPD Batu Pahat']],
            PoliceStationEdit::query()->orderBy('Id')->get()->map(fn (PoliceStationEdit $edit) => [$edit->OldDetails['Name'], $edit->NewDetails['Name']])->all(),
        );
    }
}
