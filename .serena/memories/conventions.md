# Project conventions
- Flow: TelegramUpdate -> AiRun -> ProductDraft -> Product.
- AI never receives shell, SQL, or unrestricted database access.
- ProductDraft preserves AI/review history; approved drafts publish once into `products` through ProductDraftWorkflow.
- Product is the editable catalog boundary for future website/API integrations.
- Telegram and Filament approval share ProductDraftWorkflow; rejection archives an existing product.
- Telegram requests require webhook secret and user-ID allowlist; update_id provides idempotency.
- Filament `/admin` access requires `is_admin=true` and username `ningredy`; custom login uses username.
- Slow AI work runs on the `ai` queue.
- Preserve sources, candidate images, confidence, and review metadata for verification.