---
paths:
  - "tests/**"
---

# Tests

- Run the whole suite (`php artisan test`) before pushing. A filtered run misses older tests that expect the previous behaviour, and CI then goes red on the pull request. Fix such a test on the lowest branch of a stack and merge upward.
- A test's name reads as a sentence about behaviour. Its comments say why the behaviour exists, in neutral words.
- `TestCase` forbids stray HTTP requests, so no test can make a paid call. Models are faked; that proves wiring, not understanding (see `real-model-check`).
- One container serves a whole test. After `actingAs()` for another guard, call `forgetScopedInstances()` and fetch the user again; scoped services and loaded relations otherwise carry over between requests.
- A model object returned by `create()` keeps what it knew. Refresh it after something else changed its row, or assert on `fresh()`.
- Filament pages are tested through `Livewire::test()` on the page class. Repeater rows get keys of their own: find a row by the record it carries.
- A `Select` only accepts a value from its options: put the value in the list first.
- The real-curl tests in `SafeProductHttpTest` can time out on a machine that is busy with a running bot. Rerun them alone before treating that as a regression.
- `RepositoryHygieneTest` guards the public repository: no quoted correspondence, no machine paths, keys or chat ids, and well-formed skills.
