<?php

namespace Modules\Map\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// One region's population in one year, in dbo.Populations, from the Department of Statistics Malaysia: the people
// living there at the middle of the year. The region is its ISO 3166-2 code, like MY-01 for Johor.
#[Table(name: 'Populations', key: 'Id', timestamps: false)]
#[Fillable(['Region', 'Year', 'People'])]
class Population extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // SQL Server's driver returns numbers as strings.
        return [
            'Year' => 'integer',
            'People' => 'integer',
        ];
    }
}
