# CITIZENS Agro POS: User Guide

> How the system works, screen by screen, for the Owner (Super Admin), the Manager and Sales Staff.
> Technical design: [ARCHITECTURE.md](ARCHITECTURE.md) · Printers: [PRINTING.md](PRINTING.md) · Progress: [TASK_LOG.md](TASK_LOG.md)

---

## Contents

1. [The big picture](#1-the-big-picture)
2. [Who can do what](#2-who-can-do-what)
3. [Signing in](#3-signing-in)
4. [The menu](#4-the-menu)
5. [First-time setup (go-live order)](#5-first-time-setup-go-live-order)
6. [Catalog: adding products](#6-catalog-adding-products)
7. [Inventory: how stock gets in and out](#7-inventory-how-stock-gets-in-and-out)
8. [Purchasing: order → receive → pay](#8-purchasing-order--receive--pay)
9. [POS: a normal day at the shop](#9-pos-a-normal-day-at-the-shop)
10. [Customers, credit, returns and quotations](#10-customers-credit-returns-and-quotations)
11. [Finance and banking](#11-finance-and-banking)
12. [HR, attendance and payroll](#12-hr-attendance-and-payroll)
13. [Reports and dashboard](#13-reports-and-dashboard)
14. [Administration](#14-administration)
15. [Document numbers and statuses](#15-document-numbers-and-statuses)
16. [Scheduled jobs and alerts](#16-scheduled-jobs-and-alerts)
17. [Common problems](#17-common-problems)

---

## 1. The big picture

CITIZENS Agro sells fertilizers, seeds, agro-chemicals, tools and bicycle parts **over the counter only**. Customers stay outside; there are **three service counters** and **one main cashier** (the owner).

```
 Counter 1–3 (Sales Staff)                         Main Cashier (Owner, or Manager while handed over)
 ─────────────────────────                         ─────────────────────────────────────────────────
 Search products → build the bill                  Watches every counter live (Live Billing)
 Customer pays → type amount tendered
 F9 → invoice prints on the counter printer        
   (stock is RESERVED)                     ──────▶  Invoice appears in that counter's column
 Staff walk invoice + cash to the cashier  ──────▶  Owner checks → [Settle]
                                                      stock ISSUED, cash in drawer, drawer opens
 Staff hand balance + invoice to customer  ◀──────  Owner gives the balance back
```

Everything else in the system feeds or follows this flow:

| Module | What it does | Feeds into |
|---|---|---|
| **Catalog** | Products, units, prices, categories, search words | POS search, purchasing, stock |
| **Purchasing** | Purchase orders → goods received → supplier returns → supplier payments | Stock in, what the shop owes |
| **Inventory** | Stock on hand, batches/expiry, adjustments, stocktakes | POS stock badges, reports, profit |
| **POS** | Billing, settlement, drawer, handover | Sales, stock out, cash |
| **Customers** | Credit accounts, payments, returns, quotations | Receivables |
| **Finance** | Banks, cheques, expenses, journal, P&L | Books (automatic) |
| **HR** | Employees, attendance, leave, payroll | Salaries in the books |
| **Reports** | 29 reports + dashboard, Excel/PDF | — |

**Golden rules the system enforces**

- Stock only changes through documents (GRN, sale, return, adjustment, stocktake). Every change is written to the **stock movement ledger** and can be traced back.
- Printed invoices are **never edited**. Mistakes are fixed by a **void** (before a return) or a **return**.
- Posted documents (GRN, adjustment, stocktake) are **never edited**. Mistakes are fixed with another document.
- Every money event posts to the **journal automatically**, so the P&L and balance sheet are always up to date.
- Sales Staff **never see cost prices** anywhere (screens, PDFs or data).

---

## 2. Who can do what

| Capability | Owner (Super Admin) | Manager | Sales Staff |
|---|:-:|:-:|:-:|
| Bill customers, print / reprint invoices | ✅ | ✅ | ✅ |
| See stock (without costs) | ✅ | ✅ | ✅ |
| Settle invoices, drawer, voids, refunds, approvals | ✅ | only while cashier authority is handed over | ❌ |
| Live Billing | ✅ | only while handed over | ❌ |
| Products, prices, categories | ✅ | ✅ | ❌ |
| Create / submit purchase orders | ✅ | ✅ | ✅ (quantities only) |
| Approve POs, receive goods, suppliers | ✅ | ✅ | ❌ |
| Stock adjustments | ✅ | ✅ (above Rs. 10,000 needs the owner) | ❌ |
| Customers and credit limits | ✅ | ✅ | quick-add only (no credit) |
| Banks, cheques, journal, financial reports | ✅ | ❌ never | ❌ |
| Petty-cash expenses from the drawer | ✅ | ✅ | ❌ |
| Employees, payroll, advances | ✅ | ❌ never | ❌ |
| Attendance | ✅ edit | view | own only |
| Users, terminals, settings, audit log | ✅ | ❌ | ❌ |

**Role vs cashier authority:** a role is fixed. *Cashier authority* (who owns the drawer and may settle) moves from the owner to the Manager and back through a **handover** (section 9.6). Finance, payroll and admin can **never** be handed over.

---

## 3. Signing in

| Where | How |
|---|---|
| Back office (any PC or phone) | Username + password. The owner should switch on **two-factor authentication** in *My account*. |
| Shop terminals (counters, main cashier) | **PIN** sign-in. Only works on a PC that has been **registered as a terminal** (section 14.2). |

- The **first PIN sign-in of the day** on a shop terminal also **clocks you in** (attendance). Clock out with the button in the top bar.
- Too many wrong PINs locks the PIN for a while.
- Failed and successful sign-ins are written to the audit log.
- If a PC is not registered, you land on the *"This device is not a registered terminal"* page. Sign in with a password and register it (owner only).

---

## 4. The menu

The sidebar shows only what your role may use. Groups open with a click.

| Group | Pages |
|---|---|
| **Dashboard** | Today's figures, alerts, shortcuts |
| **Reports** | All reports with Excel / PDF |
| **POS** | Billing screen · Cashier · Live Billing · Invoices · Cashier authority · Drawer sessions |
| **Customers** | Customers · Credit ageing · Customer payments · Returns · Quotations |
| **Catalog** | Products · Categories · Brands · Units · Search synonyms · Taxes |
| **Inventory** | Stock on hand · Expiring stock · Stock movements · Adjustments · Stocktakes |
| **Purchasing** | Purchase orders · Goods received · Supplier returns · Suppliers · Supplier payments |
| **Finance** | Banking · Cheques · Cheque calendar · Expenses · Journal · Chart of accounts · Financial reports |
| **HR & Payroll** | My attendance · Attendance · Leave · Employees · Payroll · Salary advances · Allowances & deductions · Shifts, holidays & leave · HR reports |
| **Administration** | Users · Terminals · Printers · Print log · Printing test · Settings · Audit log · Go-live |

Top bar: **product search (Ctrl+K)**, the **bell** (notifications), clock in / out, your account.

---

## 5. First-time setup (go-live order)

Do these in order. **Administration → Go-live** shows a checklist that ticks most of them automatically.

| # | Step | Where |
|---|---|---|
| 1 | Shop details (English + Sinhala), invoice footer, receipt language | Administration → Settings → Shop / Invoice |
| 2 | Users for every staff member, with a PIN | Administration → Users |
| 3 | Terminals: register the main cashier PC and Counter 1–3 PCs | Administration → Terminals → *Register this device* (on each PC) |
| 4 | Printers: link each printer to its terminal, **Test print** | Administration → Printers |
| 5 | Categories (with short-code ranges), brands, units | Catalog |
| 6 | Tax (only if VAT-registered) | Catalog → Taxes |
| 7 | **Products + opening stock** (Excel import) | Catalog → Products → Import from Excel |
| 8 | Check opening stock was posted | Inventory → Stock on hand |
| 9 | Suppliers and credit customers **with opening balances** | Administration → Go-live → Excel import |
| 10 | Bank accounts with opening balances | Finance → Banking |
| 11 | Employees (link each to their login), shifts, holidays | HR & Payroll |
| 12 | Training, then a **parallel run** alongside the old system | — |

---

## 6. Catalog: adding products

### 6.1 Set up the building blocks first

| Page | What to enter | Notes |
|---|---|---|
| **Categories** | Name, parent (tree), **short-code range** | Suggested ranges: Fertilizers 1000–1999, Seeds 2000–2999, Agro-chemicals 3000–3999, Tools 4000–4999, Bicycle parts 5000–6999, Other 9000–9999. New products get the next free code in their category's range. |
| **Brands** | Name | Import creates missing brands automatically. |
| **Units** | kg, g, litre, ml, piece, bag, packet… | Units can't be deleted once used. |
| **Taxes** | Name, rate | A "VAT 18%" is seeded **inactive**. |
| **Search synonyms** | Word groups, e.g. `TSP ↔ triple super phosphate`, `tube ↔ tyre tube` | Helps the POS search. |

### 6.2 Add one product (Catalog → Products → New)

The form has five tabs. A red dot on a tab means an error on it.

**1. General**

| Field | Meaning |
|---|---|
| Short code * | What staff type at the counter (e.g. `1023`). Press **Suggest** for the next free code in the category. A deleted product's code is never reused. |
| SKU / supplier code | Optional. |
| Name (English) * | |
| Category *, Brand | |
| **Base unit** * | The unit stock is counted in. **All stock is stored in this unit.** Sealed goods: packet, bag, bottle, piece. Loose goods: kg. |
| Tax | Leave empty unless VAT applies. |
| **Sold loose (weighed out)** | Tick for fertilizer weighed at the counter. It gets two prices (section 6.5). Leave it off for sealed packets and bags. |
| **Can be opened into** + **Loose quantity in one pack** | Only on a sealed bag that is sometimes opened and sold loose, e.g. *Urea 50kg bag* → *Urea (loose)*, 50 (kg). See section 7.7. |
| Description, Active | Inactive products don't show at the POS. |

**2. Units & Prices**

- Add **other units** with *Base units per unit* only when the product really is bought or sold in a bigger unit (e.g. loose urea bought as **bag = 50** kg). A different pack size is a **separate product**, not a unit (section 6.5).
- Choose the **default sale unit** (picked first at the counter) and the **default purchase unit** (picked first on POs).
- Enter prices per **price list** (Retail, Wholesale, Farmer-credit). Every box is optional: **leave it empty for no price**. Clearing a price removes it (it stays in the history as *Removed*).
- For a **loose** product the base unit has two rows: **Under 1 kg (price per kg)** and **1 kg and above (price per kg)**. Under each box the form shows the same price per 100 g.
- **Reference cost** (cost of one base unit) and **minimum margin %**: the form warns when a price is below the margin, and refuses to save it. After the first goods receipt, reference cost follows the latest GRN automatically.
- Every price change is kept in the product's **price history** and the audit log.

**3. Variants** (bicycle parts in colours)

- A parent product with variants, each with its own code and stock. **Variants share the product's prices.** When sizes have different prices (Tyre 26" and 28"), make them separate products instead.

**4. Search & Names**

- **Sinhala name** (printed on the invoice; English is used if empty), Tamil name.
- **Aliases**: other names staff use, comma-separated: `yuriya, urea bag, u50`.
- **Attributes**: e.g. NPK `46-0-0`, size `26"`.

**5. Stock settings**

| Field | Effect |
|---|---|
| Reorder level | Low-stock alert and reorder suggestion when stock (base units) falls to this level. |
| Reorder quantity | Suggested order quantity. |
| **Track batches / lot numbers** | Each delivery becomes its own batch with its own cost. Sales take the **earliest expiry first** (FEFO). Use for seeds and chemicals. |
| **Track expiry dates** | Expiry is asked on every goods receipt; shows in *Expiring stock*. |

> A product saved from this form starts with **zero stock**. Stock comes in through a goods receipt (section 8) or, for stock already on the shelf, an adjustment (section 7.4) or the import's opening stock.

### 6.3 Add many products (Excel import)

**Catalog → Products → Import from Excel**

1. **Download template.** The owner's own price sheet (*Product Name, Sinhala Name, Product Varients, Selling Price, Whole Sale Price, Price for Kg's, Price for grams …*) can also be uploaded as it is.
2. Fill one row per product (one row per **pack size**):

| Column | Required | Meaning |
|---|:-:|---|
| Short Code | | Empty = next free code of the category |
| Product Name | ✅ | English name. **Leave it empty on the next rows of the same product** to add more pack sizes |
| Sinhala Name, Tamil Name | | |
| Aliases | | Comma-separated: `yuriya, u50` |
| Category | ✅ | Existing category, or `Parent > Child` |
| Brand | | Created if new |
| Base Unit | ✅ | packet, bag, bottle, piece for sealed goods; kg for loose goods. `grams`, `kgs`, `pcs` … are understood too |
| **Pack Size** (*Product Varients*) | | `10g packet`, `50kg bag` … Each pack size becomes its **own product**: Okra + 10g packet → *Okra 10g packet* |
| Default Sale Unit | | Empty = base unit |
| **Opening Stock** | | **Stock on hand now, in the base unit** (packets, bags, kg) |
| **Cost** | | **Cost of one base unit** (one packet, one kg) |
| Whole Sale Price | | For wholesale customers. Empty = they pay the selling price |
| Selling Price | | Price of the default sale unit. Empty = imported, but can't be billed until a price is set |
| **Price for Kg's (per kg)** | | Loose goods: price per kg from 1 kg |
| **Price for grams (per kg)** | | Loose goods: price per kg under 1 kg. **Write it per kg**: Rs. 30 for 100 g is **300** |
| Reorder Level, Reorder Qty | | In the base unit |
| Other Units | | `bag=50` when it is bought in a bigger unit. No price is worked out for it |
| Batch No, Expiry Date | | Lot and expiry (`YYYY-MM-DD`) of the opening stock |
| Opens Into, Loose Qty Per Pack | | Sealed bag that is sometimes opened: the loose product's name or code, and the kg in one bag (empty = read from the pack size, `50kg bag` → 50) |

   Example (seed packets: the name once, then one row per size):

   | Product Name | Base Unit | Pack Size | Cost | Selling Price | Reorder Level |
   |---|---|---|--:|--:|--:|
   | Okra | packet | 10g packet | 72 | 120 | 50 |
   | | | 50g packet | 192 | 290 | 15 |
   | | | 100g packet | 348 | 530 | 15 |

3. **Upload** → the system checks every row and shows a **preview**: errors per row, the prices it will save, and **notes**.
   - **Errors** stop the import (unknown unit or category, a grams price that looks like a price per 100 g, a row that is both a pack and loose …).
   - **Notes** don't: *No Selling Price*, *Base unit g changed to packet* (a 10g pack is counted as one packet), *Same name as row 5*.
4. Fix errors and upload again, or **Import** when clean. The import is all-or-nothing.
5. The import **only adds** products. An existing short code is an error (edit those on the form instead).
6. Opening stock is **added to inventory straight away** as `Opening stock` movements. If older imported entries were not yet posted, **Inventory → Stock on hand → Add opening stock now** posts them.
7. Afterwards, open **Reports → Products without a selling price** (or tick *No selling price* on the product list) and fill in the missing prices.

**Export:** Catalog → Products → *Export* downloads the catalog in the same layout (the Cost column only for Owner and Manager).

### 6.4 How POS search finds products

- Exact short code wins (`1023` adds instantly).
- Then typo-tolerant search over name, aliases, Sinhala/Tamil names, brand, attributes and synonyms. Fast sellers rank higher.
- `5*urea` adds 5 of the first match; `750g*urea` adds 750 g of a loose product.
- Stock shown in search is always live.
- If the search server (Meilisearch) is down, a slower MySQL search is used automatically.

### 6.5 How pricing works (packets, loose goods, wholesale)

The shop sells three kinds of goods. Set each product up as one of them:

| Kind | Examples | How to set it up | Prices |
|---|---|---|---|
| **Sealed pack** | Okra 10g packet, Okra 50g packet, Urea 50kg bag, Glyphosate 1L bottle, brake cable | **One product per pack size.** Base unit packet / bag / bottle / piece. Never opened, so a 50g packet is not "50 grams" of a 10g product. | One price per list. |
| **Loose goods** | Urea (loose), TSP (loose) | Base unit **kg**, tick **Sold loose**. | **Under 1 kg** price per kg and **1 kg and above** price per kg. |
| **Sealed bag that is sometimes opened** | Urea 50kg bag | A sealed product, plus **Can be opened into** = the loose product, 50 kg per bag. | Its own bag price. |

**Loose prices.** The counter picks the price by the **weight of the line**:

| Customer buys | Price used (example: Rs. 300/kg under 1 kg, Rs. 250/kg from 1 kg) | Line total |
|---|---|--:|
| 250 g | under 1 kg: 0.25 × 300 | 75.00 |
| 999 g | under 1 kg: 0.999 × 300 | 299.70 |
| 1 kg | from 1 kg: 1 × 250 | 250.00 |
| 1.5 kg | from 1 kg: 1.5 × 250 | 375.00 |

If only one loose price is set, it is used for every weight.

**Price lists.** *Retail* is the normal price. Customers on the **Wholesale** list (the few who buy in bulk to sell again; set on the customer) pay the wholesale price **where there is one**, and the retail price for everything else. The invoice line remembers which list its price came from.

**No price.** A product without a selling price can be saved and stocked, but the counter refuses it ("… has no price per packet"). Find them under **Reports → Products without a selling price**.

---

## 7. Inventory: how stock gets in and out

### 7.1 Key ideas

| Idea | Meaning |
|---|---|
| **Base unit** | Stock is always stored in the base unit (e.g. kg), to 3 decimals. Selling 2 bags of 50 kg takes 100 kg. |
| **Batch** | A lot of stock with its own cost and (optionally) expiry. Batch-tracked products get one batch per delivery. Other products keep a single "general stock" batch whose cost is a **moving average**. |
| **FEFO / FIFO** | Sales take the earliest-expiring batch first; profit uses that batch's cost. |
| **Reserved** | Stock on a printed-but-unsettled invoice. It can't be sold twice. |
| **Stock movement** | One line in the permanent stock ledger. Can't be edited or deleted. |
| **Negative stock** | Refused, unless *Settings → Inventory* allows it. |

### 7.2 Every way stock moves

| Movement type | Direction | Created by |
|---|:-:|---|
| Opening stock | + | Product import (opening_stock column) |
| **Goods received** | + | Posting a GRN (section 8.3) |
| Sale | − | Cashier **settles** an invoice (reserved at print, issued at settle) |
| Sale return | + | Cashier return, *restock* option (back to the original batch) |
| Damage | − | Return marked *damaged*, expiry write-off, or adjustment |
| Return to supplier | − | Supplier return (section 8.4) |
| Adjustment (in / out) | ± | Stock adjustment (section 7.4) |
| Stocktake | ± | Posting a stocktake (section 7.5) |
| Pack opened / From opened pack | − sealed bag / + loose product | Opening sealed bags (section 7.7, or *Open now* on a GRN) |

### 7.3 Inventory pages

| Page | Use |
|---|---|
| **Stock on hand** | By product / by batch / low stock. Owner and Manager also see stock value. *Add opening stock now* button for unposted import stock. |
| **Expiring stock** | Batches expiring within 30 / 60 / 90 / 180 days, with a **write-off** action. |
| **Stock movements** | The full ledger, filter by product, type or date; each line links to its document. |
| **Adjustments** | Manual corrections (below). |
| **Open packs** | Sealed bags opened into loose stock (section 7.7). |
| **Stocktakes** | Physical counts (below). |

The product page also shows stock by batch and recent movements.

### 7.4 Stock adjustment (Inventory → Adjustments → New)

Use for damage, expiry, loss, found stock, or corrections.

1. Choose a **reason**: Damaged · Expired · Lost / missing · Found · Correction · Other. Add a note.
2. Add lines: product, variant, batch (optional), **quantity in base units** (`+` adds, `−` removes).
3. Save. The value is worked out at batch cost.
   - **Up to Rs. 10,000** (Settings → Inventory): **posted at once** (status *Posted*).
   - **Above the limit**: status *Waiting for approval*; the owner gets a bell notification and **Approves** (stock changes) or **Rejects** (nothing changes).

Numbers: `ADJ-…`. Adding stock that was already on the shelf for a new product: use reason **Found** or **Correction**.

### 7.5 Stocktake (Inventory → Stocktakes)

```
 Open ──▶ Counting ⇄ Review ──▶ Posted
   └──────────┴────────┴──────▶ Cancelled
```

1. **New stocktake:** pick categories (or all) and a note. The system **freezes the system quantities** now, one line per batch.
2. **Count:** open the counting page (works on a tablet; every count saves as you type) or print the **blind count sheet** (no system quantities on it). Enter counts in base units. An empty count means "not counted" and is left unchanged.
3. **Finish** → *Review*: see differences and their value. **Reopen** to recount.
4. **Post:** each difference becomes a `Stocktake` movement. Differences above the approval limit need the owner.
5. **Cancel** any stocktake that hasn't been posted.

Numbers: `STK-…`. Tip: count when the shop is closed, or don't sell the counted categories during the count.

### 7.6 Alerts

- **07:00 daily**: bell notification to Owner/Manager with products at or below reorder level and batches about to expire.
- Stock badges at the POS: green (OK), amber (low), red (none).

### 7.7 Opening sealed bags into loose stock (Inventory → Open packs)

Loose fertilizer comes from sealed bags. Bags opened **when a delivery arrives** are entered on the GRN (*Open now*, section 8.3). When the loose stock runs out before the next delivery:

1. **Inventory → Open packs → Open packs** (Owner and Manager).
2. Choose the **sealed pack** (only bags set up with *Can be opened into* are listed; stock on hand is shown).
3. Enter **packs to open**, and what you **weighed** into the loose stock. Leave the weight empty when the bags held their full weight.
4. Save. In one step:
   - the bags leave stock (`Pack opened`) and the loose product gets the weight (`From opened pack`);
   - the bags' cost moves to the loose stock: 3 bags at Rs. 10,000 into 150 kg = Rs. 200 per kg (the loose product's reference cost);
   - a short weight shows as a red difference (e.g. −1 kg) on the opening, so a supplier that keeps short-filling bags is easy to spot.

Numbers: `OPN-…`. An opening can't be undone; correct a mistake with a stock adjustment.

---

## 8. Purchasing: order → receive → pay

This is the **main way new stock enters the shop.**

```
 Purchase order                     Goods received (GRN)            Money
 ─────────────                      ────────────────────            ─────
 Draft ──Submit──▶ Waiting for      Draft ──Post──▶ Posted          Supplier balance goes up
                   approval         (stock IN, batch created,   ──▶ Supplier payment
      ◀─Reject──  │                  PO → Partly received /          (cash / bank / cheque)
                  Approve            Received)
                   ▼                                                 Supplier return
                Approved ──Mark sent──▶ Sent ──▶ Partly received ──▶ Received ──▶ Closed
```

### 8.1 Suppliers (Purchasing → Suppliers)

Name, phone, address, credit terms. The supplier page shows balance, ledger (goods received, returns, payments), and **Pay supplier**. Opening balances from the old books: Administration → Go-live → supplier import.

### 8.2 Purchase order (Purchasing → Purchase orders)

1. **New**, or **Reorder suggestions** (items at/below reorder level; this supplier's usual items first).
2. Choose supplier; add lines: product, unit, quantity.
   - **Sales Staff** enter **quantities only**; they never see costs.
   - Owner/Manager also enter unit cost, discount, tax (last cost is pre-filled).
3. **Submit** → *Waiting for approval*. Managers and the owner get a bell notification.
4. Owner/Manager open it, confirm/fill costs, and **Approve** (or **Reject** with a reason, which goes back to the creator).
5. Send it: **PDF**, or the **WhatsApp link** (a signed link the supplier can open without signing in). Then **Mark sent**.
6. **Cancel** an order that received nothing; **Close** one that will not be delivered in full.

### 8.3 Goods received note — GRN (Purchasing → Goods received)

**This is how you add stock from a delivery.**

1. **New GRN**: choose an approved/sent PO (lines are filled from what is still outstanding) or make a **direct GRN** (no PO).
2. Header: supplier, **supplier invoice no.**, date received, note.
3. For each line:

| Column | Meaning |
|---|---|
| Qty | Paid quantity in the chosen unit |
| **Free** | Free goods (bonus). They **lower the unit cost** of the batch. |
| Unit cost | Cost per unit (Owner/Manager) |
| **Lot no.**, **Expiry** | Required for batch/expiry-tracked products |

   - *Add a product that was not on the order* for extra items.
   - You can't receive more than is outstanding on a PO line; put extras as free quantity or a separate line.
   - **Open now** (only on bags set up with *Can be opened into*): how many of these bags are opened straight away for loose sale, and what they weighed. Example: 10 bags of urea arrive, 7 stay sealed, 3 are opened → *Open now 3, weighed 149.5*. All 10 count as received on the PO; posting then opens the 3 bags (section 7.7).
4. **Save** → *Draft* (nothing has happened to stock yet; you can still edit).
5. **Post** →
   - stock **in** (paid + free), one new batch per line for batch-tracked products;
   - batch cost = line total after the GRN discount ÷ all units received;
   - the product's reference cost updates to this cost;
   - PO becomes *Partly received* or *Received*;
   - the supplier's balance goes up by the GRN total;
   - journal: Dr Inventory, Cr Accounts payable.
6. A draft can be **cancelled**. A **posted GRN can never be edited** — fix mistakes with a supplier return (and, if needed, a new GRN).

Partial deliveries: post a GRN for what came; the PO stays *Partly received* until the rest arrives.

### 8.4 Supplier return (Purchasing → Supplier returns)

Choose supplier and the **batch** the goods came from, quantity, reason. Posted on save:
- stock out from that batch;
- the supplier's balance goes down at the **buying price** (the GRN price after its discount, else the supplier's last price);
- any difference from the stock cost is booked as a stock gain/loss.

Numbers: `SRN-…`.

### 8.5 Supplier payment (Purchasing → Supplier payments, owner only)

Pay from the drawer, cash at home, a bank transfer, or a **cheque**. The payment is applied to unpaid GRNs oldest first (or as chosen); extra stays as an advance. A cancelled or bounced cheque makes those GRNs unpaid again. Numbers: `SP-…`.

---

## 9. POS: a normal day at the shop

### 9.1 Opening the day (main cashier)

1. Owner signs in with PIN on the main cashier PC.
2. **POS → Cashier → Open drawer**: count the morning float by denomination (5000 × 3, 1000 × 12 …).
3. Counter staff sign in with their PIN on Counter 1–3 (this clocks them in).

### 9.2 Billing at a counter (POS → Billing screen)

| Key | Action |
|---|---|
| **F2** | Focus search (it is always focused) |
| ↑ ↓ **Enter** | Pick a result and add it |
| `5*urea` | Add with quantity 5 (`750g*urea` adds 750 g of a loose product) |
| **+ / − / Del** | Change quantity / remove line |
| **F4** | Choose or quick-add a customer (phone, name, NIC, village) |
| **F6** | Amount tendered |
| **F7** | Save as quotation (empty bill: load an open quotation) |
| **F8** | Hold / recall a bill |
| **F9** | **Print invoice** |
| **F10** | Reprint last invoice (marked COPY) |
| **Esc** | Clear the bill |

Steps:
1. Add items (search, favourites, recent, or category tiles). Switch the unit on a line if needed.
   - **Loose goods:** type the weight in the quantity box as kg (`1.5`) or grams (`750g`). Under 1 kg the line shows the grams, and the price column shows which price applies (*under 1 kg* or *from 1 kg*, section 6.5).
   - Choosing a **wholesale customer** (F4) switches the bill to the Wholesale list; items without a wholesale price stay at the retail price.
2. Discounts per line or for the bill. Above the staff limit, an **approval request** goes to the cashier; wait for approval before printing.
3. Tell the customer the total. Choose payment: **Cash** (type tendered; the balance shows large), or Card / Bank transfer / Cheque / **Credit** (credit needs a customer).
4. **F9**: the server re-prices every line, reserves stock, takes the next invoice number (`INV-2026-000123`) and prints the Sinhala invoice on **this counter's printer**.
5. Take invoice + cash to the main cashier.

Printer out of paper? Reprint from **Last invoices** (logged, marked COPY).

### 9.3 Settling at the main cashier (POS → Cashier)

The screen has **one column per counter**, showing the bill being built live and printed invoices waiting.

- **Settle**: check invoice and money → Settle. Stock is issued (FEFO), payment goes into the drawer session, the cash drawer opens. Card/cheque: enter the reference. **Credit**: see the customer's balance and limit; going over the limit needs the owner's tick (a Manager can't allow it). A **credit bill** prints on the main printer for the customer's signature and shop seal.
- **Find** an invoice by its last digits.
- **Void** with a reason (wrong item/amount, customer left). The counter is offered the bill back to re-bill. Voided invoices keep their number and show as VOID. A sale that has payments applied or returns can't be voided; use a return.
- **Approvals**: approve or reject discount requests.
- The owner can also bill directly on the main terminal; that invoice is settled immediately.

### 9.4 Drawer movements

On the cashier screen: **Pay in** (change brought from home), **Pay out** (cash the owner takes), **Safe drop** (to cash at home). Petty-cash expenses go through Finance → Expenses (from the drawer).

### 9.5 Closing the day

**POS → Cashier → Close drawer**:
1. Not allowed while any invoice is still waiting to be settled.
2. Count the cash by denomination → expected vs counted → **variance** (booked as cash short / over).
3. The **Z report** prints (80 mm, or A4): sales, voids, customer payments and refunds per counter.
4. Counted cash goes home ("Cash at home"); tomorrow's float comes from it.

### 9.6 Handing cashier authority to the Manager

1. Owner: **Hand over** on the main terminal → count the drawer → owner's session closes.
2. Manager enters **their PIN** and a matching count, the expiry time and scope → Manager's session opens.
3. The Manager can now settle, void, refund, approve and see Live Billing — **but never** finance, payroll or admin.
4. Owner returns: **Take back** with a count and the owner's PIN.
5. The owner can **Revoke now** from the dashboard or **POS → Cashier authority** (works from a phone). Authority also ends automatically at the expiry time.

### 9.7 Live Billing (POS → Live Billing)

The same counter columns, **view only**, for the owner's dashboard or phone. Removed items, cleared bills, reprints and voids show in red.

---

## 10. Customers, credit, returns and quotations

| Task | Where | Notes |
|---|---|---|
| Add customer | Customers → New, or **F4** at the counter (quick-add: name, phone, village, no credit) | Phone numbers are unique. Only Owner/Manager set credit limit, credit days, price list. |
| Credit sale | Counter: F4 customer → payment *Credit* → F9 | Hits the customer's account **when settled**. Due after the customer's credit days. |
| Customer pays | POS → Cashier → **Customer payment** | Oldest invoice first or chosen; extra = advance. Sinhala receipt. Cheques go to Finance → Cheques. |
| Statement | Customer page → Statement PDF (Sinhala/English) | |
| Who owes what | Customers → Credit ageing | Morning bell at 07:05 for overdue credit. |
| **Return** | POS → Cashier → **Return** (main terminal only) | Always against the original invoice. Per line: *restock* (back to the original batch) or *damaged* (written off). Refund cash from the drawer or credit to the account (forced if a credit invoice is unpaid). |
| **Quotation** | Counter: **F7** | No stock reserved. Valid 7 days. Loading it bills at **today's** prices. |

---

## 11. Finance and banking

Owner only, except petty-cash expenses (Manager too). Every sale, payment, GRN, adjustment and drawer action **posts to the journal automatically** — you never type journal entries.

| Page | Use |
|---|---|
| **Banking** | Balances of every bank, cash at home, drawer, cheques in hand. Add bank accounts with an opening balance. Bank book per account. Charges and interest. **Move money** (home → bank, bank → bank, owner in/out, card & transfer payments → bank). **Reconcile** against the statement. |
| **Cheques / Cheque calendar** | Received cheques (from settlement/customer payments) and issued cheques (supplier payments). Deposit → Cleared / Bounced / Cancelled. Post-dated cheques can't be deposited early. A bounced customer cheque makes the invoices unpaid again. Bell at 07:10. |
| **Expenses** | With a photo/PDF of the bill; paid from the drawer, cash at home or a bank. Cancel reverses it. |
| **Journal / Chart of accounts** | Read-only view of the books. |
| **Financial reports** | Profit & loss, balance sheet, trial balance, cash book. |

Card and bank-transfer takings wait under *Card & transfer payments* until the owner moves them into a bank.

---

## 12. HR, attendance and payroll

| Task | Who | How |
|---|---|---|
| Clock in | Everyone | First PIN sign-in of the day on a shop terminal (or *Clock in* button) |
| Clock out | Everyone | Button in the top bar |
| My attendance, ask for leave | Everyone | HR & Payroll → My attendance |
| Attendance sheet / month grid | Owner edits, Manager views | Click a day to correct it (reason required, audited) |
| Leave approve / reject | Owner | HR & Payroll → Leave |
| Employees | Owner | Link each employee to their user login |
| Shifts, holidays, leave types | Owner | Default shift 08:00–18:00, late after 10 min |
| Allowances & deductions | Owner | Fixed or % of basic; "EPF applies" flag |
| Salary advances | Owner | Paid from drawer / home / bank; recovered in monthly installments |
| **Payroll** | Owner | Choose month → **Calculate** → check/edit payslips → **Approve** → **Pay** (tick people) → record **EPF + ETF paid**. Payslip PDF in Sinhala/English. |

Rules (Settings → HR & payroll): EPF 8 % + 12 %, ETF 3 %, OT = hours × basic × 1.5 ÷ 240 (from 30 minutes after closing). A past working day with no clock-in and no leave counts as absent.

---

## 13. Reports and dashboard

**Dashboard:** owner sees net sales today (per counter), invoices waiting (amber after 10 min), expected cash, who holds cashier authority (**Revoke now**), approvals, stock alerts, purchasing to do, cheques due, receivables/payables, top 10 items, sales by hour. The Manager sees sales, stock and purchasing; staff see shortcuts and stock alerts.

**Reports** (menu → Reports): pick a report → date range / quick period → summary tiles → table → **Excel** or **PDF**.

| Group | Reports |
|---|---|
| Sales | Daily summary / Z report, by item, category, brand, counter, staff, customer, hour; discounts; settlement waiting time |
| Loss prevention | Removed items & voids by counter, removed items & cleared bills, reprints, discounts above limit |
| Profit (owner) | By item, category, day (FIFO cost) |
| Inventory | Stock on hand & valuation, stock by batch, movement history, expiry, dead stock, reorder list, **products without a selling price**, adjustment & stocktake variance |
| Purchasing | By supplier, by item, open orders, supplier ageing |
| Customers | Receivables ageing, credit sales |
| Cash & finance | Drawer sessions & variances, handover history, expenses (+ P&L, balance sheet, trial balance, cash/bank book, cheque register) |
| Audit | Price change history, user sign-ins |

A sale counts on the day it was **settled**. Excel over 5,000 rows is built in the background and announced under the bell. PDF over 3,000 rows: use Excel.

---

## 14. Administration

### 14.1 Users
Name, username, role, **PIN**, active. Inactive users can't sign in.

### 14.2 Terminals
Four terminals: **MAIN** (main cashier) and Counter 1–3. On each shop PC, sign in with a password → Terminals → **Register this device**. The PC is then bound by a secure cookie; only registered PCs allow PIN sign-in, and only the MAIN terminal can settle. **Unregister** a PC that is replaced.

### 14.3 Printers
One 80 mm thermal printer per terminal; the main printer has the cash drawer. **Test print**, reassign a printer to another terminal if one fails. **Print log** lists every print and reprint. **Printing test** checks Sinhala 80 mm and A4 PDF output. Chrome must run with `--kiosk-printing` on all four PCs (see [PRINTING.md](PRINTING.md)).

### 14.4 Settings
Tabs: Shop (EN/SI), Invoice, Tax, POS rules (staff discount limit, invoice waiting alert, sound), **Inventory** (allow negative stock, **adjustment approval limit Rs. 10,000**), Customers & credit (quotation validity, overdue bell), Finance, HR & payroll.

### 14.5 Audit log
Every price change, discount, void, reprint, removed cart item, adjustment, handover, attendance edit and sign-in.

### 14.6 Go-live
Readiness checklist plus Excel import of **credit customers and suppliers with opening balances** (template → check → import, all or nothing).

---

## 15. Document numbers and statuses

| Document | Number | Statuses |
|---|---|---|
| Invoice | `INV-2026-000001` (gapless, yearly) | On hold → Invoiced → Settled / Void → Partially returned / Returned |
| Purchase order | `PO-…` | Draft → Waiting for approval → Approved / Rejected → Sent → Partly received → Received → Closed · Cancelled |
| Goods received | `GRN-…` | Draft → Posted · Cancelled |
| Supplier return | `SRN-…` | Posted on save |
| Stock adjustment | `ADJ-…` | Posted · Waiting for approval → Posted / Rejected |
| Stocktake | `STK-…` | Open → Counting ⇄ Review → Posted · Cancelled |
| Opened packs | `OPN-…` | Posted on save |
| Customer | `C-00001` | Active / Inactive |
| Customer payment | `RCP-…` | |
| Sale return | `RET-…` | |
| Quotation | `QT-…` | Open → Converted / Expired / Cancelled |
| Journal entry | `JE-…` | Never edited, only reversed |
| Expense | `EXP-…` | Cancel reverses it |
| Supplier payment | `SP-…` | |
| Employee / advance | `E-001` / `ADV-…` | |

---

## 16. Scheduled jobs and alerts

The server's scheduler must run (`php artisan schedule:run` every minute).

| Time | Job |
|---|---|
| Every minute | Handover expiry |
| 07:00 | Low stock and expiry alert |
| 07:05 | Overdue customer credit |
| 07:10 | Cheques to deposit / issued cheques due |
| 07:15 | Yesterday's missing clock-outs |
| 02:50 | Rebuild report summaries (last 40 days) |
| Nightly | Sales velocity (search ranking), prune counter events older than 90 days, backups |

---

## 17. Common problems

| Problem | Fix |
|---|---|
| PIN sign-in doesn't appear / "not a registered terminal" | Register the PC: sign in with a password → Administration → Terminals → Register this device. |
| "These credentials do not match our records" | Check username (not e-mail), caps lock, and that the user is active. Try a private window. |
| Can't settle on the Manager's login | The Manager needs a handover, and must be on the **MAIN** terminal. |
| Can't close the drawer | An invoice is still waiting. Settle or void it first. |
| "Not enough stock" at print | Check Stock on hand; post the GRN for the delivery, or an adjustment. Negative stock is off by default. |
| Imported products show zero stock | Inventory → Stock on hand → **Add opening stock now**. |
| "… has no price per packet" at the counter | The product has no selling price. Set it on the product (Units & Prices). Reports → *Products without a selling price* lists them all. |
| Loose urea under 1 kg is billed at the 1 kg price | The product has only one loose price. Fill both rows (*Under 1 kg* and *1 kg and above*) on the product. |
| Import says "Price for grams … Write it as a price per kg" | The grams price was written per 100 g or per gram. Write it per kg: Rs. 30 for 100 g is 300. |
| Loose stock is zero but sealed bags are in stock | Open bags: Inventory → **Open packs**. |
| A posted GRN is wrong | Posted GRNs can't be edited. Make a **supplier return** for the wrong quantity, then a new GRN if needed. |
| Adjustment stuck "Waiting for approval" | Its value is above Rs. 10,000; the owner approves it from the bell or Inventory → Adjustments. |
| Search is slow for a few seconds | The search server is down; the MySQL fallback is being used. Restart Meilisearch. |
| Whole site shows an error (500) | Redis is not running (sessions/cache/queue use it). Start it. |
| Invoice prints a dialog instead of printing | Chrome isn't started with `--kiosk-printing`, or the wrong default printer is set. |
| Live Billing updates only every 3 seconds | Reverb isn't running (`php artisan reverb:start`); polling is the fallback. |
