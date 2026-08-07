<?php

namespace App\Providers;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\SumUpReader;
use App\Models\User;
use App\Policies\MachinePolicy;
use App\Policies\PrinterPolicy;
use App\Policies\PrintJobPolicy;
use App\Policies\SpecialCodePolicy;
use App\Policies\StaffPolicy;
use App\Policies\SumUpReaderPolicy;
use App\Policies\TseClientPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Machine::class => MachinePolicy::class,
        Printer::class => PrinterPolicy::class,
        PrintJob::class => PrintJobPolicy::class,
        // Registered explicitly because auto-discovery would look for the policy under
        // App\Domain\CatchEmAll\Policies, a directory that does not exist (plan 2.2).
        SpecialCode::class => SpecialCodePolicy::class,
        Staff::class => StaffPolicy::class,
        SumUpReader::class => SumUpReaderPolicy::class,
        TseClient::class => TseClientPolicy::class,
        // Auto-discovery already found this one; named here because plan 2.2 wants every
        // policy the manage panel relies on visible in one list.
        User::class => UserPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        /*
         * The /manage panel gate. Direct successor to User::canAccessPanel(), which
         * returns is_admin || is_reviewer and is the only panel-level gate today, so
         * nobody loses access at cutover. canAccessPanel() stays as it is: Filament
         * still calls it.
         *
         * Both flags are cast bool on the model since phase 1; the explicit bool stays
         * so the gate does not depend on that.
         */
        Gate::define('access-manage', fn (User $user) => (bool) ($user->is_admin || $user->is_reviewer));

        /*
         * Admin-only inside /manage. Successor to DbService::canAccess(), reused
         * wherever admin-only is meant.
         */
        Gate::define('manage-admin', fn (User $user) => (bool) $user->is_admin);
    }
}
