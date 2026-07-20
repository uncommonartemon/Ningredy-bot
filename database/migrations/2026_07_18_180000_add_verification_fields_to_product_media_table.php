<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->string('verification_status')->default('manual')->after('checksum')->index();
            $table->decimal('verification_score', 5, 4)->nullable()->after('verification_status');
            $table->string('verification_model')->nullable()->after('verification_score');
            $table->text('verification_notes')->nullable()->after('verification_model');
            $table->timestamp('verified_at')->nullable()->after('verification_notes');
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->dropIndex(['verification_status']);
            $table->dropColumn([
                'verification_status', 'verification_score', 'verification_model', 'verification_notes', 'verified_at',
            ]);
        });
    }
};
