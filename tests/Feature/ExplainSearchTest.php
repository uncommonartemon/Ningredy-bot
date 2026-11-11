<?php

namespace Tests\Feature;

use App\Ai\Tools\ExplainSearch;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\ProductSourceAttempt;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Operator, 2026-11-11: the admin says «вот твой последний поиск был такой-то» and points at a
 * mistake; the bot must have something to answer from. The story is the search's own journal.
 */
class ExplainSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_assistant_reads_the_story_of_its_last_search_from_the_journal(): void
    {
        [$search, $draft] = $this->search(101, 'Samsung M393A4K40BB1-CRCQ0', 'Samsung M393A4K40BB1-CRCQ0 32GB DDR4-2400 ECC Registered DIMM');
        $this->step($search, $draft, 'https://www.itcreations.com/product/92896', 'source_selection', 'validate_resolved_source_identity', 'reject_runtime_identifier_mismatch', 'failed');
        $this->step($search, $draft, 'https://serverando.de/m393', 'image_download', 'download_candidates', 'ready_for_selection', 'completed', ['downloaded_images' => 6]);
        $this->step($search, $draft, 'https://serverando.de/m393', 'image_verification', 'compare_labels', 'compared', 'info', ['frames' => [
            ['index' => 1, 'reviewer_marking' => 'M393A4K40BB1-CRC0Q', 'read_marking' => 'M393A4K40BB1-CRC0Q', 'verdict' => 'sibling', 'publishable' => false],
        ]]);
        $this->step($search, $draft, 'https://serverando.de/m393', 'image_verification', 'verify_discovered_candidates', 'reject_candidates', 'completed', ['accepted_images' => 0]);
        $draft->update(['gallery_status' => 'missing', 'gallery_search_stop_reason' => 'specifications_unreconciled']);
        $draft->media()->create(['disk' => 'public', 'path' => 'drafts/x/primary.webp', 'source_url' => 'https://img.example/1.jpg', 'checksum' => 'fixture', 'width' => 1000, 'height' => 800,
            'photo_source_context' => ['url' => 'https://www.itcreations.com/product/92896'], 'verification_status' => 'verified',
            'verification_notes' => 'Код на наклейке совпадает с запрошенным.', 'is_primary' => true]);
        $asking = $this->request(102, 'почему в последнем поиске нет фото?');

        $story = json_decode((string) (new ExplainSearch($asking))->handle(new Request(['last' => true])), true);

        $this->assertTrue($story['ok']);
        $this->assertSame([$search->id, 'Samsung M393A4K40BB1-CRCQ0', $draft->id, 'specifications_unreconciled'],
            [$story['search']['request_id'], $story['search']['asked'], $story['search']['draft_id'], $story['search']['ended']]);
        $this->assertSame('specifications_unreconciled', $story['card']['ended']);
        $this->assertSame([1, 'www.itcreations.com', '1000x800', 'Код на наклейке совпадает с запрошенным.'],
            [$story['card']['photos'][0]['position'], $story['card']['photos'][0]['from'], $story['card']['photos'][0]['size'], $story['card']['photos'][0]['why_taken']]);
        $this->assertSame([['site' => 'serverando.de', 'read_on_the_photo' => 'M393A4K40BB1-CRC0Q', 'against_the_requested_code' => 'sibling', 'taken' => false]], $story['labels_read']);
        $sites = collect($story['sites'])->keyBy('host');
        $this->assertSame([6, 0], [$sites['serverando.de']['images_downloaded'], $sites['serverando.de']['photos_accepted']]);
        $this->assertStringContainsString('reject_runtime_identifier_mismatch', implode("\n", $story['steps']));

        // The same story by the draft's number, and by the request's.
        $byDraft = json_decode((string) (new ExplainSearch($asking))->handle(new Request(['draft_id' => $draft->id])), true);
        $this->assertSame($search->id, $byDraft['search']['request_id']);
        $byRequest = json_decode((string) (new ExplainSearch($asking))->handle(new Request(['request_id' => $search->id])), true);
        $this->assertSame($draft->id, $byRequest['card']['draft_id']);
    }

    public function test_without_a_number_it_lists_the_chats_own_searches_newest_first(): void
    {
        [$older] = $this->search(201, 'Kingston KVR32S22S8/16', 'Kingston ValueRAM 16GB');
        [$newer, $newerDraft] = $this->search(202, 'WD Blue SN570', 'WD Blue SN570 500GB');
        [$foreign] = $this->search(203, 'Someone else\'s search', 'Other chat', chat: '555');
        $asking = $this->request(204, 'что ты искал сегодня?');

        $list = json_decode((string) (new ExplainSearch($asking))->handle(new Request([])), true);

        $this->assertSame([$newer->id, $older->id], array_column($list['searches'], 'request_id'));
        $this->assertSame([$newerDraft->id, 'WD Blue SN570 500GB', 'pending_review'],
            [$list['searches'][0]['draft_id'], $list['searches'][0]['title'], $list['searches'][0]['draft_status']]);
        // Another chat's search is neither listed nor told.
        $refused = json_decode((string) (new ExplainSearch($asking))->handle(new Request(['request_id' => $foreign->id])), true);
        $this->assertFalse($refused['ok']);
    }

    /** @return array{0: TelegramUpdate, 1: ProductDraft} */
    private function search(int $number, string $text, string $title, string $chat = '98765'): array
    {
        $search = $this->request($number, $text, $chat);
        $run = AiRun::query()->create(['telegram_update_id' => $search->id, 'provider' => 'fake', 'model' => 'fake', 'status' => 'completed',
            'prompt' => 'test', 'started_at' => now(), 'completed_at' => now()]);
        $draft = ProductDraft::query()->create(['telegram_update_id' => $search->id, 'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345', 'title' => $title, 'product_type' => 'component', 'category' => 'ram',
            'status' => 'pending_review', 'specifications' => [], 'sources' => [], 'image_urls' => []]);
        $this->step($search, $draft, 'https://shop.example/'.$number, 'product_research', 'research_summary', 'found', 'completed');

        return [$search, $draft];
    }

    private function request(int $number, string $text, string $chat = '98765'): TelegramUpdate
    {
        return TelegramUpdate::query()->create(['update_id' => 880000 + $number, 'telegram_user_id' => '12345', 'chat_id' => $chat,
            'text' => $text, 'payload' => [], 'status' => 'completed', 'processed_at' => now()]);
    }

    private function step(TelegramUpdate $search, ProductDraft $draft, string $url, string $phase, string $action, string $decision, string $status, array $output = []): void
    {
        ProductSourceAttempt::query()->create(['telegram_update_id' => $search->id, 'product_draft_id' => $draft->id,
            'domain' => (string) parse_url($url, PHP_URL_HOST), 'product_url' => $url, 'actor' => 'server', 'phase' => $phase,
            'action' => $action, 'status' => $status, 'decision' => $decision, 'output' => $output]);
    }
}
