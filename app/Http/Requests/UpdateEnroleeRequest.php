<?php

namespace App\Http\Requests;

/**
 * Updating an existing draft follows the same permissive rules as creating
 * one, but the NIN uniqueness check must ignore the record's own current value.
 *
 * Split from StoreEnroleeRequest so the intent is explicit at the route, and so
 * an administrator editing a *submitted* record can be given full validation
 * via a different request if needed later.
 */
class UpdateEnroleeRequest extends StoreEnroleeRequest
{
    public function authorize(): bool
    {
        $enrolee = $this->route('enrolee');

        return $enrolee && $this->user()->can('update', $enrolee);
    }
}