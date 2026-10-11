<?php

namespace Modules\Game\Entities;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
     * The name a player typed, for a solo or a live game, tidied (tidyName()) and checked: 2 to config('game.name_length')
     * letters, numbers and spaces, and a few marks names have, starting with a letter or number. A problem is a
     * validation error, in the page's language.
     */
    public static function validName(Request $request): string
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => self::tidyName($request->input('name'))]);
        }

        $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:'.config('game.name_length'), "regex:/^[\\pL\\pM\\pN][\\pL\\pM\\pN .'’@\\/-]*$/u"],
        ], [
            'name.required' => __('Type your name to play.'),
            'name.min' => __('Type your name to play.'),
            'name.max' => __('Your name can be at most :max characters.', ['max' => config('game.name_length')]),
            // Like "Muthu a/l Rajan" or "Nur Aisyah bt. Ahmad".
            'name.regex' => __('Your name can have letters, numbers and spaces, and . \' @ / or -.'),
        ]);

        return $request->input('name');
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
