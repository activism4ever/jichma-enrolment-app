<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validate issuing a new temporary password for someone else's account.
 *
 * Separate from UpdateUserRequest so that password issuance carries its own
 * policy check and its own audit entry, rather than arriving as an unremarkable
 * field on a profile form.
 */
class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target && $this->user()->can('resetPassword', $target);
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
