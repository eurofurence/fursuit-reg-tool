<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->after('is_active', function (Blueprint $table) {
                $table->string('status')->default('idle');
                $table->unsignedBigInteger('current_job_id')->nullable();
                $table->text('last_error_message')->nullable();
                $table->timestamp('last_state_update')->useCurrent();
                $table->string('handling_machine_name')->nullable();
                
                $table->index(['status', 'last_state_update']);
                $table->foreign('current_job_id')->references('id')->on('print_jobs')->onDelete('set null');
            });
        });
        
        // Migrate data from printer_states table
        if (Schema::hasTable('printer_states')) {
            DB::statement('
                UPDATE printers p
                INNER JOIN printer_states ps ON p.name = ps.name
                SET 
                    p.status = ps.status,
                    p.current_job_id = ps.current_job_id,
                    p.last_error_message = ps.last_error_message,
                    p.last_state_update = ps.last_update,
                    p.handling_machine_name = ps.machine_name
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropForeign(['current_job_id']);
            $table->dropIndex(['status', 'last_state_update']);
            $table->dropColumn([
                'status',
                'current_job_id', 
                'last_error_message',
                'last_state_update',
                'handling_machine_name'
            ]);
        });
    }
};
