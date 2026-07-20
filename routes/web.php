<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/', CatalogController::class);
Route::get('/catalog', CatalogController::class)->name('catalog');
Route::get('/products/{product:slug}', ProductController::class)->name('products.show');
