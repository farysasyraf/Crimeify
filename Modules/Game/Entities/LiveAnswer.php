<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// A player's answer in one round of a live game, stored in dbo.LiveAnswers, with its points.
#[Table(name: 'LiveAnswers', key: 'Id', timestamps: false)]
#[Fillable(['LivePlayerId', 'LiveGameId', 'Round', 'Guess', 'Correct', 'Points', 'Milliseconds', 'AnsweredAt'])]
class LiveAnswer extends Model
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
            'LivePlayerId' => 'integer',
            'LiveGameId' => 'integer',
            'Round' => 'integer',
            'Correct' => 'boolean',
            'Points' => 'integer',
            'Milliseconds' => 'integer',
            'AnsweredAt' => 'datetime',
        ];
    }
}
