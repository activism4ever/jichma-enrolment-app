<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validate creating an account.
 *
 * Two invariants are enforced here rather than by the database, which cannot
 * express them:
 *
 *   - Admin has no program; Program User and Reviewer have exactly one.
 *   - A new account is issued a temporary password and must change it, so the
 *     administrator never chooses a password the user will keep.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Case-insensitive because the column collation is, so
                // Alice@x and alice@x are the same person.
                Rule::unique('users', 'email'),
            ],
            'role' => ['required', Rule::enum(Role::class)],
            'program_id' => [
                'nullable', 'integer',
                // Only active programmes may receive a new account; a
                // deactivated programme keeps the accounts it already has.
                Rule::exists('programs', 'id')->where('is_active', true),
                // Required for the roles that belong to a program, and must
                // be absent for Admin.
                Rule::requiredIf(fn () => $this->input('role') !== Role::Admin->value),
                Rule::prohibitedIf(fn () => $this->input('role') === Role::Admin->value),
            ],
            // The administrator issues a temporary password. The account is
            // flagged must_change_password, so it cannot be used beyond the
            // password screen until the holder picks their own.
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function messages(): array
    {
        return [
            'program_id.required' => 'Choose the program this account belongs to.',
            'program_id.prohibited' => 'An administrator does not belong to a program.',
            'program_id.exists' => 'That programme is not active. Choose another.',
            'email.unique' => 'An account with this email address already exists.',
        ];
    }

    public function attributes(): array
    {
        return [
            'program_id' => 'program',
        ];
    }
}
