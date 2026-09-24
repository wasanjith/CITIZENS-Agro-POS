# Printing setup and Phase 0 test results

Record the working setup here once the printing test passes on the real printer
(IMPLEMENTATION_PLAN.md, Phase 0.4). Buy **one** printer first; buy the other three
of the same model only after every check below passes.

## How to run the test

1. On the PC that has the printer, sign in with a username and password as the Super Admin.
2. **Terminals** → *Register this device* next to the right terminal.
3. **Printers** → edit that terminal's printer → enter the exact *Windows printer name* and the model.
4. **Printing test** → *Print Sinhala sample*, then *Sinhala + English*.
5. Main cashier PC only: install QZ Tray, then *Open cash drawer*.
6. *Download sample PDF* and check the Sinhala text.

## Checklist

| Check | Result |
|---|---|
| Sinhala conjuncts print joined (ශ්‍රී, ක්‍ෂ, ප්‍ර, ත්‍රි) | ☐ |
| Nothing cut off on the right edge; numbers line up | ☐ |
| Text readable at arm's length (min 11 pt) | ☐ |
| Paper cut after the footer (auto-cutter) | ☐ |
| Prints silently with `--kiosk-printing` (no dialog) | ☐ |
| Two PCs print at the same time, each to its own printer | ☐ |
| Cash drawer opens via QZ Tray, no paper printed | ☐ |
| A4 Sinhala PDF correct | ✅ (checked in development, Chrome driver) |
| Time from click to printed invoice | ___ s (target < 3 s) |

## Working configuration

| Item | Value |
|---|---|
| Printer model | |
| Windows driver + version | |
| Driver paper size | 80 mm × receipt (margins 0) |
| Print speed / darkness | |
| Chrome version | |
| Chrome shortcut target | `"C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --kiosk-printing https://pos.citizens.local/…` |
| QZ Tray version (main PC) | |
| Date tested / by | |

## Notes

- Invoices print as an image (the browser renders Sinhala, the driver rasterises it), so the printer needs no Sinhala font.
- A4 PDFs use spatie/laravel-pdf with the **chrome** driver (`LARAVEL_PDF_DRIVER=chrome`), which runs the installed Chrome/Chromium headless. The Ubuntu server needs `chromium` installed.
- QZ Tray shows an "Allow" prompt until requests are signed with a certificate (planned for Phase 3).
