<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEventColumnToActivityLogTable extends Migration
{
    public function up()
    {
        $connection = Schema::connection(config('activitylog.database_connection'));

        if (! $connection->hasColumn(config('activitylog.table_name'), 'event')) {
            $connection->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->string('event')->nullable()->after('subject_type');
            });
        }
    }

    public function down()
    {
        $connection = Schema::connection(config('activitylog.database_connection'));

        if ($connection->hasColumn(config('activitylog.table_name'), 'event')) {
            $connection->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->dropColumn('event');
            });
        }
    }
}
