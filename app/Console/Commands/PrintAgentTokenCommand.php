<?php

namespace App\Console\Commands;

use App\Models\Machine;
use Illuminate\Console\Command;

/**
 * Issue the bearer token a print agent authenticates with.
 *
 * The agent is a desktop application on the convention LAN, so it cannot hold a
 * browser session the way the POS does. It carries a Sanctum token instead,
 * pasted into its Setup screen once when the station is built.
 */
class PrintAgentTokenCommand extends Command
{
    protected $signature = 'print-agent:token
        {machine? : Machine id or name. Omitted, you pick from a list}
        {--revoke : Revoke this machine\'s existing agent tokens instead of issuing one}
        {--list : Show which machines have a token and when their agent last called in}';

    protected $description = 'Issue, list or revoke print agent API tokens';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listMachines();
        }

        $machine = $this->resolveMachine();

        if (! $machine) {
            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $count = $machine->tokens()->where('name', 'print-agent')->delete();
            $this->info("Revoked {$count} agent token(s) for {$machine->name}.");

            return self::SUCCESS;
        }

        // One token per machine. Re-issuing replaces the old one so a leaked or
        // forgotten token on a decommissioned station stops working.
        $machine->tokens()->where('name', 'print-agent')->delete();

        $token = $machine->createToken('print-agent')->plainTextToken;

        $this->newLine();
        $this->info("Print agent token for {$machine->name} (machine #{$machine->id}):");
        $this->newLine();
        $this->line("  {$token}");
        $this->newLine();
        $this->warn('This is shown once. Paste it into the agent Setup screen now.');
        $this->line('Any previous token for this machine has been revoked.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function resolveMachine(): ?Machine
    {
        $argument = $this->argument('machine');

        if ($argument) {
            $machine = Machine::query()
                ->when(is_numeric($argument), fn ($q) => $q->where('id', $argument))
                ->when(! is_numeric($argument), fn ($q) => $q->where('name', $argument))
                ->first();

            if (! $machine) {
                $this->error("No machine matches '{$argument}'. Run with --list to see them.");
            }

            return $machine;
        }

        $machines = Machine::query()->whereNull('archived_at')->orderBy('name')->get();

        if ($machines->isEmpty()) {
            $this->error('No machines exist yet. Create one in the admin panel first.');

            return null;
        }

        $choice = $this->choice(
            'Which machine is this agent running on?',
            $machines->pluck('name')->all()
        );

        return $machines->firstWhere('name', $choice);
    }

    private function listMachines(): int
    {
        $machines = Machine::query()->orderBy('name')->get();

        if ($machines->isEmpty()) {
            $this->info('No machines.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Machine', 'Has token', 'Agent last seen', 'Version'],
            $machines->map(fn (Machine $machine) => [
                $machine->id,
                $machine->name,
                $machine->tokens()->where('name', 'print-agent')->exists() ? 'yes' : 'no',
                $machine->agent_last_seen_at?->diffForHumans() ?? 'never',
                $machine->agent_version ?? '-',
            ])->all()
        );

        return self::SUCCESS;
    }
}
