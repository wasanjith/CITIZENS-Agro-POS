# CITIZENS Agro POS: Pre-Deployment Audit

> Date: 2026-10-09 · Scope: whole codebase (`app/`, `routes/`, `config/`, `bootstrap/`, `resources/views`, `resources/js`) plus the automated test suite and static analysis.
> Method: a manual review focused on security (authentication, authorization, injection, XSS, file handling, secrets) and on money and stock correctness (POS pricing, settlement, voids, returns, drawer, payments, payroll, purchasing). A script also checked every controller action for an authorization check.

---

## 1. Summary

The code is in good shape. Nothing found is a remote, unauthenticated exploit. The core money and stock paths do what they should:

- prices are always recalculated on the server,
- every sensitive change runs in a transaction with row locks,
- invoice, settlement, return and customer payment requests can be repeated safely (idempotency keys),
- all SQL uses bound parameters or constants,
- no unescaped user data is printed in Blade and no `innerHTML` is used in JS,
- cost prices are hidden from users without `catalog.cost.view`.

What needs attention before go-live is mostly **deployment configuration** and a few **business-control gaps** that a dishonest insider could use.

| Severity | Count | Meaning |
|---|---|---|
| 🔴 High | 3 | Fix before go-live |
| 🟠 Medium | 5 | Fix soon, or accept the risk on purpose |
| 🟡 Low | 10 | Hardening and tidy-ups |

### Automated checks

| Check | Result |
|---|---|
| Pest test suite | ⚠️ 492 tests; full run 452 passed / 3 failed / 37 skipped. All 3 failures pass when re-run (see section 6) |
| Larastan (PHPStan) | ✅ 0 errors |
| `composer audit` | ✅ no advisories |
| `npm audit` | ⚠️ 3 in build-only dev tools (L10), nothing shipped to the browser |

---

## 2. 🔴 High: fix before go-live

### H1. Production environment settings
**Where:** `.env` (currently `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug`, `SESSION_ENCRYPT=false`, `SESSION_SECURE_COOKIE` not set)

With `APP_DEBUG=true`, any error page shows the full stack trace, environment variables, database credentials and query values to whoever is using the browser.

**Fix (production `.env`):**
```
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true      # only if served over HTTPS (recommended, even on the LAN)
APP_URL=https://<real host>
```
Then run `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
Also note: `Model::shouldBeStrict()` is switched off automatically only when `APP_ENV=production` (`app/Providers/AppServiceProvider.php:61`), so leaving `local` would also turn small lazy-loading mistakes into 500 errors on the shop floor.

### H2. Leftover Vite dev-server file `public/hot`
**Where:** `public/hot` (contains `http://[::1]:5173`)

While this file exists, `@vite` loads CSS and JS from a dev server on `localhost:5173` instead of `public/build`. On the shop PCs that server does not exist, so **every page would load without styles or scripts** (POS screen, cashier screen, printing).

**Fix:** delete `public/hot` on the server, run `npm run build`, and make sure deployment never copies `public/hot`. It is already in `.gitignore`, so it only matters if files are copied by hand.

### H3. No backups scheduled

> ✅ **Done in code (2026-10-09), local disk only.** `config/backup.php`, the `backup` disk (`BACKUP_PATH`), and the schedule in `routes/console.php` are in place: database every hour 08:00–20:00, full backup at 22:00, cleanup at 01:00, health check at 07:20, all AES-256 encrypted. Covered by `tests/Feature/System/BackupScheduleTest.php`. A real backup and restore check was run on the dev machine. Cloud storage comes later (owner's decision). **Still to do on the server:** mount the backup HDD, set the `BACKUP_*` values, cron for `schedule:run`, and a restore test. See [DEPLOYMENT.md § 12](DEPLOYMENT.md#12-backups). The original finding follows.

**Where:** `routes/console.php` (schedule), no `config/backup.php`

`spatie/laravel-backup` is installed, and `docs/IMPLEMENTATION_PLAN.md:772` plans hourly dumps and a nightly `backup:run`, but nothing is scheduled and the package is not configured. A disk failure would lose all sales, credit balances and payroll.

**Fix:**
1. `php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"`, set the destination disks (external USB + cloud) and encryption password.
2. Add to `routes/console.php`:
   ```php
   Schedule::command('backup:clean')->dailyAt('01:00');
   Schedule::command('backup:run --only-db')->hourly();
   Schedule::command('backup:run')->dailyAt('01:30');
   ```
3. Make sure Windows Task Scheduler runs `php artisan schedule:run` every minute. The existing jobs (delegation expiry every minute, alerts, summaries) also depend on this.
4. Do a restore test (already on the Go-live checklist).

---

## 3. 🟠 Medium

### M1. PIN sign-in skips two-factor authentication

> ✅ **Fixed (2026-10-09).** Accounts with 2FA are not listed on the PIN screen and a PIN sign-in for them is refused (`PinLoginController`). After a cashier handover or take-back to such an account, the terminal is signed out and they sign in with password and code (`HandoverController::switchUser`). The account page explains this. Tests: `PinLoginTest`, `DrawerHandoverTest`.
**Where:** `app/Http/Controllers/Auth/PinLoginController.php:61`

Password sign-in goes through Fortify, which asks for the 2FA code. PIN sign-in calls `Auth::login($user)` directly, so a Super Admin who turned on 2FA can still be signed in on any registered terminal with only their 4–6 digit PIN, which gives full owner rights (finance, payroll, users, settings).

**Fix (pick one):**
- do not offer PIN sign-in to users who have 2FA confirmed (`whereNull('two_factor_confirmed_at')` in `create()` and a check in `store()`), or
- after a PIN sign-in, limit the session to POS work and ask for the password again (`password.confirm` middleware) before the admin, finance and HR areas.

### M2. A PIN can be guessed in a day by someone at a terminal

> ✅ **Fixed (2026-10-09).** New `PinLockout`: 15 wrong PINs per user per day (`pos.pin.max_failures_per_day`) lock the PIN until midnight, for both PIN sign-in and handover PIN checks. Every wrong PIN is in the audit log (`login_failed` / `pin_failed`, then `pin_locked`), and the Super Admins get a notification. Setting a new PIN unlocks it. Test: `PinLoginTest`.
**Where:** `PinLoginController::store` (limit 5 attempts per minute per terminal+user, `config/pos.php` → `pin`)

A 4-digit PIN has 10,000 values. At 5 tries a minute the lock never gets longer, so someone standing at a shop PC could try every PIN for the owner's account in about 33 hours (half that on average), and nobody is told. The sign-in screen also lists every user who has a PIN, owner included. Failed PIN tries are not written to the audit log, while failed password sign-ins are (`FortifyServiceProvider`).

**Fix:**
- add a daily limit on top of the per-minute one (for example 20 failures per user per day → lock the PIN until an admin resets it),
- log failed PIN sign-ins with `activity()->event('login_failed')` the same way as password failures,
- think about 6-digit PINs for Super Admins, or not listing Super Admins on the PIN screen.

### M3. Any counter can bill at the Wholesale price list

> ✅ **Fixed (2026-10-09).** New permission `pos.price_list.choose` (Super Admin, delegable). Without it, `CartPricer` only accepts the default list or the customer's own list: a printed invoice or quotation is refused, and the live cart falls back to the default. On the counter the price list picker is locked for staff and follows the customer. Existing databases get the permission from migration `2026_10_09_100000_sync_permissions_price_list_choose`. Tests: `CounterInvoiceTest`.
**Where:** `app/Http/Requests/Pos/CartRequest.php:28`, `app/Domain/Sales/Services/CartPricer.php:56`, `app/Http/Controllers/Pos/CounterController.php:32`

The cart takes any `price_list_id`, and every price list is offered on the counter screen. Sales Staff can switch a walk-in customer's bill to Wholesale. That works as an unapproved discount, it skips the 5 % discount limit, and it is not flagged on any loss-prevention report. Wholesale is meant only for the few customers who have it on their profile (see the pricing rules confirmed with the owner).

**Fix:** in `CartPricer`, allow a non-default price list only when (a) it is the selected customer's `price_list_id`, or (b) the user has a new permission such as `pos.price_list.choose` (Owner / delegable). Otherwise use the default list.

### M4. Stock write-offs can be split to stay under the approval limit

> ✅ **Fixed (2026-10-09).** The limit now counts the user's self-posted adjustments of the day plus the new one (`CreateStockAdjustmentAction::needsApproval`); the setting label and the form say so. Test: `StockAdjustmentTest`.
**Where:** `app/Domain/Inventory/Actions/CreateStockAdjustmentAction.php:104`

The approval limit (`inventory.adjustment_approval_limit`, Rs. 10,000) is checked per document. A Manager can write off Rs. 40,000 of stock as four adjustments of Rs. 9,900, and each one posts straight away without the owner seeing it.

**Fix:** compare against the user's total of auto-approved adjustments for the day (or week), or send the owner a notification for every auto-approved negative adjustment. As a minimum, add "auto-approved adjustments by user" to the loss-prevention reports.

### M5. Approval requests show a discount % worked out from numbers the counter sent

> ✅ **Fixed (2026-10-09).** `RequestDiscountApprovalAction` takes the line or bill amount and the label from the server's priced copy of the cart (`LiveCartStore`); `gross` and `label` are no longer read from the request, and a discount larger than the line is refused. The counter sends any pending sync before asking. Tests: `CounterInvoiceTest`.
**Where:** `app/Http/Controllers/Pos/ApprovalController.php:20`, `app/Domain/Sales/Actions/RequestDiscountApprovalAction.php:58`

When a counter asks for a discount above its limit, the `gross` (line or bill amount) and so the `percent` the cashier sees on the approval banner come from the browser. A modified request could ask for Rs. 500 off a Rs. 600 line while claiming the line is Rs. 10,000, and the banner would show "5 %". The money amount shown is correct, and `CartPricer` does check the approved **amount** at invoice time, so the risk depends on whether the cashier reads the rupee amount or the percentage.

**Fix:** in `RequestDiscountApprovalAction`, recalculate `gross` on the server from the stored live cart (`LiveCartStore`) for that terminal and line key, or show only the rupee amount on the banner.

---

## 4. 🟡 Low: hardening and tidy-ups

| # | Finding | Where | Suggested fix |
|---|---|---|---|
| L1 | **Array query parameters cause a 500 error.** `?filter[status][]=x`, `?sort[]=x`, `?q[]=x` reach `(string) $value`, which throws "Array to string conversion". Harmless, but makes noise in the logs and on screen. | `app/Http/Controllers/Concerns/HasListQuery.php:36-44`, and the many `(string) $request->query(...)` calls | Use `$request->string('sort')` / check `is_string($value)` before casting. |
| L2 | **No duplicate protection on the server** for supplier payments, expenses, money moves, salary advances and bank charges. The buttons are disabled on submit (Alpine `busy`), but a back-button resubmit or a slow network retry can still record the payment twice. | `PaySupplierAction`, `RecordExpenseAction`, `MoveMoneyAction`, `GiveSalaryAdvanceAction`, `RecordBankChargeAction` | Add a hidden `idempotency_key` like the customer payment form already has. |
| L3 | **Abandoned import uploads are never deleted.** A product or opening-balance file that is previewed but never imported stays in `storage/app/private/imports/` forever. The file name also keeps the extension the browser sent. | `ProductImportController::preview`, `GoLiveController::preview` | Store with a fixed extension (`->extension()` from the guessed MIME type) and delete `imports/*` older than 1 day in the nightly job that already cleans `exports/`. |
| L4 | **Changing a user's password does not sign out their other sessions.** If a password or PIN leaks and the owner changes it, the old session keeps working until it expires. Deactivating a user does sign them out. | `SaveUserAction` | With `SESSION_DRIVER=database`/`redis`, delete that user's sessions on password change, or bump a `remember_token`/session version. |
| L5 | **A handover delegation can be set to expire years ahead.** Only `after:now` is checked. | `HandoverController::store` (`expires_at`) | Add `before_or_equal:` end of today (or +24 h). |
| L6 | **`Gate::before` lets the Super Admin through every policy, including the state checks inside them** (e.g. `PurchaseOrderPolicy::send/approve` status, `LeaveRequestPolicy::withdraw`). The domain actions check the state again, so nothing breaks today; the only visible effect is that the owner can "mark as sent" or download the PDF of a draft PO. This is something to keep in mind when adding new policies. | `AppServiceProvider::registerGates` | Keep state checks in actions (as now), or return `null` from `Gate::before` for policy abilities. |
| L7 | **Idempotency keys are looked up across all terminals and users.** A key reused by another terminal would return that terminal's invoice. The client makes UUIDs, so in practice this does not happen. | `IssueCounterInvoiceAction::handle`, `CreateSaleReturnAction::handle`, `ReceiveCustomerPaymentAction::handle` | Add `->where('invoiced_terminal_id', …)` / user to the lookup. |
| L8 | **Reverb allows WebSocket connections from any origin.** Private channels still need a signed-in session, so the effect is small. | `config/reverb.php:85` (`allowed_origins => ['*']`) | Set it to the shop's host name(s). |
| L9 | **Stray file in the project root** named `fetchColumn()` (58 bytes, a PHP parse error message from a mistyped shell command). | project root | Delete it. |
| L10 | **npm audit: 3 advisories in build tools.** `concurrently` → `shell-quote` (critical, command injection in `quote()`), `source-map-js` (high, DoS). They only run on the developer PC during `npm run dev/build`; none of them end up in `public/build`. | `package-lock.json` | `npm audit fix`, then `npm run build` and a quick check of the POS screen. |

---

## 5. Checked and found sound

So the next reviewer does not have to repeat it:

- **Authorization:** every controller action has a policy, a `can:` route middleware or a FormRequest `authorize()` check. The ones that look open at first (`DrawerCountRequest`, `SaveCustomerRequest`, `SaveEmployeeRequest` return `true`) are covered by the route middleware or by `authorize()` in the controller.
- **POS pricing:** `CartPricer` reprices every line from the price book, checks discounts against the user's limit and the approved amount, and approvals are single-use (`sale_id` is stamped on them).
- **Settlement / void / return:** row locks on the sale and drawer session, a drawer-holder check, only today's settlements can be voided, returns are capped at what is left to return per line and per batch, and credit limit overrides are owner-only.
- **Drawer handover:** the Manager's PIN is checked, both counts must match, non-delegable permissions are refused, and only the holder or the owner can close the drawer.
- **SQL:** all `whereRaw`/`selectRaw` interpolations are constants; list sorting uses a whitelist.
- **XSS:** the only `{!! !!}` outputs are the 2FA QR SVG and two fixed HTML literals; no `innerHTML`/`x-html` in JS.
- **Files:** the attendance photo is checked with a regex, a size cap and `getimagesizefromstring`, and saved under a random name on the private disk; report downloads are limited to the user's own folder with a strict file-name regex.
- **Terminal binding:** a 64-character random token, stored only as its SHA-256 hash, in an httpOnly cookie.
- **Shared PO link:** a temporary signed URL (30 days) that refuses cancelled or unapproved orders.
- **Cost visibility:** product search, batch lookup, PO, GRN and product export all hide cost without `catalog.cost.view`.
- **Payroll:** status guards and row locks on calculate, approve, reopen, pay, EPF/ETF and payslip edits.
- **Document numbers:** gapless, with the sequence row locked inside the business transaction.

---

## 6. Test suite and static analysis

Run on 2026-10-09 against MySQL `citizensDB_testing`.

| Run | Result |
|---|---|
| Full suite (`php artisan test`, ~7 min) | 492 tests: 452 passed, **3 failed**, 37 skipped (Meilisearch search tests: Meilisearch was not running) |
| `tests/Feature/Catalog/CatalogPagesTest.php` on its own | 25 / 25 passed |
| `tests/Feature/Reports/ReportsTest.php` on its own | 15 / 15 passed |
| Larastan level 6 | 0 errors |

**The 3 failures:**

1. `CatalogPagesTest` › *the manager manages the catalogue but not taxes*
2. `CatalogPagesTest` › *sales staff only see the product list*

   Both failed with MySQL `SQLSTATE[HY000] 1412 Table definition has changed, please retry transaction` and pass when re-run. This is a test-environment problem, not an app bug: something changed a table's structure in `citizensDB_testing` while a test transaction was open (for example a second test run, or a `migrate` against the testing database at the same time). Run the suite on its own before go-live and check that it is green. If it happens again, check that nothing else (another terminal, an IDE test runner) uses `citizensDB_testing`.

3. `ReportsTest` › *the owner dashboard shows sales, profit, …*: it looked for `"Closing cash (expected)"`. The dashboard Blade now shows `(expected)` inside a `<span class="hidden 2xl:inline">`, so the plain text no longer matches. The test in the working copy now checks `"Closing cash"` and passes. The dashboard work is still uncommitted, so **commit the dashboard changes and the updated test together**.

**After fixing anything in this report, re-run the full suite once more before deploying.**

---

## 7. Go-live deployment checklist

1. Production `.env` as in **H1**; `php artisan key:generate` only on a brand-new install (never on an existing database: it breaks encrypted cookies, so every terminal would need to be registered again).
2. Delete `public/hot` (**H2**), then `npm ci && npm run build`.
3. `composer install --no-dev --optimize-autoloader`.
4. `php artisan migrate --force`, then `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (if permissions changed).
5. `php artisan config:cache route:cache view:cache event:cache`.
6. Scheduler: Windows Task Scheduler → `php artisan schedule:run` every minute.
7. Queue worker: `php artisan queue:work redis --tries=3` as a service (NSSM or similar) for report exports and notifications.
8. Reverb: `php artisan reverb:start` as a service; set `allowed_origins` (**L8**).
9. Meilisearch running with a master key (`MEILISEARCH_KEY`), then `php artisan scout:sync-index-settings` and `scout:import` for products.
10. Backups: backup HDD mounted, `BACKUP_PATH` and `BACKUP_ARCHIVE_PASSWORD` set, first backup run by hand and restore-tested (**H3**; the code part is done).
11. Redis password set (`REDIS_PASSWORD`) and Redis/MySQL/Meilisearch bound to localhost or the shop LAN only, never the internet.
12. Remove demo and dummy data (dummy customers, demo products) before the real import.
13. Delete the stray `fetchColumn()` file (**L9**).
