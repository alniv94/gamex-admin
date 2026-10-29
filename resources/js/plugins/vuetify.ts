import 'vuetify/styles';
import { createVuetify } from 'vuetify';

export function createAdminVuetify() {
    return createVuetify({
        display: {
            mobileBreakpoint: 1264,
            thresholds: { xs: 0, sm: 600, md: 960, lg: 1264, xl: 1904, xxl: 2560 },
        },
        theme: {
            defaultTheme: 'adminLight',
            themes: {
                adminLight: {
                    dark: false,
                    colors: {
                        primary: '#00b6fe',
                        'on-primary': '#ffffff',
                        secondary: '#757575',
                        accent: '#000000',
                        background: '#f5f5f5',
                        surface: '#ffffff',
                        'on-surface': '#000000',
                        success: '#4caf50',
                        error: '#ff1744',
                    },
                },
            },
        },
        defaults: {
            VBtn: { rounded: 'sm', elevation: 0, height: 36 },
            VTextField: {
                variant: 'outlined',
                density: 'compact',
                color: 'primary',
                hideDetails: 'auto',
            },
            VCard: { rounded: 'lg', elevation: 0 },
        },
    });
}
