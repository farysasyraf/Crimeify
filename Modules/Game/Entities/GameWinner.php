<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

// A player chosen as the winner on the Game scores page, stored in dbo.GameWinners, with the dates of the games they
// were chosen from.
#[Table(name: 'GameWinners', key: 'Id', timestamps: false)]
#[Fillable(['GameRunId', 'PlayerName', 'Score', 'FromDate', 'ToDate', 'ChosenById', 'ChosenByName', 'ChosenAt'])]
class GameWinner extends Model
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
            'GameRunId' => 'integer',
            'Score' => 'integer',
            'FromDate' => 'date',
            'ToDate' => 'date',
            'ChosenById' => 'integer',
            'ChosenAt' => 'datetime',
        ];
    }

    /**
     * The dates the winner was chosen from, in words, like "1 Oct 2026 to 31 Oct 2026", "From 1 Oct 2026" or
     * "All games".
     */
    public function period(): string
    {
        return self::describePeriod($this->FromDate?->format('Y-m-d'), $this->ToDate?->format('Y-m-d'));
    }

    /**
     * Dates as Y-m-d, either of them none, in words.
     */
    public static function describePeriod(?string $from, ?string $to): string
    {
        $day = fn (string $date) => date('j M Y', strtotime($date));

        return match (true) {
            $from !== null && $to !== null => $from === $to ? $day($from) : "{$day($from)} to {$day($to)}",
            $from !== null => "From {$day($from)}",
            $to !== null => "Up to {$day($to)}",
            default => 'All games',
        };
    }
}
