<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\AttributeDefinition;
use App\Models\TelegramUpdate;
use App\Services\Catalog\AttributeDictionary;
use App\Services\Catalog\AttributeKeeper;
use App\Services\Telegram\TelegramDatabaseOperator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * The characteristic dictionary from the chat (decision of 2026-11-13): merge two characteristics
 * that mean one property, and have the dictionary's model propose merges and groups.
 */
class ManageCharacteristicDictionary implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'The characteristic dictionary keeps one characteristic per meaning. action=merge with from_key and into_key moves every '
            .'value of from_key into into_key (a value the product already has under into_key stays), keeps from_key and its names as '
            .'other names of into_key so the bot never creates it again, and removes from_key; a built-in key always stays, so the '
            .'merge may go the other way (the result says swapped). Merge when the operator says two characteristics are one. '
            .'action=review asks the dictionary\'s model for duplicates and for groups of characteristics without one; it changes '
            .'nothing: show the proposals to the operator in Russian. action=apply applies the proposals the operator confirmed: '
            .'proposals_json {"merges":[{"from":"…","into":"…"}],"groups":[{"key":"…","group":{"id":1,"name":"Memory","name_cs":null}}]} '
            .'copied from the review. Other names of a characteristic are its aliases column (ManageDatabase).';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            app(TelegramDatabaseOperator::class)->assertAuthorized($this->update);
            $action = (string) ($request['action'] ?? '');
            if ($action === 'review') {
                return $this->json(['ok' => true, ...app(AttributeKeeper::class)->review(),
                    'to_do' => 'Nothing is applied yet. Show these proposals to the operator and apply only what they confirm.']);
            }
            if ($action === 'merge') {
                $from = AttributeDefinition::query()->where('key', (string) ($request['from_key'] ?? ''))->first();
                $into = AttributeDefinition::query()->where('key', (string) ($request['into_key'] ?? ''))->first();
                throw_if($from === null || $into === null, RuntimeException::class, 'No characteristic with that key; read attribute_definitions first.');
                $result = $this->recordOperation($this->update, class_basename(self::class), 'merge_characteristics',
                    ['from' => $from->key, 'into' => $into->key], fn (): array => app(AttributeDictionary::class)->merge($from, $into),
                    AttributeDefinition::class, $into->id);

                return $this->json(['ok' => true, ...$result]);
            }
            if ($action === 'apply') {
                $proposals = json_decode((string) ($request['proposals_json'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
                $result = $this->recordOperation($this->update, class_basename(self::class), 'apply_dictionary_review', (array) $proposals,
                    fn (): array => app(AttributeKeeper::class)->apply((array) ($proposals['merges'] ?? []), (array) ($proposals['groups'] ?? [])));

                return $this->json(['ok' => true, ...$result]);
            }

            return $this->json(['ok' => false, 'error' => 'action must be merge, review or apply.']);
        } catch (Throwable $exception) {
            return $this->json(['ok' => false, 'error' => mb_substr($exception->getMessage(), 0, 800),
                'message' => 'Не сделано из-за ошибки. Скажи это оператору прямо.']);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['merge', 'review', 'apply'])->required(),
            'from_key' => $schema->string()->max(255),
            'into_key' => $schema->string()->max(255),
            'proposals_json' => $schema->string()->max(100000),
        ];
    }
}
