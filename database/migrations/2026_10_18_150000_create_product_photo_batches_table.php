<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_photo_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_draft_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('generation')->nullable();
            $table->text('source_url');
            $table->json('source_context');
            $table->string('target_fingerprint', 64);
            $table->string('policy_fingerprint', 64);
            $table->string('owner_gallery_signature', 64);
            $table->string('status')->default('pending');
            $table->text('interruption_reason')->nullable();
            $table->boolean('gallery_traversal_complete')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();
            $table->index(['product_draft_id', 'generation', 'status'], 'photo_batch_resume');
        });
        Schema::create('product_photo_frames', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_photo_batch_id')->constrained()->cascadeOnDelete();
            $table->text('source_url');
            $table->string('disk');
            $table->string('path');
            $table->string('checksum', 64);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('position');
            $table->string('status')->default('pending');
            $table->json('candidate_context');
            $table->json('review')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['product_photo_batch_id', 'position'], 'photo_frame_position');
            $table->index(['product_photo_batch_id', 'status'], 'photo_frame_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_photo_frames');
        Schema::dropIfExists('product_photo_batches');
    }
};
