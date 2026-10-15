<?php

namespace Modules\Map\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Map\Support\PlaceSearch;

// Places and addresses in Malaysia by name (?q=), as JSON, for the map's search and Check an area (PlaceSearch).
class PlaceController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        // Text, not ?q[]=.
        $query = $request->query('q');

        return response()->json(PlaceSearch::search(is_string($query) ? $query : ''));
    }
}
