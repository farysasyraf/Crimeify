<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;
use Modules\Map\Entities\PoliceStationEdit;
use Modules\Map\Support\KeepsPendingUploads;
use Modules\Map\Support\PoliceStations;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// The Police stations page (Dev Module), where administrators keep the stations the Map page lists under the map up
// to date: each one's name, the state and police district it's listed under, and its address and phone number, one
// at a time or, as on the Crime data page, by downloading them as an Excel file and uploading it back. As there,
// every change is logged with who made it. Its routes are on the Routes page, for ADMIN to start with.
class PoliceStationController extends Controller
{
    use KeepsPendingUploads;

    /**
     * Stations listed per page, as on the Crime data page: 10 at first, or as chosen from the list, as DataTables'
     * pageLength and lengthMenu: [[10, 50, 100, -1], [10, 50, 100, "All"]]. -1 is all of them.
     */
    private const PageLength = 10;

    private const LengthMenu = [10 => '10', 50 => '50', 100 => '100', -1 => 'All'];

    /**
     * Where an uploaded file's changes wait, on the "local" disk, until they're applied or cancelled.
     */
    private const Uploads = 'station-uploads';

    public function __construct(private PoliceStations $stations) {}

    /**
     * Every station, as an Excel file to change and upload back.
     */
    public function download(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'stations').'.xlsx';
        $this->stations->writeExcel($path);

        return response()->download($path, 'police-stations-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Check an uploaded Excel file, without changing anything yet. With JavaScript, the page checks it in steps it shows
     * as they go (upload-progress.js): this reads the file and keeps its stations, then compare() compares them.
     * Without, this does both, then shows what the file would change on its review page.
     */
    public function upload(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'max:5120'],
        ], [
            'file.required' => 'Choose an Excel file to upload.',
            'file.extensions' => 'Upload an Excel workbook (.xlsx), like the one Download Excel gives.',
            'file.max' => 'The file is larger than 5 MB, much more than the police stations need. Check it\'s the right file.',
        ]);

        $read = $this->stations->readExcel($request->file('file')->getRealPath());

        if ($read['problems'] !== []) {
            throw ValidationException::withMessages(['file' => $read['problems']]);
        }

        $name = $request->file('file')->getClientOriginalName();

        if ($request->expectsJson()) {
            $upload = $this->keepUpload($request, ['file' => $name, 'stations' => $read['stations'], 'warnings' => $read['warnings']]);

            return response()->json(['rows' => count($read['stations']), 'next' => page_url('police-stations/compare', ['upload' => $upload])]);
        }

        return redirect($this->checked($request, $name, $read['stations'], $read['warnings'])['next']);
    }

    /**
     * The next step of a check shown as it goes: compare the stations upload() kept with the saved ones.
     */
    public function compare(Request $request, string $upload): JsonResponse
    {
        $pending = $this->pendingUpload($request, $upload, 'stations');

        return response()->json($this->checked($request, $pending['file'], $pending['stations'], $pending['warnings'], $upload));
    }

    /**
     * What an uploaded file would change, to apply or cancel.
     */
    public function review(Request $request, string $upload): View
    {
        $pending = $this->pendingUpload($request, $upload, 'changes');

        return view('map::police-stations.review', [
            'token' => $upload,
            'file' => $pending['file'],
            'changes' => collect($pending['changes']),
            'warnings' => $pending['warnings'],
            'stationCount' => $pending['rows'],
        ]);
    }

    /**
     * Compare a file's stations with the saved ones. Its changes wait on the server, for this user only, until they're
     * applied or cancelled, and show on its review page next; with none, the list says there's nothing to change.
     *
     * @param  list<array<string, mixed>>  $stations
     * @param  list<string>  $warnings  what reading the file put right, like phone numbers' first 0
     * @return array{changes: int, next: string} how many changes, and the page to show next
     */
    private function checked(Request $request, string $file, array $stations, array $warnings, ?string $upload = null): array
    {
        $changes = $this->stations->compare($stations);

        if ($changes === []) {
            if ($upload !== null) {
                $this->forgetUpload($upload);
            }
            session()->flash('message', "{$file} has the same police stations as the page already has, so there's nothing to change.");
            session()->flash('status', 'info');

            return ['changes' => 0, 'next' => page_url('police-stations')];
        }

        $upload = $this->keepUpload($request, ['file' => $file, 'rows' => count($stations), 'changes' => $changes, 'warnings' => $warnings], $upload);

        return ['changes' => count($changes), 'next' => page_url('police-stations/review', ['upload' => $upload])];
    }

    /**
     * Make an uploaded file's changes, as long as the stations haven't changed since it was checked.
     */
    public function apply(Request $request, string $upload): RedirectResponse
    {
        $pending = $this->pendingUpload($request, $upload, 'changes');
        $changedSince = "The police stations changed after {$pending['file']} was checked, so nothing was changed. Upload it again to see what it would change now.";

        if (! $this->stations->stillApplies($pending['changes'])) {
            $this->forgetUpload($upload);

            return redirect(page_url('police-stations'))->withErrors(['file' => $changedSince]);
        }

        try {
            $this->stations->apply($pending['changes'], $request->user(), 'upload');
        } catch (UniqueConstraintViolationException) {
            // Someone added a station with one of the file's names in between.
            $this->forgetUpload($upload);

            return redirect(page_url('police-stations'))->withErrors(['file' => $changedSince]);
        }

        $this->forgetUpload($upload);
        $counts = collect($pending['changes'])->countBy('action');

        return redirect(page_url('police-stations'))->with('message', "Updated the police stations from {$pending['file']}: "
            .collect(['changed' => 'changed', 'added' => 'added', 'deleted' => 'deleted'])
                ->map(fn ($word, $action) => ($counts[$action] ?? 0).' '.$word)->implode(', ').'.');
    }

    public function cancel(Request $request, string $upload): RedirectResponse
    {
        $pending = $this->pendingUpload($request, $upload);
        $this->forgetUpload($upload);

        return redirect(page_url('police-stations'))->with(['message' => "Cancelled: nothing in {$pending['file']} was applied.", 'status' => 'info']);
    }

    /**
     * Every station, by state and then name, a page at a time, narrowed by state, a search, or to those without an
     * address or phone number yet, which are the ones to fill in; and the log of changes, a page at a time too.
     */
    public function index(Request $request): View
    {
        $filters = [
            'state' => $this->region($request->query('state')) ?? '',
            'search' => $request->string('search')->trim()->value(),
            'missing' => $request->boolean('missing'),
        ];
        $length = array_key_exists($request->integer('length'), self::LengthMenu) ? $request->integer('length') : self::PageLength;

        $chosen = PoliceStation::query()
            ->when($filters['state'] !== '', fn ($query) => $query->where('Region', $filters['state']))
            ->search($filters['search'])
            ->when($filters['missing'], fn ($query) => $query->where(fn ($query) => $query->whereNull('Address')->orWhereNull('Phone')));

        // By the state's name, not its code: MY-07 Pulau Pinang comes after MY-08 Perak and MY-09 Perlis.
        $codes = collect(config('map.states'))->sortBy('name')->keys();
        $byStateName = 'CASE Region '.$codes->map(fn () => 'WHEN ? THEN ?')->implode(' ').' END';

        $stations = $chosen
            ->orderByRaw($byStateName, $codes->flatMap(fn (string $code, int $position) => [$code, $position])->all())
            ->orderBy('Name')
            ->paginate($length === -1 ? max(1, (clone $chosen)->reorder()->count()) : $length)
            ->withQueryString();

        // The update log, newest first, paged as on the Crime data page: like the stations but on its own,
        // ?edits_length= and ?edits_page=, so paging one list leaves the other where it was. Its page links lead back
        // down to it.
        $editsLength = array_key_exists($request->integer('edits_length'), self::LengthMenu) ? $request->integer('edits_length') : self::PageLength;
        $edits = PoliceStationEdit::query()
            ->orderByDesc('Id')
            ->paginate($editsLength === -1 ? max(1, PoliceStationEdit::count()) : $editsLength, pageName: 'edits_page')
            ->withQueryString()
            ->fragment('edits-heading');

        return view('map::police-stations.index', [
            'stations' => $stations,
            'length' => $length,
            'lengthMenu' => self::LengthMenu,
            'filters' => $filters,
            'filtered' => $filters['state'] !== '' || $filters['search'] !== '' || $filters['missing'],
            'total' => PoliceStation::count(),
            'withoutAddress' => PoliceStation::whereNull('Address')->count(),
            'withoutPhone' => PoliceStation::whereNull('Phone')->count(),
            'edits' => $edits,
            'editsLength' => $editsLength,
        ]);
    }

    public function create(Request $request): View
    {
        // From a list narrowed to a state, the station starts in that state.
        return view('map::police-stations.create', [
            'station' => new PoliceStation(['Region' => $this->region($request->query('state'))]),
            'districts' => $this->districts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $this->validated($request);

        try {
            $this->stations->apply([['action' => 'added', 'id' => null, 'old' => null, 'new' => $input]], $request->user(), 'page');
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateName($input);
        }

        return redirect(page_url('police-stations'))->with('message', "Added {$input['Name']}.");
    }

    public function edit(PoliceStation $station): View
    {
        return view('map::police-stations.edit', ['station' => $station, 'districts' => $this->districts()]);
    }

    public function update(Request $request, PoliceStation $station): RedirectResponse
    {
        $input = $this->validated($request, $station);
        $old = $station->details();

        // As on the Crime data page, saving what's already there changes nothing, and so logs nothing.
        if ($input === $old) {
            return redirect(page_url('police-stations'))->with(['message' => "Nothing to save: no detail of {$station->Name} was changed.", 'status' => 'info']);
        }

        try {
            $this->stations->apply([['action' => 'changed', 'id' => $station->Id, 'old' => $old, 'new' => $input]], $request->user(), 'page');
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateName($input);
        }

        return redirect(page_url('police-stations'))->with('message', "Saved changes to {$input['Name']}.");
    }

    public function delete(PoliceStation $station): View
    {
        return view('map::police-stations.delete', compact('station'));
    }

    public function destroy(Request $request, PoliceStation $station): RedirectResponse
    {
        $this->stations->apply([['action' => 'deleted', 'id' => $station->Id, 'old' => $station->details(), 'new' => null]], $request->user(), 'page');

        return redirect(page_url('police-stations'))->with('message', "Deleted {$station->Name}.");
    }

    /**
     * A state or federal territory's code from the address, like MY-01, or null for anything else.
     */
    private function region(mixed $code): ?string
    {
        return is_string($code) && array_key_exists($code, config('map.states')) ? $code : null;
    }

    /**
     * The police districts to suggest, by state (region code): those with a pin on the map and those stations are
     * already listed under.
     *
     * @return Collection<string, list<string>>
     */
    private function districts(): Collection
    {
        // toBase(): as plain collections, whose unique() compares values, not models' keys, even with no districts yet.
        return PoliceDistrict::query()->get(['Region', 'Name'])->toBase()->map(fn (PoliceDistrict $district) => [$district->Region, $district->Name])
            ->concat(PoliceStation::query()->get(['Region', 'District'])->toBase()->map(fn (PoliceStation $station) => [$station->Region, $station->District]))
            ->mapToGroups(fn (array $pair) => [$pair[0] => $pair[1]])
            ->map(fn (Collection $districts) => array_values($districts->unique()->sort()->all()));
    }

    /**
     * Validate the form and map it onto the PoliceStations columns. A blank address or phone number is saved as none,
     * and the address's line breaks as \n, not the \r\n a textarea sends, as the Excel file has them.
     *
     * @return array{Region: string, District: string, Name: string, Address: ?string, Phone: ?string}
     */
    private function validated(Request $request, ?PoliceStation $station = null): array
    {
        $data = $request->validate([
            'region' => ['required', 'string', Rule::in(array_keys(config('map.states')))],
            'district' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120', Rule::unique(PoliceStation::class, 'Name')->where('Region', $request->input('region'))->ignore($station)],
            'address' => ['nullable', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+() .-]*[0-9][0-9+() .-]*$/'],
        ], [
            'region.required' => 'Choose the state or federal territory.',
            'region.in' => 'Choose one of the states or federal territories.',
            'district.required' => 'Type the police district.',
            'name.unique' => "A police station named ':input' is already listed in this state.",
            'phone.regex' => 'Use digits, spaces and + ( ) - . only, like 07-436 3300.',
        ]);

        return [
            'Region' => $data['region'],
            'District' => $data['district'],
            'Name' => $data['name'],
            'Address' => PoliceStation::lineBreaks($data['address'] ?? null),
            'Phone' => $data['phone'] ?? null,
        ];
    }

    // The unique rule catches duplicates up front; this covers two saves racing each other.
    private function duplicateName(array $input): RedirectResponse
    {
        return back()->withInput()->withErrors([
            'name' => "A police station named '{$input['Name']}' is already listed in this state.",
        ]);
    }
}
