import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import mask from '@alpinejs/mask';

import './echo';
import batchLines from './alpine/batch-lines';
import hotkey from './alpine/hotkey';
import productPicker from './alpine/product-picker';
import productSearch from './alpine/product-search';
import searchSelect from './alpine/search-select';

Alpine.plugin(focus);
Alpine.plugin(mask);
Alpine.plugin(hotkey);
Alpine.data('searchSelect', searchSelect);
Alpine.data('productSearch', productSearch);
Alpine.data('productPicker', productPicker);
Alpine.data('batchLines', batchLines);

window.Alpine = Alpine;
Alpine.start();
