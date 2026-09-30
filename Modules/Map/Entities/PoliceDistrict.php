<?php

namespace Modules\Map\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

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
}
