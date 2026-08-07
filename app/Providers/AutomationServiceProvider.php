<?php

namespace App\Providers;

use App\Services\Automations\Actions\EmailAction;
use App\Services\Automations\Actions\InAppNotificationAction;
use App\Services\Automations\ActionRegistry;
use App\Services\Automations\DataSourceRegistry;
use App\Services\Automations\DataSources\CustomersDataSource;
use App\Services\Automations\DataSources\SubscriptionsDataSource;
use App\Services\Automations\DataSources\TransformersDataSource;
use Illuminate\Support\ServiceProvider;

/**
 * AutomationServiceProvider — registra los registries de data sources y
 * actions como singletons. Para agregar uno nuevo: créelo y agréguelo aquí.
 *
 * Data sources actuales:
 *   - customers       → disponible para admin y super (negocio del workspace)
 *   - transformers    → parque de equipos; alertas por indice de salud, estado,
 *                       tratamiento de aceite, etc. (negocio del workspace)
 *   - subscriptions   → solo super (cross-tenant, billing). El DataSourceRegistry
 *                       lo filtra en catalog() segun el rol del user.
 *
 * NO se incluye un UsersDataSource: automatizar sobre la lista de usuarios no
 * aporta valor practico — admin ya ve a su equipo desde el listado, y super
 * tiene reportes mejores en el modulo de Tenants. Si se necesita en el futuro
 * (alertas de inactividad por ejemplo) se puede agregar como reportes manuales.
 *
 * El AutomationRunner se resuelve automáticamente con DI (constructor).
 */
class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DataSourceRegistry::class, function () {
            $registry = new DataSourceRegistry();
            $registry->register(new CustomersDataSource());
            $registry->register(new TransformersDataSource());
            $registry->register(new SubscriptionsDataSource());
            return $registry;
        });

        $this->app->singleton(ActionRegistry::class, function () {
            $registry = new ActionRegistry();
            $registry->register(new EmailAction());
            $registry->register(new InAppNotificationAction());
            return $registry;
        });
    }
}
