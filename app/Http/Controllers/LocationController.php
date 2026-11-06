<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small JSON endpoints backing the cascading LGA / ward / facility dropdowns.
 *
 * They return identifiers and names only, and each is constrained by its parent
 * so the browser cannot ask for a ward outside the chosen LGA. The same
 * relationships are re-checked server side when the form is submitted: this
 * endpoint is a convenience, never the enforcement point.
 */
class LocationController extends Controller
{
    /** @var int */
    private const MAX_RESULTS = 500;

    public function lgas(): JsonResponse
    {
        return response()->json(
            Lga::query()
                ->orderBy('name')
                ->limit(self::MAX_RESULTS)
                ->get(['id', 'name'])
        );
    }

    public function wards(Request $request, Lga $lga): JsonResponse
    {
        return response()->json(
            Ward::query()
                ->where('lga_id', $lga->id)
                ->orderBy('name')
                ->limit(self::MAX_RESULTS)
                ->get(['id', 'name'])
        );
    }

    public function facilities(Request $request, Ward $ward): JsonResponse
    {
        return response()->json(
            Facility::query()
                ->where('ward_id', $ward->id)
                ->orderBy('name')
                ->limit(self::MAX_RESULTS)
                ->get(['id', 'name'])
        );
    }
}