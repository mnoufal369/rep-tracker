<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the envelope is validated here. Individual fixes are validated by the ingestion
 * service so one bad fix is reported back instead of blocking the whole batch (a device
 * would otherwise retry that batch forever and never drain its buffer).
 */
class StoreLocationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'points' => ['required', 'array', 'min:1', 'max:'.config('tracking.max_batch_size')],
        ];
    }
}
