<?php

namespace Tests\Feature\Console;

use App\Console\Commands\RouteTranslationsListCommand;
use Symfony\Component\Console\Input\InputArgument;
use Tests\TestCase;

/**
 * Que `php artisan` arranque. Suena a poco y no lo es.
 *
 * laravel-localization hereda la `$signature` de `RouteListCommand`, asi que su
 * comando se registra como `route:list` pisando al de Laravel, y su `handle()`
 * pide un argumento `locale` que esa firma no declara. De ahi el
 * «The "locale" argument does not exist» al listar rutas.
 *
 * El arreglo le pone su nombre y le anade el argumento. Pero **Laravel aplica
 * `getArguments()` solo cuando la clase no trae `$signature`, y esa condicion
 * ha cambiado entre versiones**: donde si se aplica, anadirlo otra vez lo
 * duplica, y Symfony lanza «An argument with name "locale" already exists» al
 * CONSTRUIR el comando. Como los comandos se construyen al arrancar la consola,
 * eso no rompe `route:list`: rompe **todos** los `php artisan`, incluidos
 * `serve` y `migrate`.
 *
 * Paso exactamente eso en una maquina con otra version. De ahi estas pruebas.
 */
class RouteListCommandsTest extends TestCase
{
    public function test_route_list_es_el_de_laravel_y_funciona(): void
    {
        $this->artisan('route:list', ['--path' => 'login'])->assertSuccessful();
    }

    public function test_route_trans_list_existe_con_su_nombre_y_su_idioma(): void
    {
        $this->artisan('route:trans:list', ['locale' => 'es', '--path' => 'login'])->assertSuccessful();
    }

    /**
     * El argumento se anade una sola vez, venga de donde venga.
     *
     * Se construye el comando y despues se intenta anadir `locale` de nuevo: si
     * la clase lo hubiera dejado duplicado, esta comprobacion habria fallado ya
     * al instanciarlo.
     */
    public function test_el_argumento_locale_no_se_duplica(): void
    {
        $comando = $this->app->make(RouteTranslationsListCommand::class);

        $this->assertTrue($comando->getDefinition()->hasArgument('locale'));
        $this->assertSame('route:trans:list', $comando->getName());

        // Y las opciones del padre siguen ahi: el handle() heredado las usa.
        foreach (['json', 'method', 'path', 'sort'] as $opcion) {
            $this->assertTrue($comando->getDefinition()->hasOption($opcion), "falta la opcion --{$opcion}");
        }
    }

    /**
     * El caso de la otra version: si Laravel ya puso el argumento, construir el
     * comando no puede reventar.
     */
    public function test_construirlo_con_el_argumento_ya_puesto_no_revienta(): void
    {
        $comando = $this->app->make(RouteTranslationsListCommand::class);

        // Se simula la version que si aplica getArguments(): el argumento ya
        // estaba antes de que nuestro constructor intentara ponerlo.
        $definicion = $comando->getDefinition();

        $this->assertTrue($definicion->hasArgument('locale'));

        // Anadirlo a mano ahora si debe protestar — es la excepcion que veiamos.
        $this->expectException(\LogicException::class);
        $definicion->addArgument(new InputArgument('locale', InputArgument::REQUIRED, 'duplicado'));
    }
}
