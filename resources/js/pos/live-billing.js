import { api, ApiError, beep, isLive, money, onLiveChange, printPage, qty, randomKey } from './client';

/**
 * Live Billing: the three counters in real time.
 *
 *  - mode "cashier" (/pos/cashier on the main terminal): settle cards with Settle / Void,
 *    find by invoice number, discount approvals, drawer kick after a cash settlement.
 *  - mode "view" (/admin/live-billing): the same columns, view only (owner's phone).
 *
 * First paint and fallback come from GET /api/live-billing/snapshot (polled every 3 s
 * while the WebSocket connection is down); Reverb events update the columns instantly.
 */
export default function liveBilling(config) {
    return {
        config,
        snapshot: { counters: [], main: null, approvals: [], totals: {}, cashier: null, settle_warning_minutes: 5 },
        loaded: false,
        live: false,
        now: Date.now(),
        online: {},
        fresh: {},
        removed: {},
        flash: {},
        activeCounter: 0,
        refreshTimer: null,

        // Cashier actions
        settling: null,
        settleMethod: 'cash',
        settleReference: '',
        settleKey: null,
        settleCustomer: null,
        overrideCredit: false,
        voiding: null,
        voidReason: '',
        busy: false,
        findNo: '',
        error: null,
        notice: null,

        async init() {
            await this.refresh();
            this.loaded = true;

            if (this.config.focus_invoice) {
                const sale = this.allInvoices().find((row) => row.id === this.config.focus_invoice);
                if (sale) this.openSettle(sale);
            }

            this.live = isLive();
            onLiveChange((live) => {
                this.live = live;
                if (live) this.refresh();
            });
            this.listen();

            setInterval(() => (this.now = Date.now()), 1000);
            setInterval(() => {
                if (!this.live) this.refresh();
            }, 3000);
        },

        async refresh() {
            try {
                const data = await api(this.config.urls.snapshot);
                data.counters.forEach((column) => this.trackLines(column.terminal_id, column.cart?.lines ?? []));
                this.snapshot = data;
                this.error = this.error === 'Live Billing is not reachable.' ? null : this.error;
            } catch {
                this.error = 'Live Billing is not reachable.';
            }
        },

        refreshSoon() {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = setTimeout(() => this.refresh(), 700);
        },

        listen() {
            if (!window.Echo) return;

            window.Echo.private('live-billing')
                .listen('.cart.updated', (event) => this.onCart(event))
                .listen('.counter.activity', (event) => this.addEvents(event.terminalId, event.events))
                .listen('.invoice.issued', (event) => {
                    const column = this.column(event.sale.terminal_id);
                    if (column) {
                        column.invoices = [...column.invoices.filter((row) => row.id !== event.sale.id), event.sale];
                        column.cart = null;
                        column.status = 'printed';
                        this.addEvents(column.terminal_id, event.events);
                    }
                    if (this.config.sound) beep();
                    this.refreshSoon();
                })
                .listen('.invoice.settled', (event) => {
                    this.dropInvoice(event.sale);
                    this.flash = { ...this.flash, [event.sale.terminal_id]: Date.now() + 4000 };
                    this.addEvents(event.sale.terminal_id, event.events);
                    this.refreshSoon();
                })
                .listen('.invoice.voided', (event) => {
                    this.dropInvoice(event.sale);
                    this.addEvents(event.sale.terminal_id, event.events);
                    this.refreshSoon();
                })
                .listen('.approval.requested', (event) => {
                    this.snapshot.approvals = [...this.snapshot.approvals.filter((row) => row.id !== event.request.id), event.request];
                    if (this.config.sound) beep();
                })
                .listen('.approval.decided', (event) => {
                    this.snapshot.approvals = this.snapshot.approvals.filter((row) => row.id !== event.request.id);
                });

            window.Echo.private('cashier-authority').listen('.authority.changed', (event) => {
                this.snapshot.cashier = event.holder;
                this.notice = event.message;
            });

            window.Echo.join('pos-terminals')
                .here((members) => {
                    const online = {};
                    members.forEach((member) => member.terminal_id && (online[member.terminal_id] = member.name));
                    this.online = online;
                })
                .joining((member) => member.terminal_id && (this.online = { ...this.online, [member.terminal_id]: member.name }))
                .leaving((member) => {
                    if (!member.terminal_id) return;
                    const online = { ...this.online };
                    delete online[member.terminal_id];
                    this.online = online;
                });
        },

        column(terminalId) {
            return this.snapshot.counters.find((row) => row.terminal_id === terminalId)
                ?? (this.snapshot.main?.terminal_id === terminalId ? this.snapshot.main : null);
        },

        onCart(event) {
            const column = this.column(event.terminalId);
            if (!column) return;

            this.trackLines(column.terminal_id, event.cart.lines ?? [], column.cart?.lines ?? []);
            column.cart = event.cart.lines?.length ? event.cart : null;
            column.user = event.cart.user?.name ?? column.user;
            column.status = event.cart.lines?.length ? (event.cart.tendered !== null && event.cart.tendered !== undefined ? 'payment' : 'billing') : (column.invoices.length ? 'printed' : 'idle');
            column.online = true;
            this.addEvents(column.terminal_id, event.events);
        },

        /** New lines glow for 5 s; removed lines stay struck through in red for 10 s. */
        trackLines(terminalId, lines, previous = null) {
            const at = Date.now();
            const known = this.fresh[terminalId] ?? {};
            const nextFresh = {};

            lines.forEach((line) => {
                nextFresh[line.key] = known[line.key] ?? (previous === null && !this.loaded ? 0 : at + 5000);
            });
            this.fresh = { ...this.fresh, [terminalId]: nextFresh };

            if (previous) {
                const gone = previous.filter((line) => !lines.some((row) => row.key === line.key));
                if (gone.length) {
                    const list = (this.removed[terminalId] ?? []).filter((row) => row.until > at);
                    this.removed = { ...this.removed, [terminalId]: [...list, ...gone.map((line) => ({ line, until: at + 10000 }))] };
                }
            }
        },

        isFresh(terminalId, key) {
            return (this.fresh[terminalId]?.[key] ?? 0) > this.now;
        },

        removedLines(terminalId) {
            return (this.removed[terminalId] ?? []).filter((row) => row.until > this.now).map((row) => row.line);
        },

        addEvents(terminalId, events = []) {
            const column = this.column(terminalId);
            if (!column || !events?.length) return;
            const ids = new Set(column.events.map((row) => row.id));
            column.events = [...events.filter((row) => !ids.has(row.id)).reverse(), ...column.events].slice(0, 10);
        },

        dropInvoice(sale) {
            const column = this.column(sale.terminal_id);
            if (!column) return;
            column.invoices = column.invoices.filter((row) => row.id !== sale.id);
            if (!column.cart && column.invoices.length === 0) column.status = 'idle';
        },

        allInvoices() {
            return [...this.snapshot.counters.flatMap((row) => row.invoices), ...(this.snapshot.main?.invoices ?? [])];
        },

        isOnline(column) {
            return this.online[column.terminal_id] !== undefined || column.online;
        },

        statusOf(column) {
            if (this.flash[column.terminal_id] > this.now) return 'settled';
            if (!this.isOnline(column) && !column.cart && column.invoices.length === 0) return 'offline';
            return column.status === 'offline' ? 'idle' : column.status;
        },

        statusLabel(column) {
            const status = this.statusOf(column);
            const labels = { offline: 'Offline', idle: 'Idle', billing: 'Billing', payment: 'Payment', printed: 'Printed', settled: 'Settled' };
            if (status === 'printed' && column.invoices.length) {
                return `Printed · waiting ${this.waited(column.invoices[0])}`;
            }
            return labels[status] ?? status;
        },

        statusClass(column) {
            return {
                offline: 'bg-gray-200 text-gray-600',
                idle: 'bg-gray-100 text-gray-700',
                billing: 'bg-sky-100 text-sky-800',
                payment: 'bg-violet-100 text-violet-800',
                printed: this.late(column.invoices[0]) ? 'bg-red-600 text-white' : 'bg-amber-100 text-amber-900',
                settled: 'bg-brand-600 text-white',
            }[this.statusOf(column)];
        },

        waited(sale) {
            if (!sale?.invoiced_at) return '';
            const seconds = Math.max(0, Math.floor((this.now - new Date(sale.invoiced_at).getTime()) / 1000));
            return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        },

        late(sale) {
            if (!sale?.invoiced_at) return false;
            return this.now - new Date(sale.invoiced_at).getTime() > this.snapshot.settle_warning_minutes * 60000;
        },

        // ------------------------------------------------------------------ settle / void

        openSettle(sale) {
            if (this.config.mode !== 'cashier') return;
            this.error = null;
            this.settling = sale;
            this.settleMethod = sale.payment_method;
            this.settleReference = '';
            this.settleKey = randomKey();
            this.settleCustomer = null;
            this.overrideCredit = false;
            if (sale.customer_id) this.loadSettleCustomer(sale.customer_id);
            this.$nextTick(() => document.getElementById(['cash', 'credit'].includes(this.settleMethod) ? 'settle-confirm' : 'settle-reference')?.focus());
        },

        async settle() {
            if (!this.settling || this.busy) return;
            this.busy = true;
            this.error = null;
            const sale = this.settling;

            try {
                const url = this.config.urls.settle.replace('__ID__', sale.id);
                const data = await api(url, { method: 'POST', body: { method: this.settleMethod, reference: this.settleReference || null, override_credit_limit: this.overrideCredit, idempotency_key: this.settleKey } });
                this.settling = null;
                this.dropInvoice(sale);
                this.flash = { ...this.flash, [sale.terminal_id]: Date.now() + 4000 };
                this.notice = `${sale.invoice_no} settled${sale.payment_method === 'cash' && Number(sale.change_due) > 0 ? ` · give back Rs. ${money(sale.change_due)}` : ''}.`;

                if (data.open_drawer && this.config.has_drawer) {
                    this.openDrawer();
                }
                if (data.credit_bill_url) {
                    this.notice = `${sale.invoice_no} put on ${sale.customer?.name ?? 'the customer'}'s account. The credit bill is printing: get the customer's signature and stamp the shop seal.`;
                    printPage(data.credit_bill_url).then((ok) => {
                        if (!ok) this.error = `Check printer: the credit bill of ${sale.invoice_no} was not confirmed as printed. Reprint it from the invoice page.`;
                    });
                }
                this.refreshSoon();
            } catch (error) {
                if (error instanceof ApiError && error.data?.redirect) {
                    window.location.href = error.data.redirect;
                    return;
                }
                this.error = error instanceof ApiError ? error.first : error.message;
            } finally {
                this.busy = false;
                this.$nextTick(() => this.$refs.find?.focus());
            }
        },

        async loadSettleCustomer(id) {
            try {
                const { customer } = await api(this.config.urls.customer.replace('__ID__', id));
                if (this.settling?.customer_id === id) this.settleCustomer = customer;
            } catch {
                // The settle card still works without the balance.
            }
        },

        /** Credit settlement would take the customer over their limit. */
        get overLimit() {
            if (this.settleMethod !== 'credit' || !this.settleCustomer || !this.settling) return false;
            return Number(this.settleCustomer.balance) + Number(this.settling.total) > Number(this.settleCustomer.credit_limit) + 0.001;
        },

        async openDrawer() {
            try {
                await window.CitizensPrinting?.openCashDrawer(this.config.printer || undefined);
            } catch (error) {
                this.error = `Settled, but the cash drawer did not open (QZ Tray: ${error?.message ?? error}). Open it with the key.`;
            }
        },

        openVoid(sale) {
            if (!this.config.can_void) return;
            this.settling = null;
            this.voiding = sale;
            this.voidReason = '';
            this.$nextTick(() => document.getElementById('void-reason')?.focus());
        },

        async confirmVoid() {
            if (!this.voiding || this.busy || this.voidReason.trim() === '') return;
            this.busy = true;
            this.error = null;
            const sale = this.voiding;

            try {
                await api(this.config.urls.void.replace('__ID__', sale.id), { method: 'POST', body: { reason: this.voidReason } });
                this.voiding = null;
                this.dropInvoice(sale);
                this.notice = `${sale.invoice_no} voided. The counter can restore the bill.`;
                this.refreshSoon();
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            } finally {
                this.busy = false;
            }
        },

        async find() {
            const number = this.findNo.trim();
            if (number === '') return;
            this.error = null;

            try {
                const { sale } = await api(`${this.config.urls.find}?no=${encodeURIComponent(number)}`);
                this.findNo = '';

                if (!sale) {
                    this.error = `No invoice ending in ${number}.`;
                } else if (sale.status === 'invoiced') {
                    this.openSettle(sale);
                } else {
                    this.notice = `${sale.invoice_no} is ${sale.status_label.toLowerCase()}${sale.settled_by ? ` (by ${sale.settled_by})` : ''}.`;
                }
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            }
        },

        // ------------------------------------------------------------------ approvals

        async decide(approval, approve) {
            const note = approve ? null : window.prompt('Reason for rejecting (optional):', '');
            if (!approve && note === null) return;

            try {
                const url = (approve ? this.config.urls.approve : this.config.urls.reject).replace('__ID__', approval.id);
                await api(url, { method: 'POST', body: { note } });
                this.snapshot.approvals = this.snapshot.approvals.filter((row) => row.id !== approval.id);
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            }
        },

        money,
        qty,
    };
}
