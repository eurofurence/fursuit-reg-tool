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
        Schema::table($this->table(), function (Blueprint $table) {
            if (SchemaGuard::hasIndex($this->table(), ['from_type', 'from_id'])) {
                $table->dropIndex(['from_type', 'from_id']);
            }
            if (SchemaGuard::hasIndex($this->table(), ['to_type', 'to_id'])) {
                $table->dropIndex(['to_type', 'to_id']);
            }

            if (! SchemaGuard::hasIndex($this->table(), 'from_id')) {
                $table->index('from_id');
            }
            if (! SchemaGuard::hasIndex($this->table(), 'to_id')) {
                $table->index('to_id');
            }
        });

        if (SchemaGuard::hasColumn($this->table(), 'from_type')) {
            Schema::dropColumns($this->table(), ['from_type']);
        }
        if (SchemaGuard::hasColumn($this->table(), 'to_type')) {
            Schema::dropColumns($this->table(), ['to_type']);
        }
    }

    public function down(): void
    {
        $tableName = $this->table();
        Schema::table($tableName, static function (Blueprint $table) use ($tableName) {
            if (SchemaGuard::missingColumn($tableName, 'from_type')) {
                $table->string('from_type')
                    ->after('from_id');
            }
            if (SchemaGuard::missingColumn($tableName, 'to_type')) {
                $table->string('to_type')
                    ->after('to_id');
            }
        });
    }

    private function table(): string
    {
        return (new Transfer)->getTable();
    }
};
