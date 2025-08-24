<?php

namespace App\Providers;

use App\Domain\Checkout\Models\TseClient;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\SumUpReader;
use App\Policies\MachinePolicy;
use App\Policies\PrinterPolicy;
use App\Policies\PrintJobPolicy;
use App\Policies\StaffPolicy;
use App\Policies\SumUpReaderPolicy;
use App\Policies\TseClientPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

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
        Staff::class => StaffPolicy::class,
        SumUpReader::class => SumUpReaderPolicy::class,
        TseClient::class => TseClientPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
