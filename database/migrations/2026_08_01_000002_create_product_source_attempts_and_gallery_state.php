<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_update_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_gallery_recipe_version_id')->nullable()
                ->constrained('product_gallery_recipe_versions')->nullOnDelete();
            $table->string('domain')->index();
            $table->text('product_url');
            $table->string('actor', 40)->index();
            $table->string('phase', 80)->index();
            $table->string('action', 120);
            $table->string('status', 40)->index();
            $table->string('decision', 80)->nullable();
            $table->unsignedSmallInteger('round')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['telegram_update_id', 'created_at']);
            $table->index(['product_draft_id', 'created_at']);
        });

        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->boolean('source_blocked')->default(false)->after('status');
            $table->text('source_block_reason')->nullable()->after('source_blocked');
            $table->timestamp('source_blocked_at')->nullable()->after('source_block_reason');
        });

        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->string('gallery_status', 30)->default('pending')->after('images_staged_at');
            $table->text('gallery_notes')->nullable()->after('gallery_status');
        });

        DB::table('product_gallery_recipes')
            ->where('status', 'disabled')
            ->where('last_failure_kind', 'access_gate')
            ->update([
                'source_blocked' => true,
                'source_block_reason' => 'Подтверждённая CAPTCHA/WAF блокирует автоматический доступ к источнику.',
                'source_blocked_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table): void {
            $table->dropColumn(['gallery_status', 'gallery_notes']);
        });

        Schema::table('product_gallery_recipes', function (Blueprint $table): void {
            $table->dropColumn(['source_blocked', 'source_block_reason', 'source_blocked_at']);
        });

        Schema::dropIfExists('product_source_attempts');
    }
};
