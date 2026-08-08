<?php

namespace App\Providers;

use App\Domain\CatchEmAll\Models\SpecialCode;
use App\Domain\Checkout\Models\Checkout\Checkout;
use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\PrintBatch;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Models\Event;
use App\Models\Fursuit\Fursuit;
use App\Models\Machine;
use App\Models\RfidTag;
use App\Models\Staff;
use App\Models\SumUpReader;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Policies\CheckoutPolicy;
use App\Policies\EventPolicy;
use App\Policies\FursuitPolicy;
use App\Policies\MachinePolicy;
use App\Policies\PrintBatchPolicy;
use App\Policies\PrinterPolicy;
use App\Policies\PrintJobPolicy;
use App\Policies\RfidTagPolicy;
use App\Policies\SpecialCodePolicy;
use App\Policies\StaffPolicy;
use App\Policies\SumUpReaderPolicy;
use App\Policies\TseClientPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        /*
         * The audit trail, read-only. Registered explicitly because the model lives in
         * the Spatie package namespace, where discovery looks for
         * Spatie\Activitylog\Policies\ActivityPolicy - a class that does not exist - so
         * without this line every ability on the log silently falls open (plan 2.10 #12).
         */
        Activity::class => ActivityPolicy::class,
        /*
         * New, and registered explicitly because auto-discovery would look for it under
         * App\Domain\Checkout\Models\Policies, a directory that does not exist. The model
         * had no policy at all, so the only thing refusing a reviewer a checkout rewrite
         * was CheckoutResource's own canCreate/canEdit/canDelete (audit 51, plan 2.10
         * #19). Those three answers now live on the model's policy, where nothing routes
         * around them.
         */
        Checkout::class => CheckoutPolicy::class,
        // Auto-discovery already finds this one; named here for the same reason as User,
        // so every policy the manage panel relies on is visible in one list (plan 2.2).
        Event::class => EventPolicy::class,
        // Also found by discovery; named for the same reason. `create()` is false and
        // stays false (audit 38), which is why no create route exists for fursuits.
        Fursuit::class => FursuitPolicy::class,
        Machine::class => MachinePolicy::class,
        Printer::class => PrinterPolicy::class,
        /*
         * New, and registered explicitly because auto-discovery would look for it under
         * App\Domain\Printing\Policies, a directory that does not exist. The model had no
         * policy at all, so pause / resume / cancel of a live print run were open to
         * every reviewer (audit 51, plan 2.10 #18).
         */
        PrintBatch::class => PrintBatchPolicy::class,
        PrintJob::class => PrintJobPolicy::class,
        // Registered explicitly because auto-discovery would look for the policy under
        // App\Domain\CatchEmAll\Policies, a directory that does not exist (plan 2.2).
        SpecialCode::class => SpecialCodePolicy::class,
        /*
         * New. The model had no policy at all and was protected only by living inside
         * the admin-only Staff edit page (audit 54, plan 2.2). Discovery would find this
         * one on its own; named here so the tags cannot silently fall open again the way
         * they did the first time.
         */
        RfidTag::class => RfidTagPolicy::class,
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
         * returned is_admin || is_reviewer and was the only panel-level gate, so nobody
         * lost access at cutover. It is now the only place the rule is expressed:
         * canAccessPanel() went with Filament.
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
