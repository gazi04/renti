# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`rent-car-saas` (product name **Renti**) is a **multi-tenant, white-label car-rental SaaS** for the Kosovo / Western Balkans market. Car-rental businesses ("operators") subscribe to get their own branded booking website on a subdomain (`operatorname.yourdomain.com`); their customers book cars there with no account required. The platform owner ("Super Admin") onboards operators, charges a monthly subscription, and never touches the money that flows between operators and their customers.

The full spec lives in **`docs/RentACar_Application_Plan.pdf`** (17 pages) — read it before any domain work, for *feature intent* only (see version note below). **`docs/00-roadmap.md`** is the docs index. **`docs/summary.md`** holds what's still open (security, deploy checks, known gaps, unbuilt features), one line per built feature with its key decision, and the security decisions code comments cite (the per-step plan docs and old audits were retired 2026-10-06; originals in `~/Documents/projects/docs-backup-2026-10-06.tar.gz`). `docs/operator-feature-report.md` tracks the post-MVP operator backlog; `docs/bug-report-2026-10-05.md` is the current bug list.

**Status (2026-07-04):** The rent-car domain is **implemented**, not scaffolding — Steps 1–14 of the roadmap are done (multi-tenancy, admin dashboard, operator panel, fleet, booking engine, public site, booking management, notifications, rental-agreement PDF, white-label branding, billing, reports/localization, custom plans, AI features), plus most of the operator backlog (dashboard home, customer directory, staff accounts, custom templates, promo codes, vehicle maintenance tracking) and part of the admin-ops backlog (impersonation, manual subscription controls, audit log). Two Filament panels are live: `admin.yourdomain.com` (Super Admin) and `operatorname.yourdomain.com/dashboard` (operator, tenant-scoped). See `docs/summary.md` §1 for exactly what's still open.

> **Ignore the PDF's stack versions — use the latest.** The plan PDF names Laravel 11 / Livewire 3 / Filament 3; the installed versions in this repo (PHP 8.5.7 · Laravel 13.17 · Livewire 4.3 · **Filament 5.6** · Flux UI 2.15 · Tailwind CSS 4.3 — see Boost foundational context below) are the source of truth. Use the PDF only for *domain and feature* intent, never for stack/version decisions. Also installed (see `composer.json`/`composer.lock`): `stancl/tenancy` 3.10, `barryvdh/laravel-dompdf` 3.1, `resend/resend-laravel` 1.4, `spatie/laravel-medialibrary` 11.23, `saade/filament-fullcalendar` 4.0.0-beta7, `openai-php/laravel` 0.20, `spatie/laravel-activitylog` 5.0, and `stechstudio/filament-impersonate` 5.5. **Observability & test tooling** (added 2026-07-28): `sentry/sentry-laravel` 4.27 (error monitoring — DSN blank locally, PII deliberately withheld, see `config/sentry.php`), `laravel/pulse` 1.7 (performance dashboard, pinned to the admin host — see `config/pulse.php` for why that pinning is not optional), `pestphp/pest-plugin-browser` 4.3 (added 2026-07-29, Playwright-based) — a real-Chromium `Browser` test suite in `tests/Browser`, covering what `PublicBookingTest.php`'s `Livewire::test(...)` calls structurally cannot: real flatpickr rendering/interaction on the listing filter and the booking wizard's date picker, a full wire:click-driven multi-step walkthrough, and the storefront AI concierge's Alpine-driven open/close. Kept out of `composer test` (same carve-out as the `Postgres` suite) — run it with `composer test:browser`. Needs `ext-sockets` loaded locally (`/etc/php/conf.d/sockets.ini` on this machine's Arch PHP build) and Playwright's own Chromium (`npx playwright install chromium`; **not** `--with-deps` locally — that flag shells out to `apt-get`, which doesn't exist on Arch; CI's `browser` job uses it fine since it runs on `ubuntu-latest`). `Model::preventLazyLoading()` is on outside production, so a missing eager load is a test failure. **Laravel Cashier/Stripe was evaluated and rejected** — Stripe doesn't support payouts to Kosovo, so B2B billing (Step 11) is manual (admin-recorded cash/bank transfer), not gateway-driven; see `docs/summary.md` §2. Get approval before adding any new dependency.

## Product / Domain Model

**Three roles:** Super Admin (platform owner, `admin.yourdomain.com`, separate auth guard, `users.tenant_id = NULL`, `role = admin`) · Operator (subscribing rental business, `operatorname.yourdomain.com/dashboard`; owner role `operator` or front-desk `staff`) · Customer (renter, public subdomain, no login).

**Five modules, all implemented (see `docs/summary.md` §1 for what's still open):**
1. **Public Booking Website** — white-label per operator (logo/colors/font/footer/per-page layout via `tenant_settings` key-value table → CSS variables injected in Blade layout). Vehicle listing + filters, 3-step booking flow (dates → details → review), no account, promo codes. Bookings created as `pending`. Bilingual (Albanian/English).
2. **Operator Dashboard** — fleet management (vehicle CRUD, photos→WebP, custom JSONB fields, soft deletes, service/maintenance history), availability calendar, booking management (confirm/reject/active/complete/cancel), pricing (hourly/daily/weekly/monthly + promo codes + discounts), rental-agreement PDF per booking, dashboard notifications, reports + CSV export, customer directory, staff sub-accounts, custom contract/email templates, customer review collection/moderation/showcase, three OpenAI-backed features (listing writer, business summary, pricing suggestions).
3. **Admin Dashboard** (Filament 5) — tenant management, approve/reject/suspend/impersonate operators, manual subscription controls (extend period, change plan, extend trial), custom plan/feature-gate editor, revenue-this-month + at-risk-tenants widgets, audit log. Still planned: AI usage/cost tracking, a tenant detail/overview page, queue health, email delivery log, global search.
4. **Billing & Subscriptions** — **manual B2B billing**, no payment gateway: operator subscriptions (Trial/Basic €15/Standard €29/Pro €49), admin-recorded payments (`TenantPayment`) advance `paid_until`, daily sweep drives renewal reminders → grace → auto-suspension. **Two strictly separate payment flows:** B2B (operator→platform, manual, admin-recorded) and B2C (customer→operator, cash/bank transfer, platform never touches it).
5. **Notifications & Emails** (Resend) — queued Blade-template Mailables, bilingual, per-operator branding on customer-facing emails, Filament DB-bell notifications.

**Multi-tenancy:** Single shared database (PostgreSQL in production; SQLite `:memory:` for the fast test suite, plus a `tests/Postgres` suite for lock/migration fidelity), single codebase, `stancl/tenancy` single-DB mode. `tenant_id` on every tenant-scoped table; middleware resolves tenant from subdomain and the `BelongsToTenant` trait's Eloquent **global scope** auto-filters all queries. Availability conflict checks run server-side inside a DB transaction with a row-level lock on the vehicle (double-booking destroys operator trust) — proven against real Postgres locking in CI, not just SQLite. Core tables: `tenants`, `tenant_settings`, `plans`, `vehicles` (+ Spatie `media` for photos), `bookings`, `blocked_dates`, `contracts`, `promo_codes`, `service_records`, `customers`, `tenant_payments`, `activity_log`, `reviews` (+ Laravel `notifications`/`jobs`/`failed_jobs`).

## Commands

```bash
composer dev          # Run full dev stack: serve + queue:listen + pail logs + vite (concurrently)
composer setup        # First-time setup: install, .env, key:gen, migrate, npm install + build
npm run dev           # Vite dev server only
npm run build         # Build frontend assets (run if UI changes don't appear)

composer test         # Full CI gate: config:clear + pint --test + phpstan + artisan test --testsuite=Unit,Feature,Arch
php artisan test --compact                          # Run tests (Unit + Feature, SQLite)
php artisan test --compact --filter=testName        # Run a single test by name
php artisan test --compact tests/Feature/Auth/AuthenticationTest.php  # Single file
php artisan test --testsuite=Postgres               # Lock/migration fidelity suite (needs a real Postgres connection; CI-only, not part of `composer test`)

composer lint         # Fix code style (pint --parallel)
vendor/bin/pint --dirty --format agent              # Format only changed files (run before finalizing)
composer types:check  # Static analysis (phpstan/larastan, level in phpstan.neon)
```

Tests use SQLite `:memory:` (see `phpunit.xml`), plus a separate `tests/Postgres` suite (`php artisan test --testsuite=Postgres`) for lock/migration behavior SQLite can't exercise. The dev/production DB is **PostgreSQL** (`DB_CONNECTION=pgsql` in `.env`) — the app is Postgres-only by design. `database/database.sqlite` is an unused leftover from initial scaffolding.

## Architecture

- **Routing is Livewire-first.** Pages are registered with `Route::livewire('path', 'pages::dir.name')` in `routes/web.php`, not via controllers. There is effectively one real controller (`app/Http/Controllers/Controller.php`, base only).
- **Livewire 4 single-file components (SFC).** Page components live in `resources/views/pages/**`. The `pages::` route namespace maps to this directory. Auth pages (`pages/auth/*`) follow this convention.
- **Authentication via Fortify**, customized through the action class `app/Actions/Fortify/ResetUserPassword.php` and `app/Providers/FortifyServiceProvider.php`. Password validation rules are shared via `app/Concerns/PasswordValidationRules.php`. Logout is a Livewire action: `app/Livewire/Actions/Logout.php`. Only `resetPasswords` + `emailVerification` Fortify features are enabled — central registration is replaced by the operator self-signup Livewire page (`routes/web.php` "signup"). Post-verification routing is `app/Http/Responses/VerifyEmailResponse.php`.
- **2FA + Passkeys were removed** with the Laravel starter kit (2026-08) — they had no enrolment UI outside the deleted central account-settings pages, and neither Filament panel exposes one. The `users` two-factor columns, the `passkeys` table, and the `TwoFactorAuthenticatable`/`PasskeyAuthenticatable` traits on `User` are left in place but dormant; re-enabling means flipping the `config/fortify.php` feature flags and building a real UI.
- **UI is Flux UI (free tier)** + Tailwind v4. Custom Flux overrides and icons live in `resources/views/flux/`. Layouts in `resources/views/layouts/` (auth card/simple/split variants, plus `public` for the storefront).
- **Two Filament 5 panels**, registered as separate `PanelProvider`s: the central **admin** panel (`app/Filament/Resources/**`, no `App\Filament\Admin` namespace despite the name) and the tenant-scoped **operator** panel (`app/Filament/Operator/**`). Both gate access via `User::canAccessPanel()` (role + `tenant_id` match).
- **Tenancy is `stancl/tenancy` single-database mode.** Tenant resolved from subdomain; every tenant-owned model uses the `BelongsToTenant` trait (installs the auto-filtering global scope) — `User` and `Tenant` itself are the central exceptions. Background jobs dispatched from a central command (e.g. `ProcessVehicleMaintenanceJob`, `GenerateBusinessSummaryJob`) must call `tenancy()->initialize($tenant)`/`tenancy()->end()` themselves in a `try/finally`, since the queue's `QueueTenancyBootstrapper` doesn't do it for jobs fanned out this way.

## Skills

Domain skills live under `**/skills/**` and the project mandates activating the relevant one before working in that domain (Livewire, Flux UI, Fortify, Pest, Tailwind, Laravel best practices). See the available-skills list.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

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

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

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

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

=== filament/filament/core rules ===

## Filament

- Filament is a Laravel UI framework built on Livewire, Alpine.js, and Tailwind CSS. UIs are defined in PHP via fluent, chainable components. Follow existing conventions in this app.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Inspect required options before running, and always pass `--no-interaction`.

### Patterns

Always use static `make()` methods to initialize components. Most configuration methods accept a `Closure` for dynamic values.

Use `Get $get` to read other form field values for conditional logic:

<code-snippet name="Conditional form field visibility" lang="php">
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

Select::make('type')
    ->options(CompanyType::class)
    ->required()
    ->live(),

TextInput::make('company_name')
    ->required()
    ->visible(fn (Get $get): bool => $get('type') === 'business'),

</code-snippet>

Use `Set $set` inside `->afterStateUpdated()` on a `->live()` field to mutate another field reactively. Prefer `->live(onBlur: true)` on text inputs to avoid per-keystroke updates:

<code-snippet name="Reactive field update" lang="php">
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

TextInput::make('title')
    ->required()
    ->live(onBlur: true)
    ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
        'slug',
        Str::slug($state ?? ''),
    )),

TextInput::make('slug')
    ->required(),

</code-snippet>

Compose layout by nesting `Section` and `Grid`. Children need explicit `->columnSpan()` or `->columnSpanFull()`:

<code-snippet name="Section and Grid layout" lang="php">
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

Section::make('Details')
    ->schema([
        Grid::make(2)->schema([
            TextInput::make('first_name')
                ->columnSpan(1),
            TextInput::make('last_name')
                ->columnSpan(1),
            TextInput::make('bio')
                ->columnSpanFull(),
        ]),
    ]),

</code-snippet>

Use `Repeater` for inline `HasMany` management. `->relationship()` with no args binds to the relationship matching the field name:

<code-snippet name="Repeater for HasMany" lang="php">
use Filament\Forms\Components\Repeater;

Repeater::make('qualifications')
    ->relationship()
    ->schema([
        TextInput::make('institution')
            ->required(),
        TextInput::make('qualification')
            ->required(),
    ])
    ->columns(2),

</code-snippet>

Use `state()` with a `Closure` to compute derived column values:

<code-snippet name="Computed table column value" lang="php">
use Filament\Tables\Columns\TextColumn;

TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),

</code-snippet>

Use `SelectFilter` for enum or relationship filters, and `Filter` with a `->query()` closure for custom logic:

<code-snippet name="Table filters" lang="php">
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

SelectFilter::make('status')
    ->options(UserStatus::class),

SelectFilter::make('author')
    ->relationship('author', 'name'),

Filter::make('verified')
    ->query(fn (Builder $query) => $query->whereNotNull('email_verified_at')),

</code-snippet>

Actions are buttons that encapsulate optional modal forms and behavior:

<code-snippet name="Action with modal form" lang="php">
use Filament\Actions\Action;

Action::make('updateEmail')
    ->schema([
        TextInput::make('email')
            ->email()
            ->required(),
    ])
    ->action(fn (array $data, User $record) => $record->update($data)),

</code-snippet>

### Testing

Testing setup (requires `pestphp/pest-plugin-livewire` in `composer.json`):

- Always call `$this->actingAs(User::factory()->create())` before testing panel functionality.
- For edit pages, pass `['record' => $user->id]`, use `->call('save')` (not `->call('create')`), and do not assert `->assertRedirect()` (edit pages do not redirect after save).

<code-snippet name="Table test" lang="php">
use function Pest\Livewire\livewire;

livewire(ListUsers::class)
    ->assertCanSeeTableRecords($users)
    ->searchTable($users->first()->name)
    ->assertCanSeeTableRecords($users->take(1))
    ->assertCanNotSeeTableRecords($users->skip(1));

</code-snippet>

<code-snippet name="Create resource test" lang="php">
use function Pest\Laravel\assertDatabaseHas;

livewire(CreateUser::class)
    ->fillForm([
        'name' => 'Test',
        'email' => 'test@example.com',
    ])
    ->call('create')
    ->assertNotified()
    ->assertHasNoFormErrors()
    ->assertRedirect();

assertDatabaseHas(User::class, [
    'name' => 'Test',
    'email' => 'test@example.com',
]);

</code-snippet>

<code-snippet name="Edit resource test" lang="php">
livewire(EditUser::class, ['record' => $user->id])
    ->fillForm(['name' => 'Updated'])
    ->call('save')
    ->assertNotified()
    ->assertHasNoFormErrors();

assertDatabaseHas(User::class, [
    'id' => $user->id,
    'name' => 'Updated',
]);

</code-snippet>

<code-snippet name="Testing validation" lang="php">
livewire(CreateUser::class)
    ->fillForm([
        'name' => null,
        'email' => 'invalid-email',
    ])
    ->call('create')
    ->assertHasFormErrors([
        'name' => 'required',
        'email' => 'email',
    ])
    ->assertNotNotified();

</code-snippet>

Use `->callAction(DeleteAction::class)` for page actions, or `->callAction(TestAction::make('name')->table($record))` for table actions:

<code-snippet name="Calling actions" lang="php">
use Filament\Actions\Testing\TestAction;

livewire(ListUsers::class)
    ->callAction(TestAction::make('promote')->table($user), [
        'role' => 'admin',
    ])
    ->assertNotified();

</code-snippet>

### Correct Namespaces

- Form fields (`TextInput`, `Select`, `Repeater`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Table columns (`TextColumn`, `IconColumn`, etc.): `Filament\Tables\Columns\`
- Table filters (`SelectFilter`, `Filter`, etc.): `Filament\Tables\Filters\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, `Fieldset`, and `Repeater` do not span all columns by default.
- **Use `Select::make('author_id')->relationship('author', 'name')` for BelongsTo fields.** `BelongsToSelect` does not exist in v4.
- **`Repeater` uses `->schema()`, not `->fields()`.**
- **Never add `->dehydrated(false)` to fields that need to be saved.** It strips the value from form state before `->action()` or the save handler runs. Only use it for helper/UI-only fields.
- **Use correct property types when overriding `Page`, `Resource`, and `Widget` properties.** These properties have union types or changed modifiers that must be preserved:
  - `$navigationIcon`: `protected static string | BackedEnum | null` (not `?string`)
  - `$navigationGroup`: `protected static string | UnitEnum | null` (not `?string`)
  - `$view`: `protected string` (not `protected static string`) on `Page` and `Widget` classes

=== spatie/laravel-activitylog/core rules ===

# spatie/laravel-activitylog

Activity logging package for Laravel. Logs model events and manual activities to a database table.

## Key Concepts

- **Activity**: An Eloquent model (`Spatie\Activitylog\Models\Activity`) storing log entries with subject, causer, event, attribute_changes, and properties.
- **Subject**: The model being acted upon (polymorphic `subject_type`/`subject_id`).
- **Causer**: The model that caused the action, typically the authenticated user (polymorphic `causer_type`/`causer_id`).
- **LogOptions**: Fluent configuration object returned by `getActivitylogOptions()` on models using the `LogsActivity` trait.
- **ActivityEvent**: Enum with cases `Created`, `Updated`, `Deleted`, `Restored`.
- **`attribute_changes`** column: stores `{"attributes": {...}, "old": {...}}` for tracked model changes.
- **`properties`** column: stores custom user data set via `withProperties()`.

## Traits

### `LogsActivity`

Add to models to automatically log create/update/delete events. Optionally implement `getActivitylogOptions()` to configure which attributes to track (defaults to logging events without attribute changes).

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Article extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

### `CausesActivity`

Add to user/causer models. Provides `activitiesAsCauser()` relationship.

### `HasActivity`

Combines `LogsActivity` and `CausesActivity`. Provides `activities()`, `activitiesAsSubject()`, and `activitiesAsCauser()`.

## Manual Logging

```php
activity()
    ->performedOn($article)
    ->causedBy($user)
    ->event(ActivityEvent::Updated)
    ->withProperties(['key' => 'value'])
    ->log('Article was updated');
```

## LogOptions Methods

| Method | Description |
|--------|-------------|
| `logFillable()` | Log all fillable attributes |
| `logAll()` | Log all attributes |
| `logOnly(array)` | Log specific attributes |
| `logExcept(array)` | Exclude attributes |
| `logOnlyDirty()` | Only log changed attributes |
| `dontLogEmptyChanges()` | Skip logging when no tracked attributes changed |
| `dontLogIfAttributesChangedOnly(array)` | Ignore updates that only change these attributes |
| `useLogName(string)` | Set custom log name |
| `setDescriptionForEvent(Closure)` | Custom description per event |
| `useAttributeRawValues(array)` | Store raw (uncast) values |

## Querying Activities

```php
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Enums\ActivityEvent;

Activity::forEvent(ActivityEvent::Created)->get();
Activity::causedBy($user)->get();
Activity::forSubject($article)->get();
Activity::inLog('orders')->get();
```

## Setting the causer

Override the causer for a block of code:

```php
use Spatie\Activitylog\Facades\Activity;

Activity::defaultCauser($admin, function () {
    // all activities here are caused by $admin
});

// or set globally for the rest of the request
Activity::defaultCauser($admin);
```

## Disabling Logging

```php
activity()->withoutLogging(function () {
    // no activities logged here
});
```

## Accessing Changes and Properties

```php
$activity = Activity::latest()->first();

// Tracked model changes (set automatically by LogsActivity)
$activity->attribute_changes; // Collection: {"attributes": {...}, "old": {...}}

// Custom user data (set via withProperties)
$activity->properties; // Collection
$activity->getProperty('key'); // single value
```

## Custom Activity Model

Set `activity_model` in `config/activitylog.php` to a class that extends `Model` and implements `Spatie\Activitylog\Contracts\Activity`. Use a custom model for custom table names or database connections.

## Customizing Actions

The package uses action classes (`LogActivityAction`, `CleanActivityLogAction`) that can be extended and swapped via config:

```php
// config/activitylog.php
'actions' => [
    'log_activity' => \App\Actions\CustomLogActivityAction::class,
    'clean_log' => \App\Actions\CustomCleanAction::class,
],
```

Custom action classes must extend the originals. Override protected methods (`save()`, `beforeActivityLogged()`, `resolveDescription()`, etc.) to customize behavior.

## Configuration

Key config options in `config/activitylog.php`:
- `enabled`: Master on/off switch (env: `ACTIVITYLOG_ENABLED`)
- `clean_after_days`: Days to keep records for `activitylog:clean` command
- `default_log_name`: Default log name (string)
- `default_auth_driver`: Auth driver for causer resolution
- `include_soft_deleted_subjects`: Include soft-deleted subjects
- `activity_model`: Custom Activity model class
- `default_except_attributes`: Globally excluded attributes
- `actions.log_activity`: Action class for logging activities
- `actions.clean_log`: Action class for cleaning old activities

=== spatie/laravel-medialibrary/core rules ===

## Media Library

- `spatie/laravel-medialibrary` associates files with Eloquent models, with support for collections, conversions, and responsive images.
- Always activate the `medialibrary-development` skill when working with media uploads, conversions, collections, responsive images, or any code that uses the `HasMedia` interface or `InteractsWithMedia` trait.

</laravel-boost-guidelines>
