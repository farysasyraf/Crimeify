<?php

namespace Modules\Game\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\View\View;
use Modules\Game\Support\Cards;
use Modules\Game\Support\Library;

// The Games page (/public/game), for everyone without logging in: the games as a games console's home screen shows
// them, a row of tiles along the top and the chosen one's title, buttons and cards over its own backdrop. The games are
// listed in Library; Higher or Lower itself is at /public/game/higher-or-lower (GameController).
class LibraryController extends Controller
{
    public function __construct(private Cards $cards) {}

    public function index(): View
    {
        return view('game::library', [
            'games' => Library::games(),
            'sample' => $this->sample(),
        ]);
    }

    /**
     * A round of Higher or Lower to show beside its title: its two cards, the one to guess without its count. None
     * without figures to play with.
     *
     * @return list<array<string, int|string>>|null
     */
    private function sample(): ?array
    {
        try {
            $round = $this->cards->deal(0);
        } catch (QueryException) {
            // dbo.CrimeStats isn't there until migrate runs.
            return null;
        }

        return $round === null ? null : [
            $this->cards->face($round[0], withCount: true),
            $this->cards->face($round[1], withCount: false),
        ];
    }
}
