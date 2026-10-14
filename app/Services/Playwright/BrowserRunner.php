<?php

namespace App\Services\Playwright;

/** The external-process boundary; tests can replace it without mocking routing. */
interface BrowserRunner
{
    public function run(BrowserRunRequest $request, ?callable $debug = null): BrowserRunResult;
}
