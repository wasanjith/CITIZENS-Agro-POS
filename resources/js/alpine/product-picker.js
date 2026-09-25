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
 */
export default function productPicker({ url }) {
    return {
        url,
        query: '',
        items: [],
        open: false,
        highlighted: 0,
        controller: null,

        async search() {
            const query = this.query.trim();

            if (query.length < 1) {
                this.items = [];
                this.open = false;
                return;
            }

            this.controller?.abort();
            this.controller = new AbortController();

            try {
                const response = await fetch(`${this.url}?limit=10&q=${encodeURIComponent(query)}`, {
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

        stockText(item) {
            if (item.stock === null || item.stock === undefined) {
                return '';
            }
            return `${Number(item.stock).toLocaleString('en-LK', { maximumFractionDigits: 3 })} ${item.base_unit ?? ''} in stock`;
        },
    };
}
