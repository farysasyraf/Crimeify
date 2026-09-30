<?php

namespace Modules\Map\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// One year's count of one crime type in one police district, stored in dbo.CrimeStats as data.gov.my publishes it.
// District "All" is a state's total, state "Malaysia" the country's, and type "all" a category's total.
#[Table(name: 'CrimeStats', key: 'Id', timestamps: false)]
#[Fillable(['State', 'District', 'Category', 'Type', 'Year', 'Crimes'])]
class CrimeStat extends Model
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
            'Crimes' => 'integer',
        ];
    }
}
