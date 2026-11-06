<?php

namespace App\Http\Controllers;

use App\Models\Enrolee;
use App\Services\AuditLogger;
use App\Services\PhotoService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a passport photograph.
 *
 * Photographs are never in a public folder. This route is the only way to see
 * one, and it applies the same EnroleePolicy::view check as the record itself,
 * so changing the id in the URL cannot expose another user's or another
 * program's photograph. A failed check returns 403 rather than 404, which is
 * the correct signal here: existence is not itself confidential, and the
 * audit trail records the attempt either way.
 */
class PhotoController extends Controller
{
    public function __construct(
        private readonly PhotoService $photos,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Enrolee $enrolee): Response
    {
        $this->authorize('view', $enrolee);

        if (! $enrolee->hasPhoto()) {
            abort(404, 'No photograph on file for this record.');
        }

        $contents = $this->photos->contents($enrolee->photo_path);

        if ($contents === null) {
            abort(404, 'The photograph file is missing from storage.');
        }

        // Viewing the photograph of a sensitive-program record is recorded,
        // since it is the most revealing access to that person's data.
        if ($enrolee->program?->isSensitive() || $enrolee->isSubmitted()) {
            $this->audit->log(
                'enrolee_photo_viewed',
                "Photograph viewed for {$enrolee->full_name}",
                $enrolee
            );
        }

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            // Re-encoding through GD means the file cannot be an executable or
            // an SVG carrying script, but these headers make that explicit.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}