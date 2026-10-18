<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\TelegramUpdate;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use App\Services\Telegram\TelegramDatabaseOperator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class ManageDatabase implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Create, update or delete one or many database rows atomically. First use DescribeDatabase for actual tables/columns. commands_json is a JSON array: [{"action":"create","table":"attribute_groups","ref":"ram","values":{"name":"ОЗУ"}},{"action":"update","table":"attribute_definitions","selection":{"id_from":5,"id_to":10},"values":{"attribute_group_id":{"$ref":"ram.id"}}}]. Use selection {"all":true} for ALL matching rows (e.g. delete all drafts), not a manually enumerated first page. Filters and IDs supported as in QueryDatabase. Reuse an existing matching group when present. Non-deletion batches are committed immediately and audited. ANY batch containing delete only prepares a fixed preview; actual deletion requires the operator\'s Telegram button. Never claim a prepared deletion is done. Protected identity/proof/security fields must use the dedicated workflow tools.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            app(TelegramDatabaseOperator::class)->assertAuthorized($this->update);
            $commands = json_decode((string) ($request['commands_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($commands) || ! array_is_list($commands) || $commands === [] || count($commands) > 50) {
                throw new \InvalidArgumentException('commands_json must contain 1–50 commands in a JSON array.');
            }
            $deletes = collect($commands)->contains(fn ($command): bool => is_array($command) && ($command['action'] ?? null) === 'delete');
            if ($deletes) {
                return $this->json(app(TelegramDatabaseOperator::class)->prepare($this->update, $commands));
            }
            $result = $this->recordOperation($this->update, 'ManageDatabase', 'database_changes', ['commands' => $commands], function () use ($commands): array {
                $service = app(DatabaseOperatorService::class);
                app(TelegramDatabaseOperator::class)->assertAuthorized($this->update);

                return $service->execute($service->prepare($commands, $this->update), $this->update);
            });
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception->getMessage()];
        }

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['commands_json' => $schema->string()->max(100000)->required()];
    }
}
