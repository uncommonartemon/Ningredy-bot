<?php

// Note: the image pipeline decodes full-size photos into GD bitmaps, so the
// queue workers need a raised memory limit. StoreProductImages sets 512M
// inside the job; the worker environment is responsible for the rest.

return [
    // Public catalog limits. The original files are stored locally as WebP.
    'max_images' => 10,
    'max_images_by_type' => [
        'laptop' => 10,
        'desktop' => 10,
    ],
    'download_limit' => 20,
    'download_candidates' => 10,
    // Width and height are checked independently so useful wide product shots
    // are not reduced to one ambiguous "minimum side" setting.
    'minimum_width' => (int) env('PRODUCT_IMAGE_MINIMUM_WIDTH', 700),
    'minimum_height' => (int) env('PRODUCT_IMAGE_MINIMUM_HEIGHT', 0),
    'minimum_ratio' => 0.28,
    'maximum_ratio' => 3.5,
    'public_source_target' => 6,

    // HTTP client used for product pages and candidate image downloads.
    'http' => [
        'connect_timeout' => 3,
        'timeout' => 7,
    ],

    // How many source pages one resolve pass may open, how many image URLs a
    // single page may yield, and how wide the resolver search goes overall.
    'max_sources_per_resolve' => 10,
    'max_urls_per_page' => 60,
    'resolve_limit' => 16,
    // AI discovery: how many of the suggested page URLs are opened and how
    // many candidate URLs are kept from one discovery run. 12 is the agent's
    // own return cap (see ProductImageDiscoveryAgent::schema) - the real
    // stopping condition is max_search_cost_usd below, not this count.
    'ai_page_urls_limit' => 12,
    'ai_result_limit' => 20,

    // Once one Telegram search has spent this much (across research, vision,
    // gallery-recipe training, and discovery calls), ProductImageResolver
    // stops opening further candidate sources and the search gives up with
    // whatever was already found. 0 disables the budget cutoff.
    'max_search_cost_usd' => (float) env('PRODUCT_IMAGE_MAX_SEARCH_COST_USD', 0.50),
    // Once useful candidates exist, stop training new domains at this share
    // and preserve the rest for Vision plus the independent fallback search.
    'source_exploration_budget_fraction' => (float) env('PRODUCT_IMAGE_SOURCE_BUDGET_FRACTION', 0.70),
    // A single candidate source's own recipe training may not spend more
    // than this share of the whole search's money budget - without this, one
    // stubborn domain can consume the entire search budget by itself before
    // any other candidate source ever gets tried (each training round was
    // previously only checked against the whole-search limit, never its own
    // share of it).
    'source_training_cost_share_fraction' => (float) env('PRODUCT_IMAGE_SOURCE_TRAINING_COST_SHARE_FRACTION', 0.4),
    'source_preflight' => env('PRODUCT_IMAGE_SOURCE_PREFLIGHT', env('APP_ENV') !== 'testing'),

    // The queue worker has a larger hard timeout (2100s). Application work
    // stops earlier and keeps a reserve for persisting the draft and replying
    // to Telegram instead of being killed in the middle of finalization.
    'search_timing' => [
        'max_seconds' => (int) env('PRODUCT_SEARCH_MAX_SECONDS', 1800),
        'reserve_seconds' => (int) env('PRODUCT_SEARCH_RESERVE_SECONDS', 120),
        'product_research_timeout_seconds' => (int) env('PRODUCT_SEARCH_RESEARCH_TIMEOUT', 900),
        'product_research_idle_timeout_seconds' => (int) env('PRODUCT_SEARCH_RESEARCH_IDLE_TIMEOUT', 90),
        'image_discovery_timeout_seconds' => (int) env('PRODUCT_SEARCH_IMAGE_DISCOVERY_TIMEOUT', 180),
        'gallery_recipe_timeout_seconds' => (int) env('PRODUCT_SEARCH_GALLERY_RECIPE_TIMEOUT', 240),
        'image_vision_timeout_seconds' => (int) env('PRODUCT_SEARCH_VISION_TIMEOUT', 90),
        'browser_timeout_seconds' => (int) env('PRODUCT_SEARCH_BROWSER_TIMEOUT', 90),
        'browser_scout_timeout_seconds' => (int) env('PRODUCT_SEARCH_BROWSER_SCOUT_TIMEOUT', 120),
    ],

    // Vision receives candidates in small batches and stops after enough
    // publication images are selected.
    'vision_candidates' => 4,
    'vision_detail' => env('PRODUCT_IMAGE_VISION_DETAIL', 'low'),
    'vision_max_batches' => 3,
    // Keep independent page galleries separate and let Vision try the best
    // few sequentially. Images from different pages are never mixed.
    'vision_source_sets' => max(1, (int) env('PRODUCT_IMAGE_VISION_SOURCE_SETS', 3)),
    'vision_min_score' => 60,
    'vision_official_min_score' => 55,

    // How the publishable images are ordered into a gallery:
    // "heuristic" - code rules (front hero shot first, then official source,
    //               exact match, kind, score). Predictable, but rigid.
    // "model"     - the Vision model itself assigns a unique gallery_rank and
    //               the code trusts that order. Flexible, but depends on the
    //               model's stability.
    'ranking' => env('PRODUCT_IMAGE_RANKING', 'heuristic'),

    // Near-duplicate photos (same shot with a different crop or compression)
    // are dropped after Vision when their 64-bit perceptual hash distance is
    // within this threshold. 0 means pixel-identical, ~6 catches near-copies.
    'duplicate_hash_threshold' => 6,

    // Run a separate web image search only when the research result produced
    // no downloadable candidate, or every downloaded candidate was rejected.
    'fallback_discovery' => true,
    // Safety cap only when the price budget is disabled or there is no search
    // id (mainly deterministic tests). Normal searches retry until the
    // configured cost or total-time boundary is reached.
    'fallback_search_rounds' => max(1, (int) env('PRODUCT_IMAGE_FALLBACK_SEARCH_ROUNDS', 3)),
    'discover_after_rejection' => true,

    // In auto mode a browser scout and mandatory AI gate decide whether the
    // static URLs are sufficient or a hidden/layered gallery needs training.
    'browser_fallback' => [
        'enabled' => env('PRODUCT_IMAGE_BROWSER_ENABLED', env('APP_ENV') !== 'testing'),
        'node_binary' => env('PRODUCT_IMAGE_BROWSER_NODE', 'node'),
        'script' => 'scripts/extract-product-gallery.mjs',
        'gallery_limit' => 10,
        'dom_wait_ms' => (int) env('PRODUCT_IMAGE_BROWSER_DOM_WAIT_MS', 12000),
        'image_probe_timeout_ms' => (int) env('PRODUCT_IMAGE_BROWSER_PROBE_TIMEOUT_MS', 5000),
        'training_max_rounds' => (int) env('PRODUCT_IMAGE_GALLERY_TRAINING_MAX_ROUNDS', 3),
        'timeout' => 45,
        'scout_timeout' => 60,
    ],
];
