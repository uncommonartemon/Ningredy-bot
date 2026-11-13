<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The standard of a category (decision of 2026-11-13): its form of characteristics with an
 * example of how each is written, how important it is and in which section it stands; the
 * parts the product title is built from; a free note; and whether only the form is written.
 * The draft keeps what the operator accepted or skipped in the form report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attribute_definition_category', function (Blueprint $table) {
            $table->string('example', 255)->nullable();
            $table->string('section', 16)->default('main');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->json('title_parts')->nullable();
            $table->text('card_standard')->nullable();
            $table->boolean('form_only')->default(false);
        });
        Schema::table('product_drafts', function (Blueprint $table) {
            $table->json('form_review')->nullable();
        });
        // What a category requires is always in its form.
        DB::table('attribute_definition_category')->where('is_required', true)->update(['is_default' => true]);
    }

    public function down(): void
    {
        Schema::table('product_drafts', function (Blueprint $table) {
            $table->dropColumn('form_review');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['title_parts', 'card_standard', 'form_only']);
        });
        Schema::table('attribute_definition_category', function (Blueprint $table) {
            $table->dropColumn(['example', 'section']);
        });
    }
};
