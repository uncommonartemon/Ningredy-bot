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
    // Total decoded pixels one download pass may hold in memory at once. A
    // decoded truecolor bitmap costs ~4 bytes per pixel, so this is roughly a
    // 240MB ceiling on retained images - the loop stops with a partial gallery
    // instead of exhausting the worker's memory_limit, which loses the whole
    // search. Raise it only together with the workers' memory_limit.
    'decoded_pixel_budget' => 60_000_000,
    // Frames are held at this edge while they are compared, hashed and shown to
    // Vision - the same edge the encoder writes at, so nothing is lost that the
    // catalog would have kept. Holding originals instead emptied the budget
    // above three frames into a gallery of large photographs.
    'working_edge' => 1600,
    // The largest frame worth decoding at all. Frames are shrunk to the edge
    // above the moment they are decoded, so the full bitmap exists for an
    // instant rather than for the length of a gallery - which is what makes a
    // ceiling this high safe. Real shops serve 5000x5000 renders.
    'decode_pixel_ceiling' => 40_000_000,
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
        // Bounds the WHOLE download_candidates loop for one source, not a
        // single request - each request above is already capped, but many
        // slow-but-not-instantly-failing URLs in sequence can still add up
        // past any reasonable per-source budget with no single request ever
        // failing outright to point to.
        'download_wall_clock_cap_seconds' => 90,
    ],

    // How many source pages one resolve pass may open, how many image URLs a
    // single page may yield, and how wide the resolver search goes overall.
    'max_sources_per_resolve' => 10,
    // How many pages from one host a single resolve pass may open. Research
    // routinely returns four links to the same manufacturer's shop; opening
    // all four is one shop asked four times in three minutes, which is both
    // what a scraper looks like and four chances that fail together. Breadth
    // across domains is what actually finds a gallery.
    'max_sources_per_host' => 2,
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
    // Gallery-agent Vision is only an on-demand visual observation tool. Keep
    // more detail than the broad candidate filter so small text/model conflicts
    // and unusual product framing are less likely to be missed.
    'gallery_agent_vision_detail' => env('PRODUCT_GALLERY_AGENT_VISION_DETAIL', 'high'),
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

        // Minimum gap between two browser visits to the same host. Training
        // opens one page once per round, so without this a shop receives four
        // or five visits inside a couple of minutes.
        //
        // Four seconds was that pace measured from the shop's side: eight
        // visits to one host inside three minutes, evenly spaced, each one a
        // cold arrival straight onto a deep product page. That is what a
        // scraper looks like, and it is why the 403s started. Ten costs a
        // search well under a minute and does not.
        'host_visit_spacing_seconds' => (float) env('PRODUCT_IMAGE_BROWSER_HOST_SPACING_SECONDS', 10),
        // Applied to a host that has actually pushed back - a robot check, a
        // WAF, a 403, or a connection it accepts and then never answers. One
        // training makes five to eight visits to the same host, so spacing
        // every shop like this would spend minutes waiting on domains that
        // never objected to anything.
        'challenged_host_spacing_seconds' => (float) env('PRODUCT_IMAGE_BROWSER_CHALLENGED_SPACING_SECONDS', 25),
        'challenged_host_memory_hours' => (int) env('PRODUCT_IMAGE_BROWSER_CHALLENGED_MEMORY_HOURS', 6),

        // How many refusals from one host before its remaining sources in this
        // search are skipped instead of visited. One is not enough - shops have
        // bad minutes and a domain written off for a transient failure loses
        // sources that would have worked. Two is an answer, and it turns eight
        // visits to a host that returned nothing into two.
        'refusal_threshold' => (int) env('PRODUCT_IMAGE_BROWSER_REFUSAL_THRESHOLD', 2),
        // How long that answer stands. Long enough to cover a whole search and
        // the retry a person makes straight afterwards, short enough that a
        // shop which blocked us for a minute is not written off for the day.
        'refusal_memory_minutes' => (int) env('PRODUCT_IMAGE_BROWSER_REFUSAL_MEMORY_MINUTES', 20),
        // A server's broken HTTP/2 stack is a property of the server, so once
        // seen it is worth starting on HTTP/1.1 rather than paying for the
        // broken stream again on every later visit.
        'http11_memory_hours' => (int) env('PRODUCT_IMAGE_BROWSER_HTTP11_MEMORY_HOURS', 24),
        'training_max_rounds' => (int) env('PRODUCT_IMAGE_GALLERY_TRAINING_MAX_ROUNDS', 3),
        'timeout' => 45,
        'scout_timeout' => 60,
    ],
];
