# CITIZENS Agro POS: Implementation Plan

> Companion to [ARCHITECTURE.md](ARCHITECTURE.md) · Version 1.1 · 2026-09-24
> Stack: Laravel (latest stable) · PHP 8.3+ · MySQL 8 · Blade + Tailwind + Alpine.js · Meilisearch · Reverb · Redis

This document turns the accepted architecture into buildable work: the project setup, the build order, and for every phase the migrations, models, actions, routes, Blade views, permissions, tests and acceptance criteria.

---

## Contents

1. [Engineering conventions](#1-engineering-conventions)
2. [Development environment](#2-development-environment)
3. [Packages](#3-packages)
4. [Permission catalogue](#4-permission-catalogue)
5. [Phase plan overview](#5-phase-plan-overview)
6. [Phase 0: Foundation](#phase-0-foundation)
7. [Phase 1: Catalog + Search](#phase-1-catalog--search)
8. [Phase 2: Inventory + Purchasing](#phase-2-inventory--purchasing)
9. [Phase 3: POS core + Counter invoices + Settlement + Live Billing + Handover](#phase-3-pos-core--counter-invoices--settlement--live-billing--handover)
10. [Phase 4: Customers, Credit, Returns, Quotations](#phase-4-customers-credit-returns-quotations)
11. [Phase 5: Finance & Banking](#phase-5-finance--banking)
12. [Phase 6: HR, Attendance, Payroll](#phase-6-hr-attendance-payroll)
13. [Phase 7: Reports, Dashboard, Go-live](#phase-7-reports-dashboard-go-live)
14. [Journal posting rules](#14-journal-posting-rules)
15. [Testing strategy](#15-testing-strategy)
16. [Deployment](#16-deployment)
17. [Go-live checklist](#17-go-live-checklist)
18. [Risks](#18-risks)

---

## 1. Engineering conventions

| Topic | Convention |
|---|---|
| Structure | Business logic in `app/Domain/{Module}/{Models,Actions,Events,Listeners,Policies,Data,Enums}`. HTTP in `app/Http/Controllers/{Module}`. |
| Actions | One class per use case with a single `handle()` / `execute()` method, e.g. `SettleInvoiceAction`. Wrap in `DB::transaction()`. Called from controllers, jobs and tests the same way. |
| Validation | Form Request per write endpoint (`app/Http/Requests/{Module}`). |
| Authorization | Policy per model + `authorize()` in controllers. `@can` in Blade only hides UI; the policy is the real check. |
| Money | `DECIMAL(15,2)`. Arithmetic in PHP through `brick/math` `BigDecimal` (never floats). A `Money` cast on models. |
| Quantity | `DECIMAL(14,3)`, always stored in the product's **base unit**. |
| Enums | PHP backed enums for statuses and types (`SaleStatus`, `MovementType` …), cast on models. |
| IDs | `bigIncrements`. Human-facing numbers (invoice, PO, GRN) come from `document_sequences`. |
| Deletes | Master data: soft deletes. Financial/stock documents: never deleted; cancelled or reversed with a new document. |
| Audit | `LogsActivity` trait on every model with sensitive fields. |
| Language | UI in English with `__()` everywhere; `lang/si` for receipts (and later the full UI if wanted). |
| Code quality | Laravel Pint (PSR-12 preset), Larastan level 6, Pest tests. CI must pass before merge. |
| Git | `main` (production), `develop`, feature branches `feature/<phase>-<short-name>`. Conventional commit messages. |
| Definition of Done | Code + migration + policy + Blade view + Pest tests + activity log where relevant + reviewed + works on the 80 mm printer when it prints. |

---

## 2. Development environment

**Windows developer machine:**
- **Laravel Herd** (or Laragon) for PHP 8.3+, Composer, Nginx.
- **MySQL 8** (Herd Pro / Laragon / standalone installer).
- **Docker Desktop** for Redis and Meilisearch:
  ```yaml
  # docker-compose.dev.yml
  services:
    redis:       { image: redis:7-alpine, ports: ["6379:6379"] }
    meilisearch: { image: getmeili/meilisearch:v1, ports: ["7700:7700"],
                   environment: { MEILI_MASTER_KEY: "dev-master-key" } }
  ```
- **Node 20+** for Vite and Tailwind.
- **Google Chrome** installed (used headless by spatie/laravel-pdf for A4 PDFs).
- **Chrome** for POS testing and receipt printing.
- Target printer model + cash drawer on the developer desk from Phase 0 (for the printing spike); at least 2 printers to test counter + main printing side by side.
- **QZ Tray** (free edition) on the machine playing the main cashier, for the drawer kick.

**`.env` essentials:**
```
APP_LOCALE=en
APP_TIMEZONE=Asia/Colombo
DB_CONNECTION=mysql
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
BROADCAST_CONNECTION=reverb
```

---

## 3. Packages

**Composer**

| Package | Purpose |
|---|---|
| `laravel/fortify` | Headless password login, password reset, 2FA (views are our own Blade files) |
| `spatie/laravel-permission` | Roles and permissions |
| `spatie/laravel-activitylog` | Audit trail |
| `laravel/scout`, `meilisearch/meilisearch-php`, `http-interop/http-factory-guzzle` | Product search |
| `laravel/reverb` | WebSockets (Live Billing, settlement list, live stock) |
| `spatie/laravel-pdf` + `chrome-php/chrome` | A4 PDFs with correct Sinhala shaping (chrome driver, no Node needed) |
| `maatwebsite/excel` | Product/stock import, report export |
| `spatie/laravel-backup` | Database + file backups |
| `brick/math` | Exact decimal arithmetic |
| dev: `pestphp/pest`, `pestphp/pest-plugin-laravel`, `larastan/larastan`, `laravel/pint`, `barryvdh/laravel-debugbar` | Quality and debugging |

**npm**

| Package | Purpose |
|---|---|
| `tailwindcss`, `@tailwindcss/forms`, `@tailwindcss/vite` | Styling |
| `alpinejs`, `@alpinejs/focus`, `@alpinejs/mask` | Interactivity, keyboard focus trapping, input masks |
| `laravel-echo`, `pusher-js` | Reverb client |
| `qz-tray` | JS client for QZ Tray (cash drawer kick on the main cashier PC) |

---

## 4. Permission catalogue

Seeded by `PermissionSeeder`. Names are `module.action`.

| Permission | Super Admin | Manager | Sales Staff | Delegable |
|---|:-:|:-:|:-:|:-:|
| `pos.sell` (build bills, take tendered amount, print invoice, hold) | ✅ | ✅ | ✅ | – |
| `pos.reprint` (always marked COPY and logged) | ✅ | ✅ | ✅ | – |
| `pos.discount.basic` (up to configured %) | ✅ | ✅ | ✅ | – |
| `pos.settle` (receive invoice + money from counters, open drawer) | ✅ | | | ✅ |
| `pos.live_view` (Live Billing: 3 counters in real time) | ✅ | | | ✅ (owner can untick) |
| `pos.void` | ✅ | | | ✅ |
| `pos.refund` | ✅ | | | ✅ |
| `pos.discount.override` | ✅ | | | ✅ |
| `pos.approve_requests` | ✅ | | | ✅ |
| `drawer.manage` (open/close, pay in/out, safe drop) | ✅ | | | ✅ |
| `drawer.handover` (start a handover) | ✅ | | | – |
| `catalog.view` | ✅ | ✅ | ✅ | – |
| `catalog.manage` (products, categories, brands, units) | ✅ | ✅ | | – |
| `catalog.prices.manage` | ✅ | ✅ | | – |
| `catalog.cost.view` | ✅ | ✅ | | – |
| `catalog.synonyms.manage` | ✅ | ✅ | | – |
| `inventory.view` | ✅ | ✅ | ✅ | – |
| `inventory.adjust` | ✅ | ✅ | | – |
| `inventory.adjust.approve` | ✅ | | | – |
| `inventory.stocktake` | ✅ | ✅ | | – |
| `purchasing.po.create` | ✅ | ✅ | ✅ | – |
| `purchasing.po.approve` | ✅ | ✅ | | – |
| `purchasing.grn.create` | ✅ | ✅ | | – |
| `purchasing.suppliers.manage` | ✅ | ✅ | | – |
| `purchasing.suppliers.pay` | ✅ | | | – |
| `customers.view` | ✅ | ✅ | ✅ | – |
| `customers.manage` | ✅ | ✅ | | – |
| `customers.credit.manage` (limits, receive payments) | ✅ | ✅ | | ✅ (receive payments) |
| `hr.attendance.self` | ✅ | ✅ | ✅ | – |
| `hr.attendance.view` | ✅ | ✅ | | – |
| `hr.attendance.edit` | ✅ | | | ❌ |
| `hr.employees.manage` | ✅ | | | ❌ |
| `hr.payroll.manage` | ✅ | | | ❌ |
| `finance.banks.manage` | ✅ | | | ❌ |
| `finance.cheques.manage` | ✅ | | | ❌ |
| `finance.expenses.manage` | ✅ | ✅ (petty cash only) | | – |
| `finance.journal.view` | ✅ | | | ❌ |
| `reports.sales` | ✅ | ✅ | | – |
| `reports.inventory` | ✅ | ✅ | | – |
| `reports.profit` | ✅ | | | ❌ |
| `reports.finance` | ✅ | | | ❌ |
| `reports.hr` | ✅ | | | ❌ |
| `admin.users.manage` | ✅ | | | ❌ |
| `admin.terminals.manage` (terminals and printers) | ✅ | | | ❌ |
| `admin.settings.manage` | ✅ | | | ❌ |
| `admin.audit.view` | ✅ | | | ❌ |

- **Delegable ✅** = included in the default handover scope. **❌** = hard-coded in `config/pos.php` → `non_delegable`; `CreateDelegationAction` rejects them.
- Super Admin also gets `Gate::before(fn ($u) => $u->hasRole('super_admin') ? true : null)`.
- Delegation check order in `Gate::before`: super admin → active delegation containing the ability → normal permission.

---

## 5. Phase plan overview

| Phase | Name | Weeks | Cumulative | Milestone |
|---|---|:-:|:-:|---|
| 0 | Foundation + printing spike | 2–3 | 3 | Login, roles, terminals, printers, UI kit, Sinhala invoice printed on the real printer model |
| 1 | Catalog + Search | 2–3 | 6 | All products imported and searchable |
| 2 | Inventory + Purchasing | 3–4 | 10 | Stock is correct; POs and GRNs work |
| 3 | POS core: counter invoices, settlement, Live Billing, handover | 4–5 | 14 | **MVP: shop can sell on the system** |
| 4 | Customers, Credit, Returns, Quotations | 2 | 16 | Farmer credit and returns |
| 5 | Finance & Banking | 3 | 19 | Banks, cheques, expenses, journal |
| 6 | HR, Attendance, Payroll | 3 | 22 | Salaries paid from the system |
| 7 | Reports, Dashboard, Go-live | 2–3 | 25 | Full system live |

Phases 4–7 can start with a partly parallel team once Phase 3 is stable. With one full-time developer the timeline above applies; with two, phases 5 and 6 can run in parallel (saves ~3 weeks).

```
Week:        1   3   5   7   9   11  13  15  17  19  21  23  25
P0 Found.   ███
P1 Catalog     ███
P2 Inventory       ████
P3 POS                 █████  ◆ MVP (parallel run 1–2 wk)
P4 Customers                  ██
P5 Finance                      ███
P6 HR/Payroll                      ███
P7 Reports                            ███ ◆ Full go-live
```

---

## Phase 0: Foundation

**Goal:** a secure, styled skeleton every later module plugs into, plus proof that Sinhala invoices print on the real printer model used at all 4 terminals.

### 0.1 Project setup
- [ ] `composer create-project laravel/laravel citizens-pos`; set timezone `Asia/Colombo`, locale, currency settings.
- [ ] Install packages from section 3; publish configs (permission, activitylog, scout, reverb, fortify, backup).
- [ ] Create `app/Domain/*` folders and a `DomainServiceProvider` that registers policies and event listeners per module.
- [ ] Pint, Larastan, Pest config; GitHub Actions CI (lint → static analysis → tests on MySQL service).
- [ ] `docker-compose.dev.yml` for Redis + Meilisearch.

### 0.2 Blade UI kit
- [ ] Tailwind + Alpine via Vite; brand colours and typography; bundle **Noto Sans Sinhala** in `public/fonts`.
- [ ] Layouts: `layouts/app.blade.php` (sidebar menu filtered by `@can`, top bar showing user, terminal and **current cashier-authority holder**), `layouts/pos.blade.php` (full-screen, no sidebar), `layouts/print.blade.php`, `layouts/guest.blade.php`.
- [ ] Components in `resources/views/components/ui/`: `button`, `input`, `select`, `textarea`, `checkbox`, `money-input`, `qty-input`, `date-input`, `search-select` (Alpine + JSON endpoint), `modal`, `confirm-modal`, `table` + `th-sortable`, `pagination`, `badge`, `alert`, `flash`, `tabs`, `card`, `stat-tile`, `empty-state`, `filter-bar`.
- [ ] Shared list-page pattern: `?search=&sort=&dir=&filter[...]=` handled by a `HasListQuery` trait on controllers.
- [ ] Keyboard shortcut helper (Alpine `x-hotkey` directive).

### 0.3 Identity & access
**Migrations:** `users` (+ `username`, `pin_hash`, `employee_id` nullable, `is_active`, `last_login_at`), spatie permission tables, `terminals`, `printers`, `delegations`, `activity_log`, `settings`, `document_sequences`.

```
terminals:        id, name, code, type enum(MAIN_CASHIER,COUNTER), counter_no tinyint null UQ,
                  device_token_hash, receipt_language, is_active, last_seen_at, timestamps
printers:         id, terminal_id UQ, windows_name, model, paper_width_mm, dpi,
                  has_cash_drawer, is_active, last_test_at, timestamps
delegations:      id, from_user_id, to_user_id, drawer_session_id null, permissions json,
                  starts_at, expires_at, revoked_at null, revoked_by null, reason, timestamps
settings:         id, group, key, value json, timestamps  (UQ group+key)
document_sequences: id, type (SALE, PO, GRN, ...), prefix, next_number,
                  padding, reset_period enum(NEVER,DAILY,YEARLY), period_key, timestamps
```

- [ ] Fortify with custom Blade views: login (username + password), 2FA challenge, password reset (admin-initiated).
- [ ] **PIN login** for terminals: `PinLoginController` → user picker (avatars of active staff) + 4–6 digit PIN, rate-limited (5 tries/min per terminal), only on registered terminals.
- [ ] **Terminal registration:** Super Admin opens `/admin/terminals/{id}/register` on the device → long-lived signed, httpOnly cookie with a random token (hash stored). `EnsureRegisteredTerminal` middleware attaches `request()->terminal()`.
- [ ] Middleware `EnsureMainCashierTerminal` (settlement, drawer routes) and `EnsureCounterTerminal` (cart sync).
- [ ] Seed 4 terminals: `MAIN` (MAIN_CASHIER), `C1`–`C3` (COUNTER, counter_no 1–3), each with a printer record.
- [ ] `PermissionSeeder`, `RoleSeeder` (super_admin, manager, sales_staff) from section 4; `config/pos.php` with `non_delegable`, default handover scope, max discount % per role.
- [ ] `DelegationService::activeFor(User)` (cached per request) + `Gate::before` hook.
- [ ] User management pages (`admin/users`): list, create, edit, set PIN, reset password, deactivate, assign role.
- [ ] Terminal management pages (`admin/terminals`) and a basic printer list (full printer management in Phase 3.5).
- [ ] Settings pages: shop profile (name/address/phone **in English and Sinhala**), receipt settings, tax settings, discount limits.
- [ ] `DocumentNumber::next('SALE')` service: `SELECT … FOR UPDATE` on `document_sequences`, gapless, period reset.
- [ ] Audit log viewer (`admin/audit`) with filters: user, model, date, event.

### 0.4 Printing spike (de-risk Sinhala) ⚠️
- [ ] Blade `print/invoice-sample.blade.php` with Sinhala text including conjuncts (ක්‍ෂ, ශ්‍රී, ප්‍ර), prices, paid/balance lines, a 30-line item list.
- [ ] Test on the real thermal printer model: Chrome `--kiosk-printing`, Windows driver paper width 80 mm, speed, darkness.
- [ ] Test **two PCs printing at the same time** to their own USB printers (counter + main), confirming each prints only to its own default printer.
- [ ] Verify the cash-drawer kick with QZ Tray raw `ESC p` on the main PC (no paper printed); fallback: driver "open drawer after print" with a 3-line stub.
- [ ] Verify A4 Sinhala PDF via spatie/laravel-pdf.
- [ ] Record the working printer/driver/Chrome settings in `docs/PRINTING.md`.
- [ ] **Go / no-go on the printer model:** only after this spike passes are the other 3 printers bought (same model). Requirements: 80 mm, 203 dpi, USB, auto-cutter, graphics-capable Windows driver, RJ11 drawer port (see Architecture §12).

### Tests
- Login with password and PIN; PIN rejected on unregistered terminal; lock-out after failed attempts.
- Role permissions match section 4 (data-driven test over the matrix).
- Delegation grants delegable permission; **never** grants a non-delegable one; expires on time; revoke works.
- Document numbers are gapless under 20 concurrent requests.

### Acceptance criteria
- Owner, Manager and a Sales Staff user can log in; menus show only what their role allows.
- 4 terminals registered (Main + Counters 1–3), each linked to its printer record.
- A Sinhala sample invoice prints correctly on the chosen printer model from two PCs at once, and the drawer opens via QZ Tray.

---

## Phase 1: Catalog + Search

**Goal:** every product exists in the system with local names and aliases, and staff can find any item in under a second without a barcode.

### Migrations
```
categories:      id, parent_id null, name, name_si, sort_order, is_active, timestamps, softDeletes
brands:          id, name, is_active, timestamps, softDeletes
units:           id, name, name_si, symbol, allows_decimal bool, timestamps
taxes:           id, name, rate, is_active
products:        id, short_code UQ, sku UQ null, name, name_si, name_ta null, aliases text,
                 description, category_id, brand_id null, base_unit_id, tax_id null,
                 has_variants, track_batches, track_expiry, reorder_level dec(14,3),
                 reorder_qty dec(14,3), min_selling_margin_pct null, attributes json,
                 image_path null, is_active, sales_velocity_30d dec(14,3) default 0,
                 created_by, timestamps, softDeletes
                 FULLTEXT ft_products(name, name_si, aliases, short_code) WITH PARSER ngram
product_variants: id, product_id, short_code UQ, sku null, name, attributes json (size, colour),
                 is_active, timestamps, softDeletes
product_units:   id, product_id, unit_id, factor dec(14,3), is_default_sale, is_default_purchase
price_lists:     id, name (Retail, Wholesale, Farmer Credit), is_default
product_prices:  id, product_id, variant_id null, unit_id, price_list_id, price dec(15,2),
                 effective_from, created_by, timestamps
search_synonyms: id, term, synonyms json, timestamps
favorite_products: id, user_id null (null = shop-wide), product_id, variant_id null, sort_order
```

### Work items
- [x] Models + factories + policies for all tables above.
- [x] Seeders: units (kg, g, bag, packet, piece, pair, set, litre, ml, roll, metre), Retail/Wholesale/Farmer price lists, sample categories (Fertilizers, Seeds, Agro-chemicals, Tools, Bicycle Parts → Tyres, Tubes, Chains, Brakes, Gears …).
- [x] **Short code generator:** next free code per category range (e.g. Fertilizer 1000–1999, Seeds 2000–2999, Bike 5000–6999), editable.
- [x] Product Blade pages: list (filters: category, brand, active, low stock), create/edit form with tabs (General · Units & Prices · Variants · Search & Names · Stock settings), show page (stock by batch, price history, movement history). *(Low-stock filter, stock by batch and movement history added in Phase 2.)*
- [x] Unit conversion editor (Alpine): "1 Bag = 50 kg", default sale unit, default purchase unit.
- [x] Price editor per unit × price list; price change history; minimum price/margin guard (visible only with `catalog.cost.view`).
- [x] Category tree page, brand page, unit page, synonym management page.
- [x] **Excel import** (`maatwebsite/excel`): template download → upload → preview with row validation errors → commit. Columns: short_code, name, name_si, aliases, category, brand, base_unit, sale units + factors, retail/wholesale price, reorder level, opening stock, cost, batch/expiry.
- [x] Excel export of the product list.

### Search
- [x] `Product::toSearchableArray()` → id, short_code, name, name_si, name_ta, aliases, brand, category, category_path, attributes (flattened), variant codes/names, is_active, sales_velocity_30d.
- [x] Meilisearch index settings in `config/scout.php` → `searchableAttributes` (order from architecture §6), `filterableAttributes` (category_id, brand_id, is_active), `sortableAttributes`, `rankingRules` (words, typo, proximity, attribute, exactness, `sales_velocity_30d:desc`), `typoTolerance` (min word size 4/8, disabled on `short_code`).
- [x] Synonyms from `search_synonyms` pushed to Meilisearch on save (`SyncSearchSynonymsJob`).
- [x] `ProductSearchService::search(string $q, array $filters, int $limit = 20)`:
  1. Parse `qty*term` syntax (`5*urea`, `2.5*tsp`).
  2. If `q` is an exact short_code (product or variant) → return that single item.
  3. Else Meilisearch; on connection failure fall back to MySQL `MATCH … AGAINST` (ngram) + `LIKE` on short_code.
  4. Hydrate IDs with **live** stock from `stock_levels` (sum qty_on_hand − qty_reserved) and prices for the requested price list, in one query each.
  5. Strip cost fields unless user has `catalog.cost.view`.
- [x] `GET /api/pos/search?q=` JSON endpoint (throttled 120/min per user). Target p95 < 150 ms on LAN.
- [x] Nightly job `RecalculateSalesVelocityJob` (after Phase 3 sales exist) + reindex. *(Built in Phase 3, runs at 02:45.)*
- [x] Global product search box in back-office top bar using the same endpoint.

### Tests
- Exact short code wins; typo (`ureea`) finds Urea; Sinhala `යූරියා` and alias `yuriya` find Urea; `5*urea` returns qty 5.
- Fallback path works with Meilisearch stopped.
- Sales Staff search response contains **no** cost fields.
- Import rejects rows with unknown units/duplicate codes and reports the row numbers.

### Acceptance criteria
- Full product list imported from the owner's data.
- Owner tries 30 real search phrases staff use every day; ≥ 28 give the right item in the top 3 results. Missing ones are fixed with aliases/synonyms.

---

## Phase 2: Inventory + Purchasing

**Goal:** stock is always correct and traceable; purchase orders can be raised by any staff member and approved by Manager/Owner; goods receiving creates batches.

### Migrations
```
batches:          id, product_id, variant_id null, lot_no null, mfg_date null, expiry_date null,
                  unit_cost dec(15,4), received_at, grn_line_id null, timestamps
stock_levels:     id, product_id, variant_id null, batch_id, qty_on_hand dec(14,3),
                  qty_reserved dec(14,3), updated_at  (UQ product_id+variant_id+batch_id)
stock_movements:  id, product_id, variant_id null, batch_id, type enum(OPENING,GRN,SALE,
                  SALE_RETURN,SUPPLIER_RETURN,ADJUST_IN,ADJUST_OUT,DAMAGE,STOCKTAKE),
                  qty dec(14,3) (signed), unit_cost dec(15,4), reference_type, reference_id,
                  user_id, note, created_at   -- append-only, no updated_at
stock_adjustments: id, number, reason enum(DAMAGE,EXPIRED,LOST,FOUND,CORRECTION,OTHER),
                  status enum(DRAFT,PENDING_APPROVAL,APPROVED,REJECTED), total_value,
                  created_by, approved_by, approved_at, note, timestamps
stock_adjustment_lines: id, adjustment_id, product_id, variant_id, batch_id, qty, unit_cost
stocktakes:       id, number, scope json (category ids), status enum(OPEN,COUNTING,REVIEW,
                  POSTED,CANCELLED), started_by, posted_by, timestamps
stocktake_lines:  id, stocktake_id, product_id, variant_id, batch_id, system_qty, counted_qty,
                  counted_by, counted_at
suppliers:        id, name, contact_person, phone, email, address, payment_terms_days,
                  opening_balance, is_active, timestamps, softDeletes
supplier_products: supplier_id, product_id, supplier_code null, last_cost, lead_days
purchase_orders:  id, number, supplier_id, status enum(DRAFT,SUBMITTED,APPROVED,REJECTED,SENT,
                  PARTIAL,RECEIVED,CLOSED,CANCELLED), order_date, expected_date,
                  subtotal, discount, tax, total, note,
                  created_by, submitted_at, approved_by, approved_at, rejected_reason, timestamps
po_lines:         id, purchase_order_id, product_id, variant_id, unit_id, qty, base_qty,
                  unit_cost null (filled at approval), received_base_qty, line_total
goods_receipts:   id, number, supplier_id, purchase_order_id null, supplier_invoice_no,
                  received_at, subtotal, discount, tax, total, status enum(DRAFT,POSTED,CANCELLED),
                  received_by, posted_at, timestamps
grn_lines:        id, goods_receipt_id, po_line_id null, product_id, variant_id, unit_id, qty,
                  base_qty, unit_cost, lot_no, mfg_date, expiry_date, free_qty, line_total
supplier_returns (+ lines), supplier_ledger (id, supplier_id, date, type, reference, debit, credit)
```

### Core inventory service
- [x] `StockService` (only place that changes stock):
  - `receive(product, variant, qty, unitCost, batchData, reference)` → create/lookup batch, `stock_levels` upsert, movement row.
  - `issue(product, variant, baseQty, reference, strategy = FEFO)` → lock candidate `stock_levels` rows `FOR UPDATE` ordered by `expiry_date IS NULL, expiry_date, received_at`, allocate across batches, write movements, return allocations with cost (for COGS).
  - `reserve()/release()` on `qty_reserved` (reserved when a counter prints an invoice, released on settlement or void).
  - `adjust()` for adjustments and stocktake postings.
  - Setting `allow_negative_stock` (default **false**) → throws `InsufficientStockException`.
- [x] Products without batch tracking use one auto "default" batch per product/variant, so all code paths are the same. *(Default batch cost = moving average; batch-tracked products get one batch per receipt, so their cost is FIFO.)*
- [x] Opening stock import (from Phase 1 template) posts `OPENING` movements. *(New imports post straight away; entries from earlier imports: “Add opening stock now” on Stock on hand, or `php artisan inventory:post-opening-stock`.)*

### Purchasing work items
- [x] Supplier pages: list, create/edit, show (POs, GRNs, ledger, balance).
- [x] **PO create page (all roles with `purchasing.po.create`)**: supplier select, product search (same search component), unit select, quantity, current stock and reorder level per line. Unit cost column **only rendered if** user has `catalog.cost.view`; for Sales Staff it is hidden and left null.
- [x] Reorder suggestions button: fills lines with products below reorder level for that supplier (`reorder_qty` or `reorder_level × 2 − on_hand`).
- [x] `SubmitPurchaseOrderAction` (DRAFT → SUBMITTED) → notifies Manager/Owner (database notification + badge in top bar).
- [x] `ApprovePurchaseOrderAction` (Manager/Owner): fill/confirm unit costs (pre-filled with `supplier_products.last_cost`), approve or reject with reason.
- [x] PO PDF (A4, Blade → spatie/laravel-pdf) + "Send": download/print, or share link via WhatsApp (`https://wa.me/<phone>?text=` with PDF link on LAN/tunnel) → status SENT. *(The WhatsApp message carries a signed PDF link valid for 30 days.)*
- [x] **GRN page:** from a PO (lines prefilled with outstanding qty) or direct GRN without PO; enter received qty, free qty, cost, lot no, expiry. `PostGoodsReceiptAction` → `StockService::receive` per line, update `po_lines.received_base_qty`, PO → PARTIAL/RECEIVED, supplier ledger credit, `supplier_products.last_cost`.
- [x] Supplier return page → `StockService::issue` from specific batch + supplier ledger debit.
- [x] Stock adjustment pages; value above `settings.adjustment_approval_limit` → PENDING_APPROVAL for Super Admin.
- [x] Stocktake pages: create (scope), printable count sheet, mobile-friendly counting page (tablet), review variances, post.
- [x] Inventory pages: stock on hand (by product / by batch), batch expiry list (30/60/90 days), movement history with filters, low-stock list.
- [x] Scheduled `LowStockAndExpiryAlertJob` (daily 07:00) → database notifications.

### Tests
- FEFO allocation across 3 batches with different expiry; partial batch consumption.
- Concurrent `issue()` of the last 5 units from two processes → one succeeds, one throws (no negative stock).
- `stock_levels` always equals `SUM(stock_movements.qty)` per batch (invariant test after every scenario).
- Sales Staff can create and submit a PO but cannot approve; PO JSON/HTML for Sales Staff contains no cost values.
- GRN partial receipt keeps PO in PARTIAL, second GRN closes it.

### Acceptance criteria
- Opening stock loaded; stock values match the owner's count sheet.
- A staff member creates a PO, Manager approves it, goods are received in two deliveries, stock and supplier balance are correct.

---

## Phase 3: POS core + Counter invoices + Settlement + Live Billing + Handover

**Goal (MVP):** staff at the 3 counters build bills, take the customer's money, and print a Sinhala invoice (with paid and balance) on their own counter printer. They bring the invoice and cash to the main cashier, where the owner (or the delegated Manager) settles it. The owner watches all 3 counters live, and cashier authority can be handed over safely.

### Migrations
```
terminals (alter): type enum(MAIN_CASHIER,COUNTER), counter_no tinyint null UQ (1–3)
printers:         id, terminal_id UQ, windows_name, model, paper_width_mm (80), dpi (203),
                  has_cash_drawer bool, is_active, last_test_at null, timestamps
print_jobs:       id, terminal_id, printer_id, user_id, document_type enum(INVOICE,PAYMENT_RECEIPT,
                  Z_REPORT,HANDOVER,TEST), document_id null, is_copy bool, printed_at
drawer_sessions:  id, terminal_id, holder_user_id, opened_at, opening_float dec(15,2),
                  closed_at null, expected_cash, counted_cash, variance, denominations json,
                  close_reason enum(END_OF_DAY,HANDOVER), previous_session_id null,
                  is_open (nullable bool used for UQ terminal_id+is_open), timestamps
cash_movements:   id, drawer_session_id, type enum(PAY_IN,PAY_OUT,SAFE_DROP,BANK_DEPOSIT),
                  amount, reason, user_id, timestamps
sales:            id, invoice_no UQ, status enum(ON_HOLD,INVOICED,SETTLED,VOID,
                  PARTIALLY_RETURNED,RETURNED), customer_id null, price_list_id, cart_uuid,
                  invoiced_by, invoiced_terminal_id, invoiced_at,
                  settled_by null, settled_terminal_id null, settled_at null,
                  drawer_session_id null (set on settle),
                  subtotal, line_discount_total, bill_discount, tax_total, total,
                  payment_method_intent enum(CASH,CARD,BANK_TRANSFER,CHEQUE,CREDIT,SPLIT),
                  tendered_amount, change_due, balance_due, cost_total, note,
                  void_reason null, voided_by null, voided_at null,
                  idempotency_key UQ, print_count, timestamps
sale_items:       id, sale_id, product_id, variant_id null, unit_id, qty, base_qty,
                  unit_price, discount_amount, tax_amount, line_total, cost_total,
                  name_snapshot, name_si_snapshot
sale_item_batches: id, sale_item_id, batch_id, base_qty, unit_cost
payments:         id, sale_id, method enum(CASH,CARD,BANK_TRANSFER,CHEQUE,CREDIT),
                  amount, tendered null, reference null, cheque_id null, bank_account_id null,
                  recorded_by (counter staff), confirmed_by (cashier), drawer_session_id, timestamps
approval_requests: id, type enum(DISCOUNT,PRICE_OVERRIDE,VOID,REFUND), sale_id null, cart_uuid null,
                  terminal_id, requested_by, payload json, status enum(PENDING,APPROVED,REJECTED),
                  decided_by, decided_at, timestamps
counter_events:   id, terminal_id, user_id, sale_id null, cart_uuid, type enum(ITEM_ADDED,
                  QTY_CHANGED,ITEM_REMOVED,CART_CLEARED,CUSTOMER_SET,TENDERED,PRINTED,REPRINTED,
                  SETTLED,VOIDED,HELD,RECALLED), payload json, created_at   -- pruned after 90 days
```

### 3.1 Counter POS screen (`/pos`, Counters 1–3 and main terminal): `layouts/pos.blade.php` + Alpine `posCart()`
- [x] Left: search box (always focused) + results list with stock badge, price, unit chips, expiry hint; tabs for Favorites / Recent / Categories.
- [x] Right: cart (line: name EN + SI, unit select, qty, price, line discount, total), customer select (Phase 4 adds credit), bill discount, **large total display** (staff read it out to the customer). *(F4 customer shows a "Phase 4" note: there is no customers table yet.)*
- [x] **Tender panel (F6):** payment method (default CASH), amount tendered with quick buttons (exact, next Rs 100/500/1000/5000), balance shown in large digits. Tendered < total is blocked for CASH. Other methods are marked "confirm at cashier". *(Methods now: cash, card, bank transfer, cheque. Credit needs Phase 4 customers; split and real cheque records come with Phase 5.)*
- [x] Keyboard: F2 search, ↑↓ Enter add, `+`/`-` qty, Del remove, F4 customer, F6 tender, F8 hold, **F9 print invoice**, F10 reprint last, Esc clear.
- [x] **Cart sync:** every change → debounced 300 ms `POST /api/pos/cart-sync` → `SyncCounterCartAction` (see 3.6). On load the cart is restored from Redis (survives refresh or power cut).
- [x] Discount above the role limit → `approval_request` to the cashier screen; line shows "waiting for approval"; approval arrives via Reverb. Printing is blocked until it is approved or removed. *(Line and bill discounts. The counter also polls every 5 s while Reverb is down. The PRICE_OVERRIDE type exists but has no screen yet: staff change the price through a discount.)*
- [x] **`IssueCounterInvoiceAction`** (single DB transaction):
  1. Check idempotency key; reprice every line on the server (never trust client prices); apply approved discounts.
  2. Validate tendered ≥ total for CASH; compute `change_due`.
  3. `StockService::reserve` per line (fails clearly if stock is not available).
  4. `invoice_no` from `DocumentNumber::next('SALE')`; create `sales` (status INVOICED) + `sale_items`.
  5. Write `counter_events` PRINTED, clear the Redis cart, broadcast `InvoiceIssued` on `live-billing`.
  6. Return the print URL → the counter prints on its own printer (3.4).
- [x] "Last invoices" drawer on each counter (today's invoices from this counter, with status and a Reprint button).
- [x] Hold / recall list per counter (status ON_HOLD, no reservation, no invoice number). *(Recalling removes the held row; the HELD / RECALLED counter events keep the trail.)*

### 3.2 Settlement at the main cashier (`/pos/cashier`, main terminal only, `pos.settle`)
- [x] Middleware: `EnsureMainCashierTerminal` + `can:pos.settle` + an **open drawer session held by the current user** (else redirect to "Open drawer" page).
- [x] The screen is the **Live Billing layout** (3.6) with a settle card in each counter column for every INVOICED invoice (invoice no, total, method, tendered, balance, waiting time). Waiting time turns red after N minutes (setting).
- [x] Find by invoice number: type the last digits (e.g. `4512`) + Enter opens that settle card.
- [x] **`SettleInvoiceAction`** (single DB transaction): *(Cash settlements only. QZ Tray request signing is still open, so QZ shows its "Allow" prompt.)*
  1. Lock sale `FOR UPDATE`; status must be INVOICED; idempotency check.
  2. Cashier confirms the payment (CASH: amount received = tendered, change given = change_due; CARD/BANK: reference; CREDIT: credit-limit check / approval, from Phase 4; CHEQUE: from Phase 5).
  3. Release reservation → `StockService::issue` (FEFO) per line → `sale_item_batches`, `cost_total`.
  4. Insert `payments` linked to the current `drawer_session`.
  5. Status SETTLED; dispatch `InvoiceSettled` (journal in Phase 5, sales velocity, stock broadcast); `counter_events` SETTLED.
  6. After commit: **open cash drawer** via QZ Tray (`ESC p` to printer #0); column flashes SETTLED.
- [x] **`VoidInvoiceAction`** (`pos.void`, reason required): INVOICED → VOID, release reservation, `counter_events` VOIDED, broadcast; the counter gets a prompt "Invoice INV-… voided, restore cart?", which restores the lines to its cart for re-billing.
- [x] Void a settled sale (same day, `pos.void`): reversal stock movements, refund payment from the drawer, status VOID, audit log. *(Stock goes back into the exact batches (SALE_RETURN movements); the refund is a negative payment in the cashier's open session.)*
- [x] Discount / price-override approval requests appear as a banner on the cashier screen (approve / reject).
- [x] Direct sale on the main terminal: same counter screen; after printing on printer #0 it goes straight to the settle card.
- [x] Day close is **blocked** while any invoice is INVOICED (list shown with Settle / Void).

### 3.3 Drawer sessions & Cashier Handover
- [x] Open drawer page: opening float with denomination counter (Rs 5000, 1000, 500, 100, 50, 20, coins).
- [x] Pay in / pay out / safe drop pages (reason required).
- [x] Close drawer (end of day) → counted cash vs expected (`opening + settled cash − pay outs − drops + pay ins`) → variance → **Z-report** with totals **per counter** and voids (print 80 mm in Sinhala on printer #0 + A4 PDF). *(The Z report covers the whole day: all sessions linked by handovers. Handover slips and an X report of an open drawer use the same layout.)*
- [x] **`HandoverCashierAction`** (owner on main terminal, `drawer.handover`):
  1. Owner counts drawer → session A closed (`close_reason=HANDOVER`). Unsettled invoices stay INVOICED and move with the authority.
  2. Select Manager, set expiry (default: today's closing time from settings), reason, scope (default from `config/pos.php`; `pos.live_view` can be unticked).
  3. Manager enters PIN on the same screen (re-auth) and **confirms the counted amount** (or disputes → both see the difference, re-count).
  4. Create `delegations` row, open session B with `opening_float = counted`, `previous_session_id = A`.
  5. Log owner out of the terminal; log Manager in; broadcast `CashierAuthorityChanged` (top bar updates everywhere).
- [x] **Take back** (`ReturnCashierAuthorityAction`): Manager counts → session B closed with variance → delegation revoked → owner PIN → new session C opened for owner.
- [x] **Remote revoke** (owner's phone, `/admin/delegations`): revokes delegation immediately; the Manager's next settlement attempt is blocked with a "Cashier authority revoked, count drawer" screen.
- [x] Scheduled job every minute: expire delegations whose `expires_at` has passed (same blocking behaviour). *(Permission checks stop honouring a delegation the moment it expires; the job announces it and notifies the owner.)*
- [x] Handover history report: sessions, holders, times, variances.

### 3.4 Sinhala invoice (80 mm) on the counter printers
- [x] `print/invoice.blade.php` (layout `print`), locale from terminal/setting (`si` default):
  ```
  CITIZENS AGRO (Sinhala shop name, address, phone)
  ඉන්වොයිස් අංකය: INV-2026-004512      දිනය: 2026-09-24 14:32
  කවුන්ටරය: 2        විකුණුම්කරු: <staff>
  ───────────────────────────────────────────
  යූරියා පොහොර 50kg
     2 බෑග් × 9,500.00                 19,000.00
  වී බීජ BG 352 1kg
     5 පැකට් × 450.00                   2,250.00
  ───────────────────────────────────────────
  එකතුව                                21,250.00
  වට්ටම                                  -250.00
  ගෙවිය යුතු මුදල                       21,000.00
  ගෙවූ මුදල (මුදල්)                     22,000.00
  ඉතිරිය                                 1,000.00
  ───────────────────────────────────────────
  (Sinhala footer: return policy / thank you)
  ```
- [x] `lang/si/receipt.php` and `lang/en/receipt.php` for all labels; unit names from `units.name_si`; item name `name_si_snapshot ?? name_snapshot`.
- [x] CSS: `@page { size: 80mm auto; margin: 0 }`, `font-family: 'Noto Sans Sinhala'`, 11–12 pt items, 14 pt totals, tabular numbers; font preloaded so the first print is not blank.
- [x] Print flow (same on all 4 PCs): hidden iframe loads `/pos/sales/{sale}/invoice` → `onload` → `print()`; Chrome runs with `--kiosk-printing`, so it prints silently to that PC's default (thermal) printer. A `print_jobs` row is written.
- [x] Reprint (`pos.reprint`): increments `print_count`, prints "පිටපත / COPY", `counter_events` REPRINTED, `print_jobs.is_copy = true`.
- [x] A4 invoice (Sinhala/English) PDF for customers who ask for it.
- [x] Settings page: receipt language (si / en / si+en), show staff name, footer text (SI/EN), logo on/off.

### 3.5 Printers management (`/admin/printers`, `admin.terminals.manage`)
- [x] Printer list: terminal, Windows printer name, model, paper width, drawer yes/no, active, last test.
- [x] Assign / reassign a printer to a terminal (e.g. move a spare printer to Counter 2 when its printer fails).
- [x] **Test print** button: opens `print/test.blade.php` (Sinhala conjunct sample, 80 mm ruler, terminal name, date/time) on the target terminal via a broadcast `TestPrintRequested` to that terminal's private channel; the terminal prints and reports back → `last_test_at`. *(Needs Reverb and the POS screen open on that terminal; pressed on the terminal itself it prints straight away.)*
- [x] Drawer test (main terminal only): QZ Tray `ESC p` command.
- [x] Print log page (`print_jobs`): filters by terminal, user, type, copies only.
- [x] Health hint on the terminal: if a print result is not confirmed within 10 s, show "Check printer" with a Reprint button.

### 3.6 Live Billing
- [x] **`SyncCounterCartAction`** (`POST /api/pos/cart-sync`, counters only): validates the payload, reprices on the server, stores `live_cart:{terminal_id}` in Redis (lines, totals, customer, tendered, status, user, updated_at; TTL 12 h), diffs against the previous snapshot to write `counter_events` (ITEM_ADDED / QTY_CHANGED / ITEM_REMOVED / CART_CLEARED / TENDERED / CUSTOMER_SET), broadcasts `CounterCartUpdated` on private channel `live-billing`.
- [x] Channel auth in `routes/channels.php`: `live-billing` → `can('pos.live_view')`; presence channel `pos-terminals` → any logged-in terminal user (returns terminal, counter_no, user name).
- [x] Blade component `x-pos.counter-column` (Alpine `counterColumn(counterNo)`): header (online dot, staff name), status badge (OFFLINE / IDLE / BILLING / PAYMENT / PRINTED + timer / SETTLED flash), live cart lines (new lines highlighted, removed lines struck through in red for 10 s), totals, tendered and balance, settle cards (only when `pos.settle` and main terminal), activity ticker (last 10 events, red for removals, clears, reprints, voids).
- [x] `/pos/cashier` = 3 columns + settle actions + bottom bar (today's totals and invoice count per counter, drawer total, waiting-to-settle count, voids today).
- [x] `/admin/live-billing` = same component, view only; responsive: 3 columns on desktop/tablet, swipeable tabs on phones; linked from the owner dashboard.
- [x] Initial state + fallback: `GET /api/live-billing/snapshot` returns all 3 counters (Redis carts + INVOICED list + today's totals). The page polls it every 3 s when the Echo connection is down.
- [x] Optional sound when a counter prints an invoice (setting).
- [x] Nightly prune of `counter_events` older than 90 days.

### Tests
- Issue → settle happy path: invoice number assigned at the counter, stock reserved then issued from the correct batches, payment linked to the open drawer session.
- Issue twice with the same idempotency key → one invoice; settle twice → one settlement.
- CASH invoice with tendered < total is rejected; client-sent prices are ignored (server reprices).
- Void of an INVOICED sale releases the reservation and keeps the invoice number as VOID (numbers stay gapless).
- Sales Staff calling settle → 403; Manager without delegation → 403; Manager with active delegation on main terminal → OK; delegate on a counter terminal → 403.
- Day close blocked while any invoice is INVOICED.
- Handover: session A closed with correct expected cash, session B opened with counted amount, only one open session per terminal (DB constraint test); unsettled invoices can be settled by the delegate.
- Delegation expiry blocks settlement within one minute.
- Cart sync: diff produces the right `counter_events`; `live-billing` channel refuses users without `pos.live_view`; snapshot endpoint hides cost fields.
- Invoice view renders with `lang=si`, contains Sinhala names, paid and balance, and correct totals (snapshot test).

### Acceptance criteria (MVP)
- Full-day simulation: 3 counters issue 50+ invoices with cash tendered and balance; the owner settles each one from the Live Billing screen; one handover to the Manager at noon and back at 3 pm; end-of-day Z report balances to the rupee, per counter.
- Each counter's invoice prints on **its own printer** in readable Sinhala within 3 seconds of F9; the drawer opens on settlement.
- The owner sees items being added and removed at each counter within 1 second, on the main screen and on their phone.
- **Parallel run for 1–2 weeks** with the old method before switching fully.

---

## Phase 4: Customers, Credit, Returns, Quotations

### Migrations
```
customers:        id, code, name, name_si, phone UQ null, nic null, address, area/village,
                  price_list_id, credit_limit, credit_days, is_active, notes, timestamps, softDeletes
customer_ledger:  id, customer_id, date, type enum(OPENING,SALE,PAYMENT,RETURN,ADJUSTMENT),
                  reference_type, reference_id, debit, credit, due_date null, created_by
customer_payments: id, number, customer_id, date, amount, method, reference, cheque_id null,
                  bank_account_id null, drawer_session_id null, received_by, timestamps
customer_payment_allocations: payment_id, sale_id, amount
sale_returns:     id, number, sale_id, customer_id null, reason, refund_method enum(CASH,CREDIT_NOTE,
                  ACCOUNT), total, created_by, approved_by, drawer_session_id, timestamps
sale_return_lines: id, sale_return_id, sale_item_id, qty, base_qty, amount, restock bool
quotations (+ lines): id, number, customer_id null, valid_until, status enum(OPEN,CONVERTED,EXPIRED),
                  totals, created_by, timestamps
```

### Work items
- [x] Customer pages (list, create/edit, show with ledger, outstanding invoices, ageing). *(Plus a credit ageing report for all customers. Customer numbers `C-00001`; an opening balance from the old books can be entered when the customer is created.)*
- [x] Customer quick-add and search from POS (F4) — by phone, name, NIC, village. *(Quick-add takes name, phone and village; the credit limit stays 0 until the Owner or Manager sets one.)*
- [x] Price list per customer applied automatically in the cart.
- [x] **Credit sale:** counter selects a customer and method CREDIT (no tendered amount); the invoice prints marked "ණයට / CREDIT". At settlement the cashier confirms it: allowed if within `credit_limit` (override requires Super Admin approval) → customer ledger debit with due date. *(Settling it also prints a **credit bill** on printer #0 for the customer's signature, the owner's signature and the shop seal; the shop keeps it. The override is a tick box only the Super Admin sees; a delegated Manager can never go over a limit. The invoice's `balance_due` holds what is still owed on it.)*
- [x] Receive customer payment page (main cashier: cash/bank/cheque), allocate to oldest invoices (FIFO) or manually; prints Sinhala payment receipt on printer #0. *(Cash, card, bank transfer, cheque; real cheque records come with Phase 5. Money not applied stays as an advance. Cash is part of the drawer's expected cash and the Z report.)*
- [x] Customer statement PDF (Sinhala/English).
- [x] **Returns** (`pos.refund`): find invoice → pick lines/qty → restock yes/no (damaged goods → no restock, DAMAGE movement) → refund cash / credit note / reduce account balance → Sinhala return receipt. *(Two refund methods: cash from the drawer, or credit to the customer's account, which first lowers what is owed on that invoice; "credit note" = credit to account. Stock goes back into the batches the sale was issued from. A line's refund is its share of the invoice after the bill discount.)*
- [x] Quotations: create from the counter cart, print on the counter printer / PDF, convert to bill. *(F7. Loading a quotation reprices it at today's prices and says so if the total changed.)*
- [x] Overdue credit reminders (optional SMS gateway integration behind a `SmsChannel` interface). *(Daily 07:05 bell notification to Owner/Manager. No SMS: the owner does not want it.)*

### Tests
- Credit limit enforcement; payment allocation; ledger balance = sum of debits − credits.
- Return cannot exceed sold qty minus already returned; stock returns to the original batch.

---

## Phase 5: Finance & Banking

### Migrations
```
accounts:         id, code, name, type enum(ASSET,LIABILITY,EQUITY,INCOME,EXPENSE),
                  parent_id null, is_system, is_active
journal_entries:  id, number, date, description, source_type, source_id, created_by, timestamps
journal_lines:    id, journal_entry_id, account_id, debit, credit, memo
bank_accounts:    id, account_id (ledger link), bank_name, branch, account_no, account_name,
                  type enum(CURRENT,SAVINGS), opening_balance, is_active
bank_transactions: id, bank_account_id, date, type enum(DEPOSIT,WITHDRAWAL,TRANSFER_IN,
                  TRANSFER_OUT,CHARGE,INTEREST), amount, reference, description,
                  related_type/id, reconciled_at null, created_by
cheques:          id, direction enum(RECEIVED,ISSUED), number, bank_name, branch, cheque_date,
                  amount, party_type/id (customer/supplier), bank_account_id null,
                  status enum(PENDING,DEPOSITED,CLEARED,BOUNCED,CANCELLED), status_history json
expense_categories: id, name, account_id
expenses:         id, number, date, category_id, amount, payment_method (CASH_DRAWER, SAFE, BANK),
                  bank_account_id null, drawer_session_id null, note, receipt_path, created_by
```

### Work items
- [x] Chart of accounts seeder (section 14 system accounts); accounts page (Super Admin). *(System accounts are also created on first use, so posting never fails on a fresh database. Bank accounts get 15xx accounts, expense heads 6xxx.)*
- [x] `JournalService::post(source, lines)` — asserts Σdebit = Σcredit; entries immutable (reversal entries only).
- [x] Event listeners that post journals for every event in section 14 (`InvoiceSettled`, `InvoiceVoided`, `GoodsReceiptPosted`, `SupplierPaymentMade`, `CustomerPaymentReceived`, `SaleReturned`, `ExpenseRecorded`, `PayrollPaid`, `CashDeposited`, `ChequeCleared`, `ChequeBounced`, `StockAdjusted`). *(Built as `FinancePosting`, called inside each action's own transaction instead of listeners, so a document never exists without its entry. Payroll comes with Phase 6.)*
- [x] Backfill command to post journals for sales/GRNs created in Phases 2–4. *(`php artisan finance:backfill-journals`; safe to run again.)*
- [x] Bank account pages: list with balances, transactions register, deposit/withdraw/transfer forms. *(One "Move money" form for all moves; charges and interest on the bank page.)*
- [x] **Cash to bank:** from drawer/safe → bank deposit (cash movement + bank transaction + journal).
- [x] Cheque register: received cheques (from customers) → deposit → clear/bounce (bounce re-opens customer debt); issued cheques (to suppliers); **post-dated cheque calendar** with due alerts.
- [x] Supplier payments (cash/bank/cheque) with allocation to supplier invoices/GRNs.
- [x] Expenses (with photo of bill), petty cash from drawer.
- [x] Bank reconciliation page: tick transactions against statement, show difference.
- [x] Cash book, bank book, trial balance, P&L, balance sheet (basic).

### Tests
- Every event produces a balanced journal entry; trial balance always balances.
- Cheque bounce reverses the customer payment and restores the receivable.

---

## Phase 6: HR, Attendance, Payroll

### Migrations
```
employees:        id, user_id null, code, full_name, name_si, nic, dob, phone, address,
                  designation, join_date, leave_date null, employment_type,
                  basic_salary, bank_name, bank_account_no, epf_no null, is_epf_member,
                  is_active, timestamps, softDeletes
shifts:           id, name, start_time, end_time, grace_minutes, working_days json
attendances:      id, employee_id, date, clock_in, clock_out null, source enum(POS_PIN,MANUAL),
                  terminal_id null, photo_path null, late_minutes, early_leave_minutes,
                  ot_minutes, status enum(PRESENT,ABSENT,LEAVE,HALF_DAY,HOLIDAY),
                  edited_by null, edit_reason null   (UQ employee_id+date)
holidays:         id, date, name (Poya days, public holidays)
leave_types:      id, name, days_per_year, is_paid
leave_requests:   id, employee_id, leave_type_id, from_date, to_date, days, status, approved_by
salary_components: id, name, type enum(ALLOWANCE,DEDUCTION), calc enum(FIXED,PERCENT_BASIC),
                  value, is_epf_applicable
employee_salary_components: employee_id, component_id, value_override null
salary_advances:  id, employee_id, date, amount, installments, recovered_amount, status
payroll_runs:     id, month (YYYY-MM), status enum(DRAFT,CALCULATED,APPROVED,PAID), totals,
                  approved_by, paid_at
payslips:         id, payroll_run_id, employee_id, basic, gross, epf_employee, epf_employer,
                  etf, total_deductions, net, days_worked, ot_hours, ot_amount
payslip_lines:    id, payslip_id, component_name, type, amount
```

### Work items
- [x] Employee pages (Super Admin), link employee ↔ user login. *(Code E-001…; salary components ticked per employee with an optional own amount.)*
- [x] **Clock in via POS:** first successful PIN login of the day creates `attendances.clock_in` (optional webcam snapshot via `getUserMedia`); explicit "Clock out" button in the POS top bar; missing clock-out flagged next morning. *(Photo is off by default (Settings → HR & payroll) and needs HTTPS or a Chrome exception. Clock in/out also in the back-office user menu on a registered terminal. Bell at 07:15.)*
- [x] Attendance calendar (monthly grid per employee), daily attendance sheet, manual correction with reason (audited). *(One grid for all employees; click a day to correct it.)*
- [x] Holidays (Poya/public), shifts, late/OT rules in settings. *(Holiday dates are entered by the owner each year; none are seeded.)*
- [x] Leave requests (Super Admin approves), leave balances. *(Staff ask on My attendance; half days; working days only; approved leave writes LEAVE rows into the attendance.)*
- [x] Salary advances with recovery schedule. *(Equal monthly installments; the owner can change a month's amount on the payslip.)*
- [x] **Payroll run:** choose month → calculate per employee:
  ```
  gross       = basic + Σallowances + OT (ot_hours × hourly_rate × ot_multiplier)
                − no-pay deduction (absent days × basic / working_days)
  epf_employee = epf_base × 8%    (rates in settings)
  epf_employer = epf_base × 12%
  etf          = epf_base × 3%
  net         = gross − epf_employee − advance installment − other deductions
  ```
  review/edit → approve → pay (bank or cash) → journal → **payslip PDF (Sinhala/English)**. *(Approval posts Dr Salaries + EPF/ETF expense, Cr EPF/ETF payable, Staff advances, Salaries payable; each payment then Dr Salaries payable, Cr drawer / cash at home / bank. EPF + ETF sent to the funds recorded per month. OT = hours × basic × 1.5 ÷ 240. EPF base = basic − no-pay + allowances marked "EPF applies". An approved payroll can be reopened until someone is paid.)*
- [x] Attendance and payroll reports; EPF/ETF monthly summary.

### Tests
- Late/OT calculation around shift boundaries and grace minutes.
- Payroll with an advance installment, no-pay days, EPF on/off.
- Manager cannot open payroll pages even while delegated.

---

## Phase 7: Reports, Dashboard, Go-live

### Work items
- [ ] **Owner dashboard** (desktop + phone layout): **Live Billing** panel / link (Phase 3.6), today's sales per counter, invoices waiting for settlement, cash in drawer, current cashier-authority holder (with revoke button), pending approvals, pending POs, low stock, expiring batches, cheques due, receivables/payables, top 10 items, sales by hour chart.
- [ ] **Manager dashboard:** stock alerts, pending POs/GRNs, today's sales count.
- [ ] Reports (each: filter bar → Blade table → Excel/PDF export; heavy ones via queued export):

| Group | Reports |
|---|---|
| Sales | Daily summary / Z report, sales by item, category, brand, **counter**, staff, customer, hour; discounts given; average settlement waiting time per counter |
| Loss prevention | **Removed items & voids by counter** (from `counter_events`), cleared carts, reprints (from `print_jobs`), discounts above limit |
| Profit | Gross profit by item/category/day (FIFO COGS) |
| Inventory | Stock on hand & valuation, stock by batch, movement history, expiry, dead stock (no sale in N days), reorder list, adjustment & stocktake variance |
| Purchasing | Purchases by supplier/item, open POs, supplier ageing |
| Customers | Receivables ageing, customer statement, credit sales |
| Cash & Finance | Drawer sessions & variances, handover history, cash book, bank book, cheque register, expenses, P&L, trial balance |
| HR | Attendance summary, late/OT, leave, payroll summary, EPF/ETF |
| Audit | Activity log, price change history, user logins, print log |

- [ ] Performance: indexes for every report filter; reports over 12 months use summary table `daily_sales_summaries` rebuilt nightly.
- [ ] Data migration: final product/stock/customer balances/supplier balances import.
- [ ] Hardware installation & configuration (section 16), user training (Sinhala quick guides per role, 1 page each), owner training on handover and reports.
- [ ] Parallel run, then go-live.

---

## 14. Journal posting rules

All sale postings happen when an invoice is **settled** at the main cashier (`InvoiceSettled`), not when it is printed at the counter. An INVOICED but unsettled invoice has no journal entry; voiding it before settlement posts nothing.

| Event | Debit | Credit |
|---|---|---|
| Cash sale | Cash Drawer | Sales Revenue; Tax Payable |
| Card / bank transfer sale | Bank / Card Clearing | Sales Revenue; Tax Payable |
| Credit sale | Accounts Receivable | Sales Revenue; Tax Payable |
| Cost of sale (every sale) | Cost of Goods Sold | Inventory |
| Sale return | Sales Returns; Inventory (if restocked) | Cash Drawer / AR; COGS |
| Customer pays (cash) | Cash Drawer | Accounts Receivable |
| Cheque received | Cheques in Hand | Accounts Receivable |
| Cheque cleared | Bank | Cheques in Hand |
| Cheque bounced | Accounts Receivable | Cheques in Hand / Bank |
| GRN posted | Inventory | Accounts Payable |
| Supplier payment | Accounts Payable | Bank / Cash / Cheques Issued |
| Stock adjustment (loss) | Inventory Loss Expense | Inventory |
| Stock adjustment (gain) | Inventory | Inventory Gain |
| Expense | Expense account | Cash Drawer / Safe / Bank |
| Cash to bank | Bank | Cash Drawer / Safe |
| Drawer variance (short) | Cash Short Expense | Cash Drawer |
| Payroll | Salaries Expense; EPF/ETF Expense | Bank / Cash; EPF Payable; ETF Payable; Staff Advances |
| Staff advance | Staff Advances | Cash / Bank |

---

## 15. Testing strategy

| Level | Tool | Scope |
|---|---|---|
| Unit | Pest | Money/qty maths, unit conversion, payroll formulas, FEFO allocation, document numbering |
| Feature | Pest + `RefreshDatabase` on **MySQL** (not SQLite: locking and FULLTEXT behave differently) | Every Action, every controller authorization, JSON endpoints hide cost fields |
| Invariants | Pest datasets | stock_levels = Σ movements; qty_reserved = Σ base_qty of INVOICED invoices; trial balance = 0; one open drawer per terminal; delegations never contain non-delegable permissions; every invoice number is SETTLED, VOID or INVOICED (no gaps) |
| Concurrency | Artisan test command running parallel processes | Two counters invoicing the last units at once, gapless numbering, double print / double settle |
| Real-time | Pest + `Event::fake` / broadcast assertions | Cart sync diff → correct `counter_events`; `CounterCartUpdated`, `InvoiceIssued`, `InvoiceSettled` broadcast on `live-billing`; channel authorization |
| Browser (critical paths) | Pest browser plugin / Laravel Dusk | Counter bill → tender → print invoice → settle on cashier screen; Live Billing updates; handover flow |
| Manual | Checklist per release | Invoice print on all 4 printers, Sinhala rendering, drawer kick, Live Billing on the owner's phone |

Target: ≥ 80 % coverage on `app/Domain`, 100 % of Actions have feature tests.

---

## 16. Deployment

**Shop server** (mini PC, i5/Ryzen 5, 16 GB RAM, 512 GB NVMe SSD, UPS with USB shutdown signal):
- Ubuntu Server 24.04 LTS · Nginx · PHP 8.3-FPM (opcache on) · MySQL 8.4 · Redis 7 · Meilisearch (systemd) · Chromium (for PDFs) · Node (build only).
- Supervisor: `queue:work redis --tries=3` (2 workers), `reverb:start`.
- Cron: `* * * * * php artisan schedule:run`.
- Static LAN IP + local hostname (`pos.citizens.local`) and a self-signed or local CA HTTPS certificate (needed for webcam and secure cookies).
- **Backups:** hourly `mysqldump` (kept 48 h locally), nightly `backup:run` to external USB disk + cloud (Google Drive/S3, encrypted), monthly restore test.
- **Remote access:** Tailscale on the server and the owner's phone (no open ports).
- **Deploy script:** `git pull` → `composer install --no-dev -o` → `npm ci && npm run build` → `php artisan migrate --force` → `optimize` → `queue:restart` → `scout:sync-index-settings`.

**Terminals** (4 PCs, Chrome, each with its own USB 80 mm thermal printer, same model):

| Terminal | Chrome shortcut | Printer | Extra |
|---|---|---|---|
| Main cashier | `chrome.exe --kiosk --kiosk-printing https://pos.citizens.local/pos/cashier` | #0 + cash drawer (RJ11) | QZ Tray installed and set to start with Windows; site certificate trusted so no pop-ups |
| Counter 1–3 | `chrome.exe --kiosk --kiosk-printing https://pos.citizens.local/pos` | #1, #2, #3 | – |

- On every PC the thermal printer is the **Windows default printer**, driver paper 80 mm, margins 0.
- Each terminal registered once by the Super Admin (Phase 0.3) and its printer recorded and test-printed (Phase 3.5).
- UPS: server, switch, router, main cashier PC and printer #0. Counter PCs on UPS too if budget allows (otherwise their carts are restored from Redis after a power cut).
- Keep **one spare printer** of the same model; it can replace any of the 4 in minutes.

---

## 17. Go-live checklist

- [ ] All products, units, prices, aliases imported and reviewed by the owner.
- [ ] Opening stock counted and posted; valuation signed off.
- [ ] Customer credit balances and supplier balances imported and agreed.
- [ ] Users created, PINs set, roles verified; employees linked.
- [ ] 4 terminals registered; **test print passes on all 4 printers** (Sinhala); drawer opens on settlement.
- [ ] Live Billing checked on the main screen and on the owner's phone with all 3 counters billing at once.
- [ ] Handover rehearsed (owner → manager → owner) with correct counts.
- [ ] Backups verified by a restore to a test machine.
- [ ] UPS shutdown tested.
- [ ] Staff trained (search tricks, tender and balance, print / reprint invoice, hold, PO creation); owner trained on settle, void and Live Billing.
- [ ] 1–2 week parallel run completed with daily reconciliation.

---

## 18. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Sinhala invoice rendering on the chosen printer | Invoices unreadable | Printing spike in Phase 0 on the real printer model; QZ Tray pixel mode as fallback; choose a printer with a proper Windows driver |
| A counter printer fails or runs out of paper | That counter can't give invoices | "Check printer" warning + reprint; spare printer of the same model; Super Admin can relink a printer in minutes |
| Invoices printed but not settled (staff delayed, customer leaves) | Stock stays reserved; cash not in drawer | Waiting timer turns red on Live Billing; day close blocked until every invoice is settled or voided; voids need a reason and are reported per counter |
| Staff remove items / reprint to hide sales | Cash loss | Every removal, clear, reprint and void is recorded in `counter_events` / `print_jobs`, shown live in red, and reported per counter |
| Product data quality (many items, no barcodes) | Poor search, wrong prices | Excel import with validation; owner review; aliases/synonym tuning in the first 2 weeks of use |
| Staff search habits differ from product names | Slow billing | Log "search with no result picked" terms; weekly synonym review |
| Power cuts | Lost bills, corrupted DB | UPS on server, network and main terminal; InnoDB; counter carts restored from Redis |
| Internet outage | None for sales (on-prem) | Only remote access and cloud backup are affected |
| Server hardware failure | Shop cannot sell | Hourly local dumps + nightly cloud backup; documented rebuild procedure; spare mini PC optional |
| Handover misuse / cash disputes | Trust issues | Mandatory counts on both sides, variance records, full audit log, remote revoke |
| Scope creep | Delays | MVP after Phase 3; later requests go to a backlog reviewed at the end of each phase |
