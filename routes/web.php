<?php

use App\Http\Controllers\Account\AccountController;
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

// The shop's accounts: no open sign-up, a customer comes through their group's invitation link.
Route::get('/account', [AccountController::class, 'show'])->name('account');
Route::get('/account/login', [AccountController::class, 'loginForm'])->name('account.login');
Route::post('/account/login', [AccountController::class, 'login']);
Route::post('/account/logout', [AccountController::class, 'logout'])->name('account.logout');
Route::get('/account/join/{code}', [AccountController::class, 'joinForm'])->where('code', '[a-z0-9]{8,40}')->name('account.join');
Route::post('/account/join/{code}', [AccountController::class, 'join'])->where('code', '[a-z0-9]{8,40}');

Route::post('/admin/locale/{locale}', AdminLocaleController::class)
    ->whereIn('locale', array_keys(CatalogLocales::LABELS))->name('admin.locale');

// Adminer carries its own CSRF token on every form.
Route::any('/admin/adminer', AdminerController::class)->middleware(AdminerAccess::class)
    ->withoutMiddleware(ValidateCsrfToken::class)->name('admin.adminer');
