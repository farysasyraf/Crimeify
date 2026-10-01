<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Map\Entities\CrimeDataEdit;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\CrimeData;
use Modules\Map\Support\KeepsPendingUploads;
use Modules\Map\Support\StateCrime;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

// The Crime data page, for administrators: the crime figures the map and dashboard show, to change, add to or
// delete from, one at a time or by downloading them as an Excel file and uploading it back.
class CrimeDataController extends Controller
{
    use KeepsPendingUploads;

    /**
     * Figures listed per page: 10 at first, or as chosen from the list, as DataTables' pageLength and
     * lengthMenu: [[10, 50, 100, -1], [10, 50, 100, "All"]]. -1 is all of them.
     */
    private const PageLength = 10;

    private const LengthMenu = [10 => '10', 50 => '50', 100 => '100', -1 => 'All'];

    /**
     * Where an uploaded file's changes wait, on the "local" disk, until they're applied or cancelled.
     */
    private const Uploads = 'crime-uploads';

    public function __construct(private CrimeData $crimeData) {}

    /**
     * The figures, filtered by state, district, year and crime type, a page at a time, with the forms to add a
     * figure and upload a file, and the log of edits, a page at a time too.
     */
    public function index(Request $request): View
    {
        $filters = [
            'state' => $request->string('state')->trim()->value(),
            'district' => $request->string('district')->trim()->value(),
            'year' => $request->integer('year') ?: null,
            'type' => $request->string('type')->trim()->value(),
        ];
        [$category, $type] = str_contains($filters['type'], '.') ? explode('.', $filters['type'], 2) : [$filters['type'], null];

        $length = array_key_exists($request->integer('length'), self::LengthMenu) ? $request->integer('length') : self::PageLength;

        $chosen = CrimeData::editable()
            ->when($filters['state'] !== '', fn ($query) => $query->where('State', $filters['state']))
            ->when($filters['district'] !== '', fn ($query) => $query->where('District', $filters['district']))
            ->when($filters['year'] !== null, fn ($query) => $query->where('Year', $filters['year']))
            ->when($category !== '', fn ($query) => $query->where('Category', $category))
            ->when($type !== null, fn ($query) => $query->where('Type', $type));

        $figures = $chosen
            ->orderBy('State')->orderBy('District')->orderByDesc('Year')->orderBy('Category')->orderBy('Type')
            ->paginate($length === -1 ? max(1, (clone $chosen)->reorder()->count()) : $length)
            ->withQueryString();

        $places = CrimeData::editable()->select('State', 'District')->distinct()->orderBy('State')->orderBy('District')->get();

        // The update log, newest first, paged the same way as the figures but on its own: ?edits_length= and
        // ?edits_page=, so paging one list leaves the other where it was. Its page links lead back down to it.
        $editCount = CrimeDataEdit::count();
        $editsLength = array_key_exists($request->integer('edits_length'), self::LengthMenu) ? $request->integer('edits_length') : self::PageLength;
        $edits = CrimeDataEdit::query()
            ->orderByDesc('Id')
            ->paginate($editsLength === -1 ? max(1, $editCount) : $editsLength, pageName: 'edits_page')
            ->withQueryString()
            ->fragment('edits-heading');

        return view('map::crime-data.index', [
            'figures' => $figures,
            'length' => $length,
            'lengthMenu' => self::LengthMenu,
            'filters' => $filters,
            'filtered' => array_filter($filters) !== [],
            'total' => CrimeData::editable()->count(),
            'states' => $places->pluck('State')->unique()->values(),
            'districts' => $places->when($filters['state'] !== '', fn ($places) => $places->where('State', $filters['state']))->groupBy('State'),
            // The years with police district figures to list; a year by state only (StateCrime) has none.
            'years' => collect(StateCrime::districtYears())->reverse()->values(),
            // Add a figure starts at the year after the latest there's any figure for.
            'nextYear' => (int) (CrimeStat::query()->max('Year') ?? now()->year - 1) + 1,
            'pins' => PoliceDistrict::query()->orderBy('State')->orderBy('Name')->get(['State', 'Name'])->groupBy('State'),
            'editCount' => $editCount,
            'edits' => $edits,
            'editsLength' => $editsLength,
        ]);
    }

    /**
     * Save the counts changed in the list. Only those that differ from what the page showed are saved, and none
     * if someone else changed one of them meanwhile.
     */
    public function update(Request $request): RedirectResponse
    {
        $counts = (array) $request->input('crimes', []);
        $shown = (array) $request->input('original', []);

        // PHP takes only so many values from a form (max_input_vars) and drops the rest without a word, which a long
        // list, like All, can pass. The form says how many rows it sent, so a save that lost some saves nothing.
        $sent = $request->integer('rows', count($shown));
        if (count($shown) !== $sent || count($counts) !== $sent) {
            throw ValidationException::withMessages(['crimes' => 'Not every count reached the server: the list sent more than it takes at once. '
                .'Show fewer figures per page, or change fewer at a time, and save again.']);
        }

        $edited = collect($counts)->filter(fn ($count, $id) => array_key_exists($id, $shown) && (string) $count !== (string) $shown[$id]);

        if ($edited->isEmpty()) {
            return back()->with(['message' => 'Nothing to save: no count was changed.', 'status' => 'info']);
        }

        $figures = CrimeData::editable()->whereIn('Id', $edited->keys()->map(fn ($id) => (int) $id)->all())->get()->keyBy('Id');
        $errors = [];
        $changes = [];

        foreach ($edited as $id => $count) {
            $figure = $figures[(int) $id] ?? null;

            if ($figure === null) {
                $errors["crimes.{$id}"] = 'One of the figures was deleted by someone else. Reload the page and try again.';
            } elseif (! is_string($count) || ! ctype_digit($count) || (int) $count > 1000000) {
                $errors["crimes.{$id}"] = 'The count for '.$this->name($figure).' must be a whole number, 0 or more.';
            } elseif ((string) $figure->Crimes !== (string) $shown[$id]) {
                $errors["crimes.{$id}"] = 'The count for '.$this->name($figure).' was changed to '.number_format($figure->Crimes)
                    .' by someone else while you were editing. Check it and save again.';
            } elseif ((int) $count !== $figure->Crimes) {
                // Only a different number is a change, not the same one typed differently, like 05 for 5.
                $changes[] = ['action' => 'changed', ...CrimeData::describe($figure), 'old' => $figure->Crimes, 'new' => (int) $count];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($changes === []) {
            return back()->with(['message' => 'Nothing to save: no count was changed.', 'status' => 'info']);
        }

        $this->crimeData->apply($changes, $request->user(), 'page');

        return back()->with('message', count($changes) === 1
            ? 'Saved the new count for '.$this->name($changes[0]).', and the totals above it.'
            : 'Saved '.count($changes).' counts, and the totals above them.');
    }

    /**
     * Add a figure that isn't there yet: a district's count of a crime type in a year.
     */
    public function store(Request $request): RedirectResponse
    {
        $pins = PoliceDistrict::query()->get(['State', 'Name'])->map(fn (PoliceDistrict $pin) => "{$pin->State}|{$pin->Name}");
        $types = collect(config('map.crime.types'))->flatMap(fn (array $labels, string $category) => array_map(fn ($type) => "{$category}.{$type}", array_keys($labels)));

        $data = $request->validate([
            'district' => ['required', 'string', Rule::in($pins->all())],
            'type' => ['required', 'string', Rule::in($types->all())],
            'year' => ['required', 'integer', 'between:1900,2100'],
            'crimes' => ['required', 'integer', 'min:0', 'max:1000000'],
        ], [
            'district.in' => 'Choose a police district from the list.',
            'type.in' => 'Choose a crime type from the list.',
            'crimes.integer' => 'The number of crimes must be a whole number.',
        ], ['crimes' => 'number of crimes']);

        // A year by state only would have its states' totals added up again from this one figure.
        if (in_array((int) $data['year'], StateCrime::stateOnlyYears(), true)) {
            throw ValidationException::withMessages([
                'year' => "{$data['year']}'s figures are by state only, from the police's crime index, so police district figures can't be added for it.",
            ]);
        }

        [$state, $district] = explode('|', $data['district'], 2);
        [$category, $type] = explode('.', $data['type'], 2);
        $figure = ['state' => $state, 'district' => $district, 'category' => $category, 'type' => $type, 'year' => (int) $data['year']];

        $existing = CrimeData::editable()->where('State', $state)->where('District', $district)
            ->where('Category', $category)->where('Type', $type)->where('Year', $figure['year'])->first();
        if ($existing !== null) {
            throw ValidationException::withMessages([
                'type' => 'There\'s already a figure for '.$this->name($existing).': '.number_format($existing->Crimes).'. Change it in the list instead.',
            ]);
        }

        $this->crimeData->apply([['action' => 'added', ...$figure, 'old' => null, 'new' => (int) $data['crimes']]], $request->user(), 'page');

        return redirect(page_url('crime-data', ['state' => $state, 'district' => $district, 'year' => $figure['year']]))
            ->with('message', 'Added '.$this->name($figure).', and the totals above it.');
    }

    /**
     * Asks before deleting when JavaScript is off; with it, the page's own confirmation box does.
     */
    public function delete(CrimeStat $crimeStat): View
    {
        abort_unless(CrimeData::isEditable($crimeStat), 404);

        return view('map::crime-data.delete', ['figure' => $crimeStat, 'name' => $this->name($crimeStat)]);
    }

    public function destroy(Request $request, CrimeStat $crimeStat): RedirectResponse
    {
        abort_unless(CrimeData::isEditable($crimeStat), 404);

        $this->crimeData->apply([['action' => 'deleted', ...CrimeData::describe($crimeStat), 'old' => $crimeStat->Crimes, 'new' => null]], $request->user(), 'page');

        $back = url()->previous();

        return redirect()->to(str_contains($back, '/delete') ? page_url('crime-data') : $back)
            ->with('message', 'Deleted '.$this->name($crimeStat).', and updated the totals above it.');
    }

    /**
     * Every figure the page edits, as an Excel file to change and upload back.
     */
    public function download(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'crime').'.xlsx';
        $this->crimeData->writeExcel($path);

        return response()->download($path, 'crime-figures-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /**
     * Check an uploaded Excel file, without changing anything yet. With JavaScript, the page checks it in steps it shows
     * as they go (upload-progress.js): this reads the file and keeps its figures, then compare() compares them. Without,
     * this does both, then shows what the file would change on its review page.
     */
    public function upload(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'max:10240'],
        ], [
            'file.required' => 'Choose an Excel file to upload.',
            'file.extensions' => 'Upload an Excel workbook (.xlsx), like the one Download Excel gives.',
            'file.max' => 'The file is larger than 10 MB, much more than the figures need. Check it\'s the right file.',
        ]);

        // With new_names, a police district or crime type that's new here is taken as it is (Proceed with Review).
        $read = $this->crimeData->readExcel($request->file('file')->getRealPath(), $request->boolean('new_names'));

        if ($read['problems'] !== []) {
            $failed = ValidationException::withMessages(['file' => $read['problems']]);

            // Only names that are new here, which the dialog can offer to proceed with, as they may be meant.
            if ($request->expectsJson() && $read['newNamesOnly']) {
                return response()->json(['message' => $failed->getMessage(), 'errors' => $failed->errors(), 'newNames' => true], 422);
            }

            throw $failed;
        }

        $name = $request->file('file')->getClientOriginalName();

        if ($request->expectsJson()) {
            $upload = $this->keepUpload($request, ['file' => $name, 'figures' => $read['figures']]);

            return response()->json(['rows' => count($read['figures']), 'next' => page_url('crime-data/compare', ['upload' => $upload])]);
        }

        return redirect($this->checked($request, $name, $read['figures'])['next']);
    }

    /**
     * The next step of a check shown as it goes: compare the figures upload() kept with the saved ones.
     */
    public function compare(Request $request, string $upload): JsonResponse
    {
        $pending = $this->pendingUpload($request, $upload, 'figures');

        return response()->json($this->checked($request, $pending['file'], $pending['figures'], $upload));
    }

    /**
     * What an uploaded file would change, to apply or cancel.
     */
    public function review(Request $request, string $upload): View
    {
        $pending = $this->pendingUpload($request, $upload, 'changes');

        return view('map::crime-data.review', [
            'token' => $upload,
            'file' => $pending['file'],
            'changes' => collect($pending['changes']),
            'warnings' => $pending['warnings'],
            'figureCount' => $pending['rows'],
        ]);
    }

    /**
     * Compare a file's figures with the saved ones. Its changes wait on the server, for this user only, until they're
     * applied or cancelled, and show on its review page next; with none, the list says there's nothing to change.
     *
     * @param  array<string, array<string, mixed>>  $figures
     * @return array{changes: int, next: string} how many changes, and the page to show next
     */
    private function checked(Request $request, string $file, array $figures, ?string $upload = null): array
    {
        $compared = $this->crimeData->compare($figures);

        if ($compared['changes'] === []) {
            if ($upload !== null) {
                $this->forgetUpload($upload);
            }
            session()->flash('message', "{$file} has the same figures as the page already has, so there's nothing to change.");
            session()->flash('status', 'info');

            return ['changes' => 0, 'next' => page_url('crime-data')];
        }

        $upload = $this->keepUpload($request, [
            'file' => $file, 'rows' => count($figures), 'changes' => $compared['changes'], 'warnings' => $compared['warnings'],
        ], $upload);

        return ['changes' => count($compared['changes']), 'next' => page_url('crime-data/review', ['upload' => $upload])];
    }

    /**
     * Make an uploaded file's changes, as long as the figures haven't changed since it was checked.
     */
    public function apply(Request $request, string $upload): RedirectResponse
    {
        $pending = $this->pendingUpload($request, $upload, 'changes');

        if (! $this->crimeData->stillApplies($pending['changes'])) {
            $this->forgetUpload($upload);

            return redirect(page_url('crime-data'))->withErrors([
                'file' => "The figures changed after {$pending['file']} was checked, so nothing was changed. Upload it again to see what it would change now.",
            ]);
        }

        $this->crimeData->apply($pending['changes'], $request->user(), 'upload');
        $this->forgetUpload($upload);

        $counts = collect($pending['changes'])->countBy('action');

        return redirect(page_url('crime-data'))->with('message', "Updated the figures from {$pending['file']}: "
            .collect(['changed' => 'changed', 'added' => 'added', 'deleted' => 'deleted'])
                ->map(fn ($word, $action) => ($counts[$action] ?? 0).' '.$word)->implode(', ')
            .'. The totals above them are added up again.');
    }

    public function cancel(Request $request, string $upload): RedirectResponse
    {
        $pending = $this->pendingUpload($request, $upload);
        $this->forgetUpload($upload);

        return redirect(page_url('crime-data'))->with(['message' => "Cancelled: nothing in {$pending['file']} was applied.", 'status' => 'info']);
    }

    /**
     * A figure in words, like "Murder in Batu Pahat, Johor, 2023".
     *
     * @param  CrimeStat|array<string, mixed>  $figure
     */
    private function name(CrimeStat|array $figure): string
    {
        $figure = $figure instanceof CrimeStat ? CrimeData::describe($figure) : $figure;

        return CrimeData::typeLabel($figure['category'], $figure['type'])." in {$figure['district']}, {$figure['state']}, {$figure['year']}";
    }
}
