import { api, ApiError, beep, isLive, money, onLiveChange, printPage, qty, randomKey, uuid } from './client';

/**
 * Counter billing screen (/pos). The cart lives in the browser for instant response and
 * is synced to the server (debounced 300 ms) for Live Billing and restore after a refresh.
 * The invoice is always priced on the server when it is printed (F9).
 *
 *   <div x-data="posCounter(@js($config))">
 */
export default function posCounter(config) {
    return {
        config,
        terminal: config.terminal,

        // Search / quick grids
        tab: 'search',
        query: '',
        results: [],
        resultsFor: null,
        pendingSearch: null,
        highlighted: 0,
        searching: false,
        searchController: null,
        categoryId: null,

        // Cart
        cart: null,
        server: null,
        selected: -1,
        syncTimer: null,
        syncing: false,
        syncError: null,
        issueKey: null,

        // Panels and messages
        tender: false,
        printing: false,
        printed: null,
        printWarning: null,
        error: null,
        notice: null,
        invoices: [],
        holds: [],
        showInvoices: false,
        showHolds: false,
        voidRestores: [],
        approvals: {},
        live: false,
        cashier: null,

        init() {
            this.cart = this.emptyCart();
            this.restore();
            this.listen();

            this.live = isLive();
            onLiveChange((live) => (this.live = live));

            // Heartbeat keeps the counter "online" on Live Billing; the inbox covers for
            // WebSockets when Reverb is down.
            setInterval(() => this.sync(true), 30000);
            setInterval(() => {
                if (!this.live) this.pollInbox();
            }, 5000);

            this.$nextTick(() => this.focusSearch());
        },

        emptyCart() {
            return {
                cart_uuid: uuid(),
                price_list_id: this.config.default_price_list_id,
                payment_method: 'cash',
                tendered: '',
                bill_discount: '',
                bill_approval_request_id: null,
                lines: [],
            };
        },

        // ------------------------------------------------------------------ restore

        async restore() {
            try {
                const data = await api(this.config.urls.cart);
                this.voidRestores = data.void_restores ?? [];
                (data.approvals ?? []).forEach((approval) => this.applyApproval(approval));

                if (data.cart && data.cart.lines?.length) {
                    this.loadServerCart(data.cart);
                    this.notice = 'The bill in progress was restored.';
                }
            } catch (error) {
                this.error = error.message;
            }
        },

        loadServerCart(snapshot) {
            this.cart = {
                cart_uuid: snapshot.cart_uuid || uuid(),
                price_list_id: snapshot.price_list_id ?? this.config.default_price_list_id,
                payment_method: snapshot.payment_method ?? 'cash',
                tendered: snapshot.tendered ?? '',
                bill_discount: Number(snapshot.bill_discount) > 0 ? snapshot.bill_discount : '',
                bill_approval_request_id: snapshot.bill_approval_request_id ?? null,
                lines: (snapshot.lines ?? []).map((line) => this.lineFromServer(line)),
            };
            this.server = snapshot;
        },

        lineFromServer(line) {
            return {
                key: line.key,
                product_id: line.product_id,
                variant_id: line.variant_id,
                unit_id: line.unit_id,
                qty: qty(line.qty),
                discount: Number(line.discount) > 0 ? line.discount : '',
                approval_request_id: line.approval_request_id ?? null,
                short_code: line.short_code,
                name: line.name,
                name_si: line.name_si,
                units: line.units ?? [],
                stock: null,
            };
        },

        // ------------------------------------------------------------------ real time

        listen() {
            if (!window.Echo) return;

            window.Echo.private(`terminal.${this.terminal.id}`)
                .listen('.invoice.voided', (event) => {
                    if (event.restore) {
                        this.voidRestores = [...this.voidRestores.filter((item) => item.sale_id !== event.restore.sale_id), event.restore];
                        beep();
                    }
                    this.refreshInvoices();
                })
                .listen('.approval.decided', (event) => this.applyApproval(event.request))
                .listen('.print.test', (event) => printPage(event.url));

            window.Echo.private('cashier-authority').listen('.authority.changed', (event) => {
                this.cashier = event.holder;
            });

            window.Echo.join('pos-terminals');
        },

        async pollInbox() {
            try {
                const data = await api(`${this.config.urls.inbox}?cart_uuid=${encodeURIComponent(this.cart.cart_uuid)}`);
                this.voidRestores = data.void_restores ?? [];
                (data.approvals ?? []).forEach((approval) => this.applyApproval(approval));
                this.cashier = data.cashier;
            } catch {
                // Try again on the next tick.
            }
        },

        // ------------------------------------------------------------------ search

        focusSearch() {
            this.$refs.search?.focus();
            this.$refs.search?.select();
        },

        search() {
            const term = this.query.trim();
            this.tab = 'search';

            if (term === '') {
                this.searchController?.abort();
                this.results = [];
                this.resultsFor = null;
                return Promise.resolve();
            }

            // The same query is already on its way (Enter right after typing): wait for it.
            if (this.pendingSearch?.term === term) {
                return this.pendingSearch.promise;
            }

            this.searchController?.abort();
            const controller = new AbortController();
            this.searchController = controller;
            this.searching = true;

            const promise = (async () => {
                try {
                    const params = new URLSearchParams({ q: term, limit: 20 });
                    if (this.cart.price_list_id) params.set('price_list_id', this.cart.price_list_id);
                    const data = await api(`${this.config.urls.search}?${params}`, { signal: controller.signal });
                    // A slow answer for an older query must not replace newer results (or come back after the item was added).
                    if (this.query.trim() !== term) return;
                    this.results = (data.items ?? []).map((item) => ({ ...item, qty: data.qty }));
                    this.resultsFor = term;
                    this.highlighted = 0;
                } catch (error) {
                    if (error.name !== 'AbortError') this.results = [];
                } finally {
                    if (this.pendingSearch?.promise === promise) {
                        this.pendingSearch = null;
                        this.searching = false;
                    }
                }
            })();

            this.pendingSearch = { term, promise };

            return promise;
        },

        async showTab(tab, categoryId = null) {
            this.tab = tab;
            this.categoryId = categoryId;

            if (tab === 'search' || (tab === 'category' && categoryId === null)) {
                return;
            }

            try {
                const params = new URLSearchParams({ tab });
                if (categoryId) params.set('category_id', categoryId);
                if (this.cart.price_list_id) params.set('price_list_id', this.cart.price_list_id);
                const data = await api(`${this.config.urls.quick}?${params}`);
                this.results = data.items ?? [];
                this.highlighted = 0;
            } catch (error) {
                this.error = error.message;
            }
        },

        moveHighlight(step) {
            if (this.results.length === 0) return;
            this.highlighted = (this.highlighted + step + this.results.length) % this.results.length;
            this.$nextTick(() => document.getElementById(`result-${this.highlighted}`)?.scrollIntoView({ block: 'nearest' }));
        },

        async chooseHighlighted() {
            // Enter pressed before the results for what is typed now arrived: search first.
            if (this.tab === 'search' && this.query.trim() !== '' && this.resultsFor !== this.query.trim()) {
                await this.search();
                this.highlighted = 0;
            }
            const item = this.results[this.highlighted];
            if (item) this.add(item, item.qty);
        },

        stockColour(item) {
            const stock = Number(item.stock ?? 0);
            if (stock <= 0) return 'bg-red-100 text-red-800';
            if (Number(item.reorder_level ?? 0) > 0 && stock <= Number(item.reorder_level)) return 'bg-amber-100 text-amber-900';
            return 'bg-brand-100 text-brand-800';
        },

        stockText(item) {
            const stock = Number(item.stock ?? 0);
            const unit = item.unit && Number(item.unit.factor) > 1 ? item.unit : null;
            const base = `${qty(stock)} ${item.base_unit ?? ''}`;
            return unit ? `${qty(stock / Number(unit.factor))} ${unit.symbol} · ${base}` : base;
        },

        // ------------------------------------------------------------------ cart

        add(item, amount = null) {
            this.error = null;
            this.printed = null;
            const unit = item.unit ?? item.units?.[0];

            if (!unit) {
                this.error = `${item.name} has no sale unit.`;
                return;
            }
            if (unit.price === null || unit.price === undefined) {
                this.error = `${item.name} has no price per ${unit.name}. Ask the manager to set it.`;
                return;
            }

            const addQty = Number(amount ?? 1) || 1;
            const existing = this.cart.lines.findIndex((line) => line.product_id === item.id && (line.variant_id ?? null) === (item.variant_id ?? null) && line.unit_id === unit.id);

            if (existing >= 0) {
                this.cart.lines[existing].qty = qty(Number(this.cart.lines[existing].qty) + addQty);
                this.selected = existing;
            } else {
                this.cart.lines.push({
                    key: `${item.id}-${item.variant_id ?? 0}-${unit.id}-${Date.now().toString(36)}`,
                    product_id: item.id,
                    variant_id: item.variant_id ?? null,
                    unit_id: unit.id,
                    qty: qty(addQty),
                    discount: '',
                    approval_request_id: null,
                    short_code: item.short_code,
                    name: item.name,
                    name_si: item.name_si,
                    units: (item.units ?? []).filter((u) => u.price !== null).map((u) => ({ id: u.id, symbol: u.symbol, name: u.name, factor: u.factor, allows_decimal: u.allows_decimal, price: u.price })),
                    stock: item.stock,
                    expiry: item.expiry ?? null,
                });
                this.selected = this.cart.lines.length - 1;
            }

            this.searchController?.abort();
            this.query = '';
            this.resultsFor = null;
            this.results = this.tab === 'search' ? [] : this.results;
            this.changed();
            this.focusSearch();
        },

        remove(index) {
            this.cart.lines.splice(index, 1);
            this.selected = Math.min(this.selected, this.cart.lines.length - 1);
            this.changed();
        },

        step(index, direction) {
            const line = this.cart.lines[index];
            if (!line) return;
            const next = Number(line.qty) + direction;
            if (next <= 0) {
                this.remove(index);
                return;
            }
            line.qty = qty(next);
            this.changed();
        },

        setUnit(line, unitId) {
            line.unit_id = Number(unitId);
            line.discount = '';
            line.approval_request_id = null;
            this.changed();
        },

        unitOf(line) {
            return line.units.find((unit) => unit.id === line.unit_id) ?? { symbol: '', price: 0, factor: 1 };
        },

        serverLine(line) {
            return this.server?.lines?.find((row) => row.key === line.key) ?? null;
        },

        lineGross(line) {
            return Math.round(Number(line.qty || 0) * Number(this.unitOf(line).price || 0) * 100) / 100;
        },

        lineTotal(line) {
            return this.lineGross(line) - Number(line.discount || 0);
        },

        linePercent(line) {
            const gross = this.lineGross(line);
            return gross > 0 ? (Number(line.discount || 0) * 100) / gross : 0;
        },

        get subtotal() {
            return this.cart.lines.reduce((sum, line) => sum + this.lineGross(line), 0);
        },

        get lineDiscounts() {
            return this.cart.lines.reduce((sum, line) => sum + Number(line.discount || 0), 0);
        },

        get total() {
            return Math.max(0, this.subtotal - this.lineDiscounts - Number(this.cart.bill_discount || 0));
        },

        get tendered() {
            return this.cart.tendered === '' || this.cart.tendered === null ? null : Number(this.cart.tendered);
        },

        get balance() {
            return this.tendered === null ? null : this.tendered - this.total;
        },

        get isCash() {
            return this.cart.payment_method === 'cash';
        },

        overLimit(percent) {
            return this.config.max_discount_percent !== null && percent > Number(this.config.max_discount_percent) + 0.0001;
        },

        lineNeedsApproval(line) {
            return Number(line.discount || 0) > 0 && this.overLimit(this.linePercent(line)) && !line.approval_request_id;
        },

        get billPercent() {
            const net = this.subtotal - this.lineDiscounts;
            return net > 0 ? (Number(this.cart.bill_discount || 0) * 100) / net : 0;
        },

        get billNeedsApproval() {
            return Number(this.cart.bill_discount || 0) > 0 && this.overLimit(this.billPercent) && !this.cart.bill_approval_request_id;
        },

        get blocked() {
            if (this.cart.lines.length === 0) return 'Add items to the bill.';
            if (this.cart.lines.some((line) => this.lineNeedsApproval(line)) || this.billNeedsApproval) return 'A discount is waiting for the cashier\'s approval.';
            if (this.isCash && (this.tendered === null || this.tendered + 0.001 < this.total)) return 'Enter the amount tendered (F6).';
            return null;
        },

        quickAmounts() {
            const total = this.total;
            if (total <= 0) return [];
            const amounts = [total];
            for (const step of [100, 500, 1000, 5000]) {
                const next = Math.ceil(total / step) * step;
                if (next > total && !amounts.includes(next)) amounts.push(next);
            }
            return amounts.slice(0, 5);
        },

        setTendered(amount) {
            this.cart.tendered = String(Math.round(amount * 100) / 100);
            this.changed();
        },

        changed() {
            this.issueKey = null;
            clearTimeout(this.syncTimer);
            this.syncTimer = setTimeout(() => this.sync(), 300);
        },

        payload() {
            return {
                cart_uuid: this.cart.cart_uuid,
                price_list_id: this.cart.price_list_id,
                payment_method: this.cart.payment_method,
                tendered: this.cart.tendered === '' ? null : this.cart.tendered,
                bill_discount: this.cart.bill_discount === '' ? null : this.cart.bill_discount,
                bill_approval_request_id: this.cart.bill_approval_request_id,
                lines: this.cart.lines.map((line) => ({
                    key: line.key,
                    product_id: line.product_id,
                    variant_id: line.variant_id,
                    unit_id: line.unit_id,
                    qty: line.qty,
                    discount: line.discount === '' ? null : line.discount,
                    approval_request_id: line.approval_request_id,
                })),
            };
        },

        async sync(heartbeat = false) {
            if (heartbeat && this.syncing) return;
            this.syncing = true;

            try {
                const data = await api(this.config.urls.sync, { method: 'POST', body: this.payload() });
                this.server = data.cart;
                this.syncError = null;
            } catch (error) {
                this.syncError = error instanceof ApiError && error.status === 422 ? error.first : 'Not connected to the server. The bill is kept on this screen.';
            } finally {
                this.syncing = false;
            }
        },

        clearCart(confirmFirst = true) {
            if (confirmFirst && this.cart.lines.length > 0 && !window.confirm('Clear this bill?')) return;
            const keepUuid = this.cart.cart_uuid;
            this.cart = { ...this.emptyCart(), cart_uuid: keepUuid, price_list_id: this.cart.price_list_id };
            this.selected = -1;
            this.tender = false;
            this.changed();
            // The next bill starts with a new id once the cleared state is synced.
            setTimeout(() => {
                if (this.cart.lines.length === 0) this.cart.cart_uuid = uuid();
            }, 800);
            this.focusSearch();
        },

        // ------------------------------------------------------------------ discount approval

        async askApproval(scope, line = null) {
            this.error = null;
            const body = scope === 'line'
                ? { cart_uuid: this.cart.cart_uuid, scope, line_key: line.key, label: `${line.short_code} ${line.name}`, amount: line.discount, gross: this.lineGross(line) }
                : { cart_uuid: this.cart.cart_uuid, scope, label: 'Bill discount', amount: this.cart.bill_discount, gross: this.subtotal - this.lineDiscounts };

            try {
                const data = await api(this.config.urls.approvals, { method: 'POST', body });
                this.approvals = { ...this.approvals, [data.approval.id]: data.approval };
                this.notice = 'Approval requested. Wait for the cashier.';
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            }
        },

        pendingFor(scope, line = null) {
            return Object.values(this.approvals).find((approval) => approval.cart_uuid === this.cart.cart_uuid
                && approval.payload.scope === scope
                && (scope === 'bill' || approval.payload.line_key === line?.key)
                && Number(approval.payload.amount) >= Number(scope === 'bill' ? this.cart.bill_discount : line.discount)) ?? null;
        },

        applyApproval(approval) {
            this.approvals = { ...this.approvals, [approval.id]: approval };

            if (approval.status !== 'approved' || approval.cart_uuid !== this.cart.cart_uuid) {
                if (approval.status === 'rejected' && approval.cart_uuid === this.cart.cart_uuid && approval.decision_note !== 'Replaced by a new request') {
                    this.error = `Discount rejected by the cashier${approval.decision_note ? `: ${approval.decision_note}` : ''}.`;
                }
                return;
            }

            if (approval.payload.scope === 'bill') {
                this.cart.bill_approval_request_id = approval.id;
            } else {
                const line = this.cart.lines.find((row) => row.key === approval.payload.line_key);
                if (line) line.approval_request_id = approval.id;
            }
            this.notice = 'Discount approved.';
            this.changed();
        },

        discountChanged(line = null) {
            if (line) {
                line.approval_request_id = null;
            } else {
                this.cart.bill_approval_request_id = null;
            }
            this.changed();
        },

        // ------------------------------------------------------------------ print (F9)

        async print() {
            if (this.printing) return;
            if (this.blocked) {
                this.error = this.blocked;
                if (this.isCash && this.tendered === null) this.openTender();
                return;
            }

            this.printing = true;
            this.error = null;
            this.issueKey ??= randomKey();
            clearTimeout(this.syncTimer);

            try {
                const data = await api(this.config.urls.issue, { method: 'POST', body: { ...this.payload(), idempotency_key: this.issueKey } });
                const sale = data.sale;

                this.printed = sale;
                this.cart = this.emptyCart();
                this.server = null;
                this.selected = -1;
                this.tender = false;
                this.issueKey = null;
                this.refreshInvoices();

                if (data.print_url) {
                    this.checkPrint(data.print_url, sale);
                }

                if (this.config.can_settle && this.config.urls.cashier) {
                    setTimeout(() => (window.location.href = `${this.config.urls.cashier}?invoice=${sale.id}`), 1500);
                }
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            } finally {
                this.printing = false;
                this.focusSearch();
            }
        },

        async checkPrint(url, sale) {
            this.printWarning = null;
            const ok = await printPage(url);
            if (!ok) {
                this.printWarning = { sale, message: `Check printer: ${sale.invoice_no} was not confirmed as printed.` };
            }
        },

        async reprint(sale) {
            if (!this.config.can_reprint || !sale) return;
            try {
                const data = await api(`/api/pos/sales/${sale.id}/reprint`, { method: 'POST' });
                this.notice = `Reprinting ${sale.invoice_no} (COPY).`;
                this.printWarning = null;
                this.checkPrint(data.print_url, data.sale);
                this.refreshInvoices();
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            }
        },

        async reprintLast() {
            if (this.invoices.length === 0) await this.refreshInvoices();
            const last = this.printed ?? this.invoices[0];
            if (last) this.reprint(last);
            else this.error = 'No invoice printed on this counter today.';
        },

        async refreshInvoices() {
            try {
                this.invoices = (await api(this.config.urls.today)).invoices ?? [];
            } catch {
                // Shown on next open.
            }
        },

        async openInvoices() {
            await this.refreshInvoices();
            this.showInvoices = true;
        },

        // ------------------------------------------------------------------ hold / recall (F8)

        async hold() {
            if (this.cart.lines.length === 0) {
                this.openHolds();
                return;
            }

            const note = window.prompt('Hold this bill. Note (customer name, phone …):', '') ?? null;
            if (note === null) return;

            try {
                clearTimeout(this.syncTimer);
                await api(this.config.urls.hold, { method: 'POST', body: { ...this.payload(), note } });
                this.cart = this.emptyCart();
                this.server = null;
                this.notice = 'Bill held. Recall it with F8.';
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            }
        },

        async openHolds() {
            try {
                this.holds = (await api(this.config.urls.holds)).holds ?? [];
                this.showHolds = true;
            } catch (error) {
                this.error = error.message;
            }
        },

        async recall(hold) {
            if (this.cart.lines.length > 0) {
                this.error = 'Print, hold or clear the current bill first.';
                return;
            }
            try {
                const data = await api(`/api/pos/holds/${hold.id}/recall`, { method: 'POST' });
                this.showHolds = false;
                await this.loadCart(data.cart);
                this.notice = 'Held bill recalled.';
            } catch (error) {
                this.error = error instanceof ApiError ? error.first : error.message;
            }
        },

        /** Load a cart (recall, restore after void) and let the server price it. */
        async loadCart(cart) {
            this.cart = {
                ...this.emptyCart(),
                cart_uuid: cart.cart_uuid ?? uuid(),
                price_list_id: cart.price_list_id ?? this.config.default_price_list_id,
                payment_method: cart.payment_method ?? 'cash',
                bill_discount: Number(cart.bill_discount) > 0 ? cart.bill_discount : '',
                lines: cart.lines.map((line) => ({ ...line, qty: qty(line.qty), discount: Number(line.discount) > 0 ? line.discount : '', approval_request_id: null, units: [], name: '…', short_code: '' })),
            };
            await this.sync();
            // Fill names and units from the priced cart.
            if (this.server) {
                this.cart.lines = this.cart.lines
                    .map((line) => {
                        const priced = this.server.lines.find((row) => row.key === line.key);
                        return priced ? { ...this.lineFromServer(priced), discount: line.discount } : null;
                    })
                    .filter(Boolean);
            }
        },

        async restoreVoided(item) {
            if (this.cart.lines.length > 0) {
                this.error = 'Print, hold or clear the current bill first.';
                return;
            }
            await this.loadCart(item.cart);
            await this.dismissVoided(item);
            this.notice = `Bill of ${item.invoice_no} restored. Print it again for a new invoice number.`;
        },

        async dismissVoided(item) {
            this.voidRestores = this.voidRestores.filter((row) => row.sale_id !== item.sale_id);
            try {
                await api(`/api/pos/void-restores/${item.sale_id}/dismiss`, { method: 'POST' });
            } catch {
                // It comes back on the next poll; harmless.
            }
        },

        // ------------------------------------------------------------------ keyboard

        openTender() {
            this.tender = true;
            this.$nextTick(() => {
                this.$refs.tendered?.focus();
                this.$refs.tendered?.select();
            });
        },

        customer() {
            this.notice = 'Customer accounts arrive in Phase 4. Bills are cash customers for now.';
        },

        keydown(event) {
            const typingInSearch = document.activeElement === this.$refs.search;
            const inField = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);

            if (!typingInSearch && inField) return;

            if (typingInSearch && this.query !== '') return;

            if (event.key === '+' || event.key === 'Add') {
                event.preventDefault();
                this.step(this.selected >= 0 ? this.selected : this.cart.lines.length - 1, 1);
            } else if (event.key === '-' || event.key === 'Subtract') {
                event.preventDefault();
                this.step(this.selected >= 0 ? this.selected : this.cart.lines.length - 1, -1);
            } else if (event.key === 'Delete') {
                event.preventDefault();
                if (this.selected >= 0) this.remove(this.selected);
            } else if (event.key === 'PageUp') {
                event.preventDefault();
                this.selected = Math.max(0, this.selected - 1);
            } else if (event.key === 'PageDown') {
                event.preventDefault();
                this.selected = Math.min(this.cart.lines.length - 1, this.selected + 1);
            }
        },

        money,
        qty,
    };
}
