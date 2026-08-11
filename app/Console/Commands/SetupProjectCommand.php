<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Deja el sistema listo de una sola orden.
 *
 *   php artisan setup:project             la base desde cero, con datos de ejemplo
 *   php artisan setup:project --datos     ademas trae todo lo del sistema anterior
 *
 * El segundo es el que interesa cuando se esta preparando la migracion en
 * local: rehace la base, siembra, crea las plantillas AST/PTF/EPP/IHM con los
 * catalogos reales y trae empresas, usuarios, personas, planes, formatos
 * llenados y firmas. Sin tener que acordarse del orden.
 */
#[AsCommand(
    name: 'setup:project',
    description: 'Rehace la base desde cero, migra y siembra. Con --datos trae ademas el sistema anterior.'
)]
class SetupProjectCommand extends Command
{
    protected $signature = 'setup:project
        {--datos : Trae tambien los datos del sistema anterior (necesita LEGACY_DB_* en el .env)}
        {--desde= : Carpeta con el public/images_uploads de la v1, para copiar las fotos}';

    public function handle(): int
    {
        // Candado duro: esto borra todas las tablas. En produccion no se corre
        // ni por equivocacion. Complementa a DB::prohibitDestructiveCommands
        // del AppServiceProvider, que ataja el migrate:fresh pero no el
        // DROP DATABASE crudo por PDO, que no pasa por artisan.
        if (app()->environment('production')) {
            $this->error('En produccion no. Para cambios incrementales: php artisan migrate.');

            return self::FAILURE;
        }

        $conexion = Config::get('database.default');
        $cfg = Config::get("database.connections.{$conexion}");
        $base = $cfg['database'] ?? null;

        if (! $base) {
            $this->error("La conexion [{$conexion}] no tiene base configurada.");

            return self::FAILURE;
        }

        $this->warn(sprintf('Entorno [%s] · motor [%s] · base [%s]', app()->environment(), $conexion, $base));
        $this->warn(sprintf('Se van a BORRAR TODAS LAS TABLAS de [%s] y volver a crearlas.', $base));

        // Cual es la base que se borra y cual no. Es la duda que sale sola al
        // leer «se van a borrar todas las tablas» en un comando que se llama
        // «--datos» y que habla del sistema anterior en la misma pantalla.
        //
        // Se dice aqui y no en la documentacion porque es aqui donde da miedo.
        if ($this->option('datos')) {
            $legacy = Config::get('database.connections.legacy.database');

            $this->line(sprintf(
                '  La base anterior [%s] SOLO SE LEE: de ahi no se borra ni se modifica nada.',
                $legacy ?: 'legacy',
            ));
        }

        match ($conexion) {
            'mysql' => $this->recrearMysql($cfg),
            'pgsql' => $this->line('PostgreSQL: no se hace DROP DATABASE (pide superusuario). Basta migrate:fresh.'),
            default => $this->warn("Motor [{$conexion}] sin trato especial: se confia en migrate:fresh."),
        };

        $this->newLine();
        $this->info('── Migraciones y datos de ejemplo ──');
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true], $this->getOutput());

        if ($this->option('datos') && ! $this->traerDatosDeLaV1()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->resumen();
        $this->newLine();
        $this->info('Listo.');

        return self::SUCCESS;
    }

    /**
     * Trae el sistema anterior entero. `docufiz:migrate-data todo` ya crea las
     * plantillas por su cuenta, asi que aqui es una sola llamada.
     */
    protected function traerDatosDeLaV1(): bool
    {
        $this->newLine();
        $this->info('── Datos del sistema anterior ──');

        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable) {
            $this->error('  No se pudo conectar a la base anterior. Revisa LEGACY_DB_* en el .env.');
            $this->line('  La base nueva quedo creada y sembrada; los datos se pueden traer despues con:');
            $this->line('    php artisan docufiz:migrate-data todo');

            return false;
        }

        // Las plantillas NO se llaman desde aqui.
        //
        // Estaban, con el argumento de que «explicito se lee mejor y el orden
        // queda a la vista». El efecto era el contrario: `migrate-data todo`
        // las prepara igualmente (`prepararFormatos()`), asi que el bloque
        // salia DOS VECES en la salida —las mismas cuatro lineas de «ya existe,
        // se omite» y la misma tabla de cuatro filas— y quien miraba el log no
        // podia saber si se estaba importando dos veces o si se habia colgado.
        //
        // No duplicaba trabajo (es idempotente) pero si duplicaba la duda, que
        // en un corte de datos es igual de caro. Manda `migrate-data`, que es
        // quien tiene la dependencia y quien se para si falla.
        $argumentos = ['paso' => 'todo'];

        if ($this->option('desde')) {
            $argumentos['--desde'] = $this->option('desde');
        }

        return $this->call('docufiz:migrate-data', $argumentos) === self::SUCCESS;
    }

    /** Que quedo en la base, para no tener que ir a mirarlo. */
    protected function resumen(): void
    {
        $filas = [];

        foreach ([
            'Empresas' => 'companies',
            'Personas' => 'people',
            'Usuarios' => 'users',
            'Planes de trabajo' => 'work_plans',
            'Formatos llenados' => 'form_submissions',
            'Respuestas' => 'form_answers',
            'Firmas' => 'signature_events',
        ] as $que => $tabla) {
            $filas[] = [$que, number_format(DB::table($tabla)->count())];
        }

        $this->table(['', 'Cuantos'], $filas);
    }

    protected function recrearMysql(array $cfg): void
    {
        try {
            $pdo = new PDO("mysql:host={$cfg['host']};port={$cfg['port']}", $cfg['username'], $cfg['password']);
            $pdo->exec("DROP DATABASE IF EXISTS `{$cfg['database']}`;");
            $pdo->exec("CREATE DATABASE `{$cfg['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $this->line("Base `{$cfg['database']}` recreada.");
        } catch (\Exception $e) {
            $this->error('No se pudo recrear la base MySQL: ' . $e->getMessage());
        }
    }
}
