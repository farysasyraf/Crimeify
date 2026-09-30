<?php

namespace Modules\Map\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// A crime figure changed, added or deleted on the Crime data page, kept in dbo.CrimeDataEdits until the next
// import from data.gov.my replaces the figures.
#[Table(name: 'CrimeDataEdits', key: 'Id', timestamps: false)]
#[Fillable(['UserId', 'UserName', 'Action', 'Source', 'State', 'District', 'Category', 'Type', 'Year', 'OldCrimes', 'NewCrimes', 'CreatedAt'])]
class CrimeDataEdit extends Model
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
            'UserId' => 'integer',
            'Year' => 'integer',
            'OldCrimes' => 'integer',
            'NewCrimes' => 'integer',
            'CreatedAt' => 'datetime',
        ];
    }
}
