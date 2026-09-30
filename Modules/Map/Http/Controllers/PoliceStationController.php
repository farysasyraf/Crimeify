<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Entities\PoliceStation;

// The Police stations page (Dev Module), where administrators keep the stations the Map page lists under the map up
// to date: each one's name, the state and police district it's listed under, and its address and phone number.
// Its routes are on the Routes page, for ADMIN to start with.
class PoliceStationController extends Controller
{
    /**
     * Stations listed per page, as on the Crime data page: 10 at first, or as chosen from the list, as DataTables'
     * pageLength and lengthMenu: [[10, 50, 100, -1], [10, 50, 100, "All"]]. -1 is all of them.
     */
    private const PageLength = 10;

    private const LengthMenu = [10 => '10', 50 => '50', 100 => '100', -1 => 'All'];

    /**
     * Every station, by state and then name, a page at a time, narrowed by state, a search, or to those without an
     * address or phone number yet, which are the ones to fill in.
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

        return view('map::police-stations.index', [
            'stations' => $stations,
            'length' => $length,
            'lengthMenu' => self::LengthMenu,
            'filters' => $filters,
            'filtered' => $filters['state'] !== '' || $filters['search'] !== '' || $filters['missing'],
            'total' => PoliceStation::count(),
            'withoutAddress' => PoliceStation::whereNull('Address')->count(),
            'withoutPhone' => PoliceStation::whereNull('Phone')->count(),
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
            $station = PoliceStation::create($input);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateName($input);
        }

        return redirect(page_url('police-stations'))->with('message', "Added {$station->Name}.");
    }

    public function edit(PoliceStation $station): View
    {
        return view('map::police-stations.edit', ['station' => $station, 'districts' => $this->districts()]);
    }

    public function update(Request $request, PoliceStation $station): RedirectResponse
    {
        $input = $this->validated($request, $station);

        try {
            $station->update($input);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateName($input);
        }

        return redirect(page_url('police-stations'))->with('message', "Saved changes to {$station->Name}.");
    }

    public function delete(PoliceStation $station): View
    {
        return view('map::police-stations.delete', compact('station'));
    }

    public function destroy(PoliceStation $station): RedirectResponse
    {
        $station->delete();

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
        return PoliceDistrict::query()->get(['Region', 'Name'])->map(fn (PoliceDistrict $district) => [$district->Region, $district->Name])
            ->concat(PoliceStation::query()->get(['Region', 'District'])->map(fn (PoliceStation $station) => [$station->Region, $station->District]))
            ->groupBy(0)
            ->map(fn (Collection $pairs) => $pairs->pluck(1)->unique()->sort()->values()->all());
    }

    /**
     * Validate the form and map it onto the PoliceStations columns. A blank address or phone number is saved as none.
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
            'Address' => $data['address'] ?? null,
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
