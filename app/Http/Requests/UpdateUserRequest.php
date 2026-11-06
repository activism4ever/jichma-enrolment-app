<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validate editing an account.
 *
 * The password is not part of this form. Resetting someone's password is a
 * separate, separately-authorised action that also forces a change, so it
 * cannot be smuggled in through an ordinary profile update.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target && $this->user()->can('update', $target);
    }

    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($target->id),
            ],
            'role' => ['required', Rule::enum(Role::class)],
            // An Admin belongs to no program; the other two belong to
            // exactly one. Rule::prohibitedIf passes when the field is empty
            // or absent, so a hidden-but-submitted empty select is fine while
            // an actual program on an admin account is rejected.
            'program_id' => [
                'nullable', 'integer',
                // A newly chosen program must be active; the account's existing
                // program is accepted even if it has since been deactivated, so
                // an unrelated edit does not force a reassignment.
                Rule::exists('programs', 'id')->where(function ($query) use ($target) {
                    $query->where('is_active', true)
                        ->orWhere('id', $target->program_id);
                }),
                Rule::requiredIf(fn () => $this->input('role') !== Role::Admin->value),
                Rule::prohibitedIf(fn () => $this->input('role') === Role::Admin->value),
            ],
            // An unchecked checkbox submits nothing, so the form pairs it with
            // a hidden `is_active=0`. Absent therefore cannot be reached, and
            // the value that arrives is always the intended one.
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * Refuse changes that would take the last usable administrator out of
     * service: demoting or deactivating the only active admin.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            /** @var User $target */
            $target = $this->route('user');

            if (! $target->isAdmin() || ! $target->is_active) {
                return;
            }

            $demoted = $this->input('role') !== Role::Admin->value;
            $deactivated = ! $this->boolean('is_active');

            if (($demoted || $deactivated) && $this->lastActiveAdminId() === $target->id) {
                $validator->errors()->add(
                    'role',
                    'This is the only active administrator. Create another admin before changing this account.'
                );
            }
        });
    }

    private function lastActiveAdminId(): ?int
    {
        return User::query()
            ->where('role', Role::Admin->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');
    }

    public function messages(): array
    {
        return [
            'program_id.required' => 'Choose the program this account belongs to.',
            'program_id.prohibited' => 'An administrator does not belong to a program.',
            'program_id.exists' => 'That programme is not active. Choose another.',
            'email.unique' => 'Another account already uses this email address.',
        ];
    }

    public function attributes(): array
    {
        return ['program_id' => 'program'];
    }
}
