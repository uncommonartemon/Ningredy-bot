<?php

use App\Http\Controllers\AdminLocaleController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;
use App\Services\Products\CatalogLocales;
use Illuminate\Support\Facades\Route;

Route::get('/', CatalogController::class);
Route::get('/catalog', CatalogController::class)->name('catalog');
Route::get('/products/{product:slug}', ProductController::class)->name('products.show');

Route::post('/admin/locale/{locale}', AdminLocaleController::class)
    ->whereIn('locale', array_keys(CatalogLocales::LABELS))->name('admin.locale');
