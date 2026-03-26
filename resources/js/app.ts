import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initializeTheme } from './composables/useAppearance';
import { useAuthStore } from './stores/auth';

// ─── Google Maps ──────────────────────────────────────────────────────────────
((g) => {
    var h,
        a,
        k,
        p = 'The Google Maps JavaScript API',
        c = 'google',
        l = 'importLibrary',
        q = '__ib__',
        m = document,
        b = window;
    b = b[c] || (b[c] = {});
    var d = b.maps || (b.maps = {}),
        r = new Set(),
        e = new URLSearchParams(),
        u = () =>
            h ||
            (h = new Promise(async (f, n) => {
                await (a = m.createElement('script'));
                e.set('libraries', [...r] + '');
                for (k in g)
                    e.set(
                        k.replace(/[A-Z]/g, (t) => '_' + t[0].toLowerCase()),
                        g[k],
                    );
                e.set('key', import.meta.env.VITE_GOOGLE_PLACES_API_KEY);
                e.set('v', 'weekly');
                a.src = `https://maps.${c}apis.com/maps/api/js?` + e;
                a.onerror = () => n(Error(p + ' could not load.'));
                a.nonce = m.querySelector('script[nonce]')?.nonce || '';
                m.head.append(a);
                d[l] = f;
            }));
    d[l] = (f, ...n) => r.add(f) && u().then(() => d[l](f, ...n));
})({ key: import.meta.env.VITE_GOOGLE_PLACES_API_KEY });
(window as any).google.maps.importLibrary('places');
// ────────────────────────────────────────

// Echo / Reverb
configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: false,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            'X-CSRF-TOKEN':
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '',
        },
    },
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();

        const vueApp = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia);

        // Fetch sanctum user once on boot
        const auth = useAuthStore(pinia);
        auth.fetchUser().then(() => {
            vueApp.mount(el);
        });
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
