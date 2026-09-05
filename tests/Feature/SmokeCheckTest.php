<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmokeCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_checks_the_machine_on_a_deployment_that_has_never_run_the_bot(): void
    {
        // The moment this command is worth the most is a fresh install being
        // handed over, and that is exactly when the catalog is empty. It used
        // to stop at "no trained recipe" and check nothing at all - the person
        // taking delivery learned whether the box could run the bot by running
        // the bot.
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.webhook_secret' => 'test-secret',
            'ai.providers.openai.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('bot:smoke', ['--skip-browser' => true, '--skip-ai' => true])
            ->expectsOutputToContain('No trained recipe yet')
            ->expectsOutputToContain('php has the extensions the pipeline uses')
            ->expectsOutputToContain('the database is reachable and fully migrated')
            ->expectsOutputToContain('the queue is wired')
            ->assertExitCode(0);
    }

    public function test_it_fails_when_the_bot_cannot_talk_to_telegram_at_all(): void
    {
        // A missing token is the difference between a bot and a silent process,
        // and the exit code has to say so: a handover script gates on it.
        config([
            'services.telegram.bot_token' => '',
            'services.telegram.webhook_secret' => '',
            'ai.providers.openai.key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('bot:smoke', ['--skip-browser' => true, '--skip-ai' => true])
            ->assertExitCode(1);
    }
}
