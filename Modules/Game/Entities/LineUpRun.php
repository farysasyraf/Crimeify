<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// One game of Line-Up, stored in dbo.LineUpRuns: who played it, its score, its rounds (Dealt) and the orders given.
// LineUp (Support) deals it.
#[Table(name: 'LineUpRuns', key: 'Id', timestamps: false)]
#[Fillable(['Token', 'PlayerName', 'PlayerKey', 'Score', 'Dealt', 'Orders', 'AskedAt', 'ScoredAt', 'StartedAt', 'EndedAt'])]
#[Hidden(['Token'])]
class LineUpRun extends Model
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
            'Dealt' => 'array',
            'Orders' => 'array',
            'AskedAt' => 'datetime',
            'ScoredAt' => 'datetime',
            'StartedAt' => 'datetime',
            'EndedAt' => 'datetime',
        ];
    }

    /**
     * The round being played or last played, from 1: the one after the last order, while a round is asked.
     */
    public function round(): int
    {
        return count($this->Orders) + ($this->AskedAt === null ? 0 : 1);
    }

    /**
     * How long the game took to reach its score, in seconds; none before its first points.
     */
    public function secondsToScore(): ?int
    {
        return $this->ScoredAt === null ? null : (int) $this->StartedAt->diffInSeconds($this->ScoredAt);
    }
}
