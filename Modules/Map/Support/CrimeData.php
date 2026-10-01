<?php

namespace Modules\Map\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Map\Entities\CrimeDataEdit;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

// The crime figures as the Crime data page edits them: each police district's count of one crime type in one
// year. Every total is added up from those: a district's category total (type "all"), a state's figures
// (district "All") and the country's (state "Malaysia"). So an edit, or an uploaded Excel file, updates the
// totals above it, and every edit is recorded in dbo.CrimeDataEdits.
class CrimeData
{
    /**
     * The Excel file's columns, in order. A column after them, like the type's name, is only there to read.
     */
    public const Columns = ['state', 'district', 'category', 'type', 'year', 'crimes'];

    /**
     * The most problems an uploaded file's check lists before it stops.
     */
    private const MaxProblems = 10;

    /**
     * Rows per insert, so each stays under SQL Server's limit of 2100 values per query.
     */
    private const Chunk = 150;

    /**
     * The years with figures by state only (StateCrime), which an uploaded file can't add police district figures
     * to, found once per file.
     *
     * @var list<int>|null
     */
    private ?array $stateOnlyYears = null;

    /**
     * The figures the page edits: police districts' crime types, not the totals added up from them.
     *
     * @return Builder<CrimeStat>
     */
    public static function editable(): Builder
    {
        return CrimeStat::query()
            ->where('State', '!=', 'Malaysia')
            ->where('District', '!=', 'All')
            ->where('Type', '!=', 'all');
    }

    public static function isEditable(CrimeStat $figure): bool
    {
        return $figure->State !== 'Malaysia' && $figure->District !== 'All' && $figure->Type !== 'all';
    }

    /**
     * A figure's place, type and year, the same whatever the letter case, as SQL Server compares them.
     *
     * @param  array<string, mixed>  $figure  with state, district, category, type and year
     */
    public static function key(array $figure): string
    {
        return mb_strtolower(implode('|', [$figure['state'], $figure['district'], $figure['category'], $figure['type'], $figure['year']]));
    }

    /**
     * A figure's row as an array, the way changes describe it.
     *
     * @return array{id: int, state: string, district: string, category: string, type: string, year: int, crimes: int}
     */
    public static function describe(CrimeStat $figure): array
    {
        return [
            'id' => $figure->Id,
            'state' => $figure->State,
            'district' => $figure->District,
            'category' => $figure->Category,
            'type' => $figure->Type,
            'year' => $figure->Year,
            'crimes' => $figure->Crimes,
        ];
    }

    /**
     * A crime type's name, like "Motorcycle theft", or "Robbery" for the one figure crime by state gives for its
     * four kinds, or its code if the Map module has no name for it.
     */
    public static function typeLabel(string $category, string $type): string
    {
        return config("map.crime.types.{$category}.{$type}") ?? config("map.by_state.types.{$category}.{$type}", $type);
    }

    /**
     * Make the changes, record who made them, and add up the totals above them again, all or nothing.
     * Each change is "changed" (with id, old and new), "added" (with new) or "deleted" (with id and old).
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function apply(array $changes, User $user, string $source): void
    {
        if ($changes === []) {
            return;
        }

        DB::transaction(function () use ($changes, $user, $source) {
            $changes = collect($changes);

            foreach ($changes->where('action', 'deleted')->pluck('id')->chunk(1000) as $ids) {
                CrimeStat::query()->whereIn('Id', $ids->all())->delete();
            }

            foreach ($changes->where('action', 'changed') as $change) {
                CrimeStat::query()->whereKey($change['id'])->update(['Crimes' => $change['new']]);
            }

            $added = $changes->where('action', 'added')->map(fn (array $change) => [
                'State' => $change['state'], 'District' => $change['district'], 'Category' => $change['category'],
                'Type' => $change['type'], 'Year' => $change['year'], 'Crimes' => $change['new'],
            ]);
            foreach ($added->chunk(self::Chunk) as $rows) {
                CrimeStat::insert($rows->values()->all());
            }

            $now = now();
            $edits = $changes->map(fn (array $change) => [
                'UserId' => $user->Id, 'UserName' => $user->Name, 'Action' => $change['action'], 'Source' => $source,
                'State' => $change['state'], 'District' => $change['district'], 'Category' => $change['category'],
                'Type' => $change['type'], 'Year' => $change['year'],
                'OldCrimes' => $change['old'] ?? null, 'NewCrimes' => $change['new'] ?? null, 'CreatedAt' => $now,
            ]);
            foreach ($edits->chunk(self::Chunk) as $rows) {
                CrimeDataEdit::insert($rows->values()->all());
            }

            $this->addUpTotals($changes);
        });
    }

    /**
     * Work out again the totals above the changed figures: each changed district's category total, each changed
     * state's figure for every crime type and its category total, and the country's.
     *
     * @param  Collection<int, array<string, mixed>>  $changes
     */
    private function addUpTotals(Collection $changes): void
    {
        foreach ($changes->groupBy(fn (array $change) => $change['category'].'|'.$change['year']) as $group => $inGroup) {
            [$category, $year] = explode('|', $group);

            // Every figure in the category and year, keyed by place and type.
            $current = CrimeStat::query()->where('Category', $category)->where('Year', (int) $year)
                ->get(['Id', 'State', 'District', 'Type', 'Crimes'])
                ->keyBy(fn (CrimeStat $figure) => mb_strtolower("{$figure->State}|{$figure->District}|{$figure->Type}"));
            $districtTypes = $current->filter(fn (CrimeStat $figure) => self::isEditable($figure));

            // [State, District, Type] => crimes, or null where there's nothing left to add up.
            $totals = [];

            foreach ($inGroup->unique(fn (array $change) => mb_strtolower($change['state'].'|'.$change['district'])) as $change) {
                $types = $districtTypes->filter(fn (CrimeStat $figure) => mb_strtolower($figure->State) === mb_strtolower($change['state'])
                    && mb_strtolower($figure->District) === mb_strtolower($change['district']));
                $totals[] = [$change['state'], $change['district'], 'all', $types->isEmpty() ? null : $types->sum('Crimes')];
            }

            // Each state's figure for a type, as it will be: the changed states' added up again, the others as they are.
            $stateFigures = [];
            foreach ($current as $figure) {
                if ($figure->State !== 'Malaysia' && $figure->District === 'All' && $figure->Type !== 'all') {
                    $stateFigures[$figure->State][$figure->Type] = $figure->Crimes;
                }
            }

            foreach ($inGroup->unique(fn (array $change) => mb_strtolower($change['state'])) as $change) {
                $state = $current->first(fn (CrimeStat $figure) => mb_strtolower($figure->State) === mb_strtolower($change['state']))?->State ?? $change['state'];
                $byType = $districtTypes->filter(fn (CrimeStat $figure) => mb_strtolower($figure->State) === mb_strtolower($state))
                    ->groupBy('Type')->map(fn (Collection $figures) => $figures->sum('Crimes'));

                // Every type the state had or has: one with no districts left goes.
                foreach (array_unique([...array_keys($stateFigures[$state] ?? []), ...$byType->keys()->all()]) as $type) {
                    $totals[] = [$state, 'All', $type, $byType[$type] ?? null];
                }
                $totals[] = [$state, 'All', 'all', $byType->isEmpty() ? null : $byType->sum()];

                $stateFigures[$state] = $byType->all();
            }

            $national = [];
            foreach ($stateFigures as $byType) {
                foreach ($byType as $type => $crimes) {
                    $national[$type] = ($national[$type] ?? 0) + $crimes;
                }
            }
            foreach ($current as $figure) {
                if ($figure->State === 'Malaysia' && $figure->District === 'All' && $figure->Type !== 'all') {
                    $national[$figure->Type] ??= null;
                }
            }
            foreach ($national as $type => $crimes) {
                $totals[] = ['Malaysia', 'All', $type, $crimes];
            }
            $totals[] = ['Malaysia', 'All', 'all', array_filter($national, fn ($crimes) => $crimes !== null) === [] ? null : array_sum($national)];

            $this->saveTotals($totals, $current, $category, (int) $year);
        }
    }

    /**
     * Save worked-out totals: change the ones that differ, add the missing ones, and delete the ones with nothing
     * left under them.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: ?int}>  $totals
     * @param  Collection<string, CrimeStat>  $current
     */
    private function saveTotals(array $totals, Collection $current, string $category, int $year): void
    {
        $added = [];

        foreach ($totals as [$state, $district, $type, $crimes]) {
            $figure = $current[mb_strtolower("{$state}|{$district}|{$type}")] ?? null;

            if ($crimes === null) {
                $figure?->delete();
            } elseif ($figure === null) {
                $added["{$state}|{$district}|{$type}"] = ['State' => $state, 'District' => $district, 'Category' => $category, 'Type' => $type, 'Year' => $year, 'Crimes' => $crimes];
            } elseif ($figure->Crimes !== $crimes) {
                $figure->update(['Crimes' => $crimes]);
            }
        }

        foreach (array_chunk(array_values($added), self::Chunk) as $rows) {
            CrimeStat::insert($rows);
        }
    }

    /**
     * Write every editable figure to an Excel file: a sheet of figures in the order the page lists them, with the
     * columns an upload reads, and a sheet saying how to edit it.
     */
    public function writeExcel(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $heading = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '232529');
        $figures = $writer->getCurrentSheet();
        $figures->setName('Crime figures');
        $figures->setSheetView(new SheetView(freezeRow: 2));
        foreach ([1 => 22, 2 => 26, 3 => 12, 4 => 28, 5 => 8, 6 => 10, 7 => 24] as $column => $width) {
            $figures->setColumnWidth($width, $column);
        }
        $writer->addRow(Row::fromValuesWithStyle([...self::Columns, 'type name'], $heading));

        $count = 0;
        $rows = self::editable()->orderBy('State')->orderBy('District')->orderBy('Year')->orderBy('Category')->orderBy('Type')
            ->get(['State', 'District', 'Category', 'Type', 'Year', 'Crimes']);
        foreach ($rows as $figure) {
            $writer->addRow(Row::fromValues([
                $figure->State, $figure->District, $figure->Category, $figure->Type, $figure->Year, $figure->Crimes,
                self::typeLabel($figure->Category, $figure->Type),
            ]));
            $count++;
        }
        $figures->setAutoFilter(new AutoFilter(0, 1, 6, $count + 1));

        $readMe = $writer->addNewSheetAndMakeItCurrent();
        $readMe->setName('Read me');
        $readMe->setColumnWidth(18, 1);
        $readMe->setColumnWidth(30, 2, 3);
        $lines = [
            ['Crime figures from '.config('app.name').', one row per police district, crime type and year.'],
            ['Change the crimes column, add rows or delete them, then upload the file on the Crime data page. It shows what would change before anything does.'],
            ['Keep the headings in the first row as they are. The type name column is only there to read: uploading ignores it.'],
            ['Totals are not in this file: the app adds up each district\'s, each state\'s and Malaysia\'s totals from these rows.'],
            ['Source: '.config('map.crime.credit').', '.config('map.crime.about')],
            [],
        ];
        foreach ($lines as $line) {
            $writer->addRow(Row::fromValues($line));
        }
        $writer->addRow(Row::fromValuesWithStyle(['category', 'type', 'type name'], $heading));
        foreach (config('map.crime.types') as $category => $labels) {
            foreach ($labels as $type => $label) {
                $writer->addRow(Row::fromValues([$category, $type, $label]));
            }
        }

        $writer->close();
    }

    /**
     * Read an uploaded Excel file's figures, keyed as key() keys them, or say what's wrong with it. A police district
     * or crime type that's new here is most likely misspelled, so it's a problem too, unless $newNames says to take
     * new ones as they are, as the progress dialog's Proceed with Review does. newNamesOnly says whether those are
     * the only problems, so taking them as they are would get the file through.
     *
     * @return array{figures: array<string, array{state: string, district: string, category: string, type: string, year: int, crimes: int}>, problems: list<string>, newNamesOnly: bool}
     */
    public function readExcel(string $path, bool $newNames = false): array
    {
        $figures = [];
        $problems = [];
        $more = 0;
        $otherProblems = false;
        $note = function (string $problem, bool $newName = false) use (&$problems, &$more, &$otherProblems) {
            $otherProblems = $otherProblems || ! $newName;
            count($problems) < self::MaxProblems ? $problems[] = $problem : $more++;
        };

        $known = $this->knownNames();
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
                        $header = array_map(fn ($cell) => mb_strtolower((string) $cell), array_slice($cells, 0, count(self::Columns)));
                        if ($header !== self::Columns) {
                            return ['figures' => [], 'problems' => ['The first sheet\'s first row should be the headings '.implode(', ', self::Columns).', as in a file downloaded from this page.'], 'newNamesOnly' => false];
                        }

                        continue;
                    }

                    $figure = $this->readRow(array_slice(array_pad($cells, count(self::Columns), null), 0, count(self::Columns)), $number, $note, $known, $newNames);

                    if ($figure === null) {
                        continue;
                    }

                    $key = self::key($figure);
                    if (isset($figures[$key])) {
                        $note("Row {$number} repeats {$figure['district']}, {$figure['state']}: ".self::typeLabel($figure['category'], $figure['type'])." in {$figure['year']}. Each should be in the file once.");

                        continue;
                    }

                    $figures[$key] = $figure;
                }

                // Only the first sheet holds figures.
                break;
            }
        } catch (Throwable) {
            return ['figures' => [], 'problems' => ['That file couldn\'t be read as an Excel workbook. Save it from Excel as .xlsx and try again.'], 'newNamesOnly' => false];
        } finally {
            $reader->close();
        }

        if ($problems === [] && $figures === []) {
            $note('The file has no figures in it, only headings.');
        }
        if ($more > 0) {
            $problems[] = "And {$more} more like these.";
        }

        return ['figures' => $figures, 'problems' => $problems, 'newNamesOnly' => $problems !== [] && ! $otherProblems];
    }

    /**
     * The names a file's rows may use, as the Add a figure form allows: states and police districts with a map pin or
     * already in the figures, and crime types named in the Map module's config (the Read me sheet's) or already in the
     * figures. A misspelled one is turned down, rather than delete the figure it meant and add one the map can't show.
     *
     * @return array{states: array<string, string>, districts: array<string, array<string, string>>, types: array<string, array<string, string>>}
     *         each by its spelling in lowercase, to its spelling here; districts by state, types by category
     */
    private function knownNames(): array
    {
        $known = ['states' => [], 'districts' => [], 'types' => []];

        $places = PoliceDistrict::query()->get(['State', 'Name'])->map(fn (PoliceDistrict $pin) => [$pin->State, $pin->Name])
            ->concat(self::editable()->select('State', 'District')->distinct()->get()->map(fn (CrimeStat $figure) => [$figure->State, $figure->District]));
        foreach ($places as [$state, $district]) {
            $known['states'][mb_strtolower($state)] = $state;
            $known['districts'][mb_strtolower($state)][mb_strtolower($district)] = $district;
        }

        $types = collect(config('map.crime.types'))->flatMap(fn (array $labels, string $category) => array_map(fn (string $type) => [$category, $type], array_keys($labels)))
            ->concat(self::editable()->select('Category', 'Type')->distinct()->get()->map(fn (CrimeStat $figure) => [$figure->Category, $figure->Type]));
        foreach ($types as [$category, $type]) {
            $known['types'][$category][mb_strtolower($type)] = $type;
        }

        return $known;
    }

    /**
     * Of the names here, the one a name typed wrong is most like, if it's close enough to be what was meant: a letter
     * or so out for a short name, a few for a long one, like causing_injuries for causing_injury.
     *
     * @param  array<string, string>  $names  by how they may be typed, in lowercase
     */
    private static function closest(string $typed, array $names): ?string
    {
        $typed = mb_strtolower($typed);
        $closest = null;
        $nearest = max(1, intdiv(mb_strlen($typed), 4)) + 1;

        foreach ($names as $spelling => $name) {
            $distance = levenshtein($typed, (string) $spelling);
            if ($distance < $nearest) {
                [$closest, $nearest] = [$name, $distance];
            }
        }

        return $closest;
    }

    /**
     * One row of an uploaded file as a figure, or null after noting what's wrong with it. Its names come back spelled
     * as they are here, whatever the letter case they were typed in. A police district or crime type that's new here
     * is checked last, so a row noted for one has nothing else wrong with it, and is noted without leaving the row out.
     *
     * @param  list<mixed>  $cells
     * @param  array<string, array<string, mixed>>  $known  the names it may use, from knownNames()
     * @param  bool  $newNames  whether to take a new police district or crime type as it is
     * @return array{state: string, district: string, category: string, type: string, year: int, crimes: int}|null
     */
    private function readRow(array $cells, int $number, callable $note, array $known, bool $newNames = false): ?array
    {
        // A cell as text for a message; a date or other value Excel made is named by its kind.
        $shown = fn ($cell) => is_scalar($cell) || $cell === null ? (string) $cell : get_debug_type($cell);
        // A cell holding a whole number, 0 or more, as Excel may give it: 12, 12.0 or "12".
        $wholeNumber = fn ($cell) => match (true) {
            is_int($cell) => $cell >= 0 ? $cell : null,
            is_float($cell) => $cell >= 0 && floor($cell) === $cell && $cell <= PHP_INT_MAX ? (int) $cell : null,
            is_string($cell) => ctype_digit($cell) ? (int) $cell : null,
            default => null,
        };

        $state = $shown($cells[0]);
        $district = $shown($cells[1]);
        $category = mb_strtolower($shown($cells[2]));
        $type = mb_strtolower($shown($cells[3]));
        $year = $wholeNumber($cells[4]);
        $crimes = $wholeNumber($cells[5]);
        $districts = $known['districts'][mb_strtolower($state)] ?? [];
        $types = $known['types'][$category] ?? [];
        // A name that isn't one here: the one it's most like, or what to do instead.
        $unknown = fn (string $what, string $typed, ?string $meant, string $otherwise) => "has {$what} \"{$typed}\"".($meant !== null ? ": did you mean {$meant}?" : ", which {$otherwise}");
        // The crime type a wrong one is most like, found by its name too, so "Motorcycle theft" finds
        // theft_vehicle_motorcycle.
        $meantType = function () use ($type, $types, $category) {
            $meant = self::closest($type, $types + array_flip(array_map('mb_strtolower', config("map.crime.types.{$category}", []))));

            return $meant === null ? null : "{$meant} (".self::typeLabel($category, $meant).')';
        };

        $problem = match (true) {
            $state === '' || $district === '' => 'needs a state and a police district',
            mb_strtolower($state) === 'malaysia' || mb_strtolower($district) === 'all' || $type === 'all' => 'is a total: totals are added up from the districts, so leave them out',
            // Malaysia's states don't change, so one that isn't here is misspelled.
            ! isset($known['states'][mb_strtolower($state)]) => $unknown('state', $state, self::closest($state, $known['states']),
                'isn\'t a state here: use one like Johor or Selangor, as in a file downloaded from this page'),
            mb_strlen($district) > 80 => 'has a police district name longer than 80 characters',
            ! array_key_exists($category, config('map.crime.categories')) => 'has category "'.$shown($cells[2]).'": it should be '.implode(' or ', array_keys(config('map.crime.categories'))),
            // A crime type is kept as its code, so even a new one must look like one.
            ! isset($types[$type]) && ! preg_match('/^[a-z][a-z_]{0,39}$/', $type) => $unknown('type', $shown($cells[3]), $meantType(),
                'isn\'t a crime type: use a code from the Read me sheet, like murder or break_in'),
            $year === null || $year < 1900 || $year > 2100 => 'has year "'.$shown($cells[4]).'": it should be a year like 2023',
            // A year by state only would have its states' totals added up again from the file's few rows.
            in_array($year, $this->stateOnlyYears ??= StateCrime::stateOnlyYears(), true) => "has year {$year}, whose figures are by state only, from the police's crime index: leave its rows out",
            $crimes === null || $crimes > 1000000 => 'has crimes "'.$shown($cells[5]).'": it should be a whole number, 0 or more',
            default => null,
        };
        $state = $known['states'][mb_strtolower($state)] ?? $state;

        // Then a police district or crime type that's new here, unless new ones are taken as they are.
        $newName = match (true) {
            $problem !== null || $newNames => null,
            ! isset($districts[mb_strtolower($district)]) => $unknown('police district', $district, self::closest($district, $districts),
                "is new in {$state}: the map can't show it until its pin is added to Modules/Map/".config('map.crime.districts')),
            ! isset($types[$type]) => $unknown('type', $shown($cells[3]), $meantType(),
                'is new here: the map\'s popups and the dashboard can\'t show it until it\'s named in Modules/Map/Config/config.php'),
            default => null,
        };

        if ($problem !== null) {
            $note("Row {$number} {$problem}".(str_ends_with($problem, '?') ? '' : '.'));

            return null;
        }

        // A new name is noted, but its row is still read, to be checked as the others are, like for being in the file
        // twice: so a file whose only problems are new names has nothing else wrong, taking them as they are.
        if ($newName !== null) {
            $note("Row {$number} {$newName}".(str_ends_with($newName, '?') ? '' : '.'), true);
        }

        return [
            'state' => $state, 'district' => $districts[mb_strtolower($district)] ?? $district,
            'category' => $category, 'type' => $types[$type] ?? $type, 'year' => $year, 'crimes' => $crimes,
        ];
    }

    /**
     * What an uploaded file's figures would change: figures with another count, figures that are new, and figures
     * missing from the file, which it would delete. Also what the page can't show properly.
     *
     * @param  array<string, array<string, mixed>>  $figures
     * @return array{changes: list<array<string, mixed>>, warnings: list<string>}
     */
    public function compare(array $figures): array
    {
        $changes = [];
        $current = self::editable()->get()->keyBy(fn (CrimeStat $figure) => self::key(self::describe($figure)));

        foreach ($figures as $key => $figure) {
            $existing = $current[$key] ?? null;

            if ($existing === null) {
                $changes[] = ['action' => 'added', ...$figure, 'old' => null, 'new' => $figure['crimes']];
            } elseif ($existing->Crimes !== $figure['crimes']) {
                $changes[] = ['action' => 'changed', ...self::describe($existing), 'old' => $existing->Crimes, 'new' => $figure['crimes']];
            }
        }

        foreach ($current as $key => $existing) {
            if (! isset($figures[$key])) {
                $changes[] = ['action' => 'deleted', ...self::describe($existing), 'old' => $existing->Crimes, 'new' => null];
            }
        }

        foreach ($changes as &$change) {
            unset($change['crimes']);
        }
        unset($change);

        return ['changes' => $changes, 'warnings' => $this->warnings($changes)];
    }

    /**
     * What added figures bring that the map or popups can't show: districts without a pin, and crime types
     * without a name.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return list<string>
     */
    private function warnings(array $changes): array
    {
        $added = collect($changes)->where('action', 'added');
        $pins = PoliceDistrict::query()->get(['State', 'Name'])->map(fn (PoliceDistrict $pin) => mb_strtolower("{$pin->State}|{$pin->Name}"))->flip();
        $warnings = [];

        $unplaced = $added->reject(fn (array $change) => isset($pins[mb_strtolower("{$change['state']}|{$change['district']}")]))
            ->map(fn (array $change) => "{$change['district']} ({$change['state']})")->unique();
        if ($unplaced->isNotEmpty()) {
            $warnings[] = 'No map pin for '.$unplaced->implode(', ').'. The map leaves them out until they\'re added to '
                .'Modules/Map/'.config('map.crime.districts').'. Check the spelling if they should be districts already here.';
        }

        $unnamed = $added->reject(fn (array $change) => config("map.crime.types.{$change['category']}.{$change['type']}") !== null)
            ->map(fn (array $change) => "{$change['category']}/{$change['type']}")->unique();
        if ($unnamed->isNotEmpty()) {
            $warnings[] = 'No name for the crime types '.$unnamed->implode(', ').', so the map\'s popups and the dashboard leave them out. '
                .'Check the spelling, or add them to Modules/Map/Config/config.php.';
        }

        return $warnings;
    }

    /**
     * Whether changes worked out earlier still fit the figures: none of them changed or appeared since.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    public function stillApplies(array $changes): bool
    {
        // Id => crimes, now.
        $current = [];
        foreach (collect($changes)->where('action', '!=', 'added')->pluck('id')->chunk(1000) as $ids) {
            $current += CrimeStat::query()->whereIn('Id', $ids->all())->pluck('Crimes', 'Id')->all();
        }

        foreach ($changes as $change) {
            if ($change['action'] !== 'added' && ($current[$change['id']] ?? null) !== $change['old']) {
                return false;
            }
        }

        $added = collect($changes)->where('action', 'added');
        if ($added->isEmpty()) {
            return true;
        }

        $existing = self::editable()->whereIn('Year', $added->pluck('year')->unique()->all())->get()
            ->map(fn (CrimeStat $figure) => self::key(self::describe($figure)))->flip();

        return $added->every(fn (array $change) => ! isset($existing[self::key($change)]));
    }
}
