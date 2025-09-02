<?php

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
            // Index for status_fulfillment queries (tab filtering and counts)
            $table->index('status_fulfillment', 'idx_badges_status_fulfillment');
            
            // Index for custom_id ordering in badge lists
            $table->index('custom_id', 'idx_badges_custom_id');
            
            // Composite index for checkout queries (unpaid badges by user)
            $table->index(['fursuit_id', 'status_payment'], 'idx_badges_fursuit_payment');
            
            // Index for date-based queries (today's badges, etc.)
            $table->index('created_at', 'idx_badges_created_at');
            $table->index('printed_at', 'idx_badges_printed_at');
            $table->index('paid_at', 'idx_badges_paid_at');
        });

        // Critical index for CheckoutController active checkout lookups
        Schema::table('checkouts', function (Blueprint $table) {
            // Composite index for finding active checkouts by machine
            $table->index(['machine_id', 'status'], 'idx_checkouts_machine_status');
            
            // Index for user's checkouts
            $table->index('user_id', 'idx_checkouts_user_id');
        });

        // Index for wallet transactions performance
        Schema::table('transactions', function (Blueprint $table) {
            // Composite index for wallet transaction queries
            $table->index(['payable_type', 'payable_id', 'amount'], 'idx_transactions_payable_amount');
        });

        // Index for print jobs performance
        Schema::table('print_jobs', function (Blueprint $table) {
            // Index for status queries (pending jobs, etc.)
            $table->index('status', 'idx_print_jobs_status');
            
            // Composite index for printable lookups
            $table->index(['printable_type', 'printable_id'], 'idx_print_jobs_printable');
            
            // Index for date-based queries
            $table->index('created_at', 'idx_print_jobs_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropIndex('idx_badges_status_fulfillment');
            $table->dropIndex('idx_badges_custom_id');
            $table->dropIndex('idx_badges_fursuit_payment');
            $table->dropIndex('idx_badges_created_at');
            $table->dropIndex('idx_badges_printed_at');
            $table->dropIndex('idx_badges_paid_at');
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->dropIndex('idx_checkouts_machine_status');
            $table->dropIndex('idx_checkouts_user_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_transactions_payable_amount');
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropIndex('idx_print_jobs_status');
            $table->dropIndex('idx_print_jobs_printable');
            $table->dropIndex('idx_print_jobs_created_at');
        });
    }
};
