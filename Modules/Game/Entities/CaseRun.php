<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// One game of Case File, stored in dbo.CaseRuns: who played it, its score, its cases (Dealt), the clues open in the
// case being played (Opened), and its picks. CaseFile (Support) deals it.
#[Table(name: 'CaseRuns', key: 'Id', timestamps: false)]
#[Fillable(['Token', 'PlayerName', 'PlayerKey', 'Score', 'Dealt', 'Opened', 'Picks', 'AskedAt', 'ScoredAt', 'StartedAt', 'EndedAt'])]
#[Hidden(['Token'])]
class CaseRun extends Model
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
            'Opened' => 'array',
            'Picks' => 'array',
            'AskedAt' => 'datetime',
            'ScoredAt' => 'datetime',
            'StartedAt' => 'datetime',
            'EndedAt' => 'datetime',
        ];
    }

    /**
     * The case being played or last played, from 1: the one after the last pick, while a case is asked.
     */
    public function round(): int
    {
        return count($this->Picks) + ($this->AskedAt === null ? 0 : 1);
    }

    /**
     * How long the game took to reach its score, in seconds; none before its first points.
     */
    public function secondsToScore(): ?int
    {
        return $this->ScoredAt === null ? null : (int) $this->StartedAt->diffInSeconds($this->ScoredAt);
    }
}
