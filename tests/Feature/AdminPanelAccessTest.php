<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_ningredy_admin_can_access_the_panel(): void
    {
        $admin = User::factory()->create([
            'name' => 'ningredy',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_other_users_cannot_access_the_panel(): void
    {
        $user = User::factory()->create([
            'name' => 'another-user',
            'is_admin' => true,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_open_normalized_product_management_pages(): void
    {
        $admin = User::factory()->create([
            'name' => 'ningredy',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)->get('/admin/products')->assertOk();
        $this->actingAs($admin)->get('/admin/products/create')->assertOk();
        $this->actingAs($admin)->get('/admin/categories')->assertOk()->assertSee('Laptops');
        $this->actingAs($admin)->get('/admin/categories/create')->assertOk();
    }

    public function test_admin_can_open_telegram_webhook_settings(): void
    {
        $admin = User::factory()->create([
            'name' => 'ningredy',
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get('/admin/telegram-settings')
            ->assertOk()
            ->assertSee('Telegram webhook');
    }

    public function test_admin_can_view_and_edit_a_product_with_media(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['name' => 'ningredy', 'is_admin' => true]);
        $category = Category::query()->where('slug', 'laptops')->firstOrFail();
        $brand = Brand::query()->create(['name' => 'Test', 'slug' => 'test']);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'canonical_key' => 'admin-product',
            'product_type' => 'laptop',
            'status' => 'published',
            'slug' => 'admin-product',
            'title' => 'Admin product',
            'is_active' => true,
        ]);
        $product->variants()->create([
            'fingerprint' => 'admin-variant',
            'condition' => 'new',
            'currency' => 'CZK',
            'stock_status' => 'in_stock',
            'is_default' => true,
            'is_active' => true,
        ]);
        $imagePath = "products/{$product->id}/product.webp";
        Storage::disk('public')->put($imagePath, 'fake-webp-image');

        $product->media()->create([
            'type' => 'image',
            'disk' => 'public',
            'path' => $imagePath,
            'is_primary' => true,
        ]);

        $imageUrl = "/storage/{$imagePath}";

        $this->actingAs($admin)
            ->get('/admin/products')
            ->assertOk()
            ->assertSee($imageUrl, false);
        $this->actingAs($admin)
            ->get("/admin/products/{$product->id}")
            ->assertOk()
            ->assertSee('Admin product')
            ->assertSee($imageUrl, false);
        $this->actingAs($admin)
            ->get("/admin/products/{$product->id}/edit")
            ->assertOk()
            ->assertSee($imageUrl, false);
    }
}
