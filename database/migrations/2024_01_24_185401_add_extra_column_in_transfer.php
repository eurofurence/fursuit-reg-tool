<?php

declare(strict_types=1);

use App\Support\Migrations\SchemaGuard;
use Bavix\Wallet\Models\Transfer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = $this->table();
        Schema::table($tableName, static function (Blueprint $table) use ($tableName) {
            if (SchemaGuard::missingColumn($tableName, 'extra')) {
                $table->json('extra')
                    ->nullable()
                    ->after('fee');
            }
        });
    }

    public function down(): void
    {
        if (SchemaGuard::hasColumn($this->table(), 'extra')) {
            Schema::dropColumns($this->table(), ['extra']);
        }
    }

    private function table(): string
    {
        return (new Transfer)->getTable();
    }
};
