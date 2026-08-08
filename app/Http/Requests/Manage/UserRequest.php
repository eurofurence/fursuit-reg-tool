<?php

namespace App\Http\Requests\Manage;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Create and update for /admin/settings/users.
 *
 * The rules are UserResource's form schema (audit 4.13) with one field gone and two rules
 * added.
 *
 * Gone: `valid_registration`. Filament declared a Toggle for a column that
 * 2025_08_03_195303_remove_old_columns_from_users_table dropped from `users`, so every
 * save throws SQL 1054 (plan 2.10 change 4). It is not in the rules, so it cannot reach
 * the model even if a crafted request carries it.
 *
 * Added: uniqueness on `remote_id` and `email`. Both columns are UNIQUE in
 * 0001_01_01_000000_create_users_table and the Filament form validated neither, so a
 * duplicate would come back as SQL 1062 rather than a field error. Nobody ever saw that,
 * because the form could not get past the missing column in the first place.
 */
class UserRequest extends FormRequest
{
    /**
     * Authorized here rather than in the controller so an operator who may not write
     * never gets validation feedback on a form they cannot submit.
     */
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User
            ? Gate::allows('update', $user)
            : Gate::allows('create', User::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $user = $this->route('user');
        $id = $user instanceof User ? $user->getKey() : null;

        return [
            'remote_id' => ['required', 'string', 'max:255', Rule::unique('users', 'remote_id')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'avatar' => ['nullable', 'string'],
            // Filament's Toggle::make()->required(): present and boolean. `required`
            // accepts false, which is the point of a required toggle.
            'is_reviewer' => ['required', 'boolean'],
            'is_admin' => ['required', 'boolean'],
        ];
    }

    /**
     * Filament's auto labels, so a validation message names the field the way the form
     * does.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'remote_id' => 'remote id',
            'is_reviewer' => 'is reviewer',
            'is_admin' => 'is admin',
        ];
    }
}
