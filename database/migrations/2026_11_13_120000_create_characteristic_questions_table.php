<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the characteristic audit was not sure of and asked the operator (decision of 2026-11-13):
 * merge two characteristics or put one into a group. An answer is kept, so a pair the operator
 * kept apart is not asked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characteristic_questions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->json('payload');
            $table->string('status', 16)->default('open')->index();
            $table->string('chat_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('answered_by')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characteristic_questions');
    }
};
