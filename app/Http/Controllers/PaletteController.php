<?php

namespace App\Http\Controllers;

use App\Support\Palette;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The command palette's results (public/js/palette.js): the pages, users, police districts and stations whose name
// matches what was typed (?q=), under their headings, from App\Support\Palette's sources.
class PaletteController extends Controller
{
    public function __invoke(Request $request, Palette $palette): JsonResponse
    {
        // Text, not ?q[]=.
        $term = $request->query('q');

        return response()->json([
            'groups' => $palette->search(is_string($term) ? mb_substr($term, 0, 100) : '', $request->user()),
        ]);
    }
}
