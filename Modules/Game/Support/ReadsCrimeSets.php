<?php

namespace Modules\Game\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;

// What Skyline and Line-Up deal their rounds from, read from the Map module's dbo.PoliceDistricts and dbo.CrimeStats
// once and kept for a few minutes (config('game.deck')), as Higher or Lower's cards are: each state's districts with
// where they are, and every state, crime type and year with enough districts counted.
trait ReadsCrimeSets
{
    /**
     * What's been read this request, by its key.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $crimeSets = [];

    /**
     * Each state's districts with pins, as [latitude, longitude, region], and every state, crime type and year with
     * at least $districts districts counted from config('game.least') up and not all the same, with each district's
     * count (none there, 0). Crime types as the map lists them, but those config('game.left_out'). Kept for
     * config('game.deck.keep') seconds, under the game's key for the settings that decide it.
     *
     * @return array{pins: array<string, array<string, array{0: float, 1: float, 2: string}>>, sets: list<array{state: string, category: string, type: string, year: int, counts: array<string, int>}>}
     */
    private function crimeSets(string $game, int $districts): array
    {
        $key = "game.{$game}.".md5(json_encode([config('game.least'), config('game.left_out'), $districts, config('map.crime.types')]));

        return $this->crimeSets[$key] ??= $this->deckStore()->remember($key, config('game.deck.keep'), function () use ($districts) {
            $pins = [];
            foreach (PoliceDistrict::query()->orderBy('Name')->get(['State', 'Name', 'Region', 'Latitude', 'Longitude']) as $district) {
                $pins[$district->State][$district->Name] = [(float) $district->Latitude, (float) $district->Longitude, $district->Region];
            }

            $counts = [];
            $rows = CrimeStat::query()
                ->where('State', '!=', 'Malaysia')
                ->where('District', '!=', 'All')
                ->where(function ($query) {
                    foreach (config('map.crime.types') as $category => $types) {
                        $query->orWhere(fn ($inCategory) => $inCategory->where('Category', $category)
                            ->whereIn('Type', array_values(array_diff(array_keys($types), config('game.left_out')))));
                    }
                })
                ->get(['State', 'District', 'Category', 'Type', 'Year', 'Crimes']);
            foreach ($rows as $row) {
                if (isset($pins[$row->State][$row->District])) {
                    $counts["{$row->State}|{$row->Category}|{$row->Type}|{$row->Year}"][$row->District] = (int) $row->Crimes;
                }
            }

            $sets = [];
            foreach ($counts as $set => $byDistrict) {
                $counted = array_filter($byDistrict, fn (int $crimes) => $crimes >= config('game.least'));
                if (count($counted) >= $districts && count(array_unique($counted)) > 1) {
                    [$state, $category, $type, $year] = explode('|', $set);
                    $sets[] = ['state' => $state, 'category' => $category, 'type' => $type, 'year' => (int) $year, 'counts' => $byDistrict];
                }
            }

            return ['pins' => $pins, 'sets' => $sets];
        });
    }

    private function deckStore(): Repository
    {
        return Cache::store(config('game.deck.store'));
    }
}
