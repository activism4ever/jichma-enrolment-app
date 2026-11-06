<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An export was refused for a reason the administrator can act on.
 *
 * The messages are written to be read on the exports screen — "narrow it by
 * program or LGA", "no enrolees matched" — so the job stores them on the row
 * exactly as written. Anything else that goes wrong is a fault: the row then
 * gets a fixed, safe sentence, and the technical detail is left in the
 * application log where support can find it.
 */
final class ExportRefused extends RuntimeException {}
