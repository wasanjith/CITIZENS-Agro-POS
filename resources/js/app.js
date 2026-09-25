import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import mask from '@alpinejs/mask';

import './echo';
import batchLines from './alpine/batch-lines';
import hotkey from './alpine/hotkey';
import productPicker from './alpine/product-picker';
import productSearch from './alpine/product-search';
import searchSelect from './alpine/search-select';
import { confirmPrinted } from './pos/client';
import liveBilling from './pos/live-billing';
import posCounter from './pos/counter';

Alpine.plugin(focus);
Alpine.plugin(mask);
Alpine.plugin(hotkey);
Alpine.data('searchSelect', searchSelect);
Alpine.data('productSearch', productSearch);
Alpine.data('productPicker', productPicker);
Alpine.data('batchLines', batchLines);
Alpine.data('posCounter', posCounter);
Alpine.data('liveBilling', liveBilling);

// Print pages loaded in hidden iframes report back; this confirms their print job
// (print log, printer "last test", "Check printer" hint).
window.addEventListener('message', confirmPrinted);

window.Alpine = Alpine;
Alpine.start();
