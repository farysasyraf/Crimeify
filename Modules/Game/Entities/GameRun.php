<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// One game of Higher or Lower, stored in dbo.GameRuns: who played it, its score, and the two cards in play.
#[Table(name: 'GameRuns', key: 'Id', timestamps: false)]
#[Fillable(['Token', 'PlayerName', 'PlayerKey', 'Score', 'Current', 'Next', 'AskedAt', 'ScoredAt', 'StartedAt', 'EndedAt'])]
#[Hidden(['Token'])]
class GameRun extends Model
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
            'Current' => 'array',
            'Next' => 'array',
            'AskedAt' => 'datetime',
            'ScoredAt' => 'datetime',
            'StartedAt' => 'datetime',
            'EndedAt' => 'datetime',
        ];
    }

    /**
     * A name as it's kept: trimmed, with any run of spaces as one.
     */
    public static function tidyName(string $name): string
    {
        return Str::squish($name);
    }

    /**
     * The key a player's games count together by: their name in lowercase, so "Siti" and "siti " are one player.
     */
    public static function keyFor(string $name): string
    {
        return Str::lower(self::tidyName($name));
    }

    /**
     * How long the game took to reach its score, in seconds; none before the first right answer.
     */
    public function secondsToScore(): ?int
    {
        return $this->ScoredAt === null ? null : (int) $this->StartedAt->diffInSeconds($this->ScoredAt);
    }
}
