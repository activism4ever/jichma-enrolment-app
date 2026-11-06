<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Export Retention
    |--------------------------------------------------------------------------
    |
    | Exports hold the most sensitive data this application produces: a CSV of
    | every NIN, or a ZIP of every passport photograph. They are written to the
    | private disk and never to the web root, and they are treated as
    | short-lived.
    |
    | A ready file is offered for download for this many hours, after which the
    | download link stops working. The files themselves are removed by
    | `php artisan exports:prune`, which is intended to run daily from cron; see
    | the deployment notes. Removing them from disk any earlier than this would
    | be wrong: the administrator who requested the export needs time to collect
    | it.
    |
    */

    'retain_hours' => (int) env('EXPORT_RETAIN_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Export Queue
    |--------------------------------------------------------------------------
    |
    | Exports are generated on the named queue so a slow or large extract cannot
    | delay anything else, and so a job can be given a longer time limit than a
    | web request would survive. Remember to run `queue:work --queue=exports`
    | (or the worker configured in the deployment notes) as a long-running
    | process; without it, exports stay pending forever.
    |
    */

    'queue' => env('EXPORT_QUEUE', 'exports'),

    /*
    |--------------------------------------------------------------------------
    | Photo Bundle
    |--------------------------------------------------------------------------
    |
    | A photo bundle of an entire program is large, and building it on a
    | shared host is the slowest thing this application does. Requiring a filter
    | of some kind keeps each bundle to a size that can actually be produced.
    |
    | ZipArchive cannot reliably password-protect an archive assembled entry by
    | entry across the PHP builds this app may run on, so bundles are not
    | encrypted. Their confidentiality rests on the file never being
    | web-accessible, on only the requesting administrator being able to
    | download it, and on it being pruned.
    |
    */

    'max_photo_bundle_rows' => (int) env('EXPORT_MAX_PHOTO_BUNDLE_ROWS', 5000),

];
