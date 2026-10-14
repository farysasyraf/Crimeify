<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A live game, of Higher or Lower or DistrictGuessr (Game, its key on the Games page), stored in dbo.LiveGames: its
// code, who hosts it, its rounds (Cards: two cards a round, or a district), and where it's up to. LiveGames (Support)
// plays it.
#[Table(name: 'LiveGames', key: 'Id', timestamps: false)]
#[Fillable(['Game', 'Code', 'HostId', 'HostName', 'Rounds', 'Seconds', 'Cards', 'Status', 'Round', 'OpensAt', 'ClosesAt', 'CreatedAt', 'FinishedAt'])]
class LiveGame extends Model
{
    public const Lobby = 'lobby';

    public const Playing = 'playing';

    public const Finished = 'finished';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // SQL Server's driver returns numbers as strings.
        return [
            'HostId' => 'integer',
            'Rounds' => 'integer',
            'Seconds' => 'integer',
            'Cards' => 'array',
            'Round' => 'integer',
            'OpensAt' => 'integer',
            'ClosesAt' => 'integer',
            'CreatedAt' => 'datetime',
            'FinishedAt' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LivePlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(LivePlayer::class, 'LiveGameId');
    }

    /**
     * @return HasMany<LiveAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(LiveAnswer::class, 'LiveGameId');
    }

    /**
     * The code as the host's screen shows it, in two halves, like 482 913.
     */
    public function spacedCode(): string
    {
        return substr($this->Code, 0, 3).' '.substr($this->Code, 3);
    }
}
