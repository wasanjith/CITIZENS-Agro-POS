# CITIZENS Agro POS: Task Log

> Running record of project progress. Updated after every request.
> Plan: [ARCHITECTURE.md](ARCHITECTURE.md) · [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) · Printing: [PRINTING.md](PRINTING.md)

---

## Current status

| Item | Status |
|---|---|
| **Current phase** | Phase 2: Inventory + Purchasing (built; waiting for opening stock count + a real PO/GRN run) |
| **Last updated** | 2026-09-25 |
| **Tests** | 209 Pest tests: 200 passing, 9 skipped (Meilisearch tests; Docker was not running). New `Concurrency` suite races two MySQL connections. |
| **Static analysis** | Larastan level 6: 0 errors · Pint: clean |
| **Open issue** | Owner's browser sign-in problem ("These credentials do not match our records"). A headless Chrome signed in to `http://citizens.test` as `owner` / `password` without trouble on 2026-09-24, so the server side works. Still waiting for the owner's retry in a private window. |
| **Next** | Owner imports the real product list (opening stock now goes straight into stock) and checks stock against the count sheet → one real PO → approve → receive in two deliveries (Phase 2 acceptance) → printing test on the first printer → Phase 3 (POS) |

### Phase progress

| Phase | Name | Status |
|---|---|---|
| 0 | Foundation + printing spike | 🟡 Built · real-printer test pending |
| 1 | Catalog + Search | 🟡 Built · real product import + 30-phrase search check pending |
| 2 | Inventory + Purchasing | 🟡 Built · opening stock check + real PO/GRN run pending |
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
| 2026-09-25 | Stock only changes through `StockService` (locked rows, append-only `stock_movements`). Stock can never go below zero unless Settings → Inventory allows it. |
| 2026-09-25 | Products without batch tracking keep one "general stock" batch with a **moving-average** cost; batch-tracked products get one batch per delivery (FEFO issue, FIFO cost). |
| 2026-09-25 | GRN batch cost = line total after the GRN discount ÷ all units received, so **free goods lower the cost**. The product's reference cost follows the last GRN. |
| 2026-09-25 | Receiving more than is still outstanding on a PO line is refused; extra goods go in as free quantity or a separate line. Posted GRNs are never edited; mistakes are fixed with a supplier return. |
| 2026-09-25 | Stock adjustments and stocktake differences above **Rs. 10,000** (Settings → Inventory) need the Super Admin. |
| 2026-09-25 | Journal postings for GRNs and adjustments are left for Phase 5 (Finance), when the chart of accounts exists. Supplier payments also come with Phase 5. |

---

## Log

Newest first.

### 2026-09-25: Phase 3 built (POS: counter invoices, settlement, Live Billing, handover)
- **Tables:** drawer_sessions (one open per terminal, database constraint), cash_movements, sales, sale_items, sale_item_batches, payments, approval_requests, counter_events, print_jobs; delegations got `expiry_processed_at`.
- **Counter screen `/pos`:** search box always focused (codes, names, Sinhala, `5*urea`), favourites / recent / categories, stock badge and expiry hint, cart with unit switch, line and bill discounts, large total, tender panel with quick amounts and a large balance. Keys: F2 search, ↑↓ Enter, + / − / Del, F4, F6, F8 hold / recall, F9 print, F10 reprint, Esc clear. "Last invoices" drawer with reprint.
- **Printing an invoice (F9):** the server reprices every line (the counter's prices are ignored), checks tendered ≥ total for cash, reserves stock, takes the next gapless number and prints the 80 mm Sinhala invoice silently on that PC's printer. The same key never makes two invoices. A discount above the staff limit waits for the cashier's approval.
- **Cashier screen `/pos/cashier` (main terminal only, drawer holder with cashier authority):** Live Billing columns with settle cards, find by the last digits, settle (stock issued first-expiry-first-out, payment in the drawer session, drawer kick through QZ Tray for cash), void with a reason (the counter is offered the bill back), discount approvals.
- **Drawer and handover:** open with a denomination count, pay in / pay out / safe drop, close the day (blocked while invoices wait) with a Z report per counter for the whole day (80 mm and A4). Hand over to the Manager (count, Manager's PIN and matching count, expiry, scope), take back with the owner's PIN, remote revoke from `/admin/delegations`, expiry checked every minute. Handover slips print on the main printer.
- **Live Billing `/admin/live-billing`:** the same columns, view only, phone tabs. It updates over Reverb and polls every 3 s when Reverb is down.
- **Also:** invoices list and detail page (items, batches and cost for Owner/Manager, payments, trail), print log, printer test print and reassigning a printer to another terminal, drawer / handover history, dashboard POS card, "invoice sound" setting, nightly counter-event pruning (90 days) and sales-velocity job (deferred from Phase 1).
- **Tests:** 45 new (254 total): issue → settle, idempotency, repricing, tendered check, stock reservation, first-expiry-first-out batches, voids (waiting and settled), who may settle (staff, Manager with / without handover, counter terminal), expiry, handover and take back, the one-open-drawer constraint, the day-close block, the Z report, cart-sync events, channel authorisation, no costs in Live Billing, and Sinhala invoice rendering.
- **Checked in headless Chrome** (on a throwaway copy of the database) with two browsers: counter billing → F9 → cashier sees the invoice → settles; discount approval round trip. No JavaScript errors. This found and fixed two search races (a slow result, or Enter pressed straight after typing, could add the wrong item).
- **Not checked:** real printers and cash drawer, and Reverb (it was not running, so only the 3-second polling was tested).
- **Set up on another PC:** `php artisan migrate`, `npm run build`. The scheduler must run for handover expiry and the nightly jobs. Start Reverb (`php artisan reverb:start`) for instant Live Billing.
- **Note:** a first browser run used the development database by mistake (`artisan serve` ignores custom database settings). It registered MAIN and Counter 1 to a test browser, opened a drawer and wrote 11 audit rows. All of it was removed; nothing else was touched.

### 2026-09-25: Sidebar scrollbar colour
- The sidebar scrollbar is now thin and green to match the sidebar. The sidebar colour itself is unchanged.

### 2026-09-25: Sidebar scrolling fixed
- The sidebar menu now scrolls when it is taller than the screen. The logo and version line stay in place (desktop and mobile).

### 2026-09-25: Phase 2 built (Inventory + Purchasing)
- **Tables:** batches, stock_levels, stock_movements (append-only), stock_adjustments (+ lines), stocktakes (+ lines), suppliers, supplier_products, purchase_orders, po_lines, goods_receipts, grn_lines, supplier_returns (+ lines), supplier_ledger, notifications. New document numbers: `SRN-`, `ADJ-`, `STK-`.
- **Stock engine:** `StockService` does receive, issue (first-expiry-first-out across batches), reserve/release (for Phase 3 counter invoices) and adjust. Rows are locked, so two counters can't both sell the last units. Tested with two real MySQL connections.
- **Purchasing:** suppliers (balance + ledger), purchase orders (staff enter quantities only; Manager/Owner fill costs and approve or reject; reorder suggestions; A4 PDF; WhatsApp link), goods receipts (from a PO or direct; free quantity, lot, expiry; partial deliveries), supplier returns from a chosen batch.
- **Inventory pages:** stock on hand (by product / by batch / low stock, stock value for Owner/Manager), expiring stock (30/60/90/180 days, with write-off), movement history, adjustments with approval, stocktakes (tablet counting page that saves each count as you go, printable blind count sheet, review, post).
- **Also:** bell with notifications in the top bar (PO waiting for approval, PO approved/rejected, adjustment waiting, daily 07:00 stock alert); product search now shows live stock; product page shows stock by batch and recent movements; product list has an "In stock" column and a low-stock filter; Settings → Inventory tab.
- **Opening stock:** the product import now adds opening stock to inventory straight away. Entries from earlier imports: **Stock on hand → Add opening stock now** (or `php artisan inventory:post-opening-stock`).
- **Cost hiding:** Sales Staff never see unit costs, PO totals, stock value or batch costs (pages, JSON, PDF). Tested.
- **Checked in headless Chrome:** the PO line editor (search, unit, cost prefill, totals) and the adjustment editor work with no JS errors.
- **Tests:** 56 new (209 total), including the plan's acceptance case (staff PO → manager approves → two deliveries → stock and supplier balance correct) and the invariant *stock level = sum of movements* after every inventory test.
- **Set up on another PC:** `php artisan migrate`, `php artisan db:seed --class=DocumentSequenceSeeder`, `npm run build`. For the daily alert, the scheduler must run (`php artisan schedule:work`, or a Windows task running `schedule:run` every minute).
- **Noticed:** with Docker stopped, the site returns 500 (sessions, cache and queue use Redis), and product search waits several seconds before it falls back to MySQL when Meilisearch is down. Neither was changed.

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
