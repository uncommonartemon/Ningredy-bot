<?php

namespace Tests\Unit;

use App\Services\Products\HostReputation;
use Tests\TestCase;

/**
 * Live on 2026-09-06: one host refused eight visits in three minutes and each
 * one was treated as an independent accident. A shop only has to say no once
 * for the answer to be worth keeping.
 */
class HostReputationTest extends TestCase
{
    public function test_one_refusal_slows_the_host_down_but_does_not_close_it(): void
    {
        $reputation = app(HostReputation::class);

        $reputation->noteRefusal('https://shop.example/p/1', HostReputation::REFUSAL_SILENCE);

        $this->assertTrue($reputation->isChallenged('https://shop.example/p/1'));
        $this->assertFalse(
            $reputation->isRefusing('https://shop.example/p/2'),
            'A shop with one bad minute must not lose the rest of its pages.',
        );
    }

    public function test_a_second_refusal_closes_the_host(): void
    {
        $reputation = app(HostReputation::class);

        $reputation->noteRefusal('https://shop.example/p/1', HostReputation::REFUSAL_SILENCE);
        $reputation->noteRefusal('https://shop.example/p/2', HostReputation::REFUSAL_SILENCE);

        $this->assertTrue($reputation->isRefusing('https://shop.example/p/3'));
        $this->assertSame(2, $reputation->refusal('https://shop.example/p/3')['count']);
        $this->assertSame(HostReputation::REFUSAL_SILENCE, $reputation->refusal('https://shop.example/p/3')['reason']);
    }

    public function test_the_answer_is_kept_per_host(): void
    {
        $reputation = app(HostReputation::class);

        $reputation->noteRefusal('https://shop.example/p/1', HostReputation::REFUSAL_ACCESS_GATE);
        $reputation->noteRefusal('https://shop.example/p/2', HostReputation::REFUSAL_ACCESS_GATE);

        $this->assertFalse($reputation->isRefusing('https://other.example/p/1'));
        $this->assertFalse($reputation->isChallenged('https://other.example/p/1'));
    }

    public function test_a_host_that_answers_again_is_forgiven_at_once(): void
    {
        $reputation = app(HostReputation::class);

        $reputation->noteRefusal('https://shop.example/p/1', HostReputation::REFUSAL_SILENCE);
        $reputation->noteRefusal('https://shop.example/p/2', HostReputation::REFUSAL_SILENCE);
        $reputation->noteAcceptance('https://shop.example/p/3');

        // Ageing out instead would keep a working shop at the slow pace for the
        // rest of the window over one bad minute.
        $this->assertFalse($reputation->isRefusing('https://shop.example/p/4'));
        $this->assertFalse($reputation->isChallenged('https://shop.example/p/4'));
    }

    public function test_a_broken_http2_stack_is_remembered_and_survives_forgiveness(): void
    {
        $reputation = app(HostReputation::class);

        $this->assertFalse($reputation->prefersHttp11('https://shop.example/p/1'));

        $reputation->noteHttp11Downgrade('https://shop.example/p/1');
        $reputation->noteAcceptance('https://shop.example/p/1');

        // The downgrade is a property of the server, not of one bad visit - the
        // page that finally loaded is the one that loaded over HTTP/1.1.
        $this->assertTrue($reputation->prefersHttp11('https://shop.example/p/2'));
        $this->assertFalse($reputation->prefersHttp11('https://other.example/p/1'));
    }

    public function test_a_url_without_a_host_is_ignored_rather_than_recorded(): void
    {
        $reputation = app(HostReputation::class);

        $this->assertSame(0, $reputation->noteRefusal('not a url', HostReputation::REFUSAL_SILENCE));
        $this->assertFalse($reputation->isRefusing('not a url'));
    }
}
