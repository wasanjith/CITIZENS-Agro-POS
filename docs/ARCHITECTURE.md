# CITIZENS Agro POS: Solution Architecture

> Retail POS for CITIZENS Agro (fertilizers, seeds, agro-chemicals, mountain bicycle spare parts)
> Stack: Laravel + MySQL · Version 1.1 · 2026-09-24

---

## 1. How the requirements are read

**How the shop works:** counter service only. Customers queue outside, in front of **three service counters**. Customers cannot enter the shop because it sells agro-chemicals (poisons), so there is no self-service.

1. The counter staff member builds the bill and tells the customer the total.
2. The customer pays the counter staff member, who types the **amount tendered**. The system shows the **balance** (change).
3. Staff print the **invoice on their own counter printer**. It shows the total, amount paid and balance.
4. Staff take the invoice and the money to the **main cashier (owner)**. The owner checks both, takes the money, and gives the balance back to the staff member, who hands it with the invoice to the customer.

The owner is the only person who keeps the shop's money: **the cash drawer is only at the main cashier.** Counter staff hold a customer's cash only for the few seconds it takes to walk to the cashier. When the owner leaves, **cashier authority** (receiving money from counters, the drawer, approvals) is formally handed to the Manager with a cash count, then taken back later.

So the design uses two separate ideas:

| Concept | Meaning |
|---|---|
| **Role** (fixed) | What a person can do: Super Admin, Manager, Sales Staff (counter staff) |
| **Cashier Authority** (transferable) | Who currently owns the cash drawer and may settle invoices. Exactly one holder at any time. |

### Role and permission matrix

| Capability | Super Admin (Owner) | Manager (1) | Sales Staff (3 counters) |
|---|:-:|:-:|:-:|
| Search products, build bills, enter amount tendered, print invoice | ✅ | ✅ | ✅ |
| Reprint an invoice (always marked COPY and logged) | ✅ | ✅ | ✅ |
| See stock qty and availability | ✅ | ✅ | ✅ (no cost price) |
| **Settle invoices** (receive money from counters), open drawer | ✅ | 🔁 only while delegated | ❌ |
| **Live Billing** monitor (3 counters in real time) | ✅ | 🔁 only while delegated | ❌ |
| Void, refund, discount above limit | ✅ | 🔁 only while delegated | ❌ (request approval) |
| Products, pricing, categories | ✅ | ✅ | ❌ |
| Create / submit purchase orders | ✅ | ✅ | ✅ (quantities only; no cost prices) |
| Approve & send POs, GRN, suppliers, stock adjustments | ✅ | ✅ (adjustments above a threshold need approval) | ❌ |
| Customers and credit accounts | ✅ | ✅ | view only |
| Employees, attendance, payroll | ✅ | view attendance | own attendance only |
| Bank accounts, cheques, finance | ✅ | ❌ (never delegable) | ❌ |
| Profit reports, cost prices, user management, settings | ✅ | limited | ❌ |

🔁 = granted temporarily through **Cashier Handover** (section 5).

---

## 2. Tech stack

| Layer | Choice | Why |
|---|---|---|
| Backend | **Laravel (latest stable), PHP 8.3+** | Required stack |
| Database | **MySQL 8.x (InnoDB, utf8mb4)** | Transactions, row locking, and Sinhala/Tamil text support |
| UI (all pages) | **Blade templates + Blade components**, **Tailwind CSS**, built with **Vite** | Every page is a hand-written Blade view. No admin-panel framework (no Filament). |
| Interactivity | **Alpine.js** + small JSON endpoints (`fetch`) | POS cart lives in the browser for instant response; search, stock lookup and cart sync go through lightweight JSON routes |
| Search | **Meilisearch** through **Laravel Scout**, with MySQL FULLTEXT (ngram) as fallback | Typo-tolerant search as you type in under 50 ms. Critical because there is no barcode scanner. |
| Real-time | **Laravel Reverb** (WebSockets) | Live Billing (3 counters on the owner's screen), invoices waiting for settlement, live stock updates |
| Cache / queue | **Redis** + Laravel Queues (Supervisor) | Live cart state, search indexing, PDFs, notifications |
| Auth / RBAC | Laravel auth + **spatie/laravel-permission** | Roles and permissions, plus a delegation layer |
| Audit | **spatie/laravel-activitylog** | Every price change, void, discount, adjustment and handover is logged |
| PDF | **spatie/laravel-pdf** (chrome driver: chrome-php drives headless Chrome/Chromium directly) | Invoices, payslips, POs. Chromium shapes Sinhala text correctly; dompdf does **not** support Sinhala shaping, so it is not used. |
| Receipt printing | **Browser print of a Blade invoice page** (Chrome `--kiosk-printing` on **all 4 PCs**, Windows thermal printer driver) | Sinhala invoices print correctly because the browser renders the text as an image for the printer (see section 10a). |
| Cash drawer | **QZ Tray** on the main cashier PC only | Sends the raw ESC/POS "open drawer" command when an invoice is settled (settlement itself prints nothing) |
| Backups | **spatie/laravel-backup** | Nightly backup to an external disk and cloud storage |

### Deployment: on-premises first

```
                 ┌──────────────────────── Shop LAN ─────────────────────────┐
                 │                                                            │
 [Main Cashier PC]──┐                   ┌──── Shop Server (mini PC + UPS) ───┐│
  + USB printer #0  │                   │ Nginx + PHP-FPM (Laravel)          ││
  + cash drawer     │                   │ MySQL 8 · Redis · Meilisearch      ││
  + QZ Tray         ├──── switch ──────▶│ Reverb · Queue workers · Scheduler ││
 [Counter 1 PC] ────┤                   └──────────────┬─────────────────────┘│
  + USB printer #1  │                                  │                      │
 [Counter 2 PC] ────┤                                  │                      │
  + USB printer #2  │                                  │                      │
 [Counter 3 PC] ────┘                                  │                      │
  + USB printer #3                                     │                      │
                 └─────────────────────────────────────┼──────────────────────┘
                                                       │ Tailscale
                                     Owner's phone (dashboard, Live Billing, revoke)
                                     Nightly encrypted backup → cloud
```

Why on-premises: **sales must keep working when the internet goes down.** The owner still gets secure remote access through a tunnel.

---

## 3. Application architecture: modular monolith

One Laravel app, split into clear domain modules. Each module uses Actions (single-purpose service classes), Events and Policies.

```
app/
├── Domain/
│   ├── Identity/      Users, Roles, Terminals, Printers, PIN login, Delegation
│   ├── Catalog/       Products, Variants, Units, Brands, Categories, Search
│   ├── Inventory/     Stock ledger, Batches/Lots, Adjustments, Transfers, Stocktake
│   ├── Sales/         Counter billing, Invoices, Settlement, Live Billing, Payments, Returns, Quotations
│   ├── CashDrawer/    Drawer sessions, Handover, Cash in/out, Z-report
│   ├── Purchasing/    Suppliers, Purchase Orders, GRN, Supplier returns/payments
│   ├── Customers/     Customers, Credit accounts, Receivables
│   ├── HR/            Employees, Attendance, Leave, Payroll, Advances
│   ├── Finance/       Bank accounts, Cheques, Expenses, Journal (ledger)
│   └── Reporting/     Dashboards, Reports, Exports
│       each: Models/ Actions/ Events/ Listeners/ Policies/ Data/ (DTOs)
├── Http/
│   ├── Controllers/{Module}/   Page controllers returning Blade views
│   ├── Controllers/Api/        Fast JSON endpoints (search, stock lookup, cart sync, live snapshot)
│   └── Requests/{Module}/      Form Request validation
└── View/Components/            Class-based Blade components (tables, modals, forms)

resources/views/
├── layouts/           app.blade.php (back office), pos.blade.php (full-screen POS)
├── components/        ui/ (button, input, select, modal, table, badge, pagination),
│                      pos/ (search-box, cart, tender-panel, counter-column, settle-card)
├── pos/               counter screen, cashier screen (live billing + settlement),
│                      handover, drawer count
├── catalog/ inventory/ purchasing/ customers/ hr/ finance/ reports/ settings/
│                      each: index, create, edit, show .blade.php
└── print/             invoice (80 mm), Z report, PO, GRN, payslip (Blade → thermal / PDF)
```

**UI conventions:**
- All pages are server-rendered Blade views that extend a layout and are built from shared Blade components, so every screen looks the same.
- List pages use server-side pagination, filtering and sorting (query string based). Heavy tables (stock, sales) use `simplePaginate` + indexed filters.
- Alpine.js only where the page needs live behaviour: POS cart, search-as-you-type, Live Billing columns, modals, handover denomination counter, PO line editor.
- Real-time (Live Billing, settlement list, stock badges) uses Laravel Echo + Reverb inside Alpine components.
- Menus and buttons are shown with `@can` / `@role` directives; the same checks are enforced again in Policies.

**Rules:**
- Controllers stay thin. All business logic lives in **Actions**, e.g. `IssueCounterInvoiceAction`, `SettleInvoiceAction`, `ReceiveGoodsAction`, `HandoverCashierAction`.
- Modules talk to each other through **events**. For example, `InvoiceSettled` → Inventory issues stock, Finance posts journal entries, Customers updates credit.
- All money and stock changes happen inside **DB transactions with `lockForUpdate()`**.

---

## 4. Sales flow: counter billing → cashier settlement

```
 Counter 1-3 (Sales Staff)                          Main Cashier (Owner / delegate)
 ─────────────────────────                          ───────────────────────────────
 Search → add items → qty/unit                      Live Billing: sees the cart being built
 Tell customer the total                              on that counter's column, in real time
 Customer pays → type amount tendered
   → balance calculated
 [F9 Print Invoice]  IssueCounterInvoiceAction
   → invoice_no assigned, status INVOICED
   → stock RESERVED
   → invoice printed on the COUNTER printer
     (total · paid · balance, in Sinhala)
   → broadcast ─────────Reverb─────────────────▶  Column shows "INV-004512 · Rs 21,000
                                                     · paid 22,000 · balance 1,000 · 0:45"
 Staff walks to cashier with invoice + money  ───▶  Owner checks invoice and money
                                                     [Settle]  SettleInvoiceAction
                                                       → stock ISSUED (FEFO batches)
                                                       → payment recorded in drawer session
                                                       → status SETTLED, drawer opens (QZ Tray)
                                                       → journal posted
 Staff returns with balance + invoice  ◀───────────  Owner gives the balance back
 Customer gets balance + invoice
```

**Sale statuses:** `ON_HOLD → INVOICED → SETTLED | VOID`, then `PARTIALLY_RETURNED` / `RETURNED`. Before printing, the cart is not a database row; it lives in Redis (see Live Billing, section 6a).

**Rules:**
- **The invoice number is assigned at the counter** when the invoice is printed. Numbering is gapless; a voided invoice keeps its number and shows as VOID.
- **Stock** is reserved when the invoice is printed and issued when it is settled. An unsettled invoice can't be sold twice, and the stock ledger only moves for real sales.
- **Payment methods at the counter:** CASH (tendered → balance). CARD, BANK TRANSFER, CHEQUE and CREDIT can be chosen too; they are marked "to confirm at cashier", and the owner enters the reference or approves the credit at settlement.
- **Wrong amount, wrong item, or customer walked away:** the owner **voids** the invoice with a reason. The cart comes back on the counter so staff can re-bill with a new invoice number. Printed invoices are never edited.
- **Discount above the staff limit:** a real-time approval request goes to the owner **before** the invoice can be printed.
- **Day close:** the drawer cannot be closed while any invoice is still INVOICED. The Z report shows sales and voids per counter.
- **Direct sale:** the owner can also bill on the main terminal. That invoice prints on the main printer and is settled immediately.
- **Hold / recall** a bill when a customer needs time (e.g. to phone someone).
- **Quotations** that convert to a bill.
- **Returns and refunds** always linked to the original invoice, and always need cashier authority.
- **Idempotency key** on print and settle, so a double click cannot create two invoices or settle twice.

---

## 5. Cashier Handover (temporary delegation)

The owner's key requirement, so it gets a formal, auditable workflow:

```
1. Owner clicks "Hand Over Cashier" on the main terminal
2. Owner counts the drawer (denomination entry: 5000×3, 1000×12 …)
   → system calculates expected cash → variance recorded → Drawer Session #1 CLOSED
3. Manager enters PIN on the same terminal and accepts the counted amount
   → Drawer Session #2 OPENED (holder = Manager, opening float = counted cash)
   → Delegation row created: scope = [pos.settle, pos.live_view, pos.void, pos.refund,
     pos.discount.override, pos.approve_requests], expires_at = end of day
4. Owner is logged out of the cashier terminal (can still watch Live Billing from their phone)
5. Owner returns → reverse handover with a count → Manager's session closed with a variance report
```

Invoices already printed at the counters but not yet settled simply stay in the list; whoever holds cashier authority settles them.

**Guarantees:**
- **Only one open drawer session** per register. Enforced by a DB unique constraint on `(terminal_id, is_open)`.
- **Some permissions can never be delegated**: bank, payroll, user management, cost/profit reports and settings stay with the Super Admin.
- A delegation **auto-expires** at the time the owner sets. The owner can **revoke it remotely**.
- Settlement is only allowed on the **registered main-cashier terminal**. Terminals are bound by a device token, so a delegate cannot settle from a counter PC.
- How it's implemented: `Gate::before()` checks `delegations` where `to_user = me AND now() BETWEEN starts_at AND expires_at AND revoked_at IS NULL`.

---

## 6. Search engine design (no barcode, so this is critical)

### Product search data

| Field | Example |
|---|---|
| `short_code` (unique, easy to type, PLU-style) | `1023` |
| `name` | Urea 50kg |
| `name_si` / `name_ta` | යූරියා |
| `aliases` (Singlish and slang, editable by the owner) | yuriya, urea bag, u50 |
| brand, category | Lanka Fertilizer · Fertilizers |
| attributes | NPK `46-0-0`, size `26"`, pack `1kg` |
| compatibility (bike parts) | 26" MTB, Shimano 7-speed |

### Meilisearch index setup
- **Searchable order:** short_code → name → aliases → local names → brand → attributes → category
- **Typo tolerance** on, so `ureea` and `tyre 26` still match.
- **Synonyms** managed from an admin screen, e.g. `TSP ↔ triple super phosphate` and `tube ↔ tyre tube`.
- **Custom ranking:** relevance first, then `sales_velocity_30d` (fast sellers rise to the top).
- **Filters:** category, brand, in-stock only, active.
- **Live stock is not taken from the index.** Search returns IDs, and qty is read live from `stock_levels` (one indexed query), so availability is always exact.

### POS search UX (keyboard-first)
- The search box is always focused. Results show as you type (debounced 80 ms).
- `↑ ↓ Enter` adds the item. `5*urea` adds with qty 5. Typing an exact `1023` code adds it instantly.
- Each result shows name, unit price, **stock badge** (green / amber / red), batch expiry for seeds, and alternative units (bag / kg).
- Quick grids: **Favorites**, **Recently sold**, and category tiles for people who prefer to tap.
- Hotkeys (counters): F2 search, F4 customer, F6 amount tendered, F8 hold, **F9 print invoice**, F10 reprint last invoice.
- **Fallback:** if Meilisearch is down, Scout switches to the MySQL FULLTEXT ngram index, so search still works.

---

## 6a. Live Billing (owner watches all 3 counters in real time)

The main cashier screen (`/pos/cashier`) is split into **three columns, one per counter**. The owner sees each counter's bill being built as it happens, and settles invoices from the same screen.

```
┌───── Counter 1 ───────┬───── Counter 2 ───────┬───── Counter 3 ───────┐
│ ● Online · Nimal      │ ● Online · Kamal      │ ○ Offline             │
│ BILLING               │ PRINTED · waiting 2m  │ IDLE                  │
│ Urea 50kg   2 bag     │ INV-004512            │                       │
│ TSP 1kg     5 kg  NEW │ Total    21,000.00    │                       │
│ ~~MOP 50kg 1~~ REMOVED│ Paid     22,000.00    │                       │
│ Total      21,450.00  │ Balance   1,000.00    │                       │
│                       │ [ Settle ]  [ Void ]  │                       │
│ 10:32 removed MOP 50kg│ 10:31 printed         │                       │
│ 10:31 added TSP 1kg   │ 10:30 tendered 22,000 │                       │
├───────────────────────┴───────────────────────┴───────────────────────┤
│ Today  C1 Rs 142,300 (38) · C2 Rs 98,750 (27) · C3 Rs 120,100 (31)     │
│ Drawer Rs 312,450 · Waiting to settle: 1 · Voids today: 2              │
└────────────────────────────────────────────────────────────────────────┘
```

**Counter statuses:** OFFLINE · IDLE · BILLING · PAYMENT (amount tendered entered) · PRINTED (waiting for settlement, with a timer that turns red after a set time) · SETTLED (short flash).

**Loss-prevention highlights:** items removed after being added, bills cleared, reprints and voids are shown in red in the column's activity ticker, and are stored for the "Removed items & voids by counter" report.

**Two views of the same component:**
- `/pos/cashier` on the main terminal: live columns **plus** Settle / Void buttons.
- `/admin/live-billing`: the same three columns, **view only**, for the owner's dashboard and phone (over Tailscale). On a phone the columns become swipeable tabs.

**How it works:**
```
Counter Alpine cart ──(debounced 300 ms) POST /api/pos/cart-sync──▶ Laravel
      server recomputes prices/totals, stores Redis  live_cart:{terminal_id}
      writes counter_events row (added / qty / removed / cleared / tendered)
      broadcast CounterCartUpdated ──▶ private channel "live-billing" ──▶ owner screens
Presence channel "pos-terminals" ──▶ online / offline + who is logged in on each counter
Reverb down? ──▶ owner screen polls GET /api/live-billing/snapshot every 3 s
```
- The Redis copy also **restores the cart** on the counter after a browser refresh or power cut.
- The client snapshot is for display only; the invoice is always priced on the server when it is printed.
- Permission `pos.live_view` (Super Admin; included in the handover scope so a delegate can work the cashier screen, and the owner can untick it).

---

## 7. Domain-specific inventory design

| Need | Design |
|---|---|
| Fertilizer sold by bag **and** loose kg | **Multi-UOM:** each product has a base unit (kg) and sale units (bag = 50 kg) with conversion factors. Stock is always stored in the base unit as `DECIMAL(14,3)`. |
| Seeds with lot numbers and expiry | **Batch/lot tracking:** every GRN line creates a batch (lot no, expiry, cost). Sales pick batches **FEFO** (first expiry, first out). |
| Bicycle parts in sizes and colors | **Product variants** (parent product → variants with their own code, price and stock). |
| Accurate costing | Batch cost gives **FIFO** cost of goods sold (COGS), so profit per sale is exact. |
| Full traceability | **Immutable stock ledger** (`stock_movements`). Every in/out has a type (GRN, SALE, RETURN, ADJUST, DAMAGE, TRANSFER, STOCKTAKE) and a reference. `stock_levels` is a cached balance updated in the same transaction. |
| Alerts | Reorder level per product, expiring-batch alerts (30/60/90 days), dead-stock report |
| Stocktake | Freeze a snapshot, count by category, variance approval, then an adjustment is posted |
| Price levels | Retail / Wholesale / Farmer-credit price lists, plus a minimum selling price guard |

---

## 8. Core database schema (key tables)

**Identity**
- `users` (name, username, password, pin_hash, employee_id, is_active)
- `roles`, `permissions`, `model_has_roles` (spatie)
- `terminals` (name, code, type: MAIN_CASHIER/COUNTER, counter_no 1–3 null, device_token_hash, receipt_language)
- `printers` (terminal_id UQ, windows_name, model, paper_width_mm, dpi, has_cash_drawer, is_active, last_test_at)
- `delegations` (from_user_id, to_user_id, permissions JSON, starts_at, expires_at, revoked_at, reason)

**Catalog**
- `categories` (tree), `brands`, `units`
- `products` (short_code UQ, sku, name, name_si, name_ta, aliases, category_id, brand_id, base_unit_id, tax_id, reorder_level, track_batches, has_variants, attributes JSON, is_active; FULLTEXT index)
- `product_units` (product_id, unit_id, factor, barcode NULL, is_default_sale)
- `product_variants`, `price_lists`, `product_prices` (product/variant, price_list, unit, price, effective_from)

**Inventory**
- `batches` (product_id, lot_no, mfg_date, expiry_date, unit_cost, grn_line_id)
- `stock_levels` (product_id, variant_id, batch_id, qty_on_hand, qty_reserved) with UQ on the combination
- `stock_movements` (product, batch, qty ±, unit_cost, type, reference_type/id, user_id, created_at): **append-only**
- `stock_adjustments` (+ lines, status, approved_by), `stocktakes` (+ lines)

**Sales**
- `sales` (invoice_no UQ, status, customer_id, invoiced_by, invoiced_terminal_id, invoiced_at, settled_by, settled_terminal_id, settled_at, drawer_session_id, subtotal, discount, tax, total, tendered_amount, change_due, balance_due, void_reason, voided_by, print_count, idempotency_key UQ)
- `sale_items` (product, variant, unit, qty, base_qty, unit_price, discount, cost_total, name snapshots) + `sale_item_batches` (batch allocations)
- `payments` (sale_id, method: CASH/CARD/BANK/CHEQUE/CREDIT, amount, reference, cheque_id, confirmed_by)
- `sale_returns` (+ lines), `quotations` (+ lines)
- `approval_requests` (type: DISCOUNT/VOID/PRICE_OVERRIDE, requested_by, approved_by, status)
- `counter_events` (terminal_id, user_id, sale_id null, cart_uuid, type, payload JSON, created_at): Live Billing activity and loss-prevention audit, kept 90 days
- `print_jobs` (terminal_id, printer_id, user_id, document_type, document_id, is_copy, printed_at)

**Cash drawer**
- `drawer_sessions` (terminal_id, holder_user_id, opened_at, opening_float, closed_at, expected_cash, counted_cash, variance, denominations JSON, handed_over_to_session_id)
- `cash_movements` (session_id, type: PAY_IN/PAY_OUT/SAFE_DROP/BANK_DEPOSIT, amount, reason)

**Purchasing**
- `suppliers`, `purchase_orders` (created_by, submitted_at, approved_by, approved_at; + `po_lines`; status: draft → submitted → approved → sent → partial → received → closed)
- `goods_receipts` (GRN, + lines creating batches), `supplier_invoices`, `supplier_payments`, `supplier_returns`

**Customers**
- `customers` (name, phone, NIC, address, village/area, credit_limit, price_list_id)
- `customer_ledger` (debit/credit entries, running balance, due_date). Seasonal farmer credit is common, so ageing reports matter.

**HR**
- `employees` (NIC, join_date, designation, basic_salary, bank details, EPF no)
- `attendances` (employee_id, date, clock_in, clock_out, source: POS_PIN/MANUAL, late_minutes, ot_minutes, edited_by)
- `leave_types`, `leave_requests`, `salary_components` (allowances/deductions), `salary_advances` (+ recovery schedule)
- `payroll_runs` (month, status) → `payslips` (+ `payslip_lines`). EPF/ETF rates are configurable.

**Finance**
- `bank_accounts` (bank, branch, account_no, type, opening_balance)
- `bank_transactions` (deposit, withdrawal, transfer, charges, interest, reconciled_at)
- `cheques` (direction: RECEIVED/ISSUED, number, bank, date, amount, party, status: PENDING → DEPOSITED → CLEARED/BOUNCED)
- `expense_categories`, `expenses`
- `accounts` (chart of accounts), `journal_entries` + `journal_lines`: a **light double-entry ledger** that every financial event posts to automatically, so P&L and balance sheet are always consistent.

**System**
- `document_sequences`, `settings`, `activity_log`, `notifications`

**Conventions:** money is `DECIMAL(15,2)` and quantity is `DECIMAL(14,3)`. Financial documents use **soft deletes only**; they are never hard-deleted, only cancelled or reversed. Every table has `created_by` and timestamps.

---

## 9. Other modules

- **Purchase orders:** reorder suggestions (stock below reorder level, plus sales velocity) → draft PO. **Sales Staff, Manager and Super Admin can all create POs.** Staff see the products, supplier, current stock and reorder level, and enter quantities. Staff never see cost prices, so the Manager or Super Admin fills in or confirms the prices when they approve. Flow: `draft → submitted → approved → sent → partial → received → closed`. After approval: PDF/WhatsApp to supplier → partial GRNs → supplier invoice matching → payment by cash, bank or cheque. The person who created each PO is recorded.
- **Attendance:** staff clock in and out with their POS PIN. The rule is "first login = clock in". An optional webcam snapshot prevents buddy-punching. Late and overtime are calculated from shift rules. Only the Super Admin can edit, and every edit is audited.
- **Payroll:** monthly run → pulls attendance, OT, leave and advances → calculates EPF/ETF → owner approves → payslip PDFs → payment posted from a bank or cash account.
- **Banking:** multiple bank accounts, daily cash-to-bank deposits from drawer sessions, a post-dated cheque calendar with due alerts, and a simple bank reconciliation screen.
- **Notifications (SMS gateway, optional):** low stock, expiring batches, cheques due, overdue customer credit, and a daily sales summary to the owner.
- **Dashboard (owner):** **Live Billing** (section 6a), today's sales per counter, cash in drawer, invoices waiting for settlement, who holds cashier authority, pending approvals, low stock, top items, receivables and payables.

**Reports:** daily/Z report, sales by item, category, counter, staff and hour, profit by item, **removed items & voids by counter**, stock valuation, stock movement, expiry, dead stock, purchase history, supplier and customer ageing, attendance, payroll summary, bank book, cash book, P&L, drawer variance history, print/reprint log, and an audit trail. All reports export to Excel and PDF.

---

## 10. Security and reliability

- PIN login on POS terminals and password login for the back office. **2FA for the Super Admin** (remote access).
- Device-bound terminals. Settlement endpoints check `terminal.type = MAIN_CASHIER` **and** that the user holds cashier authority.
- Policies on every model. Cost prices are hidden from Sales Staff at the API level, not just in the UI.
- Everything sensitive is in the audit log: price changes, discounts, voids, reprints, removed cart items, stock adjustments, handovers, attendance edits.
- Stock changes happen in DB transactions with row locks. Invoice print and settlement use idempotency keys.
- Backups: hourly MySQL dump kept locally, nightly encrypted copy to the cloud, and a monthly restore test. A UPS lets the server shut down cleanly.

---

## 10a. Sinhala invoices and the 4 printers

**Requirement:** the small 80 mm invoice prints in Sinhala, on the printer of the counter that made the bill.

### The four printers

| Printer | Connected to | Prints | Cash drawer |
|---|---|---|---|
| #1, #2, #3 | Counter 1, 2, 3 PCs (USB) | Customer invoices from that counter (with paid / balance), reprints marked "පිටපත / COPY" | No |
| #0 | Main cashier PC (USB) | Owner's direct-sale invoices, customer payment receipts, Z report, handover slips | **Yes** (RJ11 from printer) |

- Use the **same printer model** for all 4: one driver, one tested configuration, and any printer can replace another.
- Every printer is registered in the system (`printers` table), linked to its terminal, with a **Test print** button and a **print log** (`print_jobs`) of every print and reprint.
- If a counter printer fails or runs out of paper, the invoice can be printed again from "Last invoices" on that counter (logged as a copy). The Super Admin can temporarily link another printer to the terminal.

### Why print as an image

Thermal printers print text using fonts built into the printer (ESC/POS code pages). They have **no Sinhala font**, and Sinhala needs complex shaping: vowel signs and conjuncts are combined, e.g. ක + ් + ෂ → ක්ෂ. Sending raw ESC/POS text prints garbage. So the invoice is printed as an **image**:

```
Blade view print/invoice.blade.php  (lang = si, font = Noto Sans Sinhala, width 72mm)
        │  rendered by Chrome (correct Sinhala shaping)
        ▼
window.print()  with Chrome --kiosk-printing  (silent, no dialog) — on all 4 PCs
        │  Windows thermal printer driver rasterises the page
        ▼
that PC's 80mm thermal printer prints the image
```

On settlement, the main cashier PC sends the raw "open drawer" command (`ESC p`) to printer #0 through **QZ Tray**. Fallback: print a 3-line settlement stub with the driver's "open drawer after print" setting.

### Invoice content
- **Font:** Noto Sans Sinhala (OFL licence) is bundled in `public/fonts/`, so it works offline. Windows' built-in Iskoola Pota / Nirmala UI is the fallback.
- **Product names:** `products.name_si` is printed. If it is empty, the English `name` is printed instead.
- **Labels:** all fixed text comes from `lang/si/receipt.php`, e.g. "එකතුව" (total), "ගෙවූ මුදල" (amount paid), "ඉතිරිය" (balance), "කවුන්ටරය" (counter). English is in `lang/en/receipt.php`.
- **Shows:** invoice no, date/time, counter no, staff name, items, total, **amount paid, balance**, payment method, footer.
- **Settings:** receipt language `si` (default) / `en` / `si+en`; shop name, address and footer are stored in both languages.
- **Numbers:** Western digits, `Rs. 12,450.00` format.
- **Layout:** CSS `@page { size: 80mm auto; margin: 0 }`, content width 72 mm, minimum font size 11 pt (Sinhala below that is hard to read at 203 dpi).
- **A4 documents** (invoice, PO, GRN, payslip) with Sinhala text use the same Blade + Chromium path through spatie/laravel-pdf.
- **Risk control:** a printing spike on **all 4 printers** of the chosen model is done in Phase 0, before POS work starts.

---

## 11. Delivery roadmap

| Phase | Scope | Est. |
|---|---|---|
| **0. Foundation** | Laravel setup, Tailwind + Alpine + Vite, Blade layouts and shared UI component library, auth + PIN login, roles, terminals, printers, audit log, settings, CI, **printing spike on all 4 printers** | 2–3 wk |
| **1. Catalog + Search** | Products, UOM, variants, prices, Meilisearch, data import from Excel | 2–3 wk |
| **2. Inventory + Purchasing** | Batches, stock ledger, suppliers, PO (staff create → approval) → GRN, adjustments, stocktake | 3–4 wk |
| **3. POS core** | Counter billing + Sinhala invoice on counter printers, settlement at main cashier, **Live Billing**, drawer sessions, **handover**, printer management | 4–5 wk |
| **4. Customers + Returns** | Credit accounts, returns, quotations, approvals | 2 wk |
| **5. Finance** | Bank accounts, cheques, expenses, journal, cash-to-bank | 3 wk |
| **6. HR + Payroll** | Employees, attendance, leave, advances, payroll, payslips | 3 wk |
| **7. Reports + Go-live** | Dashboards, reports, hardware setup, training, parallel run | 2–3 wk |

Estimates include hand-building every screen in Blade (no admin-panel framework).

Go-live suggestion: run phases 0–3 plus a data import as an **MVP after about 14 weeks**, so the shop starts selling on the new system early. Full system after about 25 weeks. Details are in [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).

---

## 12. Open questions / assumptions to confirm

1. ~~**Who handles cash**~~ **Decided:** counter staff take the customer's cash, print the invoice, and bring invoice + cash to the main cashier for settlement (section 4).
2. **Credit sales to farmers:** customers buy on credit and pay after harvest (assumed yes).
3. **Tax:** is the shop VAT-registered? Should any products be tax-exempt? (Tax is configurable per product for now.)
4. ~~**Receipt language**~~ **Decided:** small invoices print in Sinhala (section 10a).
5. **Existing data:** is there a product list in Excel or an old system to import?
6. **Payroll:** should EPF/ETF be calculated, or is salary a simple basic + allowances − deductions?
7. **Manager on the POS:** the Manager can also bill at a counter like Sales Staff when not delegated (assumed yes).
8. ~~**Agro-chemical (poison) sales**~~ **Decided:** no customer name / NIC is required. Selecting a customer on the bill stays optional for every product.
9. **Printers — decided:** 4 × 80 mm USB thermal printers of the same model. Buying rule: **buy one first**, pass the Phase 0 printing spike with it (Sinhala image printing through the Windows driver, cash drawer port, 80 mm auto-cut), then buy the other three of the same model. Requirements for the model:
   - 80 mm paper, 203 dpi, USB, **auto-cutter**
   - a proper **Windows driver** that prints graphics (not a text-only / "generic" driver)
   - an **RJ11 cash drawer port** (needed on the main printer; same model everywhere keeps them interchangeable)
   - local warranty and spare parts in Sri Lanka
   
   Examples that meet this: Epson TM-T82X (reliable, higher price) or Xprinter XP-Q200 / XP-Q260 class (budget). Buy one spare later if possible.
