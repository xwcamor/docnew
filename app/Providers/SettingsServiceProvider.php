<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Lee Settings de la BD al boot y sobreescribe los valores de `config(...)`
 * que afectan al framework: nombre de la app (visible en login/header/emails),
 * remitente de los emails y lifetime de la sesion. Permite que super cambie
 * estos valores desde la UI sin redeploy.
 *
 * Las credenciales SMTP (MAIL_USERNAME, MAIL_PASSWORD, MAIL_HOST, etc.) y el
 * MAIL_FROM_ADDRESS viven SOLO en `.env` — son secretos y/o estan atados a la
 * cuenta autenticada del SMTP (Gmail exige que from.address == username). El
 * NOMBRE del remitente (MAIL_FROM_NAME) sigue al setting `app.name` para que
 * cambiar la marca desde la UI se vea reflejado en los emails sin redeploy.
 *
 * IMPORTANTE: estos valores se leen UNA vez por proceso PHP. Si cambias el
 * setting en la UI, los procesos nuevos toman el valor nuevo. Los procesos
 * en memoria (queue:work, octane) requieren restart — `php artisan queue:restart`.
 *
 * Tolerante a setups iniciales: si la tabla `settings` aun no existe (primera
 * migracion antes del seeder) el override se saltea silenciosamente. Sin esto
 * `php artisan migrate` reventaria al arrancar el framework.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Defensive: la tabla puede no existir en el primer `migrate`.
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $this->overrideAppName();
        $this->overrideSessionLifetime();
    }

    protected function overrideAppName(): void
    {
        $name = Setting::get('app.name', null);
        if (is_string($name) && $name !== '') {
            config(['app.name' => $name]);
            // El nombre del remitente sigue al nombre de la app — al cambiar
            // la marca desde Settings, los emails se actualizan sin tocar .env.
            config(['mail.from.name' => $name]);
        }
    }

    protected function overrideSessionLifetime(): void
    {
        $minutes = Setting::getInt('security.session_lifetime_minutes', 0);
        if ($minutes > 0) {
            config(['session.lifetime' => $minutes]);
        }
    }
}
