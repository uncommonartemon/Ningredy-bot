<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately creates an empty table. Domain hints are operator data
        // that the Telegram "retrain with a hint" button and Filament both
        // write to, so a starting value belongs in ProductSourceDomainHintSeeder
        // (re-runnable, overridable) rather than pinned into schema history
        // where no operator can correct it without a new migration.
        Schema::create('product_source_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->text('agent_hint')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_source_domains');
    }
};
