import { computed, ref } from 'vue';
import { useDisplay } from 'vuetify';

const collapsed = ref(false);

export function useAdminSidebar() {
    const { mobile } = useDisplay();
    const drawer = ref(!mobile.value);
    const rail = computed(() => !mobile.value && collapsed.value);
    const expanded = computed(() => (mobile.value ? drawer.value : !rail.value));
    const toggleLabel = computed(() => {
        if (mobile.value) {
            return drawer.value ? 'Close navigation' : 'Open navigation';
        }

        return rail.value ? 'Expand navigation' : 'Collapse navigation';
    });
    const toggleIcon = computed(() => {
        if (mobile.value) {
            return drawer.value ? 'mdi-close' : 'mdi-menu';
        }

        return rail.value ? 'mdi-chevron-right' : 'mdi-chevron-left';
    });

    function toggleSidebar() {
        if (mobile.value) {
            drawer.value = !drawer.value;
        } else {
            collapsed.value = !collapsed.value;
        }
    }

    function expandSidebar() {
        collapsed.value = false;
    }

    return { drawer, rail, expanded, toggleLabel, toggleIcon, toggleSidebar, expandSidebar };
}
