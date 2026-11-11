<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Map\Support\AskProblem;
use Modules\Map\Support\AskTheMap;
use Modules\Map\Support\ExplainView;

// The map's AI, for map-ask.js. "Ask the map": a request and the view shown, and the view to go to, as AskTheMap gives
// it. "What am I looking at?": the view shown, and two or three sentences about it (ExplainView). Or {"problem": "..."}
// saying why not, in words to show.
class MapAskController extends Controller
{
    public function ask(Request $request, AskTheMap $ask): JsonResponse
    {
        $asked = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:300'],
            'now' => ['array'],
        ]);

        try {
            return response()->json($ask->answer($asked['question'], $asked['now'] ?? []));
        } catch (AskProblem $problem) {
            return response()->json(['problem' => $problem->getMessage()], $problem->status);
        }
    }

    public function explain(Request $request, ExplainView $explain): JsonResponse
    {
        $view = [
            'year' => $request->query('year'),
            'region' => $request->query('region'),
            'shade' => $request->query('shade'),
            'pins' => $request->query('pins'),
            'raised' => $request->query('raised') === null ? null : $request->boolean('raised'),
        ];

        try {
            return response()->json($explain->explain($view));
        } catch (AskProblem $problem) {
            return response()->json(['problem' => $problem->getMessage()], $problem->status);
        }
    }
}
