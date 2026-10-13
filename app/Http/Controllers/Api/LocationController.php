<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLocationsRequest;
use App\Tracking\IngestionService;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    /**
     * Receives a batch of buffered fixes from a rep's phone.
     *
     * 201 means every fix that is not listed in `rejected` is durably stored (or was already
     * stored by an earlier attempt), so the device may delete the whole batch from its buffer.
     */
    public function store(StoreLocationsRequest $request, IngestionService $ingestion): JsonResponse
    {
        $result = $ingestion->ingest($request->attributes->get('rep'), $request->validated('points'));

        return response()->json($result->toApi(), 201);
    }
}
