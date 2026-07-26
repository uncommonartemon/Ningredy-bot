<?php

return [
    // Public catalog limits. The original files are stored locally as WebP.
    'max_images' => 3,
    'max_images_by_type' => [
        'laptop' => 5,
        'desktop' => 5,
    ],
    'download_limit' => 20,
    'download_candidates' => 8,
    // Some official product galleries (for example ARTLINE) expose only a
    // clean 220px catalog image. Vision still has to approve it.
    'minimum_side' => 200,
    'minimum_ratio' => 0.28,
    'maximum_ratio' => 3.5,
    'public_source_target' => 6,

    // Vision receives candidates in small batches and stops after enough
    // publication images are selected.
    'vision_candidates' => 4,
    'vision_detail' => env('PRODUCT_IMAGE_VISION_DETAIL', 'low'),
    'vision_max_batches' => 2,
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
    'discover_after_rejection' => true,

    // A real browser is used only when static HTML does not expose enough
    // product gallery images (for example, JavaScript sliders on B&H).
    'browser_fallback' => [
        'enabled' => env('PRODUCT_IMAGE_BROWSER_ENABLED', env('APP_ENV') !== 'testing'),
        'node_binary' => env('PRODUCT_IMAGE_BROWSER_NODE', 'node'),
        'script' => 'scripts/extract-product-gallery.mjs',
        'timeout' => 45,
    ],
];
