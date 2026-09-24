import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import mask from '@alpinejs/mask';

import './echo';
import hotkey from './alpine/hotkey';
import searchSelect from './alpine/search-select';

Alpine.plugin(focus);
Alpine.plugin(mask);
Alpine.plugin(hotkey);
Alpine.data('searchSelect', searchSelect);

window.Alpine = Alpine;
Alpine.start();
