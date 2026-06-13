<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ensure the user identified by this remote_id is an admin:
     *  - If the user already exists, only elevate the permission (their existing
     *    name/email are left untouched).
     *  - If the user does not exist, create them with the given remote_id, name
     *    and email, and the admin permission.
     *
     * The remote_id is the link to the Eurofurence identity provider: the login
     * flow resolves users via User::updateOrCreate() on remote_id, so when this
     * person signs in their real name/email/avatar are filled in while the admin
     * flag is preserved. No extra rows are required here - the wallet is created
     * lazily on first access. Re-running is a harmless no-op. No down() needed.
     */
    public function up(): void
    {
        $remoteId = 'ZGD30K38PZ54LXMY';

        $user = User::where('remote_id', $remoteId)->first();

        if ($user) {
            $user->is_admin = true;
            $user->save();

            return;
        }

        User::create([
            'remote_id' => $remoteId,
            'name' => 'Rusty',
            'email' => 'rusty@invalid.me',
            'is_admin' => true,
        ]);
    }
};
