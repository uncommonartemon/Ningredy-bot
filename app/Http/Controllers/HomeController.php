<?php

namespace App\Http\Controllers;

use App\Services\Catalog\StorefrontHome;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(StorefrontHome $home): Response
    {
        return Inertia::render('Home', $home->payload());
    }
}
