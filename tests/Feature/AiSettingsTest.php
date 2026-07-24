<?php

namespace Tests\Feature;

use App\Services\Ai\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_roles_use_openai(): void
    {
        $settings = app(AiSettings::class);

        $this->assertSame('openai', $settings->providerFor('server_assistant'));
        $this->assertSame('openai', $settings->providerFor('product_image_vision'));
        $this->assertSame('openai', $settings->providerFor('voice_transcription'));
    }

    public function test_selected_model_applies_to_text_and_vision_roles(): void
    {
        config()->set('services.voice_transcription.model', 'gpt-4o-transcribe');
        config()->set('services.image_upscale.model', 'gpt-image-1-mini');

        $settings = app(AiSettings::class);
        $settings->saveModel('gpt-5-mini');

        $this->assertSame('gpt-5-mini', $settings->modelFor('server_assistant'));
        $this->assertSame('gpt-5-mini', $settings->modelFor('product_research'));
        $this->assertSame('gpt-5-mini', $settings->modelFor('product_image_discovery'));
        $this->assertSame('gpt-5-mini', $settings->modelFor('product_image_vision'));
        $this->assertSame('gpt-4o-transcribe', $settings->modelFor('voice_transcription'));
        // Real bug caught while building UpscaleProductPhoto: without this
        // exclusion, the chat model override silently replaced the image
        // model, which would have sent a chat model name to images/edits.
        $this->assertSame('gpt-image-1-mini', $settings->modelFor('image_upscale'));
    }

    public function test_invalid_model_is_not_saved(): void
    {
        $settings = app(AiSettings::class);
        $settings->saveModel('other-provider-model');

        $this->assertNull($settings->model());
    }

    public function test_blank_selection_uses_per_role_env_model(): void
    {
        config()->set('services.server_assistant.model', 'gpt-4o');

        $settings = app(AiSettings::class);
        $settings->saveModel(null);

        $this->assertSame('gpt-4o', $settings->modelFor('server_assistant'));
    }

    public function test_selected_image_model_overrides_env_default(): void
    {
        config()->set('services.image_upscale.model', 'gpt-image-2');

        $settings = app(AiSettings::class);
        $settings->saveImageModel('gpt-image-1-mini');

        $this->assertSame('gpt-image-1-mini', $settings->imageModel());
        $this->assertSame('gpt-image-1-mini', $settings->modelFor('image_upscale'));
    }

    public function test_blank_image_selection_uses_env_default(): void
    {
        config()->set('services.image_upscale.model', 'gpt-image-2');

        $settings = app(AiSettings::class);
        $settings->saveImageModel(null);

        $this->assertNull($settings->imageModel());
        $this->assertSame('gpt-image-2', $settings->modelFor('image_upscale'));
    }

    public function test_invalid_image_model_is_not_saved(): void
    {
        $settings = app(AiSettings::class);
        $settings->saveImageModel('some-random-model');

        $this->assertNull($settings->imageModel());
    }
}
