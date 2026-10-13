<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationsRequest;
use App\Tracking\IngestionService;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * A rep's phone uploads a batch of points.
     *
     * A 201 answer means every point not listed in `rejected` is safely stored, so the phone
     * may remove the batch from its local buffer.
     */
    public function store(StoreLocationsRequest $request, IngestionService $ingestion): JsonResponse
    {
        $rep = $request->attributes->get('rep');

        return response()->json($ingestion->store($rep, $request->validated('points')), 201);
    }
}
