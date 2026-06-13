<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBatchUuidColumnToActivityLogTable extends Migration
{
    public function up()
    {
        $connection = Schema::connection(config('activitylog.database_connection'));

        if (! $connection->hasColumn(config('activitylog.table_name'), 'batch_uuid')) {
            $connection->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->uuid('batch_uuid')->nullable()->after('properties');
            });
        }
    }

    public function down()
    {
        $connection = Schema::connection(config('activitylog.database_connection'));

        if ($connection->hasColumn(config('activitylog.table_name'), 'batch_uuid')) {
            $connection->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->dropColumn('batch_uuid');
            });
        }
    }
}
