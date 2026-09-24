/**
 * Searchable select backed by a JSON endpoint returning [{ id, label, hint? }].
 *
 *   <div x-data="searchSelect({ url: '/api/...', value: 1, label: 'Current' })"> ... </div>
 *
 * Used by <x-ui.search-select>.
 */
export default function searchSelect({ url, value = null, label = '', minLength = 1 }) {
    return {
        url,
        value,
        label,
        query: '',
        results: [],
        open: false,
        loading: false,
        highlighted: 0,
        controller: null,

        async search() {
            if (this.query.length < minLength) {
                this.results = [];
                return;
            }

            this.controller?.abort();
            this.controller = new AbortController();
            this.loading = true;

            try {
                const separator = this.url.includes('?') ? '&' : '?';
                const response = await fetch(`${this.url}${separator}q=${encodeURIComponent(this.query)}`, {
                    headers: { Accept: 'application/json' },
                    signal: this.controller.signal,
                });
                this.results = await response.json();
                this.highlighted = 0;
                this.open = true;
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.results = [];
                }
            } finally {
                this.loading = false;
            }
        },

        choose(result) {
            this.value = result.id;
            this.label = result.label;
            this.query = '';
            this.open = false;
            this.$dispatch('search-select-changed', result);
        },

        clear() {
            this.value = null;
            this.label = '';
        },

        move(step) {
            if (this.results.length === 0) {
                return;
            }
            this.highlighted = (this.highlighted + step + this.results.length) % this.results.length;
        },

        chooseHighlighted() {
            if (this.results[this.highlighted]) {
                this.choose(this.results[this.highlighted]);
            }
        },
    };
}
