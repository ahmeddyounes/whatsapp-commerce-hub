# WhatsApp Commerce Hub — Architecture Improvement Plan

Date: 2026-01-18  
Scope: `whatsapp-commerce-hub.php`, `includes/`, `docs/`, `tests/`  
Goal: Make the plugin’s architecture coherent, modular, testable, and safe to extend without regressions.

---

## 1) Executive Summary

The codebase contains **two partially overlapping architectures**:

- A newer, namespaced, DI-container + service-provider architecture (`includes/Container`, `includes/Providers`, `includes/Application`, etc.).
- Legacy/older patterns and docs (WCH_* classes, option structures, bootstrap expectations) that are still referenced in runtime code and documentation.

This creates runtime risk (broken references, inconsistent settings keys, missing tables), high cognitive load, and makes extension/testing harder than it needs to be.

This plan prioritizes:

1) **Boot reliability** and single source of truth for configuration and initialization.  
2) **Clear boundaries** (Domain / Application / Infrastructure / Presentation) and removing duplication.  
3) **Feature modularity** with explicit contracts and stable extension points.  
4) **Guardrails** (static analysis, tests, docs) that prevent architectural drift from returning.

---

## 2) Current Architecture Snapshot (What’s Here Today)

### 2.1 Bootstrap & DI

- Entry point: `whatsapp-commerce-hub.php`
  - Defines a custom PSR-4 autoloader for `WhatsAppCommerceHub\*`.
  - Defines global helpers `wch_get_container()` and `wch()`.
  - Registers a hard-coded provider list (order-sensitive).
  - Instantiates a legacy-ish `WhatsAppCommerceHubPlugin` singleton that also performs initialization work.

- DI container: `includes/Container/Container.php`
  - Auto-wiring via reflection, bindings/singletons, aliases, decorators (`extend()`), `call()`, circular dependency detection.
  - Service provider model: `includes/Container/ServiceProviderInterface.php` and multiple providers in `includes/Providers/`.

### 2.2 Layers & Major Subsystems

- Contracts: `includes/Contracts/**` (interfaces for services, repositories, admin, checkout, etc.).
- Application services: `includes/Application/**` (use cases/workflows, e.g. Checkout, ProductSync, Reengagement).
- Domain models: `includes/Domain/**` (Cart, Conversation, Customer, Catalog).
- Entities/ValueObjects: `includes/Entities/**`, `includes/ValueObjects/**` (overlaps with Domain concepts).
- Infrastructure: `includes/Infrastructure/**` (SettingsManager, DatabaseManager, QueueManager, etc.), plus infrastructure-like code in top-level folders:
  - Clients: `includes/Clients/**` (WhatsAppApiClient, OpenAIClient, HttpClient).
  - Repositories: `includes/Repositories/**` (wpdb-backed repositories).
  - Queue: `includes/Queue/**` (PriorityQueue, processors, DLQ, idempotency).
  - Security: `includes/Security/**` (RateLimiter, SecureVault, PIIEncryptor).
  - Resilience: `includes/Resilience/**` (CircuitBreaker, RetryPolicy, etc.).
- Presentation/Admin: `includes/Presentation/**` and also `includes/Admin/**` (two separate admin/UI “homes”).

### 2.3 Empty/Placeholder Directories (Symptoms of an Incomplete Migration)

These exist but are empty, making the intended target architecture unclear:

- `includes/Infrastructure/Api/Clients`
- `includes/Infrastructure/Api/Rest/Controllers`
- `includes/Infrastructure/Database/Migrations`
- `includes/Infrastructure/Database/Repositories`
- `includes/Infrastructure/Persistence`
- `includes/Features/Broadcasts`, `includes/Features/Notifications`, `includes/Features/Reengagement`, `includes/Features/Payments/Gateways`
- `includes/Presentation/Actions`, `includes/Presentation/Admin/Settings`
- `includes/Support/Validation`
- `includes/Legacy`

---

## 3) Key Architectural Pain Points (What’s Breaking/Costly)

### 3.1 Bootstrap has duplicated, inconsistent responsibilities

- `whatsapp-commerce-hub.php` both:
  - boots the container + providers, *and*
  - later runs a `WhatsAppCommerceHubPlugin` singleton that re-initializes things.
- The singleton also references namespaces that are currently not implemented (e.g. `WhatsAppCommerceHub\Features\Payments\RefundService`, `...Features\Notifications\OrderNotifications`, `...Features\Reengagement\ReengagementService`), which is a hard runtime failure risk.

### 3.2 Settings are not a single source of truth

- `includes/Infrastructure/Configuration/SettingsManager.php` and `includes/Application/Services/SettingsService.php` use **sectioned keys** like `api.whatsapp_phone_number_id`.
- `includes/Providers/CoreServiceProvider.php` exposes `wch.settings` as a **flat array** with keys like `phone_number_id`, which does not match the actual settings schema/docs.
- Some code reads settings via:
  - `SettingsInterface` (good),
  - `SettingsManager` directly (ok),
  - `wch.settings` / `wch.setting` (inconsistent),
  - direct `get_option()` calls (hard to audit).

### 3.3 Multiple queue abstractions overlap (and payload shapes drift)

There are at least two competing “queue systems”:

- `includes/Queue/**` (PriorityQueue + processors + DLQ + idempotency), with a wrapped payload format (`PriorityQueue::wrapPayload()` / `unwrapPayload()`).
- `includes/Infrastructure/Queue/**` (QueueManager, JobDispatcher, SyncJobHandler) using different conventions and hook signatures.

This increases the chance of:

- jobs being scheduled with the “wrong” args shape,
- action callbacks having incompatible parameter types,
- retries/idempotency being inconsistently applied.

### 3.4 Persistence & schema drift

- `includes/Infrastructure/Database/DatabaseManager.php` creates several tables, but multiple runtime components expect tables that are not created anywhere (examples: rate limiting, security log, payment webhook event storage).
- `includes/Infrastructure/Database/Migrations` is currently empty, so schema evolution is not clearly implemented as versioned migrations.

### 3.5 Domain/Entity duplication blurs boundaries

There are overlapping representations for core concepts (Cart, Customer, Conversation, Intent, Context):

- `includes/Domain/**`
- `includes/Entities/**`
- `includes/ValueObjects/**`

This makes it difficult to answer:

- which representation is canonical,
- what is safe to use in application services,
- what can depend on WordPress, persistence, or hooks.

### 3.6 DI is inconsistently applied (service locator & singletons)

Patterns currently used side-by-side:

- constructor injection through the container (good),
- global service lookup (`wch()`),
- static singleton access (`Logger::instance()`),
- optional constructor params + “fallback to singleton”.

This makes services harder to test, and makes runtime order/boot timing fragile.

### 3.7 Docs & tests describe a different codebase

Multiple docs and tests refer to legacy WCH_* classes and file paths that no longer exist (e.g. `includes/class-wch-catalog-browser.php`).

Result: new contributors (and future-you) cannot trust the docs/tests to reflect reality.

---

## 4) Target Architecture (What “Good” Looks Like)

### 4.1 Architectural principles

1) **One bootstrap path**: providers own initialization; no duplicate initialization in a separate singleton.
2) **Contracts-first dependencies**: application code depends on interfaces, not on WordPress/global functions.
3) **Stable feature modules**: each major feature has a single entrypoint (provider/module class), clear API, and explicit dependencies.
4) **Single settings API**: `SettingsInterface` (or equivalent) is the only way application/infrastructure reads configuration.
5) **One async pipeline**: a single queue abstraction, a single payload format, and consistent job registration.
6) **Schema is versioned**: all tables are defined in migrations; “features that use a table” own its migration.
7) **Guardrails prevent drift**: lint, PHPStan, tests, and architecture checks run in CI.

### 4.2 Proposed high-level structure (incremental, not a big-bang move)

Keep `includes/` as the PSR-4 root, but enforce a clearer meaning:

- `Core/` — cross-cutting infrastructure (logging, error handling, time/request IDs)
- `Contracts/` — public interfaces (internal + extension API)
- `Domain/` — business rules (no WordPress functions, no wpdb, no hooks)
- `Application/` — use cases/workflows (depends on Domain + Contracts only)
- `Infrastructure/` — adapters (WordPress, wpdb, Action Scheduler, HTTP, encryption)
- `Presentation/` — REST controllers + admin pages + templates
- `Providers/` — module composition (wiring + registering hooks)

Where feature boundaries are needed, use a “feature namespace” *inside* layers (not necessarily a folder move on day 1):

- `Application/Services/ProductSync/*`
- `Infrastructure/Api/WhatsApp/*` (eventually move `includes/Clients/WhatsAppApiClient.php` here)
- `Presentation/Admin/Pages/*`
- `Queue/Processors/*` (or move to `Infrastructure/Queue/Processors/*`, but pick one)

---

## 5) Roadmap (Phased, Safe, Measurable)

### Phase 0 — Boot & correctness stabilization (high priority)

Objective: “plugin can boot in all contexts with one consistent initialization path.”

- Bootstrap consolidation
  - Remove or drastically reduce `WhatsAppCommerceHubPlugin` responsibilities in `whatsapp-commerce-hub.php`; make providers the single place for init/boot.
  - Ensure no provider is booted before requirements checks pass (PHP/WP/WC).
  - Ensure error handling does not inadvertently force full container boot too early.
- Fix namespace/reference drift in bootstrap
  - Replace calls to missing classes under `WhatsAppCommerceHub\Features\*` with the actual services/providers currently implemented.
- Settings single source of truth
  - Make `SettingsInterface`/`SettingsService` the canonical read path.
  - Deprecate `wch.settings` and `wch.setting`, or re-implement them as read-only adapters that map to the new schema (to reduce breakage during migration).
  - Update providers that still read the flat `wch.settings` keys (notably API clients).
- Database schema completeness
  - Inventory all tables used across the codebase and ensure they exist via versioned migrations.
  - Add missing tables used by runtime components (rate limiting, security log, payment webhook idempotency).
- Queue payload compatibility
  - Choose a single queue abstraction and payload format (recommended: `includes/Queue/PriorityQueue` + processors).
  - Audit all Action Scheduler hooks to ensure callback signatures match actual scheduled payload format.

Deliverables:
- A single "boot path" diagram in docs.
- A migration list of required tables with ownership.
- A settings schema document that matches the code.
- ✅ ADR-001: Canonical Model Layer decision documented

### Phase 1 — Remove duplication and define canonical models

Objective: “clear boundaries and canonical representations for core concepts.”

- Pick canonical models for Cart/Customer/Conversation/Intent/Context
  - Either:
    - keep `Domain/*` as canonical and treat `Entities/*` as persistence DTOs, or
    - consolidate by moving/aliasing and deleting one set.
- Enforce dependency rule (lightweight at first)
  - Domain must not call WordPress functions (`do_action`, `get_option`, `wpdb`, etc.).
  - Infrastructure owns WordPress-specific glue.
- Normalize logging interface usage
  - Internal code uses `LoggerInterface` with consistent `(message, context, data)` signature.
  - Remove ad-hoc “anonymous logger” variants where possible.

Deliverables:
- ✅ "Canonical model" decisions documented in ADR-001 (see `docs/adr-001-canonical-model-layer.md`)
- A small PHPStan rule set that forbids WordPress calls in Domain.

### Phase 2 — Feature modularity & explicit dependencies

Objective: “feature modules are independently understandable and extensible.”

- Define a module boundary for each major subsystem:
  - Webhooks + inbound processing
  - Conversations + FSM/actions
  - Catalog browsing
  - Product sync
  - Checkout
  - Payments + webhooks/refunds
  - Broadcasts
  - Reengagement
  - Admin UI (pages + ajax)
  - Monitoring/Health
  - Security
- For each module:
  - define its public contracts,
  - define its provider entrypoint,
  - define its DB ownership (tables/migrations),
  - document its hooks/filters.

Deliverables:
- A “module map” page in `docs/` describing each module’s entrypoint and contracts.

### Phase 3 — Container/provider improvements (order, safety, testability)

Objective: “providers can be added safely without fragile ordering bugs.”

- Provider ordering
  - Add optional provider dependency metadata (e.g., `dependsOn(): array<class-string>`).
  - Topologically sort providers at boot, fail fast on cycles/missing deps.
- Replace global lookups inside providers/services
  - Avoid `wch()` within providers; use `$container` explicitly.
  - Avoid static singletons for services that are already container-managed.
- Context-aware boot
  - Register heavy subsystems only when relevant (admin vs REST vs cron).

Deliverables:
- Container boot diagnostics endpoint (or debug page) listing providers + bindings.

### Phase 4 — Async pipeline & eventing unification

Objective: “one job system; eventing is predictable; retries/idempotency are consistent.”

- Keep one async abstraction (PriorityQueue + processors recommended).
- Define job payload DTOs (even if they are simple arrays internally) with versioning.
- Ensure idempotency keys and DLQ reasons are consistent across processors.
- Standardize internal events:
  - WordPress hooks for extensibility at the edges,
  - internal EventBus for decoupling within the plugin,
  - avoid mixing “Entity Cart” vs “Domain Cart” in event contracts.

Deliverables:
- Job catalog in docs (hook name, payload schema, idempotency key, retry policy).

### Phase 5 — Presentation layer cleanup (REST + Admin)

Objective: “controllers/pages are thin; use cases live in Application.”

- REST controllers:
  - Make all REST controllers extend a shared base (like `includes/Controllers/AbstractController.php`) where appropriate.
  - Align auth/rate limiting/signature verification patterns.
- Admin:
  - Choose a single home for admin UI code (`includes/Presentation/Admin/*` vs `includes/Admin/*`) and migrate gradually.
  - Centralize enqueueing assets and REST nonce/API key handling.

### Phase 6 — Tests, tooling, and CI guardrails

Objective: “architecture stays healthy as the codebase grows.”

- Update/replace legacy WCH_* tests to target the current namespaced architecture.
- Add unit tests for:
  - Settings schema + encryption behavior,
  - queue payload wrapping/unwrapping,
  - provider boot order resolution,
  - critical processors (webhook ingestion happy-path).
- CI:
  - `composer test`, `composer analyze`, `composer lint` on pull requests.
  - Fail on new PHPStan issues (keep baseline but reduce over time).

### Phase 7 — Documentation & extension API modernization

Objective: “docs match code; add-on developers know the supported surface.”

- Rewrite `docs/extending.md` to use:
  - service providers/modules as extension mechanism,
  - current contracts and hook names,
  - current settings schema.
- Add an example add-on plugin in `examples/` demonstrating:
  - registering a provider via `wch_container_registered`,
  - adding an action handler,
  - adding a REST endpoint in a safe way.

---

## 6) Suggested “First PR” Slice (Low Risk, High Impact)

If you want a safe starting point that immediately reduces architectural risk:

1) Replace bootstrap references to missing `Features\*` classes with provider-driven initialization.  
2) Make `SettingsInterface` the only settings read path in providers that configure external clients.  
3) Add a schema checklist and migrate/create missing tables used by security/rate limiting/payment webhooks.  
4) Document the single boot path in `docs/` (and remove/update legacy docs that reference missing files).

---

## 7) Appendix: Files worth auditing early

- Bootstrap:
  - `whatsapp-commerce-hub.php`
- Provider wiring and duplicated initialization:
  - `includes/Providers/*`
- Settings:
  - `includes/Infrastructure/Configuration/SettingsManager.php`
  - `includes/Application/Services/SettingsService.php`
  - `includes/Providers/CoreServiceProvider.php`
  - `docs/configuration.md`
- Async processing:
  - `includes/Queue/*`
  - `includes/Infrastructure/Queue/*`
- Persistence:
  - `includes/Infrastructure/Database/DatabaseManager.php`
- Docs/tests drift:
  - `README-PLUGIN.md`, `CATALOG_BROWSER_USAGE.md`, `SETTINGS_DOCUMENTATION.md`
  - `tests/*`

