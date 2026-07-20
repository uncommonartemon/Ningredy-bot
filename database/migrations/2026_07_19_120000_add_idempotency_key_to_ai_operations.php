<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_operations', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->unique()->after('target_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_operations', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
