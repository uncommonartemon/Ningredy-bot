<?php

namespace App\Ai\Tools;

use App\Models\TelegramUpdate;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Telegram\TelegramDatabaseOperator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class QueryDatabase implements Tool
{
    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Find and count table records by IDs, inclusive ID range, all records, or column filters. selection_json example: {"id_from":5,"id_to":10}, {"all":true}, or {"filters":[{"column":"name","operator":"contains","value":"RAM"}]}. Query rows and their actual IDs yourself; do not ask the operator for IDs when they gave a usable name, SKU, range or all. Results are paginated with limit/offset; never interpret the first page as the entire table.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            app(TelegramDatabaseOperator::class)->assertAuthorized($this->update);
            if (filled($request['value_column'] ?? null)) {
                return json_encode(app(DatabaseOperatorService::class)->readValue((string) $request['table'],
                    (string) ($request['record_id'] ?? ''), (string) $request['value_column'],
                    $request->integer('value_offset', 0), $request->integer('value_limit', 6000), $this->update),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $selection = json_decode((string) ($request['selection_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($selection)) {
                throw new \InvalidArgumentException('selection_json must be a JSON object.');
            }
            $data = app(DatabaseOperatorService::class)->query((string) $request['table'], $selection,
                (array) ($request['columns'] ?? []), $request->integer('limit', 50), $request->integer('offset', 0), $this->update);
        } catch (Throwable $exception) {
            $data = ['ok' => false, 'error' => $exception->getMessage()];
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'table' => $schema->string()->required(),
            'selection_json' => $schema->string()->max(20000),
            'columns' => $schema->array()->items($schema->string()),
            'limit' => $schema->integer(), 'offset' => $schema->integer(),
            'record_id' => $schema->string()->description('For reading a long cell value; also supports composite IDs.'),
            'value_column' => $schema->string()->description('Read this complete cell in text chunks instead of querying rows. JSON cells are serialized to JSON.'),
            'value_offset' => $schema->integer(), 'value_limit' => $schema->integer(),
        ];
    }
}
