<?php

namespace App\Ai\Tools;

use App\Models\TelegramUpdate;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Telegram\TelegramDatabaseOperator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DescribeDatabase implements Tool
{
    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Discover database tables, columns, types, relationships and allowed operations before managing catalog records. Includes attributes, groups, brands, categories, products, variants, drafts and operational tables; protected fields explain their restrictions. No SQL required.';
    }

    public function handle(Request $request): Stringable|string
    {
        app(TelegramDatabaseOperator::class)->assertAuthorized($this->update);

        $description = app(DatabaseOperatorService::class)->describe();
        $table = trim((string) ($request['table'] ?? ''));
        if ($table !== '') {
            $description['tables'] = isset($description['tables'][$table])
                ? [$table => $description['tables'][$table]] : [];
        } else {
            foreach ($description['tables'] as &$entry) {
                unset($entry['column_details']);
            }
            unset($entry);
        }

        return json_encode($description, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['table' => $schema->string()->description('Optional exact table name for full column metadata; omit for the table overview.')];
    }
}
