<?php

namespace Modules\Map\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// A police station the Map page lists under the map, stored in dbo.PoliceStations: the state or federal territory
// (Region, like MY-01) and police district it's listed under, its name, and its address and phone number if known.
#[Table(name: 'PoliceStations', key: 'Id', timestamps: false)]
#[Fillable(['Region', 'District', 'Name', 'Address', 'Phone'])]
class PoliceStation extends Model
{
    /**
     * The name of the state or federal territory it's listed under, like Johor.
     */
    public function regionName(): string
    {
        return config("map.states.{$this->Region}.name") ?? $this->Region;
    }

    /**
     * The phone number as a tel: address, which phones can call: in the international form, +60 for a Malaysian
     * number written from 0, like 07-436 3300 → tel:+6074363300. Null without a number.
     */
    public function phoneLink(): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $this->Phone);

        return match (true) {
            $digits === '' => null,
            str_starts_with(trim((string) $this->Phone), '+'), str_starts_with($digits, '60') => 'tel:+'.$digits,
            str_starts_with($digits, '0') => 'tel:+60'.substr($digits, 1),
            default => 'tel:'.$digits,
        };
    }

    /**
     * What to search a map app for: the station's name and address, so the search lands on the station itself rather
     * than only its street; without an address, its name and state.
     */
    public function mapQuery(): string
    {
        return implode(', ', array_filter([$this->Name, filled($this->Address) ? $this->Address : $this->regionName()]));
    }

    /**
     * The station on Google Maps: its website on a computer, its app on a phone that has it.
     */
    public function googleMapsUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->mapQuery());
    }

    /**
     * The station in Waze: its app on a phone that has it, its website otherwise.
     */
    public function wazeUrl(): string
    {
        return 'https://waze.com/ul?q='.rawurlencode($this->mapQuery());
    }

    /**
     * Match stations whose name, police district or address contains the term.
     * %, _ and [ typed by the user are treated as literal characters in LIKE.
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        if (blank($term)) {
            return;
        }

        $pattern = '%'.addcslashes(trim($term), '\\%_[').'%';

        $query->where(fn (Builder $query) => $query
            ->whereRaw("Name LIKE ? ESCAPE '\\'", [$pattern])
            ->orWhereRaw("District LIKE ? ESCAPE '\\'", [$pattern])
            ->orWhereRaw("Address LIKE ? ESCAPE '\\'", [$pattern]));
    }
}
