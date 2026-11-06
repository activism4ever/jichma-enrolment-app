<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Base controller.
 *
 * The authorisation and validation traits are declared here because Laravel 11's
 * skeleton no longer includes them, yet every controller in this application
 * relies on $this->authorize() and the form requests rely on the validation
 * helpers from the base class.
 */
abstract class Controller
{
    use AuthorizesRequests, ValidatesRequests;
}
