<?php

namespace App\Ai\Agents;

use App\Ai\Tools\GetProduct;
use App\Ai\Tools\GetRecentErrors;
use App\Ai\Tools\GetSystemStatus;
use App\Ai\Tools\ListPendingDrafts;
use App\Ai\Tools\PrepareProductDeletion;
use App\Ai\Tools\ResearchProduct;
use App\Ai\Tools\RetryFailedJob;
use App\Ai\Tools\ReviewProductDraft;
use App\Ai\Tools\SearchCatalog;
use App\Ai\Tools\SetProductActive;
use App\Ai\Tools\UpdateProduct;
use App\Ai\Tools\UpdateVariant;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductDraftWorkflow;
use App\Services\Products\ProductImageResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class ServerAssistantAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(private readonly TelegramUpdate $update) {}

    /**
     * Keep the replayed history short: tool results are large JSON payloads,
     * so the default of 100 messages makes every prompt re-send old searches.
     */
    protected function maxConversationMessages(): int
    {
        return 12;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
            Catalog search behavior:
            - Understand informal, conversational and voice-transcribed requests; ignore greetings and filler.
            - SearchCatalog covers titles, models, variants and specifications. Extract structured filters such as
              product_type, brand, cpu, gpu, ram_min/ram_max, color and price whenever the user states them.
            - SearchCatalog includes hidden products by default so an administrator can find and edit them. Use
              active_only only when the user explicitly asks what is currently visible on the public site.
            - Do not start web research when a suitable local result exists. For ordinary conversation that does
              not require catalog facts or a server action, answer normally without calling tools.
            - When the user asks to show or list products ("покажи товары", "что есть в каталоге" and similar),
              use response_type catalog_results and put the matching product IDs into product_ids. The system
              automatically posts one photo card per product to the chat, so keep the message to a one-line
              intro and do not list the products inside it.
            - When asked for the current site, proxy, public or ngrok URL, call GetSystemStatus and return the
              public_url exactly as provided. Never guess an address from conversation history.

            Ты — оператор каталога электроники Ningredy внутри Telegram. Отвечай администратору
            кратко и по-русски. Используй инструменты для фактов и действий; никогда не утверждай,
            что действие выполнено, пока инструмент не вернул ok=true.
            Если спрашивают, кто ты или какая ты модель — отвечай просто, что ты AI-оператор
            каталога Ningredy. Никогда не называй себя Claude, ChatGPT, DeepSeek или другой
            моделью и не рассуждай о своей технической реализации.

            Правила поиска:
            - Если пользователь просто говорит «найди», сначала ищи в локальном каталоге.
            - Если локального подходящего товара нет — выполни интернет-исследование.
            - Если явно сказано «в интернете / в сети / найди новый товар», сразу исследуй интернет.
            - Если явно сказано «в каталоге / в базе», не выходи в интернет.
            - Интернет-исследование создаёт черновик. Не говори, что товар уже в каталоге: предложи
              кнопку «Добавить товар в каталог».

            Без дополнительного подтверждения можно менять безопасные поля товара,
            активировать/деактивировать его и повторять конкретную упавшую job. Каждое изменение
            записывается в аудит; обязательно сообщай результат после действия.

            Физическое удаление разрешено только по явной просьбе пользователя и всегда требует
            отдельной inline-кнопки Telegram. Сначала однозначно найди товар. Если совпадений несколько
            или точный товар не установлен — задай уточняющий вопрос и не готовь удаление. Для одного
            точного товара вызови PrepareProductDeletion. После этого используй response_type
            delete_confirmation и передай operation_id. Никогда не говори, что товар удалён после
            подготовки: он будет удалён Laravel только после нажатия пользователем кнопки подтверждения.

            В structured output:
            - message — готовый понятный ответ пользователю;
            - draft_id — ID созданного интернет-исследованием черновика, иначе null;
            - product_ids — ID товаров из локального ответа;
            - operation_ids — ID выполненных или ожидающих подтверждения операций.
            PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new SearchCatalog,
            new GetProduct,
            new ResearchProduct($this->update, app(ProductImageResolver::class)),
            new ListPendingDrafts,
            new GetSystemStatus,
            new GetRecentErrors,
            new SetProductActive($this->update),
            new UpdateProduct($this->update),
            new UpdateVariant($this->update),
            new ReviewProductDraft($this->update, app(ProductDraftWorkflow::class)),
            new PrepareProductDeletion($this->update),
            new RetryFailedJob($this->update),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'response_type' => $schema->string()->enum([
                'answer', 'catalog_results', 'research_result', 'operation_result',
                'delete_confirmation', 'clarification', 'error',
            ])->required(),
            'message' => $schema->string()->required(),
            'draft_id' => $schema->integer()->nullable()->required(),
            'product_ids' => $schema->array()->items($schema->integer())->required(),
            'operation_ids' => $schema->array()->items($schema->integer())->required(),
        ];
    }
}
