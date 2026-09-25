/**
 * Line editor where every line takes stock out of (or puts it into) a batch:
 * supplier returns and stock adjustments. Quantities are in the product's base unit.
 *
 *   <form x-data="batchLines({ lines: [...], batchesUrl: '/api/inventory/batches', allowGeneral: true })"
 *         @product-picked="addLine($event.detail)"> ... </form>
 *
 * allowGeneral: offer "General stock" (no batch) — adjustments only; supplier returns
 * must name the batch the goods came from.
 */
export default function batchLines({ lines = [], batchesUrl, allowGeneral = false }) {
    let nextKey = 1;

    // Adjustment quantities arrive signed ("-3"); the editor shows a direction and a positive number.
    const row = (line) => {
        const qty = line.qty === undefined || line.qty === null || line.qty === '' ? null : Number(line.qty);

        return {
            batches: [],
            ...line,
            key: nextKey++,
            variant_id: line.variant_id ?? null,
            batch_id: line.batch_id ? String(line.batch_id) : '',
            direction: allowGeneral && qty !== null && qty > 0 ? '+' : '-',
            qty: qty === null ? '' : String(Math.abs(qty)),
        };
    };

    return {
        lines: lines.map(row),
        allowGeneral,
        message: '',

        async addLine(item) {
            const params = new URLSearchParams({ product_id: item.id });
            if (item.variant_id) {
                params.set('variant_id', item.variant_id);
            }

            const response = await fetch(`${batchesUrl}?${params}`, { headers: { Accept: 'application/json' } });
            const batches = response.ok ? (await response.json()).batches : [];

            if (!allowGeneral && batches.length === 0) {
                this.message = `${item.name} has no stock to return.`;
                return;
            }

            const line = row({
                product_id: item.id,
                variant_id: item.variant_id,
                short_code: item.short_code,
                name: item.name,
                base_unit: item.base_unit,
                batches,
                batch_id: batches.length === 1 ? String(batches[0].id) : '',
            });

            this.lines.push(line);
            this.message = '';
            this.$nextTick(() => document.getElementById(`qty-${line.key}`)?.focus());
        },

        removeLine(index) {
            this.lines.splice(index, 1);
        },

        batchOf(line) {
            return line.batches.find((batch) => String(batch.id) === String(line.batch_id));
        },

        signedQty(line) {
            if (line.qty === '' || line.qty === null) {
                return '';
            }
            return line.direction === '+' ? String(line.qty) : `-${line.qty}`;
        },

        qtyText(qty) {
            return Number(qty ?? 0).toLocaleString('en-LK', { maximumFractionDigits: 3 });
        },
    };
}
