<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups of customers with prices of their own (operator, 2026-11-11): a customer signs up
 * through the invitation link of a group and sees that group's prices; a product without a
 * price for the group shows its ordinary one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('invite_code', 40)->unique();
            $table->boolean('is_active')->default(true);
            $table->boolean('registration_open')->default(true);
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 40)->nullable();
            $table->string('company')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('customer_group_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->timestamps();
            $table->unique(['customer_group_id', 'product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_prices');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('customer_groups');
    }
};
