# CITIZENS Agro POS: Task Log

> Running record of project progress. Updated after every request.
> Plan: [ARCHITECTURE.md](ARCHITECTURE.md) · [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) · Printing: [PRINTING.md](PRINTING.md)

---

## Current status

| Item | Status |
|---|---|
| **Current phase** | Phase 5: Finance & Banking (built; waiting for the real bank accounts and opening balances) |
| **Last updated** | 2026-09-25 |
| **Tests** | 374 Pest tests, all passing (Meilisearch was running, so none skipped). |
| **Static analysis** | Larastan level 6: 0 errors · Pint: clean |
| **Open issue** | Owner's browser sign-in problem ("These credentials do not match our records"). A headless Chrome signed in to `http://citizens.test` as `owner` / `password` without trouble on 2026-09-24, so the server side works. Still waiting for the owner's retry in a private window. |
| **Next** | Owner: add the shop's bank accounts with their opening balances (Finance → Banking), then enter bank movements yourself (Move money, cheque deposits), and try an expense, a supplier payment and a customer cheque (deposit → cleared). Earlier checks still pending (product import, real PO/GRN, printers, real customer list) → Phase 6 (HR & Payroll) |

### Phase progress

| Phase | Name | Status |
|---|---|---|
| 0 | Foundation + printing spike | 🟡 Built · real-printer test pending |
| 1 | Catalog + Search | 🟡 Built · real product import + 30-phrase search check pending |
| 2 | Inventory + Purchasing | 🟡 Built · opening stock check + real PO/GRN run pending |
| 3 | POS core: counter invoices, settlement, Live Billing, handover | 🟡 Built · real printers, drawer and Reverb test pending |
| 4 | Customers, Credit, Returns, Quotations | 🟡 Built · real customer list + in-shop credit/return run pending |
| 5 | Finance & Banking | 🟡 Built · real bank accounts + opening balances pending |
| 6 | HR, Attendance, Payroll | ⚪ Not started |
| 7 | Reports, Dashboard, Go-live | ⚪ Not started |

### Open questions for the owner

1. **VAT:** is the shop VAT-registered? Any tax-exempt products? (A "VAT 18%" rate is seeded **inactive**; products have no tax until this is answered.)
2. **Product list:** please fill the import template (Products → Import from Excel → Download template) or send the old system's export.
3. **Short code ranges:** OK with Fertilizers 1000–1999, Seeds 2000–2999, Agro-chemicals 3000–3999, Tools 4000–4999, Bicycle Parts 5000–6999, Other 9000–9999? (Editable on the Categories page.)
4. **Payroll:** calculate EPF/ETF, or simple basic + allowances − deductions? (needed for Phase 6)
5. **Printer model:** which 80 mm printer will be bought? Buy one first and run the printing test.
6. **Real credit customers:** dummy customers are used for now (owner's choice, 2026-09-25). Before go-live, send the real list (name, phone, what each owes, limit, credit days).
7. **Counter staff and customers:** the reply "can counter staff" looked cut off. For now counter staff can add customers (no credit) but not set credit limits. Please confirm, or say if staff should also set limits.

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
| 2026-09-25 | **Credit:** a credit sale needs a customer. It hits the customer's account when the cashier settles it (not when it prints), due after the customer's credit days. Going over the credit limit needs the Super Admin's tick at settlement; a delegated Manager can never allow it. |
| 2026-09-25 | Counter staff may quick-add a customer (name, phone, village) with no credit; only the Owner/Manager set credit limits. Phone numbers are unique. |
| 2026-09-25 | Customer payments are taken at the main cashier (drawer holder); cash counts in the drawer. Oldest invoice first unless the cashier chooses; any extra stays as an advance. |
| 2026-09-25 | **Returns** only at the main cashier (`pos.refund`), always against the original invoice. Refund = the line's share after the bill discount; cash from the drawer, or credit to the customer's account (forced while a credit invoice is unpaid). Stock goes back to the batches it was sold from; damaged goods are written off as DAMAGE. A returned invoice can no longer be voided. |
| 2026-09-25 | **Quotations** reserve no stock and are billed at today's prices when loaded (F7); valid 7 days (Settings → Customers). Owner confirmed today's prices. |
| 2026-09-25 | **Credit bill:** when the cashier settles a credit sale, a "ණය බිල්පත / Credit bill" (shop copy) prints on the main printer with the customer's details, items, total, due date and account balance, and space for the customer's signature, the owner's signature and the shop seal. The shop keeps it. Reprints are marked COPY. |
| 2026-09-25 | Returns: cash or credit to account, confirmed by the owner. |
| 2026-09-25 | **No SMS** reminders (owner). Overdue credit is only a morning bell notification to the Owner/Manager; the SMS code and setting were removed. Dummy customers for development. |
| 2026-09-25 | **Journal posting** happens inside the same database transaction as the sale, payment, GRN, adjustment or drawer action (called directly, not through queued listeners), so a document can never exist without its journal entry. Each document posts once per event; entries are never edited, only reversed. |
| 2026-09-25 | **Cash places:** Cash drawer (main cashier), Safe, bank accounts, and the Owner (capital in, drawings out). Opening float comes from the safe; at closing the counted cash goes back to the safe and a shortage/excess is posted as Cash short / Cash over. Drawer "pay in" = change from the safe, "pay out" = cash the owner takes, "safe drop" = to the safe. Shop expenses go through Expenses. |
| 2026-09-25 | Card and bank-transfer payments go to the one bank account marked for them (else a clearing account). Cheques from customers go to "Cheques in hand" and only reach the bank when marked cleared; post-dated cheques cannot be deposited early. A bounced cheque makes the customer owe it again (the invoices it paid are unpaid again); a walk-in customer's bounced cheque goes to "Dishonoured cheques". |
| 2026-09-25 | Banks, cheques, the journal, the chart of accounts and financial reports are **Super Admin only** (never delegated). The Manager records petty-cash expenses from the drawer; only the owner pays from the safe or a bank, cancels expenses and pays suppliers. |
| 2026-09-25 | Supplier payments are applied to goods receipts oldest first (or as chosen); extra stays as an advance. A cancelled or bounced supplier cheque makes those receipts unpaid again. |
| 2026-09-25 | **Owner:** returns to a supplier go back at the **buying price** (the goods receipt price, net of its discount; else the supplier's last price), not the average stock cost. The difference to the stock cost is a stock gain / loss. |
| 2026-09-25 | **Owner:** the owner enters the opening bank balances and every bank movement (deposits, transfers, cheque deposits). Card and bank-transfer payments wait under "Card & transfer payments" until moved into a bank (automatic posting can still be switched on per bank account). |
| 2026-09-25 | **Owner:** the counted cash goes home at closing and the next day starts with the cashier's morning float brought from it. The "safe" account is shown as **Cash at home (day's takings)**. |

---

## Log

Newest first.

### 2026-09-25: Owner's answers on Phase 5
- **Supplier returns at the buying price:** each line now uses what the shop paid the supplier (the goods receipt line the batch came from, else this product's line on the chosen goods receipt, net of that receipt's discount; else the supplier's last price). The supplier's balance goes down by that amount; the stock leaves at its stock cost and the difference is posted as a stock gain or loss. The return page shows "Buying price".
- **Bank balances entered by the owner:** new bank accounts no longer take card payments automatically (the option is off by default and explained on the form). Card and transfer money waits under "Card & transfer payments"; the Banking page says how much and links to **Move money**, which now has "Card & transfer payments (not yet in a bank)" as a source.
- **Cash goes home:** the "safe" is now called **Cash at home (day's takings)** everywhere (Banking, Move money, expenses, supplier payments, cash book); the drawer page explains pay in / pay out / safe drop that way. How it works did not change: the morning float comes from it and the counted cash returns to it at closing. Renamed on this PC's `citizensDB` too.
- **Tests:** 374, all passing (new: return at the goods receipt price with the stock gain, return at the supplier's last price, card money moved into the bank by hand). Larastan 0 errors, Pint clean.

### 2026-09-25: Phase 5 built (Finance & Banking)
- **Tables:** accounts (chart of accounts), journal_entries + journal_lines, bank_accounts, bank_transactions, bank_reconciliations, cheques, expense_categories, expenses, supplier_payments (+ allocations); goods receipts got `amount_paid`, cash movements a link to the document that took the cash, customer payments `reversed_at`. New numbers: `JE-` (journal), `EXP-` (expenses), `SP-` (supplier payments).
- **Journal:** `JournalService` refuses any entry whose debits and credits differ and never edits an entry (reversals only). `FinancePosting` holds the posting rules of the plan (section 14) and is called by the existing actions: settlement (cash / card / cheque / credit), void of a settled sale (reversal), returns (restocked and damaged), customer payments, customer and supplier opening balances, GRNs, supplier returns, stock adjustments, stocktakes, opening stock, drawer open / close (variance) and pay in / pay out / safe drop. 28 system accounts are created automatically; each bank account gets its own 15xx account and each expense head a 6xxx account.
- **Banking** (menu → Finance → Banking): balances of every bank, the safe, the drawer and cheques in hand; add/edit bank accounts with an opening balance; bank book per account with a date range and running balance; bank charges and interest; **Move money** (safe / drawer → bank, bank → safe, bank → bank, owner in / out); **reconciliation** (tick lines on the statement, live difference, history).
- **Cheques:** received cheques are created by settlement or a customer payment (the payment form now asks for the cheque's bank, branch and date); issued cheques by supplier payments. Register (received / issued, status filter), **post-dated cheque calendar**, cheque page with deposit → cleared / bounced / cancelled and its history. Morning bell at 07:10 for cheques ready to deposit and issued cheques due within 3 days (Settings → Finance).
- **Expenses:** with a photo or PDF of the bill; paid from the drawer (petty cash, Manager too), the safe or a bank (owner); cancel reverses it and puts the money back; 11 expense heads seeded (rent, electricity, transport …), more can be added.
- **Supplier payments** (Purchasing → Supplier payments, "Pay supplier" on the supplier page): cash from the drawer or safe, bank transfer or cheque, applied to goods receipts oldest first or as chosen.
- **Reports:** profit & loss, balance sheet, trial balance, cash book (safe / drawer), journal, chart of accounts with balances and a ledger per account.
- **Backfill:** `php artisan finance:backfill-journals` posts journals for documents created before Phase 5, oldest first; running it again adds nothing.
- **Tests:** 62 new (372 total). After every finance test the books are checked: every entry balances, the trial balance balances, receivables = customer ledger, payables = supplier ledger, each bank's ledger account = its bank book. Also: cheque bounce restores the receivable and the invoices, cancelled supplier cheque makes the GRN unpaid again, post-dated deposit blocked, petty cash lowers the drawer's expected cash, the Manager cannot pay from the safe/bank or open banking/journal even while holding cashier authority, backfill gives the same trial balance.
- **Checked in headless Chrome** (throwaway database `citizensDB_browser`, `php -S` on port 8123): sign-in, banking overview, move money, reconciliation difference updating live, expense form showing the bank only for bank payments, supplier payment page, reports and journal. No JavaScript errors. The dev database was not touched by the check.
- **This PC's `citizensDB`:** migrated, chart of accounts and expense heads seeded, and the backfill posted the 5 dummy customers' opening balances. No demo bank accounts were added (`DevelopmentFinanceSeeder` adds two on `migrate:fresh --seed`).
- **Set up on another PC:** `php artisan migrate`, `php artisan db:seed --class=DocumentSequenceSeeder`, `php artisan db:seed --class=ChartOfAccountsSeeder`, `php artisan finance:backfill-journals`, `npm run build`. The scheduler must run for the 07:10 cheque reminder.
- **Noticed (not changed):** a return to a supplier of a product without batch tracking is valued at the moving-average cost of the general stock batch, not at the price on the GRN, so the supplier's balance goes down by that average (Phase 2 behaviour). Tell me if returns should use the GRN price instead.

### 2026-09-25: Owner's answers on Phase 4: credit bill, dummy customers, no SMS
- **Credit bill:** settling a credit sale at the main cashier now also prints a credit bill (`print/credit-bill.blade.php`) on the main printer: customer name (Sinhala), code, phone, NIC, address, items, total, due date, credit days, total owed on the account, the promise to pay, and lines for the customer's signature and the owner's signature plus a box for the shop seal. The cashier screen says "get the customer's signature and stamp the shop seal". A retried settlement does not print it twice. Invoice page: "Credit bill" (view) and "Reprint credit bill" (COPY, main terminal).
- **Dummy customers:** `DevelopmentCustomerSeeder` (8 customers, Sinhala names, villages, limits, credit days, 5 opening balances, 1 inactive). Runs with `migrate:fresh --seed` locally; added to this PC's `citizensDB` now. Safe to run again.
- **No SMS:** removed the SMS gateway, its setting and the SMS part of the reminder job; the morning bell notification stays (can be switched off in Settings → Customers & credit).
- Quotation prices and the return rules stay as built (owner confirmed).
- **Tests:** 310, all passing (new: credit bill printed once, content, reprint as COPY, none for cash sales; seeder; reminder without SMS). Larastan 0 errors, Pint clean. `npm run build` done.

### 2026-09-25: Phase 4 built (Customers, Credit, Returns, Quotations)
- **Tables:** customers, customer_ledger, customer_payments (+ allocations), sale_returns (+ lines, + line batches), quotations (+ lines); sales got a customer link, `due_date` and `quotation_id`. New numbers: `C-00001` (customers), `RCP-`, `RET-`, `QT-`.
- **Customers** (menu → Customers): list with filters (village, owes money, overdue), add/edit with price list, credit limit, credit days and an opening balance from the old books, profile with balance, unpaid invoices, ageing, payments and the account, **statement PDF** (Sinhala/English), and a **credit ageing** report for everyone.
- **Counter screen:** **F4** finds a customer by phone, name, NIC or village (shows what they owe, credit left, overdue) or quick-adds one (name, phone, village; no credit). The customer's price list is applied. **Credit** payment needs a customer; the invoice prints marked "ණය ඉන්වොයිසිය / CREDIT INVOICE" with the customer. **F7** prints a quotation from the bill; F7 on an empty bill lists open quotations to load and bill.
- **Cashier:** the settle card shows the customer's balance and limit. Over the limit only the owner can allow it (tick box); a delegated Manager cannot. New buttons: **Customer payment** (oldest invoice first or chosen amounts; extra stays as an advance; Sinhala receipt on the main printer) and **Return** (find the invoice, quantities per line, restock or damaged, cash from the drawer or credit to the account; Sinhala return receipt). Invoice pages have a "Return items" button at the main terminal.
- **Drawer and Z report:** cash customer payments count in the expected cash; cash refunds are shown separately; the Z report lists customer payments and returns.
- **Voids:** voiding a settled credit sale takes it off the customer's account; a sale with payments applied or returns can no longer be voided (use a return).
- **Reminders:** every morning at 07:05 the Owner/Manager get a bell notification about overdue credit. SMS reminders (days 1, 7, 14, 30 overdue) can be switched on in Settings → Customers & credit, but only write to the log until an SMS provider is chosen.
- **Tests:** 53 new (307 total): credit limit and override, ledger balance = debits − credits = unpaid invoices, FIFO and manual payment allocation, advances, drawer cash, returns never above sold − returned, stock back to the original batch, damage write-off, bill-discount shares adding up to the invoice total, quotations (convert, expiry, idempotency), overdue reminders, and Sinhala receipts.
- **Checked in headless Chrome** (throwaway database `citizensDB_browser`, served with `php -S` on port 8123): F4 search and select, credit invoice, credit blocked without a customer, F7 quotation → load → bill, quick-add, cashier credit settlement with the balance shown, and the new pages. No JavaScript errors. This found and fixed a bug: the counter's `customer` data and the `customer()` (F4) function had the same name, so the F4 key stopped working after a customer was chosen (renamed to `openCustomer()`). The dev database was not touched by the check.
- **Also fixed:** this status table still said Phase 2; it now shows Phases 3 and 4 as built.
- **Set up on another PC:** `php artisan migrate`, `php artisan db:seed --class=DocumentSequenceSeeder`, `npm run build`. The scheduler must run for the 07:05 overdue reminder. (Done on this PC's `citizensDB`.)
- **Not checked:** real printers for the new receipts, SMS sending (no provider yet).

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
