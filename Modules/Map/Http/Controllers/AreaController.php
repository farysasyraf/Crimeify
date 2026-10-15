<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetPublicLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\AreaProfile;
use Modules\Map\Support\DistrictPreview;

// "Check an area", on the public pages, for someone deciding where to live or stay: a police district's profile
// (AreaProfile), and up to three side by side. Facts and trends from the police's figures, not a safety score.
class AreaController extends Controller
{
    /**
     * At most this many areas side by side.
     */
    public const MaxCompared = 3;

    /**
     * The areas to choose from, up to three at once, and every police district by state to go to.
     */
    public function index(Request $request): View
    {
        // One already chosen, as a profile's "Compare with other areas" gives it.
        $with = $request->query('with');

        return view('map::areas.index', [
            'regions' => $this->choices(),
            'chosen' => is_string($with) && $this->find($with) ? [$with] : [],
            'language' => $this->language(),
            'pins' => $this->pins(),
            'turf' => versioned_asset('vendor/turf/turf.min.js'),
        ]);
    }

    /**
     * A police district's profile (/public/areas/johor/batu-pahat), with the link preview its map link has.
     */
    public function show(string $region, string $district): View
    {
        $found = PoliceDistrict::findByPath($region, $district) ?? abort(404);
        $preview = DistrictPreview::of($found);
        $path = $found->path() + $this->language();

        return view('map::areas.show', [
            'profile' => AreaProfile::of($found),
            'language' => $this->language(),
            'path' => implode('/', $found->path()),
            'mapUrl' => route('public.map.district', $path),
            'compareUrl' => route('public.areas', ['with' => implode('/', $found->path())] + $this->language()),
            'share' => [
                'title' => $preview->title(),
                'description' => $preview->description(),
                'url' => route('public.areas.show', $path),
                'image' => route('public.map.district.preview', $path + ['v' => $preview->version()]),
                'alt' => $preview->alt(),
            ],
        ]);
    }

    /**
     * Up to three police districts side by side (?areas[]=johor/batu-pahat&areas[]=selangor/kajang). With only one
     * found, its profile; with none, the page to choose them.
     */
    public function compare(Request $request): View|RedirectResponse
    {
        $districts = collect((array) $request->query('areas', []))
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->map(fn (string $path) => $this->find($path))
            ->filter()
            ->unique('Id')
            ->take(self::MaxCompared)
            ->values();

        if ($districts->count() < 2) {
            return $districts->isEmpty()
                ? to_route('public.areas', $this->language())
                : to_route('public.areas.show', $districts[0]->path() + $this->language());
        }

        $profiles = $districts->map(fn (PoliceDistrict $district) => AreaProfile::of($district));
        // The years all of them have, so each line starts from the same year.
        $years = array_values(array_intersect(...$profiles->map(fn (AreaProfile $profile) => $profile->years())->all()));

        return view('map::areas.compare', [
            'profiles' => $profiles,
            'years' => $years,
            'regions' => $this->choices(),
            'chosen' => $districts->map(fn (PoliceDistrict $district) => implode('/', $district->path()))->all(),
            'language' => $this->language(),
            'profileUrls' => $districts->map(fn (PoliceDistrict $district) => route('public.areas.show', $district->path() + $this->language()))->all(),
        ]);
    }

    /**
     * Every police district by region, each region by name, for the lists and the links: its name and path.
     *
     * @return array<string, list<array{name: string, path: string}>>
     */
    private function choices(): array
    {
        return PoliceDistrict::query()->orderBy('Name')->get()
            ->groupBy(fn (PoliceDistrict $district) => $district->regionName())
            ->sortKeys()
            ->map(fn ($districts) => $districts->map(fn (PoliceDistrict $district) => ['name' => $district->Name, 'path' => implode('/', $district->path())])->values()->all())
            ->all();
    }

    /**
     * Each police district's pin, for "Near you" (nearby.js): its name and state, where it is, its path and its profile.
     *
     * @return list<array{name: string, region: string, lat: float, lng: float, path: string, profile: string}>
     */
    private function pins(): array
    {
        return PoliceDistrict::query()->orderBy('Name')->get()
            ->map(fn (PoliceDistrict $district) => [
                'name' => $district->Name,
                'region' => $district->regionName(),
                'lat' => $district->Latitude,
                'lng' => $district->Longitude,
                'path' => implode('/', $district->path()),
                'profile' => route('public.areas.show', $district->path() + $this->language()),
            ])
            ->all();
    }

    /**
     * The police district at a path like johor/batu-pahat, if there is one.
     */
    private function find(string $path): ?PoliceDistrict
    {
        [$region, $district] = array_pad(explode('/', $path, 2), 2, '');

        return PoliceDistrict::findByPath($region, $district);
    }

    /**
     * The page's language for the addresses it gives, so a link keeps it. English, the default, needs none.
     *
     * @return array<string, string>
     */
    private function language(): array
    {
        return app()->getLocale() === SetPublicLocale::defaultLocale() ? [] : ['lang' => app()->getLocale()];
    }
}
