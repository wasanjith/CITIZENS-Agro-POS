# CITIZENS Agro POS: Deployment Architecture

> The full production setup for the shop: which machines to buy, how the server is built, how everything connects, and how it is kept running and backed up.
> Expands [IMPLEMENTATION_PLAN.md § 16](IMPLEMENTATION_PLAN.md#16-deployment). Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [PRINTING.md](PRINTING.md) · [PRE_DEPLOYMENT_AUDIT.md](PRE_DEPLOYMENT_AUDIT.md)

---

## Contents

1. [Design principles](#1-design-principles)
2. [The whole picture](#2-the-whole-picture)
3. [Shopping list (bill of materials)](#3-shopping-list-bill-of-materials)
4. [Shop server](#4-shop-server)
5. [Server software stack](#5-server-software-stack)
6. [Terminal PCs](#6-terminal-pcs)
7. [Printers and cash drawer](#7-printers-and-cash-drawer)
8. [Network](#8-network)
9. [Power (UPS)](#9-power-ups)
10. [HTTPS inside the shop](#10-https-inside-the-shop)
11. [Remote access for the owner](#11-remote-access-for-the-owner)
12. [Backups](#12-backups)
13. [Security hardening](#13-security-hardening)
14. [Configuration files](#14-configuration-files)
15. [First install, step by step](#15-first-install-step-by-step)
16. [Updating the app (deploy script)](#16-updating-the-app-deploy-script)
17. [Monitoring and daily checks](#17-monitoring-and-daily-checks)
18. [When things go wrong (runbook)](#18-when-things-go-wrong-runbook)

---

## 1. Design principles

| Principle | What it means for the setup |
|---|---|
| **Selling must never depend on the internet** | Everything (app, database, search, real-time) runs on one server **inside the shop**. The internet is only used for remote viewing (and, later, cloud backups). |
| **One server, simple to look after** | No cluster, no Docker in production. One mini PC with standard Ubuntu services that a local technician can understand. |
| **Survive a power cut** | UPS on everything that takes money. MySQL InnoDB + clean UPS shutdown. Counter carts are kept in Redis and come back after a restart. |
| **Lose at most one hour of data** | Hourly encrypted database backups during shop hours and a full backup every night, on the server's **backup hard disk**. Cloud copies come later (owner's decision, 2026-10-09). |
| **No open ports to the internet** | Remote access only through Tailscale (a private encrypted tunnel). The router forwards nothing. |
| **Any broken part can be swapped in minutes** | Spare thermal printer of the same model; the server can be rebuilt from this document plus the latest backup. |

---

## 2. The whole picture

```
                                   INTERNET (only for remote view; cloud backup later)
                                            │
                                   ┌────────┴────────┐
                                   │  ISP router     │  4G/fibre, NO port forwards
                                   │  192.168.10.1   │
                                   └────────┬────────┘
                                            │
 ┌──────────────────────────────── SHOP LAN 192.168.10.0/24 ────────────────────────────────┐
 │                                          │                                                │
 │                              ┌───────────┴───────────┐                                    │
 │                              │ 8-port gigabit switch │  (on UPS)                          │
 │                              └─┬─────┬─────┬─────┬───┘                                    │
 │                                │     │     │     │                                        │
 │  ┌─────────────────────────────┴┐    │     │     │                                        │
 │  │ SHOP SERVER  192.168.10.10    │    │     │     │                                        │
 │  │ pos.citizens.local            │    │     │     │                                        │
 │  │ Ubuntu 24.04 LTS              │    │     │     │                                        │
 │  │  Nginx :443 ── PHP-FPM        │    │     │     │                                        │
 │  │        └── /app → Reverb :8080│    │     │     │                                        │
 │  │  MySQL 8.4 · Redis 7          │    │     │     │                                        │
 │  │  Meilisearch · Chromium (PDF) │    │     │     │                                        │
 │  │  Queue workers · Scheduler    │    │     │     │                                        │
 │  │  Tailscale · UPS daemon       │    │     │     │                                        │
 │  │  + backup hard disk           │    │     │     │                                        │
 │  └───────────────────────────────┘    │     │     │                                        │
 │                                       │     │     │                                        │
 │  MAIN CASHIER PC  .21 ────────────────┘     │     │                                        │
 │   Chrome kiosk /pos/cashier                  │     │                                        │
 │   USB printer #0 (manual cash drawer for now)│     │                                        │
 │   QZ Tray later (drawer kick) · webcam (opt.)│     │                                        │
 │                                              │     │                                        │
 │  COUNTER 1 PC  .31 ──────────────────────────┘     │                                        │
 │   Chrome kiosk /pos · USB printer #1               │                                        │
 │  COUNTER 2 PC  .32 ────────────────────────────────┘                                        │
 │   Chrome kiosk /pos · USB printer #2                                                        │
 │  COUNTER 3 PC  .33 ── (5th switch port) Chrome kiosk /pos · USB printer #3                  │
 │                                                                                            │
 │  Back-office use (owner/manager laptop, Wi-Fi) → https://pos.citizens.local               │
 └────────────────────────────────────────────────────────────────────────────────────────────┘
                                            │
                                    Tailscale tunnel
                                            │
                       Owner's phone: dashboard, Live Billing, revoke handover
                       Cloud storage: nightly encrypted backup
```

**What runs where**

| Machine | Runs | Talks to |
|---|---|---|
| Shop server | The whole application and all data | Everyone on the LAN over HTTPS (443) and WebSocket (wss via 443) |
| Main cashier PC | Chrome only (+ QZ Tray once a printer-driven drawer is fitted) | Server; its own USB printer |
| Counter PCs ×3 | Chrome only | Server; their own USB printer |
| Owner's phone | Browser | Server through Tailscale |

The terminal PCs keep **no data**. If one dies, plug in another PC, open Chrome, and have the Super Admin register it as that terminal (Administration → Terminals → Register this device).

---

## 3. Shopping list (bill of materials)

| # | Item | Qty | Minimum spec | Notes |
|---|---|---|---|---|
| 1 | **Shop server** (mini PC) | 1 | see [§ 4](#4-shop-server) | Intel NUC-class / Lenovo ThinkCentre Tiny / HP EliteDesk Mini / Beelink-class with a business-grade SSD |
| 2 | **Terminal PCs** | 4 | see [§ 6](#6-terminal-pcs) | 1 main cashier + 3 counters; mini PCs or all-in-ones |
| 3 | **Monitors** (if not all-in-one) | 4 | 21.5–24" 1920×1080 | Main cashier: **24" 1920×1080** (Live Billing shows 3 columns) |
| 4 | Keyboard + mouse | 4 | Wired USB | Spill-resistant keyboards; counters work mostly by keyboard (F2/F4/F6/F8/F9) |
| 5 | **80 mm thermal printers** | 4 + **1 spare** | USB, proper Windows driver, 203 dpi, auto-cutter, RJ11 drawer port | All the **same model**; choose it after the Sinhala print test ([PRINTING.md](PRINTING.md)) |
| 6 | **Cash drawer** | — | **Keep the existing manual drawer** (about one more year) | Later: RJ11/RJ12 printer-driven, 24 V, 5 note / 8 coin, matching the printer's drawer port. See [§ 7](#7-printers-and-cash-drawer) |
| 7 | **UPS for the server rack** | 1 | Line-interactive, 1000–1500 VA, **USB/HID shutdown port** | Powers server, switch, router |
| 8 | **UPS for the main cashier** | 1 | Line-interactive, 650–850 VA | Main cashier PC + monitor + printer #0 |
| 9 | UPS for the counters (recommended) | 1–3 | 650 VA each | Optional: carts survive anyway, but staff can finish printing |
| 10 | **Gigabit switch** | 1 | 8-port unmanaged | 5 wired machines + spare ports |
| 11 | Router | 1 | Existing ISP fibre/4G router | Must allow a **DHCP reservation** or static IPs |
| 12 | Network cable | — | Cat6, patch cables | All terminals **wired**, never Wi-Fi |
| 13 | **Backup hard disk** | 1 | **1–2 TB HDD**, inside the server (2.5"/3.5" bay) or a USB 3 disk left plugged in | Holds all backups ([§ 12](#12-backups)). Cloud storage is added later |
| 14 | Paper rolls | stock | 80 mm × 80 m thermal | Keep at least 1 month of rolls |
| 15 | Webcam (optional) | 1–4 | 720p USB | Only if "clock-in photo" is switched on (Settings → HR) |
| 16 | Small lockable cabinet / shelf | 1 | Ventilated | For server, switch, router and UPS, away from agro-chemical dust and water |

**Not needed:** barcode scanners (the shop uses short codes and search), a customer display, or a cloud server.

---

## 4. Shop server

### 4.1 Hardware

| Part | Minimum | **Recommended** | Why |
|---|---|---|---|
| CPU | 4 cores (Intel i3 12th gen / Ryzen 3) | **6+ cores, Intel i5 12th gen+ / Ryzen 5 5600+** | PHP-FPM, MySQL, Meilisearch indexing and headless Chromium (PDFs) all run at once at peak hours |
| RAM | 8 GB | **16 GB DDR4/DDR5** | See the memory budget below |
| System disk | 256 GB NVMe | **512 GB NVMe SSD, business/TLC with DRAM cache** (Samsung, WD Red/Blue SN, Crucial) | Database and search index want fast random I/O; avoid cheap DRAM-less QLC disks |
| **Backup disk** | 1 TB HDD | **1–2 TB HDD** (internal bay, or USB 3 left connected) | Backups on a **separate disk** from the database, so one disk failure cannot take both. Pick a mini PC with a free 2.5" bay, or use USB |
| Network | 1 Gb Ethernet | **1 Gb Intel/Realtek Ethernet** | Wired only |
| USB | 2 × USB 3 | 3+ USB | UPS signal cable (+ backup disk if it is USB) |
| Power | — | **BIOS: "Restore on AC power loss = Power On"** | Boots by itself when the power comes back |
| Form factor | Mini PC | Mini PC with a fan (not fanless) | Sri Lankan heat; keep it out of direct sun |

### 4.2 Memory budget (16 GB)

| Service | Allowance |
|---|---|
| MySQL InnoDB buffer pool | 4 GB |
| MySQL other | 0.5 GB |
| PHP-FPM (12 workers × ~80 MB) | 1 GB |
| Queue workers (2) + scheduler | 0.5 GB |
| Reverb (WebSockets) | 0.2 GB |
| Redis (cache, sessions, live carts, queues) | 0.5 GB (`maxmemory 512mb`) |
| Meilisearch | 1–2 GB |
| Chromium for PDFs (peak, 2 at once) | 1 GB |
| Ubuntu, Nginx, Tailscale | 1 GB |
| **Free for the OS file cache** | **~5 GB** |

With 8 GB, lower the InnoDB buffer pool to 2 GB and PHP-FPM to 8 workers. It will work but has no headroom.

### 4.3 Disk and data growth

Expected volume (3 counters, a busy agro shop): ~300 invoices/day × ~5 lines.

| Data | Per year (estimate) |
|---|---|
| Sales, sale items, payments, stock movements, journal | 1–3 GB |
| Counter events (kept 90 days, pruned nightly) | < 0.5 GB rolling |
| Meilisearch index | < 1 GB |
| Attendance photos (if switched on) | ~0.5 GB |
| Local hourly dumps (kept 48 h) + daily (kept 14 days) | 5–15 GB rolling |
| Logs (rotated) | < 1 GB |

**512 GB lasts many years.** Set an alert at 80 % disk use ([§ 17](#17-monitoring-and-daily-checks)).

### 4.4 Disk layout

| Mount | Size | Content |
|---|---|---|
| `/` (ext4) | rest of the disk | OS, `/var/www/citizens`, MySQL data `/var/lib/mysql`, Meilisearch data `/var/lib/meilisearch` |
| swap | 4 GB file | Safety net only |
| `/mnt/backup` | **backup HDD** (ext4), mounted by UUID in `/etc/fstab` with `nofail` | All backups, in `/mnt/backup/citizens` (`BACKUP_PATH`) |

### 4.5 Server features to switch on

- **BIOS:** auto power-on after power loss; disable sleep; enable virtualization (not needed now, useful later).
- **Ubuntu:** unattended security updates (no automatic reboots during shop hours), NTP time sync (`timedatectl set-ntp true`) with timezone **Asia/Colombo**. Invoices, drawer days and attendance all depend on the correct time.
- **UPS daemon** (`nut` or `apcupsd`): shut down cleanly when the battery is low ([§ 9](#9-power-ups)).
- **Static IP** `192.168.10.10` (netplan) or a DHCP reservation on the router.
- **Hostname** `pos` and local DNS name `pos.citizens.local` ([§ 8](#8-network)).

---

## 5. Server software stack

| Component | Version | Runs as | Listens on | Purpose |
|---|---|---|---|---|
| Ubuntu Server | 24.04 LTS | — | — | OS (supported to 2029) |
| Nginx | distro | systemd `nginx` | **0.0.0.0:443**, :80 → redirect | HTTPS, static files, WebSocket proxy to Reverb |
| PHP-FPM | **8.4** (match the dev machine; Laravel 13 needs 8.3+) | systemd `php8.4-fpm` | unix socket | Laravel app. Extensions: `bcmath, curl, gd, intl, mbstring, mysql, redis, xml, zip, opcache` |
| Composer | 2.x | — | — | Install PHP packages |
| MySQL | **8.4 LTS** | systemd `mysql` | **127.0.0.1:3306** | Main database (InnoDB, `utf8mb4_unicode_ci`) |
| Redis | 7.x | systemd `redis-server` | **127.0.0.1:6379** (password) | Cache, sessions, queues, live carts |
| Meilisearch | 1.12.x (same as dev) | systemd `meilisearch` | **127.0.0.1:7700** (master key) | Typo-tolerant product search (MySQL fallback if it is down) |
| Laravel Reverb | from composer | Supervisor `citizens-reverb` | **127.0.0.1:8080** (Nginx proxies `/app`) | Live Billing, approvals, cashier authority updates |
| Queue workers | — | Supervisor `citizens-queue` ×2 | — | Notifications, large Excel exports, background jobs |
| Scheduler | — | cron, every minute | — | Delegation expiry (every minute), stock/cheque/credit alerts, counter-event pruning, sales summaries, backups |
| Chromium / Google Chrome | stable | used by `spatie/laravel-pdf` | — | A4 PDFs (invoices, statements, payslips, POs, reports) |
| Node.js | 20+ (**build only**) | — | — | `npm ci && npm run build` during deploys |
| Tailscale | stable | systemd `tailscaled` | tailnet only | Owner's remote access |
| NUT / apcupsd | distro | systemd | — | UPS monitoring and clean shutdown |
| UFW firewall | distro | — | — | Allow only 443/80 from the LAN, SSH only from the LAN/Tailscale |

**Nothing except Nginx (and SSH) listens on the network.** MySQL, Redis, Meilisearch and Reverb are bound to `127.0.0.1`.

---

## 6. Terminal PCs

### 6.1 Hardware

| Part | Counter PC (×3) | Main cashier PC (×1) |
|---|---|---|
| CPU | Intel i3 / Ryzen 3 (or newer Celeron N100-class) | Intel i3/i5 / Ryzen 3/5 |
| RAM | 8 GB | 8 GB (16 GB if the owner also uses it for back-office work) |
| Disk | 128–256 GB SSD | 256 GB SSD |
| Screen | 21.5" 1920×1080 | **24" 1920×1080** (3 Live Billing columns + settle cards) |
| Network | **Wired Gigabit** | **Wired Gigabit** |
| USB | Printer + keyboard + mouse (+ webcam) | Printer + keyboard + mouse (+ webcam) |
| OS | Windows 10/11 Pro | Windows 10/11 Pro |

### 6.2 Software and settings on every terminal

1. **Google Chrome** (stable), updates on.
2. **Thermal printer driver** from the maker; printer set as the **Windows default printer**, paper 80 mm, margins 0 ([PRINTING.md](PRINTING.md)).
3. **Trust the shop's HTTPS certificate** (install the local CA certificate in *Trusted Root Certification Authorities*, [§ 10](#10-https-inside-the-shop)).
4. **Chrome kiosk shortcut** in *Startup* (`shell:startup`):

   | Terminal | Shortcut target |
   |---|---|
   | Main cashier | `"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing https://pos.citizens.local/pos/cashier` |
   | Counter 1–3 | `"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing https://pos.citizens.local/pos` |

5. **Register the device once** as its terminal (Super Admin: Administration → Terminals → Register this device). This sets a long-lived device cookie; **do not clear Chrome's cookies** on terminals.
6. Windows: sleep **never**, screen-off after 30 min, a local Windows user per PC (no admin rights for staff), automatic sign-in to that Windows user, Windows Update active hours set to shop hours.
7. **Main cashier only, later:** the shop keeps its **manual cash drawer** for about one more year, so QZ Tray is **not needed yet**. When a printer-driven drawer is fitted, install QZ Tray (starting with Windows, allowed for `pos.citizens.local`), tick *Cash drawer connected* on printer #0, and put back the drawer alert ([§ 7](#7-printers-and-cash-drawer)). Signing QZ requests with the shop certificate is still a TODO in `resources/js/printing/drawer.js`.
8. Optional webcam, allowed for `pos.citizens.local` in Chrome site settings, if clock-in photos are switched on.

---

## 7. Printers and cash drawer

| Printer | Connected to | Prints | Extra |
|---|---|---|---|
| #0 | Main cashier PC (USB) | Direct sales, credit bills, payment receipts, return receipts, drawer reports | Printer-driven cash drawer on the RJ11 port **later** (manual drawer for now) |
| #1, #2, #3 | Counter PCs (USB) | Invoices (Sinhala/English), quotations | — |
| Spare | Shelf | — | Same model; swap and update the printer record in Administration → Printers |

- Printing goes **browser → Windows driver** (`--kiosk-printing`), so Sinhala text prints as an image and looks correct.
- **Cash drawer, now:** the shop keeps its **manual cash drawer** for about one more year (owner, 2026-10-09). The cashier opens it by hand. All the drawer *accounting* in the app works the same: opening float, cash in and out, handover counts, expected cash and variance, Z report.
- **Cash drawer, later:** the automatic drawer kick is kept in the code. With a printer-driven drawer it opens **only** on a cash settlement, through QZ Tray on the main PC (ESC/POS `ESC p 0 25 250`). Leave *Cash drawer connected* **unticked** on printer #0 until then, so no kick is attempted.
- The "cash drawer did not open" alert after a settlement is **commented out** in `resources/js/pos/live-billing.js` (`openDrawer()`). Put that line back (and run `npm run build`) when the printer-driven drawer is installed.
- Test every printer from Administration → Printers → *Test print* during installation.

---

## 8. Network

### 8.1 IP plan (example: change it to fit the router)

| Device | IP | How |
|---|---|---|
| Router / gateway | 192.168.10.1 | — |
| **Shop server** | **192.168.10.10** | Static (netplan) |
| Main cashier PC | 192.168.10.21 | DHCP reservation |
| Counter 1 / 2 / 3 | 192.168.10.31 / .32 / .33 | DHCP reservation |
| Office laptop, owner's phone on Wi-Fi | DHCP pool .100–.200 | — |

### 8.2 Name resolution: `pos.citizens.local`

Every device must resolve `pos.citizens.local` → `192.168.10.10`. Options, best first:

1. **Router local DNS entry** (if the router supports it), so it works for every device including phones on Wi-Fi.
2. Otherwise add a line to the **hosts file** on each Windows PC (`C:\Windows\System32\drivers\etc\hosts`):
   ```
   192.168.10.10   pos.citizens.local
   ```
3. Remote access uses the Tailscale MagicDNS name ([§ 11](#11-remote-access-for-the-owner)).

### 8.3 Rules

- **All terminals wired.** Wi-Fi only for back-office laptops and phones.
- Guest/customer Wi-Fi (if any) on a separate network that cannot reach `192.168.10.0/24`.
- **No port forwarding** on the router.
- `APP_URL=https://pos.citizens.local`.

---

## 9. Power (UPS)

| UPS | Powers | Size | Runtime target |
|---|---|---|---|
| **UPS A** | Server, switch, router | 1000–1500 VA line-interactive, USB/HID | ≥ 20 min |
| **UPS B** | Main cashier PC, monitor, printer #0 | 650–850 VA | ≥ 10 min (finish settling, close the drawer) |
| UPS C (optional) | Counter PCs | 650 VA each, or one shared | ≥ 5 min |

**Clean shutdown:** connect UPS A's USB cable to the server and configure NUT/apcupsd to shut the server down when **battery < 30 %** or after **10 min on battery**. With BIOS *Power On after AC loss*, the server starts again by itself. On boot, systemd/Supervisor start every service in order, and counters get their carts back from Redis.

Test it before go-live by pulling the UPS plug (Go-live checklist).

---

## 10. HTTPS inside the shop

HTTPS is **required**, not optional: the webcam (clock-in photo), secure cookies and (later) QZ Tray all need a trusted `https://` page.

**Recommended: a small local certificate authority with `mkcert`:**

1. On the server: `mkcert -install && mkcert pos.citizens.local 192.168.10.10`, which creates `pos.citizens.local+1.pem` and `-key.pem`.
2. Put them in `/etc/ssl/citizens/` and point Nginx at them ([§ 14.2](#142-nginx-site)).
3. Copy `rootCA.pem` (from `mkcert -CAROOT`) to every terminal PC and install it under *Trusted Root Certification Authorities*. On the owner's phone, install it as a trusted profile/certificate.
4. **Keep `rootCA-key.pem` secret** (store it with the backups, encrypted). Anyone with it can create certificates that the shop PCs trust.
5. The certificate lasts about 2 years; renew it before it expires (add a calendar reminder).

---

## 11. Remote access for the owner

- **Tailscale** on the server and the owner's phone (and the developer's laptop for support). Free plan is enough.
- Turn on **MagicDNS**; the owner opens `https://pos.<tailnet>.ts.net` (Tailscale can issue a real certificate with `tailscale cert`), or the local name over the tailnet.
- Add the Tailscale host name to the Nginx `server_name`. `APP_URL` stays the local name; signed links (PO PDFs) use `APP_URL`.
- **No router port forwarding, no public IP, no cloud server.**
- SSH for support only over Tailscale, with **key-based login only** (password login disabled).
- Remove a lost phone from the Tailscale admin console straight away.

---

## 12. Backups

**For now (owner's decision, 2026-10-09): backups go only to the backup hard disk in the server.** The owner will buy cloud storage later; adding it is a configuration change, not a code change ([§ 12.3](#123-later-adding-cloud-storage)).

Set up in the code with `spatie/laravel-backup`: `config/backup.php`, the `backup` disk in `config/filesystems.php`, the schedule in `routes/console.php`, and the test `tests/Feature/System/BackupScheduleTest.php`.

### 12.1 What runs when

| Schedule | Command | Contains |
|---|---|---|
| **Every hour 08:00–20:00** | `backup:run --only-db` | The whole database (`mysqldump --single-transaction`: no table locks, the shop keeps selling) |
| **Every night 22:00** | `backup:run` | Database **+** `storage/app/private` and `storage/app/public` (attendance photos, uploads) **+** `.env` |
| **01:00** | `backup:clean` | Deletes old backups by the rules below |
| **07:20** | `backup:monitor` | Checks that the newest backup is less than 1 day old and the folder is under the size limit; reports a problem if not |

- Every archive is a **zip encrypted with AES-256** (`BACKUP_ARCHIVE_PASSWORD`) and checked after it is written.
- Files land in `/mnt/backup/citizens/citizens-pos/`, one zip per backup (e.g. `2026-10-09-22-00-00.zip`).
- Not included (temporary or rebuildable): report exports, import previews, `vendor/`, `node_modules/`, `public/build`, the Meilisearch index (`php artisan scout:import …`), Redis (live carts last minutes).
- Problems (failed backup, failed cleanup, unhealthy backup) are reported through Laravel mail. With `MAIL_MAILER=log` they are written to `storage/logs/laravel-*.log`. Set a real mailer and `BACKUP_NOTIFY_EMAIL` to get an e-mail instead. Successful backups send nothing.

**Kept (cleanup rules):**

| Age | Kept |
|---|---|
| Last 2 days | **Every** backup (all hourly + nightly) |
| Up to 14 days | One per day (the 22:00 full backup, because it is the day's last) |
| Up to 8 weeks | One per week |
| Up to 12 months | One per month |
| Up to 3 years | One per year |
| Any time | Oldest deleted first if the folder grows past `BACKUP_MAX_MEGABYTES` (default 50 GB) |

### 12.2 Server setup

1. Fit the backup HDD, format it ext4, and mount it by UUID at `/mnt/backup` (`/etc/fstab`: `UUID=… /mnt/backup ext4 defaults,nofail 0 2`).
2. `sudo mkdir -p /mnt/backup/citizens && sudo chown www-data:www-data /mnt/backup/citizens && sudo chmod 700 /mnt/backup/citizens`
3. In `.env`:
   ```dotenv
   BACKUP_PATH=/mnt/backup/citizens
   BACKUP_DISKS=backup
   BACKUP_ARCHIVE_PASSWORD=<long random password, written down for the owner>
   BACKUP_MAX_MEGABYTES=50000
   BACKUP_NOTIFY_EMAIL=
   DB_DUMP_BINARY_PATH=            # empty on Ubuntu (mysqldump is on the PATH)
   ```
4. `php artisan config:cache`, then run one backup by hand as `www-data` and check it: `sudo -u www-data php artisan backup:run`, then `php artisan backup:list` (Healthy ✅).
5. The scheduler cron ([§ 14.6](#146-scheduler-and-backups)) does the rest.

On a Windows test machine set `DB_DUMP_BINARY_PATH` to the MySQL `bin` folder, e.g. `C:/Program Files/MySQL/MySQL Server 8.0/bin`.

### 12.3 Later: adding cloud storage

When the owner buys cloud space (Google Drive, S3, Backblaze B2, …):

1. Install the Flysystem adapter for that service and add a disk named `cloud` in `config/filesystems.php`.
2. `.env`: `BACKUP_DISKS=backup,cloud`.
3. `php artisan config:cache && php artisan backup:run && php artisan backup:list`; both disks should show Healthy.

Backups then go to both places. `continue_on_failure` is on, so an internet outage never stops the local backup. Cleanup and monitoring cover both disks automatically.

### 12.4 Restore (test it monthly)

1. Copy the newest zip from `/mnt/backup/citizens/citizens-pos/` to the test machine.
2. Unzip with the archive password (7-Zip on Windows opens AES-256 zips).
3. Load `db-dumps/mysql-citizensDB.sql` into an **empty** database: `mysql -u root -p citizensDB < mysql-citizensDB.sql`.
4. Copy `storage/app/private` and the `.env` back, then `php artisan config:cache` and `php artisan scout:import "App\Domain\Catalog\Models\Product"`.
5. Sign in, open a few recent invoices and the drawer report, then note the test in the task log.

### 12.5 Know the limits

Until cloud backups are added, the backups are **in the same building as the server**. A fire, flood or theft can take both. Until then:

- the backup disk must be a **different disk** from the system disk, so a disk failure never loses both;
- once a month, copy the newest nightly zip to a USB stick that the owner takes home (it is encrypted, so it is safe to carry);
- **store separately, offline:** the backup archive password, `.env` (has `APP_KEY`, which decrypts sessions and terminal device cookies), and the mkcert root CA key. Keep them in a sealed envelope or a password manager the owner controls. **Without the archive password the backups cannot be opened.**

---

## 13. Security hardening

| Area | Setting |
|---|---|
| Laravel | `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, config/route/view caches (audit **H1**) |
| Build | No `public/hot` on the server (audit **H2**) |
| OS | UFW: allow 443/80 from `192.168.10.0/24` and Tailscale; SSH only via Tailscale; unattended security upgrades |
| Services | MySQL, Redis, Meilisearch, Reverb bound to `127.0.0.1`; Redis `requirepass`; Meilisearch `--master-key`; MySQL app user with rights **only** on `citizensDB` |
| Files | `/var/www/citizens` owned by `deploy:www-data`; only `storage/` and `bootstrap/cache/` writable; `.env` mode `640` |
| Reverb | `allowed_origins` = `pos.citizens.local` + Tailscale name (audit **L8**) |
| Accounts | Super Admin with 2FA on; strong passwords; 6-digit PINs for owner and manager (audit **M1/M2**) |
| Physical | Server in a locked cabinet; BIOS password; USB boot disabled |

---

## 14. Configuration files

### 14.1 Production `.env` (key lines)

```dotenv
APP_NAME="CITIZENS Agro POS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://pos.citizens.local
APP_TIMEZONE=Asia/Colombo
LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=citizensDB
DB_USERNAME=citizens
DB_PASSWORD=<strong random>

SESSION_DRIVER=redis
SESSION_LIFETIME=720
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<strong random>

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=<id>
REVERB_APP_KEY=<key>
REVERB_APP_SECRET=<secret>
REVERB_HOST=pos.citizens.local
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=<master key>

LARAVEL_PDF_DRIVER=chrome
POS_DEVICE_COOKIE=citizens_terminal
MAIL_MAILER=log
```

> `VITE_*` values are baked into the JS at `npm run build` time. Rebuild after changing them.

### 14.2 Nginx site

`/etc/nginx/sites-available/citizens`:

```nginx
server {
    listen 80;
    server_name pos.citizens.local;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name pos.citizens.local;            # + Tailscale name if used
    root /var/www/citizens/current/public;
    index index.php;

    ssl_certificate     /etc/ssl/citizens/pos.citizens.local+1.pem;
    ssl_certificate_key /etc/ssl/citizens/pos.citizens.local+1-key.pem;

    client_max_body_size 12M;                  # product/Excel imports (10 MB limit in the app)
    add_header X-Frame-Options SAMEORIGIN;     # print pages load in a same-origin iframe
    add_header X-Content-Type-Options nosniff;
    add_header Referrer-Policy strict-origin-when-cross-origin;

    # Laravel Reverb (WebSockets)
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 120s;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 120s;             # PDF generation
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

### 14.3 PHP (`/etc/php/8.4/fpm/conf.d/99-citizens.ini`)

```ini
memory_limit = 512M
upload_max_filesize = 12M
post_max_size = 12M
max_execution_time = 120
date.timezone = Asia/Colombo
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0   ; reload php-fpm after each deploy
expose_php = Off
```

PHP-FPM pool: `pm = dynamic`, `pm.max_children = 12`, `pm.start_servers = 4`.

### 14.4 MySQL (`/etc/mysql/mysql.conf.d/zz-citizens.cnf`)

```ini
[mysqld]
bind-address = 127.0.0.1
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
default-time-zone = '+05:30'
innodb_buffer_pool_size = 4G
innodb_flush_log_at_trx_commit = 1   # never lose a committed sale
sync_binlog = 1
innodb_file_per_table = 1
max_connections = 100
ngram_token_size = 2                 # MySQL FULLTEXT search fallback
```

### 14.5 Supervisor (`/etc/supervisor/conf.d/citizens.conf`)

```ini
[program:citizens-queue]
command=php /var/www/citizens/current/artisan queue:work redis --tries=3 --timeout=300 --sleep=1
numprocs=2
process_name=%(program_name)s_%(process_num)02d
user=www-data
autostart=true
autorestart=true
stopwaitsecs=310
stdout_logfile=/var/www/citizens/shared/storage/logs/queue.log

[program:citizens-reverb]
command=php /var/www/citizens/current/artisan reverb:start --host=127.0.0.1 --port=8080
user=www-data
autostart=true
autorestart=true
stdout_logfile=/var/www/citizens/shared/storage/logs/reverb.log
```

### 14.6 Scheduler and backups

Cron for `www-data` (`crontab -u www-data -e`):

```cron
* * * * * cd /var/www/citizens/current && php artisan schedule:run >> /dev/null 2>&1
```

That one cron line runs every scheduled job, **including the backups**, which are already in `routes/console.php`:

```php
Schedule::command('backup:run --only-db')->hourly()->between('08:00', '20:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('22:00')->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:monitor')->dailyAt('07:20');
```

Check it with `php artisan schedule:list`.

### 14.7 Meilisearch (`/etc/systemd/system/meilisearch.service`)

```ini
[Unit]
Description=Meilisearch
After=network.target

[Service]
User=meilisearch
ExecStart=/usr/local/bin/meilisearch --env production --http-addr 127.0.0.1:7700 \
  --master-key ${MEILI_MASTER_KEY} --db-path /var/lib/meilisearch/data --no-analytics
EnvironmentFile=/etc/meilisearch.env
Restart=always

[Install]
WantedBy=multi-user.target
```

---

## 15. First install, step by step

**A. Server**
1. Install Ubuntu Server 24.04 LTS (OpenSSH on). Set the hostname, static IP, timezone `Asia/Colombo`, NTP.
2. BIOS: power on after AC loss, BIOS password, USB boot off.
3. `apt install nginx mysql-server redis-server supervisor ufw git unzip nut` + PHP 8.4 (ondrej/php PPA) with the extensions in [§ 5](#5-server-software-stack), Composer, Node 20, Google Chrome stable, Meilisearch binary, Tailscale.
4. Apply the config files in [§ 14](#14-configuration-files). Create the MySQL database `citizensDB` and user `citizens`.
5. Create certificates with mkcert ([§ 10](#10-https-inside-the-shop)).
6. Folder layout: `/var/www/citizens/{releases,shared,current}`; `shared/` holds `.env` and `storage/`.
7. First deploy ([§ 16](#16-updating-the-app-deploy-script)), then:
   ```bash
   php artisan key:generate          # ONLY on a brand-new install
   php artisan migrate --force --seed
   php artisan storage:link
   php artisan pos:create-owner      # first Super Admin
   php artisan scout:sync-index-settings && php artisan scout:import "App\Domain\Catalog\Models\Product"
   ```
8. Start everything: `systemctl enable --now nginx php8.4-fpm mysql redis-server meilisearch supervisor nut-monitor`, then `supervisorctl reread && supervisorctl update`.
9. UFW: `ufw allow from 192.168.10.0/24 to any port 80,443 proto tcp`, `ufw allow in on tailscale0`, `ufw enable`.
10. Configure the UPS shutdown and **test it**.
11. Configure backups and **run one restore test**.

**B. Terminals** ([§ 6.2](#62-software-and-settings-on-every-terminal))
1. Chrome, printer driver (default printer, 80 mm, margin 0), CA certificate, hosts entry if needed.
2. Sign in as Super Admin on each PC → Administration → Terminals → **Register this device** → Printers → **Test print**.
3. Main cashier: manual cash drawer for now, so nothing to install. Later, with a printer-driven drawer: QZ Tray → open the drawer once from a test settlement.
4. Put the kiosk shortcut in *Startup* and reboot each PC to check it comes up on its own.

**C. Go-live**: work through [IMPLEMENTATION_PLAN.md § 17](IMPLEMENTATION_PLAN.md#17-go-live-checklist) and **Administration → Go-live** in the app.

---

## 16. Updating the app (deploy script)

Zero-downtime release folders, run **after shop hours** (or when no invoice is waiting for settlement):

```bash
#!/bin/bash
set -euo pipefail
APP=/var/www/citizens
REL=$APP/releases/$(date +%Y%m%d%H%M%S)

git clone --depth 1 <repo-url> "$REL"
ln -s $APP/shared/.env "$REL/.env"
rm -rf "$REL/storage" && ln -s $APP/shared/storage "$REL/storage"
rm -f "$REL/public/hot"                                  # audit H2

cd "$REL"
composer install --no-dev --optimize-autoloader --no-interaction
npm ci && npm run build && rm -rf node_modules

php artisan backup:run --only-db                         # backup right before migrating
php artisan migrate --force
php artisan optimize                                     # config, routes, views, events
ln -sfn "$REL" $APP/current

sudo systemctl reload php8.4-fpm
php artisan queue:restart
sudo supervisorctl restart citizens-reverb
php artisan scout:sync-index-settings

ls -1dt $APP/releases/* | tail -n +6 | xargs rm -rf      # keep 5 releases
```

**Rollback:** point `current` back at the previous release (`ln -sfn …`), reload PHP-FPM, restart queue and Reverb. If a migration must be undone, restore the dump taken just before the deploy.

---

## 17. Monitoring and daily checks

| Check | How | Who |
|---|---|---|
| App is up | `https://pos.citizens.local/up` returns 200 (Tailscale phone bookmark) | Owner, any time |
| Backups ran | `php artisan backup:list` shows Healthy, or a zip dated today in `/mnt/backup/citizens`; no backup errors in the log | Owner / developer, every morning |
| Disk space | Simple cron: e-mail or Telegram alert above 80 % | Automatic |
| Errors | `storage/logs/laravel-*.log`, `queue.log`, `reverb.log` | Developer, weekly or on complaint |
| Printers | "Check printer" warnings on the POS; Administration → Print jobs | Staff / owner |
| Terminals online | Live Billing shows online/offline dots | Owner |
| Day close | Drawer closed with no invoices left INVOICED; Z report printed | Owner, nightly |
| Restore test | Restore the latest backup on another machine | Developer, monthly |

---

## 18. When things go wrong (runbook)

| Problem | What happens | What to do |
|---|---|---|
| **Internet down** | Nothing for sales or backups (backups are local). Remote viewing pauses. | Nothing. |
| **Power cut** | UPS keeps the server, network and main cashier running; counters may go off. | Finish settling. If the power stays off, the server shuts itself down cleanly. When power returns everything starts by itself and carts come back. |
| **A counter PC dies** | That counter cannot bill. | Use another PC: Chrome + printer driver + certificate, then Super Admin registers it as that counter. |
| **A printer fails / out of paper** | "Check printer" warning; invoice not printed. | Load paper or swap in the spare printer; reprint (F10). |
| **Live Billing not updating** | Reverb down; counters fall back to polling. | `sudo supervisorctl restart citizens-reverb`. |
| **Search slow / no results** | Meilisearch down; MySQL fallback search is used. | `sudo systemctl restart meilisearch`; if needed `php artisan scout:import "App\Domain\Catalog\Models\Product"`. |
| **Cash drawer does not open** | Manual drawer for now: not an app issue. Later (printer-driven): QZ Tray not running. | Later: start QZ Tray; check the RJ11 cable; open with the drawer key meanwhile. |
| **Wrong time on invoices** | Server clock off. | `timedatectl` → re-enable NTP. |
| **Server dead** | Shop cannot sell. | Write bills by hand. Install Ubuntu on the spare/new mini PC from this document, restore the newest zip from the backup HDD ([§ 12.4](#124-restore-test-it-monthly)), or from the owner's monthly USB copy if the backup disk is lost too, copy `.env` (same `APP_KEY`, so terminals stay registered), then check the last invoice numbers against the paper bills. |
| **Disk full** | Writes fail. | Clear old logs; check that `backup:clean` and log rotation run and that `BACKUP_PATH` really points at the backup HDD, not the system disk. |
