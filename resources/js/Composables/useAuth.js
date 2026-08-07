import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Permisos del usuario actual, derivados de los shared props de Inertia.
 * Espeja el `Gate::before` del backend: super pasa todas las checks.
 *
 *   const { can, isSuper, canSeeAudit } = useAuth();
 *   if (can('regions.create')) { ... }
 */
export function useAuth() {
    const page = usePage();

    const roles = computed(() => page.props.auth?.user?.roles ?? []);

    const can = (permission) => {
        const u = page.props.auth?.user;
        if (!u) return false;
        if (u.roles?.includes('super')) return true;
        return u.permissions?.includes(permission) ?? false;
    };

    const isSuper = computed(() => roles.value.includes('super'));

    const canSeeAudit = computed(() =>
        roles.value.includes('super') || roles.value.includes('admin'),
    );

    // Usuario acotado a su cartera (customer_user): solo-lectura en Clientes.
    // El front lo usa para esconder crear/duplicar de cliente (coherente con el
    // override del backend), aunque su perfil tenga customers.create marcado.
    const isCustomerRestricted = computed(() =>
        !!page.props.auth?.user?.is_customer_restricted,
    );

    return { can, isSuper, canSeeAudit, isCustomerRestricted };
}
