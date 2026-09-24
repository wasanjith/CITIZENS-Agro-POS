/**
 * Back-office product search box (top bar). Uses the same endpoint as the POS:
 * GET /api/pos/search?q= → { items: [{ key, short_code, name, name_si, price, unit, url }] }
 *
 *   <div x-data="productSearch({ url: '/api/pos/search' })"> ... </div>
 */
export default function productSearch({ url }) {
    return {
        url,
        query: '',
        items: [],
        open: false,
        highlighted: 0,
        controller: null,

        async search() {
            const query = this.query.trim();

            if (query.length < 2) {
                this.items = [];
                this.open = false;
                return;
            }

            this.controller?.abort();
            this.controller = new AbortController();

            try {
                const response = await fetch(`${this.url}?limit=8&q=${encodeURIComponent(query)}`, {
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

        go(item = this.items[this.highlighted]) {
            if (item) {
                window.location.href = item.url;
            }
        },

        price(item) {
            if (item.price === null) {
                return '';
            }
            const amount = Number(item.price).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return `Rs. ${amount} / ${item.unit?.symbol ?? ''}`;
        },
    };
}
