# Completion checklist
1. Run `vendor\bin\pint` after PHP edits.
2. Run `php artisan test --compact`.
3. If migrations changed, run `php artisan migrate:fresh --force` and tests again.
4. Run `composer validate --no-check-publish` after dependency or composer.json changes.
5. Never report live Telegram/OpenAI integration as verified without real credentials and a public HTTPS webhook.