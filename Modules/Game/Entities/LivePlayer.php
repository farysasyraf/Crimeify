<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// A player in a live game, stored in dbo.LivePlayers: the name they joined with and their points so far.
#[Table(name: 'LivePlayers', key: 'Id', timestamps: false)]
#[Fillable(['LiveGameId', 'Token', 'PlayerName', 'PlayerKey', 'Score', 'JoinedAt'])]
#[Hidden(['Token'])]
class LivePlayer extends Model
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
            'LiveGameId' => 'integer',
            'Score' => 'integer',
            'JoinedAt' => 'datetime',
        ];
    }
}
