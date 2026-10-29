import { createInertiaApp } from '@inertiajs/vue3';
import { createAdminVuetify } from './plugins/vuetify';

void createInertiaApp({
    title: (title) => (title ? `${title} · GameXSports Admin` : 'GameXSports Admin'),
    withApp: (app) => {
        app.use(createAdminVuetify());
    },
    progress: { color: '#ff8102' },
});
