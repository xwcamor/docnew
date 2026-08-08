<?php

namespace App\Console\Commands;

use Illuminate\Routing\Router;
use Mcamara\LaravelLocalization\Commands\RouteTranslationsListCommand as Original;
use Symfony\Component\Console\Input\InputArgument;

/**
 * Arregla el `route:list` que rompe laravel-localization.
 *
 * El comando del paquete hereda de `RouteListCommand`, que en Laravel moderno
 * declara su nombre en `$signature` en vez de en `$name`. La firma se hereda
 * entera, asi que el comando del paquete acaba registrado como **`route:list`**
 * —pisando al de Laravel— y encima su `handle()` pide un argumento `locale`
 * que esa firma heredada no declara.
 *
 * Resultado: `php artisan route:list` reventaba con «The "locale" argument does
 * not exist», y con el se caia el paso de comprobacion de rutas de
 * `docs/CREATE-MODULE.md`.
 *
 * Se corrige lo justo: se le pone su nombre y se le anade el argumento que le
 * faltaba, **conservando todas las opciones del padre** (`--json`, `--method`,
 * `--path`, `--sort`…), que el `handle()` heredado usa.
 *
 * Se comprueba antes si el argumento ya esta, y no es paranoia: Laravel solo
 * aplica `getArguments()` cuando la clase **no** trae `$signature`, y esa
 * condicion ha cambiado entre versiones. Anadirlo a ciegas lo duplica en las
 * versiones donde si se aplica, y entonces revienta la construccion del
 * comando — que ocurre al arrancar la consola, o sea que se cae **cualquier**
 * `php artisan`, no solo este.
 */
class RouteTranslationsListCommand extends Original
{
    public function __construct(Router $router)
    {
        parent::__construct($router);

        $this->setName('route:trans:list');
        $this->setDescription('Lista las rutas registradas para un idioma concreto');

        if (! $this->getDefinition()->hasArgument('locale')) {
            $this->getDefinition()->addArgument(
                new InputArgument('locale', InputArgument::REQUIRED, 'El idioma cuyas rutas se quieren listar'),
            );
        }
    }
}
