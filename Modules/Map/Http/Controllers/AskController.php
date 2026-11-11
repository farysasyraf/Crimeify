<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Map\Support\AskCrimeify;
use Modules\Map\Support\AskProblem;

// Ask Crimeify's answer to a question from the command palette (palette.js): {"answer": "..."} in Markdown links and
// lists, or {"problem": "..."} saying why there isn't one, in words to show.
class AskController extends Controller
{
    public function __invoke(Request $request, AskCrimeify $ask): JsonResponse
    {
        $question = $request->validate(['question' => ['required', 'string', 'min:3', 'max:300']])['question'];

        try {
            return response()->json($ask->answer($question));
        } catch (AskProblem $problem) {
            return response()->json(['problem' => $problem->getMessage()], $problem->status);
        }
    }
}
