# CLAUDE.md — Restaurant POS System

**Stack:** Laravel 12 + Inertia React back office · React Native Expo (planned POS + KDS) · MySQL
**Hardware:** Intel NUC + TP-Link Deco M5 · Goojrpt PT-210 thermal printer (Local Deployment after Development of the overall Application)
**Real-time:** Laravel Reverb (Pusher-protocol-compatible) — implemented for the KDS channel (`kds.orders`); general POS/back-office sync not yet wired
**Auth:** Laravel web/session authentication for the back office; Laravel Sanctum bearer tokens for the POS API (`/api/v1`, implemented)

---

## Repository Structure

```
app/
  Http/
    Controllers/
      *Controller.php   ← Back office controllers (session auth; Inertia pages + JSON)
      Api/V1/           ← POS REST API controllers (Sanctum)
      Api/Concerns/     ← ApiResponses trait (success/error envelope)
    Middleware/
    Requests/           ← Form requests (validation + role authorization)
  Exceptions/           ← Domain exceptions (mapped to 409/403 for /api/*)
  Models/
  Services/             ← Business logic shared by back office and API
resources/
  js/
    pages/              ← Inertia React pages (back office)
    components/         ← Shared React components
routes/
  web.php               ← Inertia routes (back office)
  api.php → api_v1.php  ← POS API, prefix /api/v1
database/
  migrations/
  seeders/
docs/
  ENHANCED_SPEC.md        ← Product spec (implementation status marked)
  UNIFIED_API_ENDPOINTS.md ← The implemented /api/v1 contract
  MERGED_DATABASE_SCHEMA.sql  ← Reference only (drifted); use migrations
```

---

## Key Business Rules

| Rule                   | Detail                                                                                                   |
| ---------------------- | -------------------------------------------------------------------------------------------------------- |
| **KDS order**          | Strictly `created_at` ASC (FIFO across all terminals); served by `GET /api/v1/kds/orders` (excludes paid/cancelled/merged tickets, voided lines and `fee` lines; no prices or `terminal_id`), pushed live on the `kds.orders` Reverb channel |
| **KDS completion**     | Persisted as `ticket_items.completed_at` (nullable timestamp), toggled per item via `PATCH /api/v1/kds/orders/items/{ticketItem}/complete` (no passcode), broadcasts `item.completed`/`item.uncompleted`; or for the whole ticket at once via `PATCH /api/v1/kds/orders/{ticket}/complete` (the "tap the customer name" gesture — bumps every pending line, broadcasts `ticket.updated`). A completed line drops off the feed entirely — a ticket with no pending lines disappears from `GET /api/v1/kds/orders` until something new is added to it, and that add-on shows up alone, not mixed with the already-served lines. Not audit/historical data — swept back to `null` every night by `kds:clear-completed` (`routes/console.php`, `dailyAt('03:00')`; confirm that time against real operating hours) regardless of ticket status |
| **Terminal isolation** | POS only sees its own open tickets                                                                       |
| **Inventory**          | Direct items use item stock; recipe items use shared ingredient stock; reserve on add, deduct on payment |
| **Shift lock**         | One open shift at a time (DB UNIQUE INDEX)                                                               |
| **Split payment**      | Cash + GCash simultaneously on one ticket; amounts-only (no per-item assignment); charges must equal the recomputed total to the centavo; one receipt per charge with a prorated discount |
| **Notes**              | KDS-only — never printed on customer receipt                                                             |
| **Duplicate names**    | Auto-append among open tickets within a shift, across all terminals: john → john2 → john3                |
| **Passcode**           | 4-digit PIN hashed with bcrypt; required to void item on open ticket or approve/reject refund. The POS sends only the passcode; the server identifies the approver among active admins/managers (`PasscodeService`), refuses a passcode shared by more than one, and returns 429 after 5 failed attempts/minute. Passcodes must be unique among active managers/admins (enforced by the back office user forms) |
| **Shift formula**      | Starting Cash + Cash Sales + Additions − Expenses − Cash Refunds = Expected Cash                          |
| **Payment atomicity**  | Charges, inventory deduction, ticket close and receipt generation succeed or roll back together          |
| **Special items**      | Items in a `special` category (Fee item: cashier types the amount; Custom item: cashier types name + amount). No inventory/cost/recipe/modifiers; discount applies; `ticket_items.line_type` = item/fee/custom (KDS hides `fee`). The word "charge" always means a payment — never use it for these |
| **Ticket merge**       | Open tickets in the same shift fold into a target; sources become `merged` with zeroed money             |
| **Comps**              | A ticket discounted to a `0` total is closed with a single `amount: 0` charge (`POST .../charges`), same endpoint as any payment. Inventory is still deducted; the shift's revenue/cash totals are not. More than one charge against a `0` total is rejected (`409`) — required so receipt discount proration never divides by a `0` total |
| **Refund validation**  | `RefundService::RequestRefund` re-derives every bound from the database, never trusts client-supplied numbers: the `ticket_item_id` must belong to the ticket being refunded; requested quantity can't exceed what was purchased minus what's already pending/approved for that line; requested amount can't exceed the line's actual per-unit price × quantity; and the running total for the specific charge being refunded can't exceed what that charge collected. Ticket item and charge rows are locked for the duration so concurrent refund requests can't race past these checks. A `rejected` refund releases its quantity/amount back for a future request; `pending` and `approved` both count against the limit |

### Inventory Modes

- `direct` — the menu item owns integer `quantity` and `reserved_quantity` stock.
- `recipe` — the menu item consumes ingredient stock through `item_ingredient`.
- `none` — the menu item bypasses inventory accounting.
- Ingredients belong to an `ingredient_group` and use decimal quantities with units `piece`, `kg`, `gram`, `liter`, or `ml`.
- Recipe requirements store `quantity_required` and must use the same unit as the ingredient.
- Recipe availability is the smallest complete-serving count across its ingredients.
- Inventory mutations are transactional and lock item/ingredient rows to prevent competing reservations.

---

## Users & Roles

- Login via `username` (not email)
- `password` — full login credential (bcrypt)
- `passcode` — 4-digit PIN (bcrypt); used for sensitive POS actions:
    - Removing an item from an open ticket → POS prompts a manager/admin for their passcode → server `Hash::check()`s it against every active admin/manager → the single match is recorded as `voided_by`
    - Refund approval/rejection → same passcode-only lookup → `approved_by` set to the matching manager/admin user id
    - The passcode alone identifies the approver (no `approver_id`), so it must be unique among active managers/admins; cashiers and inactive users never match
- POS Sanctum tokens never expire on their own (`config/sanctum.php` `expiration` is `null`) — a terminal logs in once and stays signed in. A manager/admin revokes a device from Employee Management → Devices (`/users/{id}/sessions`); the revoked token is rejected on its very next request. Not available for admin accounts
- `POST /auth/login` accepts an optional `scope: "kds"`, issuing a token restricted to the `kds:read`/`kds:complete` abilities instead of full access (`*`) — for a kitchen display tablet, so a lost/stolen device can't touch payments, tickets or refunds. Every other route requires the `full-access` ability, which an unscoped login always has (Sanctum's default). Revoked the same way as any other device
- Roles: `admin` · `manager` · `cashier`

---

## Schema Changes from v1.0 → v1.1

| Table        | Change                                                                        |
| ------------ | ----------------------------------------------------------------------------- |
| `users`      | `email` → `username` (unique); `password_hash` → `password`; added `passcode` |
| `categories` | Removed `icon_url`, `display_order`; added `is_visible_to_pos` boolean        |
| `items`      | Status enum changed: `active/inactive` → `available/unavailable/hidden`       |
| `modifiers`  | Removed `price_modifier`, `display_order`                                     |

---

## Item Status Semantics

- `available` — shown and orderable on POS
- `unavailable` — shown on POS but greyed out (cannot be ordered)
- `hidden` — not shown on POS at all; visible in back office only

---

## Development Priority

1. **Back office** (Laravel Inertia React) — done, except item image upload (only an `image_url` string) and the stats/report pages below
    - Auth (login page), users, categories, items, modifier groups/modifiers, ingredient groups/ingredients, recipes
2. **POS API** (`/api/v1`, Sanctum) — done: auth, menu, shifts, shift transactions, tickets (create/add/void/discount/merge/cancel), payments, receipts, refunds, KDS feed + completion
    - Still missing: server-enforced terminal isolation
    - Known issues: none outstanding. `DELETE /shifts/{shift}/transactions/{transaction}` intentionally has no manager/admin gate (create/update do) — any authenticated staff may remove a mistaken cash addition or expense entry
    - Deleting a category/item/modifier/modifier group/ingredient/ingredient group that's referenced by sales history or a recipe is blocked at the database level (`restrictOnDelete()`); the back office catches the resulting `RecordInUseException` (`App\Services\Concerns\DeletesSafely`) and flashes a readable error instead of a raw `500`
3. Back-office shift, orders/receipts, refunds and dashboard pages (planned)
4. React Native POS app (planned)
5. KDS app — API, completion persistence and realtime channel done (above); the tablet display itself is planned
6. Real-time — done for the KDS channel (Laravel Reverb); general POS/back-office sync (`shift.{shift_id}`, `inventory.updated`, `refund.*`) still planned

See `docs/UNIFIED_API_ENDPOINTS.md` §13 and `docs/ENHANCED_SPEC.md` §11 for the detailed status.

---

## Locked Decisions (v1)

- No offline queue — real-time only
- No complex tax logic
- KDS completion is persisted (`ticket_items.completed_at`), toggled via the KDS API, broadcast on toggle, and cleared nightly — not retained as audit/historical data (supersedes the original "UI-only, no DB timestamp" plan)
- No per-modifier cost tracking
- Margin calc on frontend: `(base_price - cost_price) / base_price * 100`
- WebSockets via Laravel (Reverb) — not Node/Socket.io, not `beyondcode/laravel-websockets` (unmaintained)
- Remote access via Tailscale
- No offline queue; current inventory behavior assumes real-time access

---

## References

- `docs/ENHANCED_SPEC.md` — Full feature specification
- `docs/UNIFIED_API_ENDPOINTS.md` — Planned REST API contract
- `docs/MERGED_DATABASE_SCHEMA.sql` — Reference SQL only; migrations are authoritative

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.2. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
