import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import mask from '@alpinejs/mask';

import './echo';
import hotkey from './alpine/hotkey';
import productSearch from './alpine/product-search';
import searchSelect from './alpine/search-select';

Alpine.plugin(focus);
Alpine.plugin(mask);
Alpine.plugin(hotkey);
Alpine.data('searchSelect', searchSelect);
Alpine.data('productSearch', productSearch);

window.Alpine = Alpine;
Alpine.start();
