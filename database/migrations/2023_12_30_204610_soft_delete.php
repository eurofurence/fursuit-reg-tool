<?php

declare(strict_types=1);

use App\Support\Migrations\SchemaGuard;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Transfer;
use Bavix\Wallet\Models\Wallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $walletTable = (new Wallet)->getTable();
        Schema::table($walletTable, static function (Blueprint $table) use ($walletTable) {
            if (SchemaGuard::missingColumn($walletTable, 'deleted_at')) {
                $table->softDeletesTz();
            }
        });
        $transferTable = (new Transfer)->getTable();
        Schema::table($transferTable, static function (Blueprint $table) use ($transferTable) {
            if (SchemaGuard::missingColumn($transferTable, 'deleted_at')) {
                $table->softDeletesTz();
            }
        });
        $transactionTable = (new Transaction)->getTable();
        Schema::table($transactionTable, static function (Blueprint $table) use ($transactionTable) {
            if (SchemaGuard::missingColumn($transactionTable, 'deleted_at')) {
                $table->softDeletesTz();
            }
        });
    }

    public function down(): void
    {
        $walletTable = (new Wallet)->getTable();
        Schema::table($walletTable, static function (Blueprint $table) use ($walletTable) {
            if (SchemaGuard::hasColumn($walletTable, 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
        $transferTable = (new Transfer)->getTable();
        Schema::table($transferTable, static function (Blueprint $table) use ($transferTable) {
            if (SchemaGuard::hasColumn($transferTable, 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
        $transactionTable = (new Transaction)->getTable();
        Schema::table($transactionTable, static function (Blueprint $table) use ($transactionTable) {
            if (SchemaGuard::hasColumn($transactionTable, 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
