<?php

namespace App\Console\Commands;

use App\Services\Peru\ConsultaDni;
use App\Services\Peru\ConsultaRuc;
use App\Services\Peru\Proveedor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Prueba la consulta a SUNAT o RENIEC y dice QUE fallo, no solo que fallo.
 *
 * Desde la pantalla, todos los problemas se ven igual: «No se pudo consultar».
 * Pero por debajo son cosas muy distintas y cada una se arregla de otra forma:
 * el token equivocado, el proveedor equivocado, un cortafuegos, PHP sin
 * certificados... Adivinar cual es cuesta una tarde.
 *
 * Esto lo separa. Ademas del resultado enseña el proveedor, la URL exacta, el
 * codigo que devolvio y **cuanto tardo**, que es el dato que mas dice: una
 * respuesta en decimas con error es el servidor contestando que no; una que
 * tarda justo lo que dura el plazo de espera es que no llego nunca, y entonces
 * el problema no es el token sino la red.
 *
 *   php artisan docufiz:probar-consulta dni 46027897
 *   php artisan docufiz:probar-consulta ruc 20512345678
 */
#[AsCommand(name: 'docufiz:probar-consulta')]
class ProbarConsultaPeruCommand extends Command
{
    protected $signature = 'docufiz:probar-consulta {tipo : dni o ruc} {numero}';

    protected $description = 'Prueba la consulta a RENIEC o SUNAT y explica que paso';

    public function handle(Proveedor $proveedor, ConsultaDni $dni, ConsultaRuc $ruc): int
    {
        $tipo = strtolower((string) $this->argument('tipo'));

        if (! in_array($tipo, ['dni', 'ruc'], true)) {
            $this->error('El tipo tiene que ser «dni» o «ruc».');

            return self::FAILURE;
        }

        $numero = (string) $this->argument('numero');

        // Sin esto el diagnostico puede contestar desde la cache y decir que
        // todo va bien sin haber llamado a nadie, que es justo lo contrario de
        // lo que se le pide. Se olvida siempre.
        \Illuminate\Support\Facades\Cache::forget(ConsultaDni::claveDeCache(
            preg_replace('/\D/', '', $numero) ?? ''
        ));

        $this->line('');
        $this->line('  Proveedor: <options=bold>' . $proveedor->nombre() . '</>');
        $this->line('  Token:     ' . ($proveedor->hayToken()
            ? '<fg=green>configurado</>'
            : '<fg=red>SIN CONFIGURAR</> — sin el no se consulta nada y todo se escribe a mano'));
        $this->line('  Espera:    ' . config('services.peru_lookup.timeout', 6) . ' s');
        $this->line('');

        // Se apunta la peticion de verdad para poder enseñar la URL exacta: la
        // mitad de los problemas son que se esta llamando a donde no es.
        $url = null;
        Http::globalRequestMiddleware(function ($peticion) use (&$url) {
            $url = (string) $peticion->getUri();

            return $peticion;
        });

        $arranque = microtime(true);
        $resultado = $tipo === 'dni' ? $dni->buscar($numero) : $ruc->buscar($numero);
        $tardo = microtime(true) - $arranque;

        $this->line('  URL:       ' . ($url ?? '<fg=yellow>no se llego a llamar</>'));
        $this->line(sprintf('  Tardo:     %.2f s', $tardo));
        $this->line('');

        $this->explicar($resultado, $tardo);

        return $resultado['estado'] === 'encontrado' ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $resultado */
    private function explicar(array $resultado, float $tardo): void
    {
        $plazo = (float) config('services.peru_lookup.timeout', 6);

        match ($resultado['estado']) {
            'encontrado' => $this->info('  ✓ ENCONTRADO: ' . json_encode(
                collect($resultado)->except('estado')->all(),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            )),

            'sin_configurar' => $this->warn('  Falta el token. Pon `PERU_LOOKUP_TOKEN` en el .env y corre `php artisan config:clear`.'),

            'no_encontrado' => $this->warn('  El proveedor contesto que ese numero no existe. Prueba con otro que sepas que si.'),

            default => $this->fallo($tardo, $plazo),
        };

        $this->line('');
        $this->line('  El detalle exacto queda en storage/logs/laravel-' . now()->format('Y-m-d') . '.log');
        $this->line('');
    }

    private function fallo(float $tardo, float $plazo): void
    {
        $this->error('  ✗ No se pudo consultar.');
        $this->line('');

        // Aqui esta el valor de todo esto: el tiempo separa dos causas que
        // desde la pantalla se ven exactamente igual.
        if ($tardo >= $plazo * 0.9) {
            $this->line('  Tardo lo que dura el plazo de espera, asi que <options=bold>la respuesta no llego nunca</>.');
            $this->line('  Eso NO es el token: un token malo contesta en decimas. Mira por ese lado:');
            $this->line('');
            $this->line('    · El cortafuegos o el proxy de la red bloquean la salida.');
            $this->line('    · PHP sin certificados (el clasico en Windows con Laragon o XAMPP):');
            $this->line('      en php.ini, `curl.cainfo` y `openssl.cafile` apuntando a un cacert.pem.');
            $this->line('    · Sin salida a internet en esta maquina.');
            $this->line('');
            $this->line('  Para separarlo del sistema, prueba a pelo desde la misma maquina:');
            $this->line('    curl -v "https://api.decolecta.com/v1/reniec/dni?numero=46027897" -H "Authorization: Bearer TU_TOKEN"');

            return;
        }

        $this->line('  Contesto rapido, asi que <options=bold>si llego</> y devolvio un error. Lo mas probable:');
        $this->line('');
        $this->line('    · 401 → el token no vale, o es de OTRO proveedor.');
        $this->line('            Los de Decolecta empiezan por `sk_`. Revisa `PERU_LOOKUP_PROVIDER`.');
        $this->line('    · 403 → sin cuota o sin plan para ese servicio.');
        $this->line('    · 5xx → el proveedor esta caido; no hay nada que arreglar de este lado.');
    }
}
