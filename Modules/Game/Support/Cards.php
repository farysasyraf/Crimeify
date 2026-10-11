<?php

namespace Modules\Game\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Lottery;
use Modules\Map\Entities\CrimeStat;
use Modules\Map\Entities\PoliceDistrict;
use Modules\Map\Support\CrimeData;

// Higher or Lower's cards: each one a police district's count of one crime type in one year, from the Map module's
// dbo.CrimeStats, so only the years with police district figures, not those by state (StateCrime). Each round deals two
// cards of one crime type, chosen at random each round, so every guess compares like with like. A card is kept with its
// game as an array: state, district, category, type, year and crimes.
class Cards
{
    /**
     * A round's two cards: the first(), shown with its count, and the one to guess after() it, of the same crime type.
     * None when there are no cards to play with.
     *
     * @return list<array{state: string, district: string, category: string, type: string, year: int, crimes: int}>|null
     */
    public function deal(int $score): ?array
    {
        $shown = $this->first();
        $next = $shown === null ? null : $this->after($shown, $score);

        return $next === null ? null : [$shown, $next];
    }

    /**
     * Whether there are cards to play with: a crime type with enough different counts (types()). None until
     * "php artisan map:import-crime" has run.
     */
    public function ready(): bool
    {
        try {
            return $this->types() !== [];
        } catch (QueryException) {
            // dbo.CrimeStats isn't there until migrate runs.
            return false;
        }
    }

    /**
     * A round's first card, which sets its crime type: a type chosen at random from types(), each as likely as the
     * others, then any card of it but those with its lowest or highest count, after which the second card could only
     * be higher, or lower.
     *
     * @return array{state: string, district: string, category: string, type: string, year: int, crimes: int}|null
     */
    public function first(): ?array
    {
        $types = $this->types();

        if ($types === []) {
            return null;
        }

        [$category, $type] = Arr::random($types);
        $ofType = fn () => $this->pool()->where('Category', $category)->where('Type', $type);

        $row = $ofType()
            ->where('Crimes', '>', $ofType()->min('Crimes'))
            ->where('Crimes', '<', $ofType()->max('Crimes'))
            ->inRandomOrder()->first()
            // A type with only two counts has none between them.
            ?? $ofType()->inRandomOrder()->first();

        return $row ? $this->card($row) : null;
    }

    /**
     * The card to guess after one: of the same crime type; higher or lower, half the time each, so neither guess is
     * the safer one, whatever the count shown; and no more than config('game.closeness') times more or fewer for the
     * score so far. If there's none that way, the other way, and if none that close, any other count of the type.
     * None only when every card of the type has the same count.
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

        foreach ([$toward($higher), $toward(! $higher), fn (Builder $query) => $query] as $narrow) {
            $query = $this->pool()->where('Category', $shown['category'])->where('Type', $shown['type'])->where('Crimes', '!=', $crimes);
            $narrow($query);
            $row = $query->inRandomOrder()->first();

            if ($row) {
                return $this->card($row);
            }
        }

        return null;
    }

    /**
     * A card as the page shows it, in the page's language, or the one given: the crime type, the police district, the
     * region its pin is in, the year, an icon, and the count unless it's the one to guess.
     *
     * @param  array{state: string, district: string, category: string, type: string, year: int, crimes: int}  $card
     * @return array<string, int|string>
     */
    public function face(array $card, bool $withCount, ?string $locale = null): array
    {
        $district = PoliceDistrict::query()->where('State', $card['state'])->where('Name', $card['district'])->first();
        $icons = config('game.icons');

        return [
            'type' => __(CrimeData::typeLabel($card['category'], $card['type']), [], $locale),
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
     * The crime types a round can be dealt, as [category, type]: those with at least config('game.fewest_counts')
     * different counts, so the second card can be close to the first. Armed gang robbery, which few districts have 5 of
     * in a year, isn't one.
     *
     * @return list<array{string, string}>
     */
    private function types(): array
    {
        return $this->pool()
            ->select('Category', 'Type')
            ->groupBy('Category', 'Type')
            ->havingRaw('COUNT(DISTINCT Crimes) >= ?', [config('game.fewest_counts')])
            ->get()
            ->map(fn (CrimeStat $row) => [$row->Category, $row->Type])
            ->values()
            ->all();
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
