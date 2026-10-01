<?php

namespace Modules\Map\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Entities\PoliceStationEdit;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

// The police stations as the Police stations page edits them, as CrimeData is for the Crime data page's figures: one
// at a time on the page, or all of them as an Excel file to download, edit and upload back. Each of the file's rows
// keeps its station's id, so a station can be renamed or moved to another state; a row without one is a new station,
// and a station missing from the file is deleted. Nothing changes until the uploaded file's changes have been shown
// and applied, and every change is recorded in dbo.PoliceStationEdits.
class PoliceStations
{
    /**
     * The file's columns, in order.
     */
    public const Columns = ['id', 'state', 'district', 'name', 'address', 'phone'];

    /**
     * The most problems an uploaded file's check lists before it stops.
     */
    private const MaxProblems = 10;

    /**
     * Write every station to an Excel file: a sheet of stations by state and name, and a sheet saying how to edit it,
     * with the states' names. The cells are text, so Excel keeps a phone number's first 0.
     */
    public function writeExcel(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $heading = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '232529');
        $text = new Style(format: '@');

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Police stations');
        $sheet->setSheetView(new SheetView(freezeRow: 2));
        foreach ([1 => 8, 2 => 18, 3 => 22, 4 => 44, 5 => 60, 6 => 18] as $column => $width) {
            $sheet->setColumnWidth($width, $column);
        }
        $writer->addRow(Row::fromValuesWithStyle(self::Columns, $heading));

        $stations = $this->current()->sortBy(fn (PoliceStation $station) => [$station->regionName(), $station->Name])->values();
        foreach ($stations as $station) {
            $writer->addRow(Row::fromValuesWithStyles(
                [$station->Id, $station->regionName(), $station->District, $station->Name, (string) PoliceStation::lineBreaks($station->Address), (string) $station->Phone],
                [1 => $text, 2 => $text, 3 => $text, 4 => $text, 5 => $text],
            ));
        }
        $sheet->setAutoFilter(new AutoFilter(0, 1, 5, $stations->count() + 1));

        $readMe = $writer->addNewSheetAndMakeItCurrent();
        $readMe->setName('Read me');
        $readMe->setColumnWidth(24, 1);
        $readMe->setColumnWidth(14, 2);
        $lines = [
            ['The police stations '.config('app.name').' lists under the map, one row each.'],
            ['Change any cell, add rows or delete them, then upload the file on the Police stations page. It shows what would change before anything does.'],
            ['Keep the headings in the first row, and each station\'s id, as they are: the id says which station a row is. Leave id empty for a new station.'],
            ['A station missing from the file is deleted, so upload the whole file, not part of it.'],
            ['state is one of the names below. Address and phone can be left empty.'],
            [],
        ];
        foreach ($lines as $line) {
            $writer->addRow(Row::fromValues($line));
        }
        $writer->addRow(Row::fromValuesWithStyle(['state', 'code'], $heading));
        foreach (collect(config('map.states'))->sortBy('name') as $code => $region) {
            $writer->addRow(Row::fromValues([$region['name'], $code]));
        }

        $writer->close();
    }

    /**
     * Read an uploaded file's stations, or say what's wrong with it. Phone numbers Excel turned into numbers get their
     * first 0 back, with a warning.
     *
     * @return array{stations: list<array{id: ?int, Region: string, District: string, Name: string, Address: ?string, Phone: ?string}>, problems: list<string>, warnings: list<string>}
     */
    public function readExcel(string $path): array
    {
        $stations = [];
        $problems = [];
        $more = 0;
        $zeroes = [];
        $note = function (string $problem) use (&$problems, &$more) {
            count($problems) < self::MaxProblems ? $problems[] = $problem : $more++;
        };

        $ids = $this->current()->pluck('Id')->flip();
        $seenIds = [];
        $seenNames = [];
        $reader = new Reader;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;

                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = array_map(fn ($cell) => is_string($cell) ? trim($cell) : $cell, $row->toArray());

                    if (array_filter($cells, fn ($cell) => $cell !== null && $cell !== '') === []) {
                        continue;
                    }

                    if ($header === null) {
                        $header = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), array_slice($cells, 0, count(self::Columns)));
                        if ($header !== self::Columns) {
                            return ['stations' => [], 'warnings' => [], 'problems' => ['The first sheet\'s first row should be the headings '.implode(', ', self::Columns).', as in a file downloaded from this page.']];
                        }

                        continue;
                    }

                    $station = $this->readRow(array_slice(array_pad($cells, count(self::Columns), null), 0, count(self::Columns)), $number, $note, $zeroes);

                    if ($station === null) {
                        continue;
                    }

                    if ($station['id'] !== null && ! isset($ids[$station['id']])) {
                        $note("Row {$number} has id {$station['id']}, which isn't a station here. Leave id empty for a new station.");

                        continue;
                    }
                    if ($station['id'] !== null && isset($seenIds[$station['id']])) {
                        $note("Row {$number} has the same id as row {$seenIds[$station['id']]}, {$station['id']}. Each station should be in the file once.");

                        continue;
                    }
                    $nameKey = mb_strtolower("{$station['Region']}|{$station['Name']}");
                    if (isset($seenNames[$nameKey])) {
                        $note("Row {$number} has the same name as row {$seenNames[$nameKey]} in the same state, {$station['Name']}. Each station's name should be different in its state.");

                        continue;
                    }

                    if ($station['id'] !== null) {
                        $seenIds[$station['id']] = $number;
                    }
                    $seenNames[$nameKey] = $number;
                    $stations[] = $station;
                }

                // Only the first sheet holds stations.
                break;
            }
        } catch (Throwable) {
            return ['stations' => [], 'warnings' => [], 'problems' => ['That file couldn\'t be read as an Excel workbook. Save it from Excel as .xlsx and try again.']];
        } finally {
            $reader->close();
        }

        if ($problems === [] && $stations === []) {
            $problems[] = 'The file has no police stations in it, only headings. To delete every station, delete them on the page instead.';
        }
        if ($more > 0) {
            $problems[] = "And {$more} more like these.";
        }

        $warnings = $zeroes === [] ? [] : [
            'Excel kept the phone '.(count($zeroes) === 1 ? 'number in row ' : 'numbers in rows ').implode(', ', $zeroes).' as a number, which drops its first 0. '
            .'It was put back: check '.(count($zeroes) === 1 ? 'it' : 'them').' below.',
        ];

        return ['stations' => $stations, 'problems' => $problems, 'warnings' => $warnings];
    }

    /**
     * One row of an uploaded file as a station, or null after noting what's wrong with it.
     *
     * @param  list<mixed>  $cells
     * @param  list<int>  $zeroes  rows whose phone number got its first 0 back
     * @return array{id: ?int, Region: string, District: string, Name: string, Address: ?string, Phone: ?string}|null
     */
    private function readRow(array $cells, int $number, callable $note, array &$zeroes): ?array
    {
        // A cell as text; a date or other value Excel made is named by its kind.
        $text = fn ($cell) => match (true) {
            $cell === null => '',
            is_float($cell) && floor($cell) === $cell => (string) (int) $cell,
            is_scalar($cell) => trim(PoliceStation::lineBreaks((string) $cell)),
            default => get_debug_type($cell),
        };

        [$id, $state, $district, $name, $address, $phone] = $cells;
        $id = match (true) {
            $id === null || $id === '' => null,
            is_int($id) => $id,
            is_float($id) && floor($id) === $id => (int) $id,
            is_string($id) && ctype_digit($id) => (int) $id,
            default => false,
        };
        $state = $text($state);
        $region = $this->region($state);

        // A phone number Excel made a number, like 333762222 for 03-3376 2222, without its first 0.
        if (is_int($phone) || is_float($phone)) {
            $phone = $text($phone);
            if ($phone !== '' && ! str_starts_with($phone, '0') && ! str_starts_with($phone, '60')) {
                $phone = '0'.$phone;
                $zeroes[] = $number;
            }
        }
        $phone = $text($phone);
        [$district, $name, $address] = [$text($district), $text($name), $text($address)];

        $problem = match (true) {
            $id === false => 'has id "'.$text($cells[0]).'": it should be a station\'s number from the file, or empty for a new station',
            $region === null => 'has state "'.$state.'": it should be one of the names on the Read me sheet, like Johor or Kuala Lumpur',
            $district === '' => 'needs a police district',
            mb_strlen($district) > 80 => 'has a police district longer than 80 characters',
            $name === '' => 'needs the police station\'s name',
            mb_strlen($name) > 120 => 'has a name longer than 120 characters',
            mb_strlen($address) > 300 => 'has an address longer than 300 characters',
            mb_strlen($phone) > 30 => 'has a phone number longer than 30 characters',
            $phone !== '' && ! preg_match('/^[0-9+() .-]*[0-9][0-9+() .-]*$/', $phone) => 'has phone "'.$phone.'": use digits, spaces and + ( ) - . only, like 07-436 3300',
            default => null,
        };

        if ($problem !== null) {
            $note("Row {$number} {$problem}.");

            return null;
        }

        return [
            'id' => $id,
            'Region' => $region,
            'District' => $district,
            'Name' => $name,
            'Address' => $address === '' ? null : $address,
            'Phone' => $phone === '' ? null : $phone,
        ];
    }

    /**
     * What an uploaded file's stations would change: stations with other details, new stations, and stations missing
     * from the file, which it would delete. Each change keeps the station as it was and as it would be.
     *
     * @param  list<array<string, mixed>>  $stations
     * @return list<array{action: string, id: ?int, old: ?array<string, ?string>, new: ?array<string, ?string>}>
     */
    public function compare(array $stations): array
    {
        $current = $this->current()->keyBy('Id');
        $changes = [];
        $kept = [];

        foreach ($stations as $station) {
            $new = array_intersect_key($station, array_flip(PoliceStation::Details));

            if ($station['id'] === null) {
                $changes[] = ['action' => 'added', 'id' => null, 'old' => null, 'new' => $new];

                continue;
            }

            $kept[$station['id']] = true;
            $old = $current[$station['id']]->details();
            if ($old !== $new) {
                $changes[] = ['action' => 'changed', 'id' => $station['id'], 'old' => $old, 'new' => $new];
            }
        }

        foreach ($current as $id => $station) {
            if (! isset($kept[$id])) {
                $changes[] = ['action' => 'deleted', 'id' => $id, 'old' => $station->details(), 'new' => null];
            }
        }

        return $changes;
    }

    /**
     * Whether changes worked out earlier still fit the stations: the ones they change or delete are as they were, and
     * no two stations in a state would have the same name after them.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function stillApplies(array $changes): bool
    {
        $current = $this->current()->keyBy('Id');

        foreach ($changes as $change) {
            if ($change['action'] !== 'added' && (! isset($current[$change['id']]) || $current[$change['id']]->details() !== $change['old'])) {
                return false;
            }
        }

        // The stations after the changes, by state and name.
        $after = $current->map(fn (PoliceStation $station) => $station->details())->all();
        foreach ($changes as $change) {
            match ($change['action']) {
                'deleted' => $after = array_diff_key($after, [$change['id'] => true]),
                'changed' => $after[$change['id']] = $change['new'],
                default => $after[] = $change['new'],
            };
        }
        $names = array_map(fn (array $station) => mb_strtolower("{$station['Region']}|{$station['Name']}"), $after);

        return count($names) === count(array_unique($names));
    }

    /**
     * Make the changes and record who made them, on the page ("page") or from an uploaded file ("upload"), all or none:
     * deletions first, then changes, then new stations. A renamed station goes through a name no station has on the
     * way, so two stations can swap names. Each change is "changed" (with id, old and new), "added" (with new) or
     * "deleted" (with id and old), as compare() gives them.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function apply(array $changes, User $user, string $source): void
    {
        $changes = collect($changes);

        DB::transaction(function () use ($changes, $user, $source) {
            PoliceStation::query()->whereIn('Id', $changes->where('action', 'deleted')->pluck('id')->all())->delete();

            $changed = $changes->where('action', 'changed');
            foreach ($changed as $change) {
                if ($change['old']['Name'] !== $change['new']['Name'] || $change['old']['Region'] !== $change['new']['Region']) {
                    PoliceStation::query()->whereKey($change['id'])->update(['Name' => "~renaming {$change['id']}"]);
                }
            }
            foreach ($changed as $change) {
                PoliceStation::query()->whereKey($change['id'])->update($change['new']);
            }

            // A new station's id is known once it's added, for its record.
            $changes = $changes->map(fn (array $change) => $change['action'] === 'added'
                ? ['id' => PoliceStation::create($change['new'])->Id] + $change
                : $change);

            PoliceStationEdit::record($user, $source, $changes->values()->all());
        });
    }

    /**
     * A state or federal territory's code from its name or code, in capitals or not, like Johor or MY-01.
     */
    private function region(string $state): ?string
    {
        foreach (config('map.states') as $code => $region) {
            if (strcasecmp($state, $region['name']) === 0 || strcasecmp($state, $code) === 0) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, PoliceStation>
     */
    private function current(): Collection
    {
        return PoliceStation::query()->get();
    }
}
