<?php

namespace App\Console\Commands;

use App\Support\CifradoEnReposo;
use App\Support\DocumentoBuscable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Cifra en la base lo que hasta ahora estaba en claro, y rellena el indice ciego.
 *
 * QUE CIFRA
 * ---------
 *   · `people.num_doc`                     el documento de identidad
 *   · `person_biometrics.face_descriptor`  los 128 numeros de una cara
 *   · `person_biometrics.consent_text`     el texto que la persona acepto
 *
 * Y de paso rellena `people.num_doc_hash`, que es por donde se busca desde que
 * el documento no se puede comparar directamente (ver
 * `App\Support\DocumentoBuscable`).
 *
 * QUE PROTEGE ESTO Y QUE NO — LAS DOS COSAS QUE HAY QUE DECIDIR SABIENDO
 * ----------------------------------------------------------------------
 * **1. Esto protege contra quien lee un backup, no contra quien tiene el
 * `APP_KEY`.** El `.sql` de la noche, la copia que alguien se baja para probar
 * en local, el disco del droplet, el volcado que se pasa por correo: eso deja
 * de ser una lista de 14 000 DNI y de 14 000 caras. Pero la aplicacion
 * descifra con el `APP_KEY`, que vive en el `.env` del mismo servidor, asi que
 * quien entre al servidor lo lee todo igual. Son dos agujeros distintos y este
 * comando cierra uno.
 *
 * **2. Si se pierde el `APP_KEY`, estos datos no se recuperan.** Nunca. No hay
 * copia de la clave dentro de la aplicacion, no hay pregunta de seguridad, no
 * hay soporte que lo arregle: se pierden los documentos, las caras enroladas y
 * ademas la posibilidad de buscar a nadie por su DNI, porque el indice ciego
 * tambien se deriva de esa clave. La clave se custodia FUERA del servidor.
 *
 * Y rotarla no es cambiar una linea del `.env`. Rotar exige RE-CIFRAR, y en
 * este orden:
 *
 *   1. la clave vieja pasa a `APP_PREVIOUS_KEYS` (asi se sigue pudiendo leer),
 *   2. `APP_KEY` recibe la nueva,
 *   3. se corre este comando con `--recifrar`, que reescribe cada fila con la
 *      nueva y recalcula todos los hashes,
 *   4. y solo entonces se retira la clave vieja de `APP_PREVIOUS_KEYS`.
 *
 * Saltarse el paso 1 o el 3 es tirar los datos.
 *
 * COMO ESTA HECHO, Y POR QUE ASI
 * ------------------------------
 *   · **Se puede repetir.** Cada valor se mira antes de tocarlo: lo que ya esta
 *     cifrado se deja como esta. Cifrar dos veces no da un error, da un dato
 *     ilegible —al descifrarlo sale el sobre de dentro, no el DNI— y eso es una
 *     perdida silenciosa. Poder relanzarlo importa porque un comando que toca
 *     14 000 filas se corta: se cae la conexion, se acaba el tiempo, alguien
 *     pulsa Ctrl+C.
 *   · **Va por lotes.** Cargar 14 000 filas de golpe para cifrarlas se come la
 *     memoria del proceso. Se avanza por `id` y no por `offset`, que es lo unico
 *     estable cuando la aplicacion sigue escribiendo mientras esto corre.
 *   · **No pasa por el modelo.** Consultas crudas a proposito: por el modelo,
 *     cada fila dispararia el `Auditable` y dejaria en `audit_logs` una copia
 *     nueva de lo que se acaba de cifrar. Ademas de inutil, seria exactamente
 *     el agujero que se viene a tapar.
 *   · **No hay transaccion unica.** Cada lote se confirma por su cuenta. Con
 *     una transaccion de 14 000 filas, un corte al 90 % lo deshace todo y hay
 *     que empezar de cero; asi, lo hecho queda hecho y la siguiente pasada
 *     sigue donde se quedo.
 *
 * LO QUE NO SE PUEDE ABRIR SE CUENTA, NO SE PISA
 * ----------------------------------------------
 * Un valor con forma de sobre que no descifra significa que se cifro con OTRA
 * clave. El comando no lo toca y lo reporta aparte: escribirlo encima seria
 * destruir el unico dato que quedaba. Si aparece uno solo de estos, hay que
 * parar y buscar la clave vieja antes de seguir.
 */
class CifrarDatosSensiblesCommand extends Command
{
    protected $signature = 'docufiz:cifrar-datos-sensibles
        {--dry-run : No escribe nada. Cuenta lo que haria y lo enseña}
        {--recifrar : Vuelve a cifrar tambien lo que ya estaba cifrado. Es lo que hay que correr al ROTAR el APP_KEY}
        {--lote=500 : Cuantas filas por vuelta}';

    protected $description = 'Cifra en reposo el documento, el descriptor facial y el consentimiento, y rellena el indice ciego del documento';

    /** @var array<string, int> */
    private array $resumen = [];

    /** Filas con forma de sobre que no abren con la clave de hoy. */
    private int $ilegibles = 0;

    public function handle(): int
    {
        // Se arranca de cero cada vez. En la consola cada corrida es un proceso
        // nuevo y daria igual, pero `Artisan::call()` reutiliza la instancia:
        // sin esto, dos llamadas seguidas dentro del mismo proceso —una prueba,
        // un job— informarian los conteos sumados y el informe mentiria.
        $this->resumen = [];
        $this->ilegibles = 0;

        $seco = (bool) $this->option('dry-run');
        $recifrar = (bool) $this->option('recifrar');
        $lote = max(1, (int) $this->option('lote'));

        $this->info('Cifrado en reposo de los datos sensibles');
        $this->line($seco
            ? '  Ensayo (--dry-run): no se escribe nada.'
            : '  Escribiendo. Se puede volver a lanzar si se corta.');

        if ($recifrar) {
            $this->warn('  --recifrar: se reescribe TAMBIEN lo ya cifrado. Solo tiene sentido al rotar el APP_KEY,');
            $this->warn('  y solo si la clave anterior sigue en APP_PREVIOUS_KEYS. Sin eso, esto no puede leer nada.');
        }

        $this->newLine();

        $this->personas($seco, $recifrar, $lote);
        $this->biometrias($seco, $recifrar, $lote);

        $this->newLine();
        $this->informe($seco);

        // Un valor que no abre es lo unico que convierte esto en un fallo: hay
        // datos que este proceso ya no sabe leer y hay que enterarse ahora, no
        // el dia que alguien abra la ficha de esa persona.
        return $this->ilegibles > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `people`: cifra el documento y rellena su indice ciego.
     *
     * El hash se recalcula SIEMPRE que falte, aunque el documento ya estuviera
     * cifrado. Son dos estados independientes y se dan por separado: una fila
     * escrita por la aplicacion durante la ventana del despliegue nace cifrada
     * y con hash, pero una fila insertada en crudo —el sembrado, un `INSERT` a
     * mano— puede tener una cosa y no la otra. Sin hash, esa persona existe y
     * no la encuentra nadie.
     */
    private function personas(bool $seco, bool $recifrar, int $lote): void
    {
        $this->line('people.num_doc');

        $this->porLotes('people', ['num_doc', 'num_doc_hash'], $lote, function (object $fila) use ($seco, $recifrar): void {
            $cambios = [];

            $enClaro = $this->enClaro($fila->num_doc, 'people.num_doc', $fila->id);

            if ($enClaro === false) {
                return;   // no abre: ya se conto y se aviso, no se toca
            }

            $yaCifrado = CifradoEnReposo::estaCifrado($fila->num_doc);

            if ($enClaro !== null && $enClaro !== '' && (! $yaCifrado || $recifrar)) {
                $cambios['num_doc'] = Crypt::encryptString($enClaro);
                $this->contar($yaCifrado ? 'documentos re-cifrados' : 'documentos cifrados');
            }

            $hash = DocumentoBuscable::hash($enClaro);

            if ($hash !== $fila->num_doc_hash) {
                $cambios['num_doc_hash'] = $hash;
                $this->contar('indices ciegos escritos');
            }

            if ($cambios !== [] && ! $seco) {
                DB::table('people')->where('id', $fila->id)->update($cambios);
            }
        });
    }

    /** `person_biometrics`: la cara y el texto del consentimiento. */
    private function biometrias(bool $seco, bool $recifrar, int $lote): void
    {
        $this->line('person_biometrics.face_descriptor + consent_text');

        $columnas = [
            'face_descriptor' => 'descriptores faciales cifrados',
            'consent_text'    => 'textos de consentimiento cifrados',
        ];

        $this->porLotes('person_biometrics', array_keys($columnas), $lote, function (object $fila) use ($seco, $recifrar, $columnas): void {
            $cambios = [];

            foreach ($columnas as $columna => $etiqueta) {
                $valor = $fila->{$columna};

                // `consent_text` es nulo en todo lo enrolado antes de que el
                // consentimiento se guardara, y en lo migrado. Un hueco no se
                // cifra: cifrar el vacio produciria un sobre que al abrirse da
                // una cadena vacia, o sea convertiria «no consta» en «acepto un
                // texto en blanco». No es lo mismo.
                if ($valor === null || $valor === '') {
                    continue;
                }

                $enClaro = $this->enClaro($valor, "person_biometrics.{$columna}", $fila->id);

                if ($enClaro === false) {
                    continue;
                }

                $yaCifrado = CifradoEnReposo::estaCifrado($valor);

                if ($yaCifrado && ! $recifrar) {
                    continue;
                }

                $cambios[$columna] = Crypt::encryptString((string) $enClaro);
                $this->contar($yaCifrado ? "{$etiqueta} (de nuevo)" : $etiqueta);
            }

            if ($cambios !== [] && ! $seco) {
                DB::table('person_biometrics')->where('id', $fila->id)->update($cambios);
            }
        });
    }

    /**
     * Recorre una tabla por lotes, avanzando por `id`.
     *
     * Por `id` y no por `offset`: con `offset` bastaria que alguien diera de
     * alta a una persona a mitad del recorrido para que las filas se
     * desplazaran y el lote siguiente se saltara unas cuantas — que quedarian
     * en claro sin que nadie se entere. El `id` no se mueve.
     *
     * @param  array<int, string>  $columnas
     * @param  \Closure(object): void  $porFila
     */
    private function porLotes(string $tabla, array $columnas, int $lote, \Closure $porFila): void
    {
        $ultimo = 0;
        $vistas = 0;
        $barra = $this->output->createProgressBar(DB::table($tabla)->count());
        $barra->start();

        while (true) {
            $filas = DB::table($tabla)
                ->select(array_merge(['id'], $columnas))
                ->where('id', '>', $ultimo)
                ->orderBy('id')
                ->limit($lote)
                ->get();

            if ($filas->isEmpty()) {
                break;
            }

            foreach ($filas as $fila) {
                $porFila($fila);
                $ultimo = $fila->id;
                $vistas++;
                $barra->advance();
            }
        }

        $barra->finish();
        $this->newLine();
        $this->line("  {$vistas} filas revisadas.");
    }

    /**
     * El valor en claro, o `false` si tiene forma de sobre y no se puede abrir.
     *
     * Los tres estados son distintos y hacen falta los tres: texto plano (hay
     * que cifrarlo), sobre que abre (ya esta hecho, o hay que re-cifrarlo) y
     * sobre que no abre (**se cifro con otra clave**, y ahi hay que parar).
     */
    private function enClaro(mixed $valor, string $donde, int $id): string|false|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! CifradoEnReposo::estaCifrado($valor)) {
            return (string) $valor;
        }

        try {
            return Crypt::decryptString($valor);
        } catch (DecryptException) {
            $this->ilegibles++;
            $this->newLine();
            $this->error("  {$donde} (id {$id}) esta cifrado con otra clave y no se puede abrir. No se toca.");

            return false;
        }
    }

    private function contar(string $clave): void
    {
        $this->resumen[$clave] = ($this->resumen[$clave] ?? 0) + 1;
    }

    private function informe(bool $seco): void
    {
        if ($this->resumen === []) {
            $this->info('No habia nada que cifrar: todo estaba ya en su sitio.');

            return;
        }

        $verbo = $seco ? 'se harian' : 'hechas';

        $this->table(
            [$seco ? 'Lo que se haria' : 'Lo hecho', 'Filas'],
            collect($this->resumen)->map(fn (int $n, string $k) => [$k, $n])->values()->all(),
        );

        $this->info(sprintf('%d escrituras %s.', array_sum($this->resumen), $verbo));

        if ($seco) {
            $this->comment('Ensayo: no se ha escrito nada. Quita --dry-run para hacerlo de verdad.');
        }

        if ($this->ilegibles > 0) {
            $this->newLine();
            $this->error(sprintf(
                '%d valores estan cifrados con una clave que no es la de hoy. NO se han tocado.',
                $this->ilegibles,
            ));
            $this->error('Busca el APP_KEY con el que se cifraron y ponlo en APP_PREVIOUS_KEYS antes de seguir.');
        }
    }
}
