<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_source_domains', function (Blueprint $table): void {
            $table->text('auto_agent_hint')->nullable()->after('agent_hint');
        });

        DB::table('product_source_domains')
            ->select(['id', 'agent_hint'])
            ->whereNotNull('agent_hint')
            ->orderBy('id')
            ->each(function (object $domain): void {
                $lines = array_values(array_filter(
                    preg_split('/\R/', (string) $domain->agent_hint) ?: [],
                    fn (string $line): bool => trim($line) !== '',
                ));
                $manual = array_values(array_filter(
                    $lines,
                    fn (string $line): bool => ! str_starts_with(trim($line), '[auto '),
                ));
                $automatic = array_values(array_filter(
                    $lines,
                    fn (string $line): bool => str_starts_with(trim($line), '[auto '),
                ));

                if ($automatic !== []) {
                    DB::table('product_source_domains')->where('id', $domain->id)->update([
                        'agent_hint' => $manual === [] ? null : implode("\n", $manual),
                        'auto_agent_hint' => implode("\n", $automatic),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('product_source_domains')
            ->select(['id', 'agent_hint', 'auto_agent_hint'])
            ->whereNotNull('auto_agent_hint')
            ->orderBy('id')
            ->each(function (object $domain): void {
                $lines = array_filter([(string) $domain->agent_hint, (string) $domain->auto_agent_hint]);
                DB::table('product_source_domains')->where('id', $domain->id)->update([
                    'agent_hint' => $lines === [] ? null : implode("\n", $lines),
                ]);
            });

        Schema::table('product_source_domains', function (Blueprint $table): void {
            $table->dropColumn('auto_agent_hint');
        });
    }
};
