import { onMounted, onBeforeUnmount } from 'vue';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import axios from 'axios';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/Plugins/i18n';

/**
 * useModuleTour — composable reutilizable para tours de onboarding por módulo.
 *
 * Cada módulo define su set de pasos y un `module` slug único. El composable:
 *   1. Lee `auth.user.module_tours` del shared prop. Si ya está marcado, no
 *      dispara nada. Si no, espera `delay` ms (DOM pintado) y arranca.
 *   2. Marca completado al cerrar (POST /user-prefs/module-tours/complete).
 *   3. Provee `restart()` para botón "Ver tour" sin re-marcar completado.
 *
 * `steps` es función para evaluar selectores al disparar (no en setup).
 */
export function useModuleTour({ module, steps, autoStart = true, delay = 600 }) {
    const page = usePage();
    const { t } = useI18n();

    let driverInstance = null;
    let timer = null;

    const hasSeenTour = () => Boolean(page.props.auth?.user?.module_tours?.[module]);

    const markComplete = async () => {
        try {
            await axios.post(route('user_prefs.module_tours.complete'), { module });
            const u = page.props.auth?.user;
            if (u) {
                u.module_tours = { ...(u.module_tours ?? {}), [module]: new Date().toISOString() };
            }
        } catch (_) { /* no bloquea UI */ }
    };

    // Filtra pasos cuyo selector no exista en el DOM ahora (ej. bulk-bar solo
    // aparece con selección — no debería ser un step para usuarios nuevos).
    const visibleSteps = () => (steps() || []).filter(
        (s) => !s.element || document.querySelector(s.element),
    );

    const buildConfig = ({ markOnDestroy } = { markOnDestroy: false }) => ({
        animate: true,
        showProgress: true,
        allowClose: true,
        nextBtnText: t('global.tour_next'),
        prevBtnText: t('global.tour_prev'),
        doneBtnText: t('global.tour_done'),
        progressText: '{{current}} / {{total}}',
        steps: visibleSteps(),
        ...(markOnDestroy ? { onDestroyed: () => { markComplete(); } } : {}),
    });


    const start = () => {
        const cfg = buildConfig({ markOnDestroy: true });
        if (cfg.steps.length === 0) return;
        driverInstance = driver(cfg);
        driverInstance.drive();
    };

    /** Re-dispara el tour manualmente sin re-marcar como visto. */
    const restart = () => {
        const cfg = buildConfig({ markOnDestroy: false });
        if (cfg.steps.length === 0) return;
        driver(cfg).drive();
    };

    if (autoStart) {
        onMounted(() => {
            if (hasSeenTour()) return;
            timer = setTimeout(start, delay);
        });
        onBeforeUnmount(() => {
            if (timer) clearTimeout(timer);
            if (driverInstance) driverInstance.destroy();
        });
    }

    return { start, restart, hasSeenTour };
}
