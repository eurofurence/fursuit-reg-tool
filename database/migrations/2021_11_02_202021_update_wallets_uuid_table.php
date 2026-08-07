<?php

declare(strict_types=1);

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn($this->table(), 'uuid')) {
            return;
        }

        // upgrade from 6.x
        Schema::table($this->table(), static function (Blueprint $table) {
            $table->uuid('uuid')
                ->after('slug')
                ->nullable()
                ->unique();
        });

        // Backfilled with the query builder rather than the wallet package's model and
        // UUID factory: the package is gone, and this only ever needs a unique v4 per row.
        DB::table($this->table())->orderBy('id')->chunkById(10000, static function ($wallets) {
            foreach ($wallets as $wallet) {
                DB::table('wallets')
                    ->where('id', $wallet->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            }
        });

        Schema::table($this->table(), static function (Blueprint $table) {
            $table->uuid('uuid')
                ->change();
        });
    }

    public function down(): void
    {
        if (SchemaGuard::hasColumn($this->table(), 'uuid')) {
            Schema::dropColumns($this->table(), ['uuid']);
        }
    }

    private function table(): string
    {
        return 'wallets';
    }
};
