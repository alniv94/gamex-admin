import { createInertiaApp } from '@inertiajs/vue3';
import AdminLayout from './layouts/AdminLayout.vue';
import { createAdminVuetify } from './plugins/vuetify';

void createInertiaApp({
    title: (title) => (title ? `${title} · GameXSports Admin` : 'GameXSports Admin'),
    layout: (name) => (name.startsWith('auth/') ? null : AdminLayout),
    withApp: (app) => {
        app.use(createAdminVuetify());
    },
    progress: { color: '#ff8102' },
});
