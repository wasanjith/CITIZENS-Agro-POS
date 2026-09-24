# CITIZENS Agro POS: Task Log

> Running record of project progress. Updated after every request.
> Plan: [ARCHITECTURE.md](ARCHITECTURE.md) · [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) · Printing: [PRINTING.md](PRINTING.md)

---

## Current status

| Item | Status |
|---|---|
| **Current phase** | Phase 1: Catalog + Search (built; waiting for the owner's product list) |
| **Last updated** | 2026-09-24 |
| **Tests** | 153 Pest tests, all passing (MySQL `citizensDB_testing`; the `Search` suite also uses the local Meilisearch) |
| **Static analysis** | Larastan level 6: 0 errors · Pint: clean |
| **Open issue** | Owner's browser sign-in problem ("These credentials do not match our records"). A headless Chrome signed in to `http://citizens.test` as `owner` / `password` without trouble on 2026-09-24, so the server side works. Still waiting for the owner's retry in a private window. |
| **Next** | Owner imports the real product list and tries 30 everyday search phrases (Phase 1 acceptance) → printing test on the first printer → Phase 2 (inventory + purchasing) |

### Phase progress

| Phase | Name | Status |
|---|---|---|
| 0 | Foundation + printing spike | 🟡 Built · real-printer test pending |
| 1 | Catalog + Search | 🟡 Built · real product import + 30-phrase search check pending |
| 2 | Inventory + Purchasing | ⚪ Not started |
| 3 | POS core: counter invoices, settlement, Live Billing, handover | ⚪ Not started |
| 4 | Customers, Credit, Returns, Quotations | ⚪ Not started |
| 5 | Finance & Banking | ⚪ Not started |
| 6 | HR, Attendance, Payroll | ⚪ Not started |
| 7 | Reports, Dashboard, Go-live | ⚪ Not started |

### Open questions for the owner

1. **VAT:** is the shop VAT-registered? Any tax-exempt products? (A "VAT 18%" rate is seeded **inactive**; products have no tax until this is answered.)
2. **Product list:** please fill the import template (Products → Import from Excel → Download template) or send the old system's export.
3. **Short code ranges:** OK with Fertilizers 1000–1999, Seeds 2000–2999, Agro-chemicals 3000–3999, Tools 4000–4999, Bicycle Parts 5000–6999, Other 9000–9999? (Editable on the Categories page.)
3. **Payroll:** calculate EPF/ETF, or simple basic + allowances − deductions? (needed for Phase 6)
4. **Printer model:** which 80 mm printer will be bought? Buy one first and run the printing test.

---

## Decisions made

| Date | Decision |
|---|---|
| 2026-09-24 | Stack: Laravel 13 + MySQL 8, modular monolith (`app/Domain/*`). |
| 2026-09-24 | **No Filament**: every page is a hand-written Blade view (Tailwind + Alpine.js). |
| 2026-09-24 | Roles: Super Admin (owner), Manager (1), Sales Staff (3 counters). Sales Staff may create purchase orders (quantities only, no cost prices); Manager/Owner approve. |
| 2026-09-24 | **Shop flow:** counter staff bill, take the customer's cash, print the invoice (paid + balance) on the counter printer, and bring invoice + cash to the main cashier, who settles it. |
| 2026-09-24 | Cashier authority can be handed to the Manager temporarily (cash count both ways, auto-expiry, remote revoke). Finance, payroll and admin can never be delegated. |
| 2026-09-24 | **Live Billing:** owner's screen split into 3 columns showing each counter's bill in real time. |
| 2026-09-24 | **4 USB thermal printers** (Counter 1–3 + main); cash drawer only on the main printer. Same model for all; buy one first and test. |
| 2026-09-24 | **Small invoices print in Sinhala**: printed as an image by Chrome (`--kiosk-printing`), because thermal printers have no Sinhala font. |
| 2026-09-24 | A4 PDFs via spatie/laravel-pdf **chrome driver** (headless Chrome); dompdf can't shape Sinhala. |
| 2026-09-24 | Poison/agro-chemical sales: customer name/NIC **not** required. |
| 2026-09-24 | On-premises server in the shop; Tailscale for the owner's remote access. |
| 2026-09-24 | Development: local MySQL 8.0 (`citizensDB`, root), Docker only for Redis + Meilisearch, site served by Laravel Herd at `http://citizens.test`. |
| 2026-09-24 | **No git** in this project (owner's instruction). |
| 2026-09-24 | Products get a `reference_cost` (cost per base unit) for the minimum-margin check until batch costs exist in Phase 2. Opening stock from the import is kept in `opening_stock_entries` and posted as OPENING stock in Phase 2. |
| 2026-09-24 | Import only **adds** products; an existing short code is an error. `retail_price`/`wholesale_price` are for the default sale unit; other units get the proportional price. |
| 2026-09-24 | A deleted product's short code is never reused. Variants share the product's prices. |
| 2026-09-24 | Excel: `maatwebsite/excel` 4.0 (the Laravel 13 compatible release). |

---

## Log

Newest first.

### 2026-09-24: Phase 1 built (Catalog + Search)
- **Tables:** categories (tree + short code range), brands, units, taxes, price lists, products (FULLTEXT ngram index), product variants, product units, product prices (price history), search synonyms, favourite products, opening stock entries.
- **Code:** `app/Domain/Catalog`: models, factories, policies, `SaveProductAction`, `ShortCodeGenerator`, `PriceBook`, `ProductSearchService`, `SyncSearchSynonymsJob`, Excel import/export (`Import/`).
- **Pages:** Products (list with filters, 5-tab form with unit editor, price grid with margin check, variants, show page with price history), Categories (tree), Brands, Units, Taxes, Search synonyms, Import (template → check → import), Excel export. Product search box in the top bar (Ctrl+K).
- **Search:** `GET /api/pos/search` (120/min per user). Exact code wins, then Meilisearch (typos, Sinhala, aliases, synonyms, fast sellers first), and MySQL FULLTEXT + LIKE if Meilisearch is down. `5*urea` gives qty 5. Cost fields only for Owner/Manager.
- **Seeders:** `CatalogSeeder` (units, price lists, categories with code ranges, inactive VAT) runs everywhere; `DevelopmentCatalogSeeder` adds 11 demo products (urea, TSP, MOP, seeds, chemicals, mammoty, tyres/tubes with variants) locally.
- **Checked in a real browser (headless Chrome):** created a product with bag = 25 kg, prices, margins and a variant, then found it from the top-bar search. Found and fixed two bugs: the default-sale-unit radios were not saved, and the delete dialog's Cancel button threw a JS error on every page with a delete dialog (Phase 0 `confirm-modal` component).
- **Tests:** 71 new (153 total). A new `tests/Search` suite commits its rows so MySQL FULLTEXT and the real Meilisearch (`testing_` index prefix, skipped if not running) are tested.
- **Deferred:** low-stock filter, stock by batch, movement history (Phase 2 tables); `RecalculateSalesVelocityJob` (needs Phase 3 sales).
- **Set up on another PC:** run `php artisan migrate`, `php artisan db:seed --class=CatalogSeeder`, `php artisan scout:sync-index-settings`, and `npm run build`.

### 2026-09-24: Task log created; duplicate sign-in records fixed
- Created this task log (`docs/TASK_LOG.md`); it is updated after every request.
- **Fix:** each successful sign-in was written to the audit log twice (Fortify runs the credential check twice). Moved the "Signed in" record to a `Login` event listener (`app/Listeners/RecordSuccessfulLogin.php`), which writes one record for both password and PIN sign-in, including the method and the terminal code.
- Tests added: one audit record per sign-in (password and PIN).

### 2026-09-24: Sign-in problem investigated
- Owner: demo accounts "don't work"; the browser shows "These credentials do not match our records".
- Checked: accounts exist in `citizensDB`, password and PINs verify; sign-in over HTTP works on `citizens.test`, `127.0.0.1:8000` and `localhost:8000`.
- Found the owner had changed `APP_URL` to `http://citizens.test/` (Herd).
- Added **failed sign-in records** to the audit log (username typed + reason: unknown username / wrong password / inactive; never the password), plus a test.
- Explained that PIN sign-in only works after the PC is registered as a terminal (Terminals → Register this device).
- Asked the owner to retry in a private window, typing `owner` / `password` by hand.

### 2026-09-24: Phase 0 built
- Laravel 13 project in `C:\projects\Citizens`; packages: Fortify, spatie permission / activitylog / pdf / backup, Scout + Meilisearch, Reverb, brick/math, chrome-php; dev: Pest 4, Larastan, Boost.
- Identity: users (username, PIN, active flag), roles and the permission catalogue in `config/pos.php`, delegation service + actions (`Gate::before`), cashier-authority resolver.
- Terminals + printers: models, device registration by cookie (only the token hash is stored), middleware `terminal`, `terminal:main_cashier`, `terminal:counter`.
- Sign-in: username + password (Fortify, 2FA available), PIN sign-in on registered terminals with lockout; self-registration and e-mail reset disabled.
- Pages: dashboard, my account (password, 2FA), users, terminals (register/unregister device), printers, settings (shop EN/SI, invoice, tax, POS rules), audit log, printing test.
- Blade UI kit: 22 `x-ui.*` components, layouts (app, pos, guest, print), `x-hotkey` directive, search-select.
- Printing spike: Sinhala 80 mm invoice sample (auto-print for `--kiosk-printing`), QZ Tray cash-drawer kick, A4 Sinhala PDF. Verified the PDF and the 80 mm layout render Sinhala conjuncts correctly.
- Gapless document numbers (`INV-2026-000001`, yearly reset).
- Seeders: roles/permissions, 4 terminals + 4 printers, sequences, demo users (local only). Command `php artisan pos:create-owner` for production.
- Removed the git repository at the owner's request.

### 2026-09-24: Planning
- Wrote `ARCHITECTURE.md` and `IMPLEMENTATION_PLAN.md` (8 phases, about 25 weeks; MVP after about 14 weeks).
- Updated for: no Filament, staff purchase orders, Sinhala invoices, 4 printers, counter-billing → settlement flow, Live Billing.
- Explained the deployment architecture (on-prem server, LAN, UPS, backups, Tailscale).

---

## How to run (development)

1. Start Docker Desktop, then: `docker compose -f docker-compose.dev.yml up -d` (Redis + Meilisearch).
2. MySQL 8.0 runs as a Windows service (database `citizensDB`).
3. Open `http://citizens.test` (Laravel Herd), or run `php artisan serve` → `http://127.0.0.1:8000`.
4. Reset demo data: `php artisan migrate:fresh --seed`, then `php artisan scout:sync-index-settings` and `php artisan queue:work --stop-when-empty` (pushes search synonyms).
5. Tests: `php artisan test --compact` · Style: `vendor/bin/pint` · Analysis: `vendor/bin/phpstan analyse`.
6. After changing CSS/JS: `npm run build` (or `npm run dev`).

### Demo accounts (local only)

| Username | Password | PIN | Role |
|---|---|---|---|
| owner | password | 1111 | Super Admin |
| manager | password | 2222 | Manager |
| staff1 / staff2 / staff3 | password | 3331 / 3332 / 3333 | Sales Staff |

PIN sign-in needs the PC registered first: sign in with a password → **Terminals** → **Register this device**.
