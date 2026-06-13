<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Critical indexes for BadgeManagementController performance
        Schema::table('badges', function (Blueprint $table) {
            $this->addIndex($table, 'badges', 'status_fulfillment', 'idx_badges_status_fulfillment');
            $this->addIndex($table, 'badges', 'custom_id', 'idx_badges_custom_id');
            $this->addIndex($table, 'badges', ['fursuit_id', 'status_payment'], 'idx_badges_fursuit_payment');
            $this->addIndex($table, 'badges', 'created_at', 'idx_badges_created_at');
            $this->addIndex($table, 'badges', 'printed_at', 'idx_badges_printed_at');
            $this->addIndex($table, 'badges', 'paid_at', 'idx_badges_paid_at');
        });

        // Critical index for CheckoutController active checkout lookups
        Schema::table('checkouts', function (Blueprint $table) {
            $this->addIndex($table, 'checkouts', ['machine_id', 'status'], 'idx_checkouts_machine_status');
            $this->addIndex($table, 'checkouts', 'user_id', 'idx_checkouts_user_id');
        });

        // Index for wallet transactions performance
        Schema::table('transactions', function (Blueprint $table) {
            $this->addIndex($table, 'transactions', ['payable_type', 'payable_id', 'amount'], 'idx_transactions_payable_amount');
        });

        // Index for print jobs performance
        Schema::table('print_jobs', function (Blueprint $table) {
            $this->addIndex($table, 'print_jobs', 'status', 'idx_print_jobs_status');
            $this->addIndex($table, 'print_jobs', ['printable_type', 'printable_id'], 'idx_print_jobs_printable');
            $this->addIndex($table, 'print_jobs', 'created_at', 'idx_print_jobs_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $this->dropIndex($table, 'badges', 'idx_badges_status_fulfillment');
            $this->dropIndex($table, 'badges', 'idx_badges_custom_id');
            $this->dropIndex($table, 'badges', 'idx_badges_fursuit_payment');
            $this->dropIndex($table, 'badges', 'idx_badges_created_at');
            $this->dropIndex($table, 'badges', 'idx_badges_printed_at');
            $this->dropIndex($table, 'badges', 'idx_badges_paid_at');
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $this->dropIndex($table, 'checkouts', 'idx_checkouts_machine_status');
            $this->dropIndex($table, 'checkouts', 'idx_checkouts_user_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $this->dropIndex($table, 'transactions', 'idx_transactions_payable_amount');
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $this->dropIndex($table, 'print_jobs', 'idx_print_jobs_status');
            $this->dropIndex($table, 'print_jobs', 'idx_print_jobs_printable');
            $this->dropIndex($table, 'print_jobs', 'idx_print_jobs_created_at');
        });
    }

    private function addIndex(Blueprint $table, string $tableName, string|array $columns, string $name): void
    {
        if (! SchemaGuard::hasIndex($tableName, $name)) {
            $table->index($columns, $name);
        }
    }

    private function dropIndex(Blueprint $table, string $tableName, string $name): void
    {
        if (SchemaGuard::hasIndex($tableName, $name)) {
            $table->dropIndex($name);
        }
    }
};
