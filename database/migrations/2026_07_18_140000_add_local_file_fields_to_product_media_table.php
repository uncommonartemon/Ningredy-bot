<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->string('disk')->nullable()->after('type');
            $table->string('path')->nullable()->after('disk');
            $table->string('role')->nullable()->after('path')->index();
            $table->string('mime_type')->nullable()->after('source_url');
            $table->unsignedInteger('width')->nullable()->after('mime_type');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedBigInteger('file_size')->nullable()->after('height');
            $table->string('checksum', 64)->nullable()->after('file_size')->index();
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['checksum']);
            $table->dropColumn(['disk', 'path', 'role', 'mime_type', 'width', 'height', 'file_size', 'checksum']);
        });
    }
};
