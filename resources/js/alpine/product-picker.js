/**
 * Product search box for document line editors (purchase orders, GRNs, adjustments …).
 * Uses the POS search endpoint and dispatches `product-picked` with the chosen item, so
 * the surrounding form adds a line:
 *
 *   <div x-data="poForm(...)" @product-picked="addLine($event.detail)">
 *       <x-ui.product-picker />
 *   </div>
 *
 * Items: { id, variant_id, short_code, name, name_si, units: [{ id, symbol, factor, ... }], stock, base_unit, ... }
 *
 * With `supplier: true` (purchase orders) it lists only the chosen supplier's products while
 * "only this supplier" is ticked; the form announces the supplier with
 * window.dispatchEvent(new CustomEvent('picker-supplier', { detail: { id, name } })).
 */
export default function productPicker({ url, supplier = false, supplierId = null, supplierName = '' }) {
    return {
        url,
        supplier,
        supplierId,
        supplierName,
        onlySupplier: true,
        query: '',
        items: [],
        open: false,
        highlighted: 0,
        controller: null,

        get filtersBySupplier() {
            return this.supplier && this.onlySupplier && Boolean(this.supplierId);
        },

        setSupplier({ id, name }) {
            this.supplierId = id || null;
            this.supplierName = name || '';
            this.items = [];
            this.open = false;
        },

        async search() {
            const query = this.query.trim();

            // With a supplier chosen, an empty box lists all of that supplier's products.
            if (query.length < 1 && !this.filtersBySupplier) {
                this.items = [];
                this.open = false;
                return;
            }

            this.controller?.abort();
            this.controller = new AbortController();

            const supplierFilter = this.filtersBySupplier ? `&supplier_id=${encodeURIComponent(this.supplierId)}` : '';
            const limit = query.length < 1 ? 50 : 10;

            try {
                const response = await fetch(`${this.url}?limit=${limit}&q=${encodeURIComponent(query)}${supplierFilter}`, {
                    headers: { Accept: 'application/json' },
                    signal: this.controller.signal,
                });

                if (!response.ok) {
                    return;
                }

                this.items = (await response.json()).items;
                this.highlighted = 0;
                this.open = true;
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.items = [];
                }
            }
        },

        move(step) {
            if (this.items.length === 0) {
                return;
            }
            this.highlighted = (this.highlighted + step + this.items.length) % this.items.length;
        },

        pick(item = this.items[this.highlighted]) {
            if (!item) {
                return;
            }
            this.$dispatch('product-picked', item);
            this.query = '';
            this.items = [];
            this.open = false;
            this.$nextTick(() => this.$refs.input?.focus());
        },

        emptyText() {
            if (!this.filtersBySupplier) {
                return 'No products found.';
            }
            return this.query.trim() === ''
                ? `No products linked to ${this.supplierName || 'this supplier'} yet. Untick "Only this supplier's products" to search all.`
                : `No ${this.supplierName || 'supplier'} product matches. Untick "Only this supplier's products" to search all.`;
        },

        stockText(item) {
            if (item.stock === null || item.stock === undefined) {
                return '';
            }
            return `${Number(item.stock).toLocaleString('en-LK', { maximumFractionDigits: 3 })} ${item.base_unit ?? ''} in stock`;
        },
    };
}
