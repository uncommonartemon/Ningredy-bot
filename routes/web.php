<?php

use App\Http\Controllers\Admin\AdminerController;
use App\Http\Controllers\AdminLocaleController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;
use App\Http\Middleware\AdminerAccess;
use App\Services\Products\CatalogLocales;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', CatalogController::class);
Route::get('/catalog', CatalogController::class)->name('catalog');
Route::get('/products/{product:slug}', ProductController::class)->name('products.show');

Route::post('/admin/locale/{locale}', AdminLocaleController::class)
    ->whereIn('locale', array_keys(CatalogLocales::LABELS))->name('admin.locale');

// Adminer carries its own CSRF token on every form.
Route::any('/admin/adminer', AdminerController::class)->middleware(AdminerAccess::class)
    ->withoutMiddleware(ValidateCsrfToken::class)->name('admin.adminer');
