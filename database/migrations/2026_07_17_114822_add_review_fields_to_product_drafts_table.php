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
        Schema::table('product_drafts', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_telegram_user_id')->nullable()->after('reviewed_by_user_id');
            $table->text('rejection_reason')->nullable()->after('reviewed_by_telegram_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['reviewed_at', 'reviewed_by_telegram_user_id', 'rejection_reason']);
        });
    }
};
