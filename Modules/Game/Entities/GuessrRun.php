<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// One game of DistrictGuessr, stored in dbo.GuessrRuns: who played it, at which level (Difficulty: DistrictGuessr::Easy
// or Hard), its score, its districts and its guesses. DistrictGuessr (Support) plays it.
#[Table(name: 'GuessrRuns', key: 'Id', timestamps: false)]
#[Fillable(['Token', 'PlayerName', 'PlayerKey', 'Difficulty', 'Score', 'Districts', 'Guesses', 'AskedAt', 'ScoredAt', 'StartedAt', 'EndedAt'])]
#[Hidden(['Token'])]
class GuessrRun extends Model
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
            'Score' => 'integer',
            'Districts' => 'array',
            'Guesses' => 'array',
            'AskedAt' => 'datetime',
            'ScoredAt' => 'datetime',
            'StartedAt' => 'datetime',
            'EndedAt' => 'datetime',
        ];
    }

    /**
     * The round being played or last played, from 1: the one after the last guess, while a district is asked.
     */
    public function round(): int
    {
        return count($this->Guesses) + ($this->AskedAt === null ? 0 : 1);
    }

    /**
     * How long the game took to reach its score, in seconds; none before its first points.
     */
    public function secondsToScore(): ?int
    {
        return $this->ScoredAt === null ? null : (int) $this->StartedAt->diffInSeconds($this->ScoredAt);
    }
}
