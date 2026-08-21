<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain')->unique();
            $table->text('agent_hint')->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('product_source_domains')->insert([
            'domain' => 'bhphotovideo.com',
            'agent_hint' => 'После открытия основного media viewer/lightbox проверь вложенный уровень увеличения: нажми на главное изображение или zoom-контрол внутри уже открытого viewer. B&H часто только после этого запрашивает более крупную rendition. Подтверди улучшение по DOM и сетевым image URL; не считай страничные миниатюры финальным разрешением, если доступен вложенный zoom.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_source_domains');
    }
};
