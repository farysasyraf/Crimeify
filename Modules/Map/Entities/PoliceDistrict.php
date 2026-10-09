<?php

namespace Modules\Map\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// A police district's pin on the Map page, stored in dbo.PoliceDistricts: its name as the crime data spells it,
// the state the data files it under, the region the pin is in, and where the pin goes.
#[Table(name: 'PoliceDistricts', key: 'Id', timestamps: false)]
#[Fillable(['State', 'Name', 'Region', 'Latitude', 'Longitude'])]
class PoliceDistrict extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'Latitude' => 'float',
            'Longitude' => 'float',
        ];
    }

    /**
     * The name of the region the pin is in, like Labuan for Labuan's district, which the data files under Sabah.
     */
    public function regionName(): string
    {
        return config("map.states.{$this->Region}.name") ?? $this->Region;
    }

    /**
     * Where the district is in an address, as its region's name and its own, like johor/batu-pahat. By name, not Id,
     * so a shared link keeps working after "php artisan map:import-crime" loads the pins again.
     *
     * @return array{region: string, district: string}
     */
    public function path(): array
    {
        return ['region' => Str::slug($this->regionName()), 'district' => Str::slug($this->Name)];
    }

    /**
     * The district at a path() in an address, if there is one.
     */
    public static function findByPath(string $region, string $district): ?self
    {
        $code = collect(config('map.states'))->search(fn (array $state) => Str::slug($state['name']) === $region);

        return $code === false ? null : self::query()->where('Region', $code)->get()
            ->first(fn (self $found) => Str::slug($found->Name) === $district);
    }
}
