<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_page_evidence', function (Blueprint $table): void {
            // Where this text actually came from, not just the URL it was
            // requested for - a redirect or a click can land somewhere else,
            // and a later attempt needs to know that happened, not just what
            // text resulted.
            $table->text('final_url')->nullable()->after('url');
            // The one selector that successfully revealed this text, when it
            // came from an in-page open rather than the page as first
            // loaded - so a later attempt does not have to rediscover which
            // control to click.
            $table->string('open_selector', 300)->nullable()->after('captured_via');
            // The click's own outcome (clicked/changed/navigated away and
            // why/selector missing) - kept even when it did not produce
            // usable text, so a later attempt can see what was already
            // tried and why it did not help, not just that something was.
            $table->json('click_outcome')->nullable()->after('open_selector');
        });
    }

    public function down(): void
    {
        Schema::table('product_source_page_evidence', function (Blueprint $table): void {
            $table->dropColumn(['final_url', 'open_selector', 'click_outcome']);
        });
    }
};
