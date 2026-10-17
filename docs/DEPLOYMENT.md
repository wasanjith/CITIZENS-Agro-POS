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
| **One server, simple to look after** | No cluster, no Docker in production. One self-built desktop PC with standard Ubuntu services that a local technician can understand. |
| **The server is also the main cashier** | The server runs Ubuntu Desktop with a 24" monitor and printer #0, and the cashier works on it in a locked-down Chrome kiosk. **4 computers in total:** server/main cashier + 3 counter PCs (owner's decision, 2026-10-10). |
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
 │  ┌─────────────────────────────┴────┐ │     │     │                                        │
 │  │ SHOP SERVER = MAIN CASHIER .10   │ │     │     │                                        │
 │  │ pos.citizens.local               │ │     │     │                                        │
 │  │ Ubuntu Desktop 24.04 LTS         │ │     │     │                                        │
 │  │  Nginx :443 ── PHP-FPM           │ │     │     │                                        │
 │  │        └── /app → Reverb :8080   │ │     │     │                                        │
 │  │  MySQL 8.4 · Redis 7             │ │     │     │                                        │
 │  │  Meilisearch · Chromium (PDF)    │ │     │     │                                        │
 │  │  Queue workers · Scheduler       │ │     │     │                                        │
 │  │  Tailscale · UPS daemon          │ │     │     │                                        │
 │  │  + backup hard disk              │ │     │     │                                        │
 │  │ ── cashier side ──               │ │     │     │                                        │
 │  │  24" monitor · keyboard · mouse  │ │     │     │                                        │
 │  │  Chrome kiosk /pos/cashier       │ │     │     │                                        │
 │  │  (user "cashier", auto sign-in)  │ │     │     │                                        │
 │  │  USB printer #0 via CUPS         │ │     │     │                                        │
 │  │  manual cash drawer for now      │ │     │     │                                        │
 │  │  QZ Tray later · webcam (opt.)   │ │     │     │                                        │
 │  └──────────────────────────────────┘ │     │     │                                        │
 │                                       │     │     │                                        │
 │  COUNTER 1 PC  .31 ───────────────────┘     │     │                                        │
 │   Chrome kiosk /pos · USB printer #1        │     │                                        │
 │  COUNTER 2 PC  .32 ─────────────────────────┘     │                                        │
 │   Chrome kiosk /pos · USB printer #2              │                                        │
 │  COUNTER 3 PC  .33 ───────────────────────────────┘                                        │
 │   Chrome kiosk /pos · USB printer #3                                                        │
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
| Shop server **+ main cashier** | The whole application and all data; **plus** the cashier's Chrome kiosk on its own screen, and printer #0 (+ QZ Tray once a printer-driven drawer is fitted) | Everyone on the LAN over HTTPS (443) and WebSocket (wss via 443); its own Chrome talks to itself the same way |
| Counter PCs ×3 | Chrome only | Server; their own USB printer |
| Owner's phone | Browser | Server through Tailscale |

The counter PCs keep **no data**. If one dies, plug in another PC, open Chrome, and have the Super Admin register it as that terminal (Administration → Terminals → Register this device).

The cashier's Chrome uses the **same** `https://pos.citizens.local` address as everyone else (not `localhost`), so the certificate, cookies and Reverb settings are identical on every terminal.

---

## 3. Shopping list (bill of materials)

Everything the shop needs to run the POS, grouped by type, with the capacity to ask for when buying. "Minimum" works; **Recommended** is what to buy if the price difference is small. Model names are examples of the right class: any known brand with the same numbers is fine. Ask every shop for a **Sri Lankan warranty** (at least 1 year, 3 years for PCs and UPS).

Mains power in Sri Lanka is **230 V, 50 Hz**, sockets **BS 1363 (square 3-pin, 13 A)** or the older round-pin type. Check that every power cable and UPS outlet matches the shop's sockets before buying.

### 3.1 Everything at a glance

| # | Item | Qty | Capacity to ask for | Details |
|---|---|---|---|---|
| 1 | **Shop server = main cashier** (self-built) | 1 | i5-12400 class, 16–32 GB RAM (2 sticks), 1 TB NVMe with DRAM cache, 2 TB HDD | [§ 4.1.1](#411-parts-list-self-built-server) |
| 2 | **Counter PCs** (business mini PC) | 3 | i3 / N100 class, 8–16 GB RAM, 256 GB NVMe, wired gigabit LAN | [§ 3.2](#32-computers) |
| 3 | **Monitors** | 4 | 1 × 24" + 3 × 21.5", 1920×1080 IPS, HDMI, VESA 100 | [§ 3.3](#33-screens-keyboards-and-mice) |
| 4 | Keyboard + mouse sets | 4 + 1 spare | Wired USB, full size with number pad, spill-resistant | [§ 3.3](#33-screens-keyboards-and-mice) |
| 5 | **80 mm thermal printers** | 4 + **1 spare** | 203 dpi, ≥ 250 mm/s, auto-cutter, USB, Windows **and** Linux driver, RJ11 drawer port | [§ 3.4](#34-printers-paper-and-cash-drawer) |
| 6 | Thermal paper rolls | 1 box of 50 to start | 80 mm × **80 m**, 55–60 gsm, BPA-free | [§ 3.4](#34-printers-paper-and-cash-drawer) |
| 7 | Cash drawer | — | **Keep the existing manual drawer** (about one more year) | [§ 3.4](#34-printers-paper-and-cash-drawer) |
| 8 | **Network switch** | 1 | 8 × gigabit ports, metal, fanless, "easy smart" (VLAN) | [§ 3.5](#35-network) |
| 9 | **Network cable** | ~100–150 m (measure) | **Cat6 U/UTP, 23 AWG solid pure copper** (no CCA) | [§ 3.5](#35-network) |
| 10 | Patch panel, wall jacks, patch cords | see § 3.5 | Cat6 rated | [§ 3.5](#35-network) |
| 11 | Wall network cabinet | 1 | 6U, 450 mm deep, glass door, lock | [§ 3.5](#35-network) |
| 12 | Router | 1 | Existing ISP router with DHCP reservation | [§ 3.5](#35-network) |
| 13 | **UPS A** (server, cashier, network) | 1 | **1500 VA / 900 W, pure sine wave**, line-interactive, AVR, USB | [§ 3.6](#36-power-ups-sockets-and-surge) |
| 14 | **UPS B** (one per counter) | 3 | 650–850 VA / 360–480 W, line-interactive, AVR | [§ 3.6](#36-power-ups-sockets-and-surge) |
| 15 | Electrical work | — | Earth check, surge protector at the DB, sockets at each counter | [§ 3.6](#36-power-ups-sockets-and-surge) |
| 16 | **Under-counter server compartment** | 1 | Ventilated, lockable, raised off the floor | [§ 3.7](#37-furniture-and-installation-materials) |
| 17 | Monitor mounts / cable trunking / labels | — | VESA 100 | [§ 3.7](#37-furniture-and-installation-materials) |
| 18 | Spares and tools | — | — | [§ 3.8](#38-spares-and-tools) |
| 19 | Webcam (optional) | 0–4 | 720p USB | Only if "clock-in photo" is switched on (Settings → HR) |

**What sits where**

| Place | Equipment |
|---|---|
| **Cashier counter** | Server (in the compartment) · 24" monitor · keyboard + mouse · printer #0 · manual cash drawer · UPS A |
| Wall above / next to the cashier counter | Network cabinet: patch panel, switch, ISP router, power strip fed from UPS A |
| **Counter 1, 2, 3** (each) | Mini PC (on the back of the monitor) · 21.5" monitor · keyboard + mouse · printer #1/#2/#3 · UPS B · network wall jack |
| Store room shelf | Spare printer, spare keyboard + mouse, paper stock, spare patch cords |

### 3.2 Computers

**Shop server = main cashier (×1):** the full parts list and BIOS settings are in [§ 4.1.1](#411-parts-list-self-built-server). In short: Intel Core i5-12400/13400/14400 (built-in graphics, not an "F" model), tower cooler, B660/B760 micro-ATX board, **2 × 8 GB or 2 × 16 GB** RAM, **1 TB NVMe with DRAM cache**, **2 TB CMR HDD** for backups, **550 W 80 Plus Bronze/Gold** PSU, micro-ATX case with dust filters and 3 fans, no graphics card.

**Counter PCs (×3):** business mini PCs, fixed to the back of the monitor. They only run Chrome, so they do not need much, but they run all day in a hot, dusty shop.

| Part | Minimum | **Recommended** |
|---|---|---|
| Type | Mini PC | **Business mini PC**: Lenovo ThinkCentre M70q / M75q Tiny, HP Pro Mini 400, Dell OptiPlex Micro |
| CPU | Intel N100 (4 cores) | **Intel Core i3-1215U / i3-12100T / i5-12500T**, or AMD Ryzen 3/5 PRO |
| RAM | 8 GB | **16 GB** (2 × 8 GB where the PC has two slots) |
| Disk | 256 GB SSD | **256–512 GB NVMe SSD** |
| Network | **Wired gigabit RJ45** (Intel or Realtek) | Same. Wi-Fi is not used at the counters |
| Ports | 4 × USB, 1 × HDMI | 6 × USB (printer, keyboard, mouse, webcam, spare), HDMI or DisplayPort |
| Power | External adapter, 65 W | Same (uses 15–35 W) |
| Mounting | VESA 100 bracket | Bracket included with the PC |
| OS | **Windows 11 Pro**, genuine licence | Same |
| Warranty | 1 year | 3 years on-site |

Avoid: no-name mini PCs without a local warranty, models with only Wi-Fi (no RJ45), and Windows 11 Home (no proper user lock-down).

**Back-office:** the owner's / manager's existing laptop and phone are enough (browser only, Wi-Fi or Tailscale). Nothing to buy.

### 3.3 Screens, keyboards and mice

| Item | Qty | Capacity | Examples |
|---|---|---|---|
| **24" monitor** (cashier, on the server) | 1 | **1920×1080 IPS**, 60–75 Hz, HDMI + DP, anti-glare, **VESA 100**, tilt stand, ~20 W | Dell P/SE24, HP 24, LG 24MP, Samsung 24" business |
| **21.5"–22" monitor** (counters) | 3 | 1920×1080 IPS, HDMI, anti-glare, VESA 100, ~15 W | Same brands, 22" models |
| HDMI cable | 4 | HDMI 1.4 or newer, 1.5 m | If not in the monitor box |
| **Keyboard** | 4 + 1 spare | **Wired USB**, full size with **F-keys and number pad** (staff use F2/F4/F6/F8/F9 and type quantities), spill-resistant | Logitech K120, Dell KB216, HP 125 |
| Mouse | 4 + 1 spare | Wired USB optical | Logitech B100 / M90 |
| Keyboard cover (optional) | 4 | Clear silicone cover for the model bought | Keeps out fertiliser and chemical dust |

No wireless keyboards or mice: batteries run out and they interfere with each other at close counters.

### 3.4 Printers, paper and cash drawer

**80 mm thermal receipt printers: 4 in use + 1 spare, all the same model.**

| Capacity | Minimum | **Recommended** |
|---|---|---|
| Print method / resolution | Direct thermal, **203 dpi** | Same |
| Paper width | **80 mm** (72 mm print width) | Same |
| Print speed | 200 mm/s | **250–300 mm/s** |
| Auto-cutter | Partial cut, 1 million cuts | **Partial cut, ≥ 1.5 million cuts** |
| Print head life | 100 km | **150 km** |
| Roll size | Up to Ø 80 mm | **Up to Ø 83 mm** (takes 80 m rolls) |
| Interface | **USB** | USB + Ethernet (Ethernet not used now, useful later) |
| Drivers | **Windows 10/11 and Linux (CUPS) for Ubuntu 24.04** | Maker's own drivers for both |
| Cash drawer port | **RJ11/RJ12, 24 V** | Same (for the drawer later) |
| Power | 24 V DC adapter (~2.5 A) | Adapter included, with a UK-plug cable |

Examples to test first: **Epson TM-T82III / TM-T20III** (Linux CUPS driver from Epson), **Bixolon SRP-330III / SRP-350V**. Cheaper Xprinter / Rongta models also work on Windows, but check their Linux driver carefully. **Buy one, run the Sinhala print test on Windows and on Ubuntu ([§ 7](#7-printers-and-cash-drawer), [PRINTING.md](PRINTING.md)), and only then buy the other four.**

**Paper:**

| Item | Capacity | How much |
|---|---|---|
| Thermal rolls | **80 mm × 80 m**, core 12 mm, 55–60 gsm, BPA-free, from one supplier | Estimate: ~300 invoices × ~20 cm + cashier receipts ≈ **one 80 m roll a day for the whole shop**, about 30 a month. Start with **one box of 50**, keep at least one month in stock |

Many rolls sold as "80 mm" are only 50 m or 70 m long. Check the length on the box: a longer roll means fewer changes during busy hours.

**Cash drawer:** keep the **existing manual drawer** for about one more year (owner, 2026-10-09). When it is replaced: printer-driven, **RJ11/RJ12, 24 V**, 5 note / 8 coin slots, metal, 3-position key lock, about 410 mm wide, plugged into printer #0 ([§ 7](#7-printers-and-cash-drawer)).

### 3.5 Network

**How it is wired:** every PC has its own cable to the switch. The switch, ISP router and patch panel sit in one small wall cabinet near the cashier counter, so UPS A can power all of them. Counters are wired with solid Cat6 cable in trunking to a wall jack under each counter; short patch cords connect the PC to the jack and the patch panel to the switch.

```
Counter 1 PC ─2 m cord─ wall jack ═══ Cat6 solid in trunking ═══ patch panel ─0.5 m─┐
Counter 2 PC ─2 m cord─ wall jack ═══════════════════════════════ patch panel ─0.5 m─┤
Counter 3 PC ─2 m cord─ wall jack ═══════════════════════════════ patch panel ─0.5 m─┼─ SWITCH ─0.5 m─ ISP router
Server (under the cashier counter) ────── 2–3 m Cat6 patch cord ────────────────────┘
```

**Switch (×1)**

| Capacity | Minimum | **Recommended** |
|---|---|---|
| Ports | 8 × 10/100/1000 Mbps RJ45 | **8 × gigabit** (5 used: server, 3 counters, router; 3 spare) |
| Switching capacity | 16 Gbps | 16 Gbps, 11.9 Mpps |
| Type | Unmanaged | **"Easy smart" / web-managed**: VLANs (to keep guest Wi-Fi apart), port statistics and cable test (finds a bad cable), loop prevention |
| Build | Metal case, fanless | Same, wall-mountable |
| Power | ~5 W | Same, on UPS A |
| Examples | TP-Link TL-SG108, D-Link DGS-108 | **TP-Link TL-SG108E**, Netgear GS308E, D-Link DGS-1100-08V2 |

Gigabit is far more than the POS needs (each terminal uses well under 1 Mbps), so 2.5 GbE or 10 GbE switches only add cost. **PoE is not needed**, unless a ceiling Wi-Fi access point is added later: then buy the PoE version (TL-SG108PE: 4 PoE ports, 64 W). If CCTV, more counters or other devices are planned, buy a **16-port** switch now.

**Cable and connectors**

| Item | Qty | Capacity |
|---|---|---|
| **Cat6 cable, solid** (for the runs in trunking) | Measure every route, add 20 % + 3 m at each end. A small shop is usually **100–150 m**; buy a 305 m box only if more than ~150 m is needed | **Cat6 U/UTP, 23 AWG solid bare copper**, 250 MHz, PVC or **LSZH** jacket. 1 Gbps up to **90 m** per run (+ 10 m patch cords). Brands: CommScope (AMP), Panduit, Legrand, D-Link, Dintek |
| Spare runs | **2 cables to each counter** | Pull a second cable while the trunking is open. Cable is cheap; pulling it again later is not |
| Wall faceplate + Cat6 keystone jacks | 3 faceplates (dual), 6 jacks | Cat6 rated, 1 faceplate per counter |
| **Cat6 patch panel** | 1 | 12-port, 1U, Cat6 (keystone or punch-down) |
| Cat6 patch cords, factory-made | 6 × 0.5 m, 4 × 2 m, 1 × 3 m (server), + 2 spare × 2 m | **Moulded, stranded, snagless boots**, from the same brand as the cable. Do not hand-crimp cords |
| PVC trunking / conduit | As measured | 25 × 16 mm or larger, with bends and screws. Data cable in its **own** trunking, at least **30 cm away from power cables** where they run side by side |
| Velcro straps, cable labels | 1 pack each | Label both ends of every cable: `C1-A`, `C1-B`, `C2-A` … |

**Do not buy CCA cable** ("copper-clad aluminium", often sold cheap as "Cat6"). Scrape a wire: CCA is silver under the copper colour. It breaks easily, gets hot and causes random drop-outs. Cat6A, shielded (F/UTP) or fibre cable is not needed inside a shop.

Any cable that goes **outside** or between buildings: outdoor (PE jacket, UV-proof) Cat6 inside conduit. Better still, avoid outdoor runs.

**Network cabinet (×1)**

| Item | Capacity |
|---|---|
| Wall-mount cabinet | **6U, 600 mm wide × 450 mm deep**, glass front door with lock, side vents (fan optional) |
| 1U shelf | For the ISP router |
| 1U cable manager | Optional, keeps patch cords tidy |
| Power strip (PDU) | 4–6 sockets, fed from **one battery-backed outlet of UPS A** |

A cabinet keeps the switch and router away from dust, water and staff. On a tight budget, a high wall shelf is acceptable.

**Router and Wi-Fi**

| Item | Capacity |
|---|---|
| **ISP router** (existing SLT fibre / 4G router) | **DHCP reservation** (or static IPs), local DNS entry if possible ([§ 8.2](#82-name-resolution-poscitizenslocal)), at least 1 free LAN port, guest Wi-Fi that cannot reach the shop LAN, **no port forwarding**. On UPS A |
| Wi-Fi access point (optional) | Only if the router's Wi-Fi does not reach the office: ceiling Wi-Fi 6 access point (TP-Link Omada EAP610 / EAP225 class), PoE, with a separate guest network. Used by laptops and phones only, **never by terminals** |
| Internet speed | Any working connection. The POS sells with **no internet at all**; the internet is only for the owner's remote view and Tailscale |

### 3.6 Power: UPS, sockets and surge

**UPS A: server, cashier screen, printer #0, switch, router (×1)**

| Capacity | Minimum | **Recommended** |
|---|---|---|
| Rating | 1500 VA / 865–900 W | **1500 VA / 900 W** |
| Type | Line-interactive with **AVR** (fixes low/high voltage without using the battery) | Same |
| Output wave | Simulated sine | **Pure sine wave**. Modern "80 Plus" PSUs with active PFC can switch off on a simulated-sine UPS at the moment the power fails |
| Shutdown port | **USB (HID)** that works with NUT on Linux | Same + LCD showing load and battery |
| Battery-backed outlets | 4 | 6 or more (server, monitor, printer #0, cabinet power strip) |
| Battery | 2 × 12 V 9 Ah, **user-replaceable** | Same. **Replace every 2–3 years** (heat shortens battery life) |
| Examples | APC Back-UPS BX1500MI, CyberPower UT1500EG | **APC Back-UPS Pro BR1500MI**, **CyberPower CP1500EPFCLCD** (PFC Sinewave) |

**UPS A load:**

| Device | Normal | Peak |
|---|---|---|
| Server (i5, SSD, HDD) | 60–80 W | 150 W |
| 24" monitor | 20 W | 25 W |
| Printer #0 | 5 W | 60 W (while printing) |
| Switch | 5 W | 5 W |
| ISP router | 10 W | 15 W |
| **Total** | **≈ 110 W (12 % of 900 W)** | **≈ 255 W (28 %)** |

At that load a 1500 VA UPS runs **about 30–45 minutes** (about 20 minutes at peak). The server is set to shut down after 10 minutes on battery or at 30 % battery ([§ 9](#9-power-ups)), so there is plenty of margin even when the battery is older.

**UPS B: one per counter (×3)**

| Capacity | Minimum | **Recommended** |
|---|---|---|
| Rating | 650 VA / 360 W | **750–850 VA / 410–480 W** |
| Type | Line-interactive with AVR | Same, simulated sine is fine (mini PCs use an external adapter) |
| Outlets | 3 battery-backed | 4 |
| Examples | APC BX650MI, CyberPower UT650EG | **APC BX750MI / BX850MI**, CyberPower VP700ELCD |
| Load per counter | Mini PC 15–35 W + 22" monitor 15 W + printer 5 W (60 W printing) ≈ **50 W normal, ~110 W peak** | Runs about **20–30 minutes**: enough to finish and print the bill in hand |

One small UPS per counter is cheaper to wire than one big shared UPS, and one failing does not stop the others.

**Electrical work (by an electrician, before the equipment arrives)**

| Item | Capacity |
|---|---|
| **Earth check** | Test the shop's earth; fix it if poor. UPS AVR, surge protection and printer static protection all rely on a good earth |
| **Surge protection device (SPD)** at the main distribution board | **Type 2 SPD** (230 V, 20–40 kA). Sri Lanka has frequent lightning; this protects everything, not only the POS |
| Cashier counter circuit | A **dedicated 13 A circuit** (own breaker) with 2 earthed sockets for UPS A, not shared with water pumps, motors, freezers or kettles |
| Each counter | 1 earthed 13 A double socket for UPS B |
| Wall cabinet | Fed from UPS A (no separate socket needed; a socket nearby is handy for tools) |

Do not plug UPS units into extension boards or into each other. Do not plug kettles, fans, phone chargers or heaters into any UPS.

### 3.7 Furniture and installation materials

| Item | Qty | Capacity |
|---|---|---|
| **Under-counter server compartment** | 1 | Holds the server + UPS A. **Ventilated** (vents or a fan at the back), **lockable** door, raised at least 10 cm off the floor, **10 cm free** behind and at the sides of the server. Away from water, agro-chemical dust and feet |
| Monitor mount / arm (optional) | 0–4 | VESA 100, if the counter top is small. The counter mini PCs mount behind the monitor with their own bracket |
| Printer place | 4 | Flat, dry spot next to each monitor, paper exit facing the staff, not under a water tap or fan |
| Cable trunking, clips, Velcro | As measured | See [§ 3.5](#35-network) |
| Cable labels | 1 pack | Label every power, USB and network cable at both ends |

### 3.8 Spares and tools

| Item | Qty | Why |
|---|---|---|
| **Spare thermal printer** (same model) | 1 | Swap in minutes ([§ 7](#7-printers-and-cash-drawer)) |
| Spare keyboard + mouse | 1 set | Most common thing to break at a counter |
| Spare Cat6 patch cords (2 m) | 2 | |
| Spare USB printer cable (A–B, 1.5 m) | 1 | |
| Spare HDMI cable | 1 | |
| Ubuntu install USB stick | 1 × 16 GB | Rebuilding the server ([§ 15](#15-first-install-step-by-step)) |
| Network cable tester | 1 | Installer: test every run after fitting the jacks. Can be borrowed |
| Punch-down tool | 1 | Installer, for keystone jacks / patch panel |

### 3.9 Not needed

A separate main cashier PC (the server is the cashier), a graphics card, barcode scanners (the shop uses short codes and search), a customer display, a cloud server, a NAS, 2.5/10 GbE network gear, Wi-Fi for terminals, or a generator for the POS (the UPS covers the shutdown; the shop's own generator, if any, should feed the UPS, not replace it).

---

## 4. Shop server

### 4.1 Hardware

| Part | Minimum | **Recommended** | Why |
|---|---|---|---|
| CPU | 4 cores (Intel i3 12th gen / Ryzen 3) | **6+ cores, Intel i5 12th gen+ / Ryzen 5 5600+** | PHP-FPM, MySQL, Meilisearch indexing and headless Chromium (PDFs) all run at once at peak hours |
| RAM | **16 GB** | **16 GB DDR4/DDR5** (32 GB if cheap) | See the memory budget below. **8 GB is not enough** now that the cashier's desktop and Chrome run here too |
| System disk | 256 GB NVMe | **512 GB NVMe SSD, business/TLC with DRAM cache** (Samsung, WD Red/Blue SN, Crucial) | Database and search index want fast random I/O; avoid cheap DRAM-less QLC disks |
| **Backup disk** | 1 TB HDD | **1–2 TB HDD** (internal bay, or USB 3 left connected) | Backups on a **separate disk** from the database, so one disk failure cannot take both. Fitted inside the case in a 3.5" bay |
| Network | 1 Gb Ethernet | **1 Gb Intel/Realtek Ethernet** | Wired only |
| USB | 4 × USB | **5+ USB** | UPS signal cable, printer #0, keyboard, mouse (+ backup disk if USB, + webcam) |
| Display | 1 × HDMI/DP | HDMI or DisplayPort, 1920×1080 | The cashier's 24" monitor; Intel/AMD integrated graphics (good Linux support; avoid NVIDIA-only boxes) |
| Power | — | **BIOS: "Restore on AC power loss = Power On"** | Boots by itself when the power comes back |
| Form factor | Micro-ATX mini tower | Micro-ATX case with dust filters and 3 fans | Sri Lankan heat; keep it out of direct sun. It sits under the cashier counter in a ventilated, lockable compartment |

### 4.1.1 Parts list (self-built server)

The owner is assembling the server from parts (2026-10-11). This list is sized so the server **and** the cashier screen never lag with 3 counters at peak hours. "Minimum" works; "Recommended" leaves room for years of growth.

| # | Part | Minimum | **Recommended** | Must have / avoid |
|---|---|---|---|---|
| 1 | **CPU** | Intel Core i3-12100 (4 cores) / AMD Ryzen 5 5600G | **Intel Core i5-12400 / i5-13400 / i5-14400** (6+ cores) or **AMD Ryzen 5 7600** | **Must have built-in graphics.** Avoid Intel **"F"** models (i5-12400**F** has no graphics) and AMD Ryzen 5600/5700 **without "G"** |
| 2 | **CPU cooler** | Stock cooler in the CPU box | **Tower cooler, 120 mm fan** (DeepCool AK400, ID-Cooling SE-214, Thermalright Assassin X 120 class) | Runs cooler and quieter in Sri Lankan heat, 24/7 |
| 3 | **Motherboard** | Micro-ATX **B660 / B760** (Intel) or **B650** (AMD 7600); **A520/B550** only for 5600G | Same, from ASUS / MSI / Gigabyte / ASRock | **HDMI or DisplayPort on the back panel**, 2 RAM slots or more, **2 × M.2** slot, 4 × SATA, **wired LAN** (Intel or Realtek 1 G/2.5 G), 6+ USB ports, BIOS option *Restore on AC power loss*. Wi-Fi not needed. Match DDR4 / DDR5 to the RAM you buy |
| 4 | **RAM** | **16 GB = 2 × 8 GB** DDR4-3200 or DDR5-4800 | **32 GB = 2 × 16 GB** DDR4-3200 / DDR5-5600 | **Always two sticks** (dual channel; built-in graphics need it). Same kit, Kingston / Corsair / Crucial / G.Skill. Never 8 GB |
| 5 | **System SSD** (OS, app, database, search) | **512 GB NVMe**, TLC, **with DRAM cache** | **1 TB NVMe** with DRAM cache: Samsung 990 Pro / 980 Pro, WD Black SN850X, Crucial T500, Kingston KC3000 | Avoid DRAM-less / QLC disks (cheap "Gen4" no-name SSDs): they slow MySQL down as they fill |
| 6 | **Backup HDD** | **1 TB** 3.5" SATA HDD | **2 TB** WD Red Plus / Seagate IronWolf (24/7 NAS disks) | Separate disk from the SSD ([§ 12](#12-backups)). Prefer **CMR**; avoid SMR "archive" disks |
| 7 | **Power supply (PSU)** | **450 W 80 Plus Bronze**, known brand | **550 W 80 Plus Bronze/Gold**: Corsair CX/RM, Cooler Master MWE V2, Seasonic, Antec, DeepCool PK/PM | The build uses only about 100–150 W, but a **good PSU** survives Sri Lankan power dips. Never a free "case PSU" or no-name unit |
| 8 | **Case** | Micro-ATX mini tower | Micro-ATX with **front + top dust filters**, mesh front, **2 front + 1 rear 120 mm fans**, one **3.5" bay** | Must fit the under-counter compartment with **10 cm free behind and at the sides** for air |
| 9 | Extra case fans | — | 120 mm, if the case comes with fewer than 3 | Air in at the front, out at the back |
| 10 | **Graphics card** | **None** | **None** | Built-in graphics run the 24" cashier screen. A graphics card only adds heat, power and Linux driver trouble |
| 11 | **24" monitor** | 24" **1920×1080** IPS, HDMI | Same, with a VESA mount or a stand that tilts | Live Billing shows 3 columns + settle cards. Bring an HDMI/DP cable if the monitor has none |
| 12 | Keyboard + mouse | Wired USB | Spill-resistant wired keyboard | No wireless (batteries, interference) |
| 13 | **UPS** | 1500 VA line-interactive with **USB** port | **1500 VA / 900 W pure sine wave** (APC Back-UPS Pro BR1500MI, CyberPower CP1500EPFCLCD) | Powers server, monitor, printer #0, switch, router; USB cable to the server for clean shutdown ([§ 3.6](#36-power-ups-sockets-and-surge), [§ 9](#9-power-ups)) |
| 14 | Install USB stick | 8 GB | 16 GB USB 3 | For the Ubuntu Desktop 24.04 installer; remove it after installing |
| 15 | Cat6 patch cable | 1 | 2–3 m, factory-made | Server → switch ([§ 3.5](#35-network)) |

**Already counted elsewhere:** thermal printer #0 ([§ 7](#7-printers-and-cash-drawer)), webcam (optional).

**Why this does not lag:** the shop's load (~300 invoices a day, 4 terminals) uses only a small part of a 6-core i5. The things that can cause lag are **slow disk** (fixed by an NVMe with DRAM cache), **too little or single-channel RAM** (fixed by 2 sticks, 16–32 GB) and **overheating** (fixed by the tower cooler, 3 fans and dust filters). Do not save money on these three.

**After assembling, before installing Ubuntu (BIOS):**
- *Restore on AC power loss* → **Power On**.
- Memory profile **XMP / EXPO** on, so the RAM runs at its rated speed.
- **Boot from the NVMe SSD**, USB boot off after install, BIOS password set ([§ 13](#13-security-hardening)).
- Built-in graphics enabled; sleep / ErP options so the PC never sleeps.
- Check the CPU temperature: under 80 °C at full load, 30–50 °C idle.
- Run a 30-minute stress test (`stress-ng`) and a RAM test (`memtest86+` from the Ubuntu boot menu) before go-live.

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
| **Cashier side:** desktop session + Chrome kiosk (Live Billing) | 2 GB |
| **Free for the OS file cache** | **~3 GB** |

Do not run this combined server on 8 GB: the cashier's Chrome would compete with MySQL and PDF generation at peak hours.

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
- **Desktop for the cashier** ([§ 6.3](#63-main-cashier-on-the-server)): Ubuntu Desktop with automatic sign-in to the limited `cashier` user, screen lock and sleep off, Chrome kiosk at login. The admin account (`sudo`) is never used at the counter.

---

## 5. Server software stack

| Component | Version | Runs as | Listens on | Purpose |
|---|---|---|---|---|
| **Ubuntu Desktop** | 24.04 LTS (minimal install) | — | — | OS (supported to 2029). Desktop, not Server, because the main cashier works on this machine. GNOME on **Xorg** session (most reliable for kiosk Chrome and printing) |
| CUPS | distro | systemd `cups` | **localhost:631** only | Printer #0 (the cashier's thermal printer) with the maker's Linux driver |
| Google Chrome (cashier) | stable | user `cashier`, auto-start | — | Main cashier kiosk on `/pos/cashier` (same Chrome install as the PDF one) |
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

**Nothing except Nginx (and SSH) listens on the network.** MySQL, Redis, Meilisearch, Reverb and CUPS are bound to `127.0.0.1`. Do not share printer #0 on the network.

---

## 6. Terminal PCs

There are **3 counter PCs** (Windows). The main cashier has no PC of its own: it works on the shop server ([§ 6.3](#63-main-cashier-on-the-server)).

### 6.1 Counter PC hardware (×3)

| Part | Counter PC |
|---|---|
| Type | Business mini PC on the back of the monitor (full spec: [§ 3.2](#32-computers)) |
| CPU | Intel i3 12th gen+ / Ryzen 3 (Intel N100 minimum) |
| RAM | 8 GB minimum, 16 GB recommended |
| Disk | 256 GB NVMe SSD |
| Screen | 21.5–22" 1920×1080 IPS |
| Network | **Wired Gigabit** |
| USB | Printer + keyboard + mouse (+ webcam) |
| OS | Windows 10/11 Pro |

### 6.2 Software and settings on every counter PC

1. **Google Chrome** (stable), updates on.
2. **Thermal printer driver** from the maker; printer set as the **Windows default printer**, paper 80 mm, margins 0 ([PRINTING.md](PRINTING.md)).
3. **Trust the shop's HTTPS certificate** (install the local CA certificate in *Trusted Root Certification Authorities*, [§ 10](#10-https-inside-the-shop)).
4. **Chrome kiosk shortcut** in *Startup* (`shell:startup`):
   ```
   "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing https://pos.citizens.local/pos
   ```
5. **Register the device once** as its terminal (Super Admin: Administration → Terminals → Register this device). This sets a long-lived device cookie; **do not clear Chrome's cookies** on terminals.
6. Windows: sleep **never**, screen-off after 30 min, a local Windows user per PC (no admin rights for staff), automatic sign-in to that Windows user, Windows Update active hours set to shop hours.
7. Optional webcam, allowed for `pos.citizens.local` in Chrome site settings, if clock-in photos are switched on.

### 6.3 Main cashier on the server

The cashier sits at the server's own 24" screen. The server keeps doing all its server work in the background; the cashier only ever sees Chrome.

**Two Ubuntu accounts, kept apart:**

| Account | Used by | Rights |
|---|---|---|
| `admin` (created at install) | Developer, over SSH/Tailscale | `sudo`. **Never left signed in at the counter** |
| `cashier` | Signs in automatically at boot | **No `sudo`**, not in any admin group. Only runs Chrome |

**Set-up (once, as `admin`):**

1. Create the user: `sudo adduser cashier` (do **not** add it to `sudo`).
2. Automatic sign-in on Xorg. In `/etc/gdm3/custom.conf` under `[daemon]`:
   ```ini
   WaylandEnable=false
   AutomaticLoginEnable=true
   AutomaticLogin=cashier
   ```
3. No sleep, ever: `sudo systemctl mask sleep.target suspend.target hibernate.target hybrid-sleep.target`.
4. Name resolution: add `127.0.0.1   pos.citizens.local` to `/etc/hosts`. The cashier then reaches the app **without going through the network**, so it keeps working even if the switch or a cable fails.
5. **Printer #0 through CUPS:** install the maker's Linux driver, add the printer (Settings → Printers, or `lpadmin`), paper 80 mm, margins 0, and make it the **default printer** (`sudo lpadmin -d <printer-name>`). Print the Sinhala test page ([§ 7](#7-printers-and-cash-drawer)).
6. **Trust the shop certificate in the cashier's Chrome.** Chrome on Linux has its own certificate list per user:
   ```bash
   sudo apt install libnss3-tools
   sudo cp "$(mkcert -CAROOT)/rootCA.pem" /usr/local/share/citizens-rootCA.pem
   sudo -u cashier mkdir -p /home/cashier/.pki/nssdb
   sudo -u cashier certutil -d sql:/home/cashier/.pki/nssdb -N --empty-password
   sudo -u cashier certutil -d sql:/home/cashier/.pki/nssdb -A -t "C,," -n citizens-ca -i /usr/local/share/citizens-rootCA.pem
   ```
   (Copy only `rootCA.pem`. `rootCA-key.pem` must never be readable by `cashier`.)
7. **Desktop settings for `cashier`** (run in the cashier's session, or with `sudo -u cashier dbus-launch gsettings …`):
   ```bash
   gsettings set org.gnome.desktop.screensaver lock-enabled false
   gsettings set org.gnome.desktop.session idle-delay 1800                 # screen off after 30 min, no lock
   gsettings set org.gnome.settings-daemon.plugins.power sleep-inactive-ac-type 'nothing'
   gsettings set org.gnome.desktop.notifications show-banners false        # no update pop-ups over the POS
   gsettings set org.gnome.settings-daemon.plugins.media-keys terminal "[]" # Ctrl+Alt+T does nothing
   ```
8. **Chrome kiosk at sign-in:** `/home/cashier/.config/autostart/pos-kiosk.desktop`
   ```ini
   [Desktop Entry]
   Type=Application
   Name=CITIZENS POS
   Exec=google-chrome --kiosk --kiosk-printing --no-first-run --noerrdialogs --disable-session-crashed-bubble https://pos.citizens.local/pos/cashier
   X-GNOME-Autostart-enabled=true
   ```
   Copy the same file to `/home/cashier/.local/share/applications/` and pin **CITIZENS POS** to the dock, so the cashier can reopen it with one click if Chrome is ever closed.
9. Reboot. The screen must come up straight on Live Billing. Then **register the device** as the main cashier terminal (Super Admin signs in inside the kiosk → Administration → Terminals → Register this device), **sign the Super Admin out**, and do a test print from Administration → Printers.
10. **Later, with a printer-driven drawer:** the shop keeps its **manual cash drawer** for about one more year, so QZ Tray is **not needed yet**. When a printer-driven drawer is fitted, install QZ Tray for Linux (auto-start for `cashier`, allowed for `pos.citizens.local`), tick *Cash drawer connected* on printer #0, and put back the drawer alert ([§ 7](#7-printers-and-cash-drawer)). Signing QZ requests with the shop certificate is still a TODO in `resources/js/printing/drawer.js`.
11. Optional webcam, allowed for `pos.citizens.local` in Chrome site settings, if clock-in photos are switched on.

**House rules for the cashier counter**

- Never switch the machine off with the power button or the wall switch; it is the server for every counter. If it must be restarted, the owner does it after closing the drawer day.
- No USB sticks, phones charging or other devices in the server's ports.
- Drinks away from the keyboard and the under-counter compartment.

---

## 7. Printers and cash drawer

| Printer | Connected to | Prints | Extra |
|---|---|---|---|
| #0 | **Shop server** (USB, CUPS) | Direct sales, credit bills, payment receipts, return receipts, drawer reports | Printer-driven cash drawer on the RJ11 port **later** (manual drawer for now) |
| #1, #2, #3 | Counter PCs (USB) | Invoices (Sinhala/English), quotations | — |
| Spare | Shelf | — | Same model; swap and update the printer record in Administration → Printers |

- Printing goes **browser → printer driver** (`--kiosk-printing`), so Sinhala text prints as an image and looks correct. Counters use the Windows driver; printer #0 on the server uses the maker's **Linux (CUPS) driver**.
- **Pick the printer model only after it passes on both systems:** print a real Sinhala invoice and a drawer report from Chrome on Windows **and** from Chrome on Ubuntu 24.04. Check that the paper width is right, nothing is cut off, the auto-cutter fires and the text is sharp. If the model has no working Linux driver, choose another model (all printers must be the same model so the spare fits everywhere).
- **Cash drawer, now:** the shop keeps its **manual cash drawer** for about one more year (owner, 2026-10-09). The cashier opens it by hand. All the drawer *accounting* in the app works the same: opening float, cash in and out, handover counts, expected cash and variance, Z report.
- **Cash drawer, later:** the automatic drawer kick is kept in the code. With a printer-driven drawer it opens **only** on a cash settlement, through QZ Tray on the server's cashier session (ESC/POS `ESC p 0 25 250`). Leave *Cash drawer connected* **unticked** on printer #0 until then, so no kick is attempted.
- The "cash drawer did not open" alert after a settlement is **commented out** in `resources/js/pos/live-billing.js` (`openDrawer()`). Put that line back (and run `npm run build`) when the printer-driven drawer is installed.
- Test every printer from Administration → Printers → *Test print* during installation.

---

## 8. Network

### 8.1 IP plan (example: change it to fit the router)

| Device | IP | How |
|---|---|---|
| Router / gateway | 192.168.10.1 | — |
| **Shop server = main cashier** | **192.168.10.10** | Static (netplan / NetworkManager) |
| Counter 1 / 2 / 3 | 192.168.10.31 / .32 / .33 | DHCP reservation |
| Office laptop, owner's phone on Wi-Fi | DHCP pool .100–.200 | — |

### 8.2 Name resolution: `pos.citizens.local`

Every device must resolve `pos.citizens.local` → `192.168.10.10` (the server itself resolves it to `127.0.0.1` through its own `/etc/hosts`, [§ 6.3](#63-main-cashier-on-the-server)). Options, best first:

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
| **UPS A** | Server (= main cashier), its 24" monitor, printer #0, switch, router | **1500 VA / 900 W**, pure sine wave, line-interactive with AVR, USB/HID | ≥ 20 min for the server (about 30–45 min at the normal ~110 W load); the cashier can keep settling while it runs |
| UPS B (recommended) | One per counter: PC, monitor, printer | 650–850 VA line-interactive with AVR, one each | ≥ 5 min (about 20–30 min at ~50 W) |

Load calculation, models and the electrical work (earth, surge protector, dedicated circuit): [§ 3.6](#36-power-ups-sockets-and-surge).

Thermal printers draw a short high current when printing; plug printer #0 into a **battery-backed** socket of UPS A (not a surge-only socket) and check the load stays under ~60 % of the UPS rating.

**Clean shutdown:** connect UPS A's USB cable to the server and configure NUT/apcupsd to shut the server down when **battery < 30 %** or after **10 min on battery**. Because the cashier works on the server, a long power cut ends with the cashier screen going off too: **settle what is waiting and count the drawer within the first 10 minutes.** With BIOS *Power On after AC loss*, the server starts again by itself. On boot, systemd/Supervisor start every service in order, and counters get their carts back from Redis.

Test it before go-live by pulling the UPS plug (Go-live checklist).

---

## 10. HTTPS inside the shop

HTTPS is **required**, not optional: the webcam (clock-in photo), secure cookies and (later) QZ Tray all need a trusted `https://` page.

**Recommended: a small local certificate authority with `mkcert`:**

1. On the server: `mkcert -install && mkcert pos.citizens.local 192.168.10.10`, which creates `pos.citizens.local+1.pem` and `-key.pem`.
2. Put them in `/etc/ssl/citizens/` and point Nginx at them ([§ 14.2](#142-nginx-site)).
3. Copy `rootCA.pem` (from `mkcert -CAROOT`) to every counter PC and install it under *Trusted Root Certification Authorities*. On the owner's phone, install it as a trusted profile/certificate. On the server, add it to the `cashier` user's Chrome ([§ 6.3](#63-main-cashier-on-the-server), step 6); `mkcert -install` only covers the user who ran it.
4. **Keep `rootCA-key.pem` secret** (store it with the backups, encrypted; on the server only `root` may read it, never `cashier`). Anyone with it can create certificates that the shop PCs trust.
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
| Physical | Server in a **ventilated, lockable compartment under the cashier counter**; BIOS password; USB boot disabled; staff never get the `admin` password |
| Cashier session | `cashier` user has no `sudo`; Chrome kiosk only; Ctrl+Alt+T disabled; CUPS not shared; `rootCA-key.pem`, `.env` and backups not readable by `cashier` ([§ 6.3](#63-main-cashier-on-the-server)) |

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
1. Install **Ubuntu Desktop 24.04 LTS** (minimal installation), first user `admin`. Then `apt install openssh-server`. Set the hostname, static IP, timezone `Asia/Colombo`, NTP.
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

**B. Main cashier on the server** ([§ 6.3](#63-main-cashier-on-the-server))
1. `cashier` user, automatic sign-in on Xorg, no sleep, `/etc/hosts` entry.
2. Printer #0 in CUPS (default printer, 80 mm, margin 0), shop certificate in the cashier's Chrome, desktop settings, kiosk autostart.
3. Reboot → Live Billing comes up by itself → Super Admin registers the device as the main cashier → **Test print** → Super Admin signs out.
4. Manual cash drawer for now, so nothing else to install. Later, with a printer-driven drawer: QZ Tray → open the drawer once from a test settlement.

**C. Counter PCs** ([§ 6.2](#62-software-and-settings-on-every-counter-pc))
1. Chrome, printer driver (default printer, 80 mm, margin 0), CA certificate, hosts entry if needed.
2. Sign in as Super Admin on each PC → Administration → Terminals → **Register this device** → Printers → **Test print**.
3. Put the kiosk shortcut in *Startup* and reboot each PC to check it comes up on its own.

**D. Go-live**: work through [IMPLEMENTATION_PLAN.md § 17](IMPLEMENTATION_PLAN.md#17-go-live-checklist) and **Administration → Go-live** in the app.

---

## 16. Updating the app (deploy script)

Zero-downtime release folders, run **after shop hours** (or when no invoice is waiting for settlement). The deploy does not touch the cashier's screen; press **F5** in the cashier kiosk afterwards to load the new version. OS updates that need a reboot (kernel) are also done after shop hours, after the drawer day is closed, because a reboot takes the cashier screen down too:

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
| Server compartment | Fan running, vents clear, no dust or water; nothing extra plugged into the server's USB ports | Owner, weekly |
| Pending reboot | `ls /var/run/reboot-required` over SSH; reboot after closing time if it exists | Developer, weekly |
| Day close | Drawer closed with no invoices left INVOICED; Z report printed | Owner, nightly |
| Restore test | Restore the latest backup on another machine | Developer, monthly |

---

## 18. When things go wrong (runbook)

| Problem | What happens | What to do |
|---|---|---|
| **Internet down** | Nothing for sales or backups (backups are local). Remote viewing pauses. | Nothing. |
| **Power cut** | UPS keeps the server (and so the main cashier), network and printer #0 running; counters may go off. | Finish settling and count the drawer in the first 10 minutes. If the power stays off, the server shuts itself down cleanly and the cashier screen goes off with it. When power returns everything starts by itself (cashier signs in automatically, Chrome opens Live Billing) and carts come back. |
| **Cashier screen frozen / Chrome closed** | Server still fine; counters keep billing. | Press **Alt+F4** to close Chrome, then click **CITIZENS POS** in the dock to open it again. As a last resort the owner restarts the server **after** warning the counters (their carts survive). |
| **Cashier keyboard, mouse, monitor or printer #0 broken** | Server fine; cashier cannot settle. | Swap the part (the spare thermal printer fits). Until then the Super Admin can register a **counter PC** as the main cashier terminal (Administration → Terminals → Main cashier → Register this device); register the server back afterwards. |
| **A counter PC dies** | That counter cannot bill. | Use another PC: Chrome + printer driver + certificate, then Super Admin registers it as that counter. |
| **A printer fails / out of paper** | "Check printer" warning; invoice not printed. | Load paper or swap in the spare printer; reprint (F10). |
| **Live Billing not updating** | Reverb down; counters fall back to polling. | `sudo supervisorctl restart citizens-reverb`. |
| **Search slow / no results** | Meilisearch down; MySQL fallback search is used. | `sudo systemctl restart meilisearch`; if needed `php artisan scout:import "App\Domain\Catalog\Models\Product"`. |
| **Cash drawer does not open** | Manual drawer for now: not an app issue. Later (printer-driven): QZ Tray not running. | Later: start QZ Tray; check the RJ11 cable; open with the drawer key meanwhile. |
| **Wrong time on invoices** | Server clock off. | `timedatectl` → re-enable NTP. |
| **Server dead** | Shop cannot sell, and the main cashier screen is gone too. | Write bills by hand. Install Ubuntu Desktop on a spare/new PC from this document (including [§ 6.3](#63-main-cashier-on-the-server) for the cashier), restore the newest zip from the backup HDD ([§ 12.4](#124-restore-test-it-monthly)), or from the owner's monthly USB copy if the backup disk is lost too, copy `.env` (same `APP_KEY`, so terminals stay registered), then check the last invoice numbers against the paper bills. |
| **Disk full** | Writes fail. | Clear old logs; check that `backup:clean` and log rotation run and that `BACKUP_PATH` really points at the backup HDD, not the system disk. |
