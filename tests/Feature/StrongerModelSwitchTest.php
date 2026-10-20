<?php

namespace Tests\Feature;

use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrongerModelSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stronger_model_is_off_until_the_operator_switches_it_on_and_its_choice_is_kept(): void
    {
        $settings = app(AiSettings::class);
        $settings->saveCardAssemblyFallbackModel('gpt-6-sol');

        $this->assertFalse($settings->strongerModelEnabled());
        $this->assertNull($settings->cardAssemblyFallbackModel());
        $this->assertSame('gpt-6-sol', $settings->storedCardAssemblyFallbackModel());

        $settings->saveStrongerModelEnabled(true);
        $this->assertSame('gpt-6-sol', $settings->cardAssemblyFallbackModel());

        $settings->saveStrongerModelEnabled(false);
        $this->assertNull($settings->cardAssemblyFallbackModel());
    }
}
