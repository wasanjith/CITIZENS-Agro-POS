import qz from 'qz-tray';

/**
 * Opens the cash drawer attached to the main cashier's thermal printer.
 *
 * Settlement prints nothing, so the drawer is kicked with the raw ESC/POS
 * command ESC p 0 25 250 sent through QZ Tray (installed on the main PC only).
 *
 * TODO (Phase 3): sign QZ Tray requests with the shop certificate so the
 * "Allow" prompt does not appear.
 */
const DRAWER_KICK = '\x1B\x70\x00\x19\xFA';

async function connect() {
    if (!qz.websocket.isActive()) {
        await qz.websocket.connect({ retries: 1, delay: 1 });
    }
}

export async function openCashDrawer(printerName) {
    await connect();

    const printer = printerName || (await qz.printers.getDefault());
    const config = qz.configs.create(printer);

    await qz.print(config, [{ type: 'raw', format: 'command', flavor: 'plain', data: DRAWER_KICK }]);

    return printer;
}

export async function listPrinters() {
    await connect();

    return qz.printers.find();
}

window.CitizensPrinting = { openCashDrawer, listPrinters };
