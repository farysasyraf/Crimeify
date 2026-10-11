<?php

namespace Modules\Game\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Lottery;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\CrimeData;

// Higher or Lower's cards: each one a police district's count of one crime type in one year, from the Map module's
// dbo.CrimeStats, so only the years with police district figures, not those by state (StateCrime). A card is kept with
// its game as an array: state, district, category, type, year and crimes.
class Cards
{
    /**
     * Whether there are cards to play with: at least two different counts. None until "php artisan map:import-crime"
     * has run.
     */
    public function ready(): bool
    {
        try {
            $crimes = $this->pool()->value('Crimes');

            return $crimes !== null && $this->pool()->where('Crimes', '!=', $crimes)->exists();
        } catch (QueryException) {
            // dbo.CrimeStats isn't there until migrate runs.
            return false;
        }
    }

    /**
     * A game's first card: any of them.
     *
     * @return array{state: string, district: string, category: string, type: string, year: int, crimes: int}|null
     */
    public function first(): ?array
    {
        $row = $this->pool()->inRandomOrder()->first();

        return $row ? $this->card($row) : null;
    }

    /**
     * The card to guess after one: higher or lower, half the time each, so neither guess is the safer one, whatever
     * the count shown; no more than config('game.closeness') times more or fewer for the score so far; and some of the
     * time of the same crime type. If there's none that way, the other way, and if none that close, any other count.
     * None only when every card has the same count.
     *
     * @param  array{state: string, district: string, category: string, type: string, year: int, crimes: int}  $shown
     * @return array{state: string, district: string, category: string, type: string, year: int, crimes: int}|null
     */
    public function after(array $shown, int $score): ?array
    {
        $crimes = $shown['crimes'];
        $times = $this->closeness($score);
        $higher = Lottery::odds(1, 2)->choose();
        $toward = fn (bool $up) => fn (Builder $query) => $up
            ? $query->whereBetween('Crimes', [$crimes + 1, max($crimes + 1, (int) floor($crimes * $times))])
            : $query->whereBetween('Crimes', [(int) ceil($crimes / $times), $crimes - 1]);

        $tries = [$toward($higher), $toward(! $higher), fn (Builder $query) => $query];

        if (Lottery::odds(config('game.same_type'))->choose()) {
            array_unshift($tries, fn (Builder $query) => $toward($higher)($query)->where('Category', $shown['category'])->where('Type', $shown['type']));
        }

        foreach ($tries as $narrow) {
            $query = $this->pool()->where('Crimes', '!=', $crimes);
            $narrow($query);
            $row = $query->inRandomOrder()->first();

            if ($row) {
                return $this->card($row);
            }
        }

        return null;
    }

    /**
     * A card as the page shows it, in the page's language: the crime type, the police district, the region its pin
     * is in, the year, an icon, and the count unless it's the one to guess.
     *
     * @param  array{state: string, district: string, category: string, type: string, year: int, crimes: int}  $card
     * @return array<string, int|string>
     */
    public function face(array $card, bool $withCount): array
    {
        $district = PoliceDistrict::query()->where('State', $card['state'])->where('Name', $card['district'])->first();
        $icons = config('game.icons');

        return [
            'type' => __(CrimeData::typeLabel($card['category'], $card['type'])),
            'district' => $card['district'],
            'place' => $district?->regionName() ?? $card['state'],
            'year' => $card['year'],
            'icon' => $icons[$card['type']] ?? $icons[$card['category']] ?? 'local_police',
        ] + ($withCount ? ['crimes' => $card['crimes']] : []);
    }

    /**
     * Every police district's count of each crime type the map lists, but those config('game.left_out'), in each
     * year, from config('game.least') up. Not the states' and Malaysia's totals (district "All"), nor the categories'
     * ("all").
     *
     * @return Builder<CrimeStat>
     */
    private function pool(): Builder
    {
        return CrimeStat::query()
            ->where('State', '!=', 'Malaysia')
            ->where('District', '!=', 'All')
            ->where('Crimes', '>=', config('game.least'))
            ->where(function (Builder $query) {
                foreach (config('map.crime.types') as $category => $types) {
                    $query->orWhere(fn (Builder $inCategory) => $inCategory->where('Category', $category)
                        ->whereIn('Type', array_values(array_diff(array_keys($types), config('game.left_out')))));
                }
            });
    }

    /**
     * How many times more or fewer the next count can be, for the score so far.
     */
    private function closeness(int $score): float
    {
        $times = 2.0;

        foreach (config('game.closeness') as $from => $closeness) {
            if ($score >= $from) {
                $times = (float) $closeness;
            }
        }

        return $times;
    }

    /**
     * @return array{state: string, district: string, category: string, type: string, year: int, crimes: int}
     */
    private function card(CrimeStat $row): array
    {
        return [
            'state' => $row->State,
            'district' => $row->District,
            'category' => $row->Category,
            'type' => $row->Type,
            'year' => $row->Year,
            'crimes' => $row->Crimes,
        ];
    }
}
