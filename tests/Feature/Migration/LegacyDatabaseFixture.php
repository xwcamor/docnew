<?php

namespace Tests\Feature\Migration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una base "vieja" de mentira para poder probar la migracion.
 *
 * La v1 es MySQL y no esta en el entorno de pruebas, asi que aqui se levanta la
 * misma forma de tablas sobre sqlite en memoria y se rellena con datos
 * inventados. Ni un nombre ni un documento reales: los de verdad son de
 * personas y este repositorio es publico.
 */
class LegacyDatabaseFixture
{
    /** Conecta `legacy` a sqlite en memoria y crea el esquema de la v1. */
    public static function levantar(): void
    {
        Config::set('database.connections.legacy', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('legacy');

        $esquema = Schema::connection('legacy');

        // ── organizacion y personas ──────────────────────────────────────────
        // Las columnas son las del esquema real de la v1 (db/schema.rb:192): sin
        // `created_at`/`updated_at`/`deleted_description` aqui no se podia
        // comprobar que la migracion respeta la antiguedad ni las bajas.
        $esquema->create('companies', function ($t) {
            $t->id(); $t->string('num_doc'); $t->string('name'); $t->string('complete_name');
            $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
            $t->string('deleted_description')->nullable();
            $t->timestamps();
        });

        foreach (['workers', 'supervisors', 'hse_supervisors'] as $tabla) {
            $esquema->create($tabla, function ($t) use ($tabla) {
                $t->id(); $t->string('num_doc'); $t->string('name'); $t->string('lastname');
                $t->string('signature')->nullable(); $t->string('photo')->nullable();
                // NOT NULL en la v1, en las tres tablas: todo el mundo trae una.
                $t->unsignedBigInteger('nationality_id')->nullable();
                $t->boolean('is_deleted')->default(false);

                if ($tabla === 'workers') {
                    $t->unsignedBigInteger('company_id')->nullable();
                    $t->unsignedBigInteger('position_id')->nullable();
                }
            });
        }

        $esquema->create('user_details', function ($t) {
            $t->id(); $t->unsignedBigInteger('user_id');
            $t->string('name'); $t->string('lastname'); $t->string('cellphone')->nullable();
        });

        // `users` y `profiles`: el primer volcado vino sin ellas y el completo
        // si las trae, asi que la migracion tiene que funcionar de las dos
        // formas. Estan vacias por defecto; conBaseCompleta() las llena.
        $esquema->create('users', function ($t) {
            $t->id(); $t->string('email'); $t->string('encrypted_password');
            $t->string('real_password')->nullable();
            $t->unsignedBigInteger('profile_id')->nullable();
            $t->unsignedBigInteger('country_id')->default(1);
            $t->boolean('is_hidden')->default(false);
            // La baja de la v1. Faltaba aqui, y por eso ninguna prueba cubria
            // que se respetara — y no se respetaba: los usuarios dados de baja
            // hacia anos entraban activos.
            $t->boolean('is_deleted')->default(false);
            $t->text('deleted_description')->nullable();
        });

        $esquema->create('profiles', function ($t) {
            $t->id(); $t->string('name'); $t->boolean('is_deleted')->default(false);
        });

        // ── catalogos ────────────────────────────────────────────────────────
        $esquema->create('work_types', function ($t) {
            $t->id(); $t->unsignedBigInteger('country_id'); $t->string('name_es');
            $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
        });

        foreach (['locations', 'areas'] as $tabla) {
            $esquema->create($tabla, function ($t) {
                $t->id(); $t->unsignedBigInteger('country_id'); $t->string('name');
                $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
            });
        }

        $esquema->create('workstations', function ($t) {
            $t->id(); $t->unsignedBigInteger('country_id'); $t->unsignedBigInteger('location_id');
            $t->string('name'); $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
        });

        // Cargos: Tecnico, Supervisor... En la v1 `workers.position_id` es NOT
        // NULL, asi que todo trabajador trae el suyo.
        //
        // `is_signature_approver` sigue aqui aunque en DOCUFIZ ya no exista:
        // esto reproduce el volcado de la v1, no nuestro esquema. Quitarla haria
        // que el fixture mintiera sobre el origen y taparia el dia que la
        // migracion vuelva a leer una columna que creiamos muerta.
        $esquema->create('positions', function ($t) {
            $t->id(); $t->unsignedBigInteger('country_id');
            $t->string('name_es'); $t->string('name_en')->nullable(); $t->string('name_pt')->nullable();
            $t->boolean('is_signature_approver')->default(false);
            $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
        });

        $esquema->create('approval_rules', function ($t) {
            $t->id(); $t->unsignedBigInteger('country_id'); $t->string('name_es');
            $t->string('approver_type'); $t->integer('priority_level');
            $t->boolean('is_required')->default(true); $t->boolean('is_active')->default(true);
            $t->boolean('is_deleted')->default(false);
        });

        $esquema->create('documents', function ($t) {
            $t->id(); $t->unsignedBigInteger('country_id'); $t->string('name'); $t->string('db_name');
        });

        $esquema->create('work_type_documents', function ($t) {
            $t->id(); $t->unsignedBigInteger('work_type_id'); $t->unsignedBigInteger('document_id');
            $t->boolean('is_required')->default(true);
        });

        // Catalogos que alimentan las plantillas del motor.
        foreach (['ast_activities', 'ast_dangers', 'ast_risks', 'ast_controls', 'ast_equipments', 'ast_objetives',
                  'epp_items', 'ihm_items', 'ihm_tools', 'ptf_questions'] as $tabla) {
            $esquema->create($tabla, function ($t) {
                $t->id(); $t->string('name_es'); $t->string('name_pt')->nullable(); $t->string('name_en')->nullable();
                // Las dos banderas de la v1: el formulario de alla filtraba
                // `not_deleted.is_active`, y el migrador tiene que hacer lo
                // mismo — sin `is_active` en la maqueta, ese filtro no se
                // podria probar (y de hecho el fallo real fue no filtrarlo).
                $t->boolean('is_active')->default(true);
                $t->boolean('is_deleted')->default(false);
            });
        }

        // Los bloques del PTF: el titulo con su imagen, y cada pregunta cuelga
        // de uno. Es la relacion que el migrador perdia al leer la tabla plana.
        $esquema->create('ptf_question_titles', function ($t) {
            $t->id(); $t->string('name_es'); $t->string('name_pt')->nullable(); $t->string('name_en')->nullable();
            $t->string('image')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
        });
        $esquema->table('ptf_questions', function ($t) {
            $t->unsignedBigInteger('ptf_question_title_id')->nullable();
        });

        // Plana y sin pais, como en la v1: dice de donde es la persona, no
        // donde se trabaja.
        $esquema->create('nationalities', function ($t) {
            $t->id(); $t->string('name'); $t->string('flag')->nullable();
            $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
        });

        foreach (['severities', 'probabilities'] as $tabla) {
            $esquema->create($tabla, function ($t) {
                $t->id(); $t->string('name'); $t->boolean('is_deleted')->default(false);
            });
        }

        // La tabla donde la v1 guardaba lo que de verdad se leia en pantalla
        // (db/schema.rb:736). `severities.name` es la clave interna (c1..c5);
        // el formulario hacia `I18n.t("severities.#{id}")` y el texto salia de
        // aqui, con la clave `severities.<id>` por locale. Sin esta tabla en el
        // fixture, la migracion de los nombres reales no se podia probar.
        $esquema->create('translations', function ($t) {
            $t->id(); $t->string('locale'); $t->string('key'); $t->text('value')->nullable();
            $t->timestamps();
        });

        // La matriz de riesgo de la v1: un valor por celda, no una formula.
        $esquema->create('risks', function ($t) {
            $t->id(); $t->unsignedBigInteger('severity_id'); $t->unsignedBigInteger('probability_id');
            $t->integer('risk_value'); $t->boolean('is_deleted')->default(false);
        });

        // ── planes ───────────────────────────────────────────────────────────
        $esquema->create('plans', function ($t) {
            $t->id(); $t->unsignedBigInteger('country_id'); $t->string('slug'); $t->string('code');
            $t->string('num_os')->nullable(); $t->text('description');
            $t->dateTime('date_start'); $t->dateTime('date_end')->nullable();
            $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('area_id');
            $t->unsignedBigInteger('location_id'); $t->unsignedBigInteger('workstation_id');
            $t->unsignedBigInteger('work_type_id'); $t->unsignedBigInteger('user_id');
            $t->boolean('is_locked')->default(false); $t->boolean('is_done')->default(false);
            $t->boolean('is_active')->default(true); $t->boolean('is_deleted')->default(false);
            $t->string('deleted_description')->nullable(); $t->timestamps();
        });

        $esquema->create('plan_workers', function ($t) {
            $t->id(); $t->unsignedBigInteger('plan_id'); $t->unsignedBigInteger('worker_id');
            $t->string('slug'); $t->string('signature')->nullable(); $t->string('photo')->nullable();
            $t->boolean('is_approved')->default(false); $t->timestamps();
        });

        $esquema->create('plan_approvals', function ($t) {
            $t->id(); $t->unsignedBigInteger('plan_id'); $t->unsignedBigInteger('approval_rule_id');
            $t->string('slug'); $t->unsignedBigInteger('approver_id')->nullable();
            $t->string('approver_type')->nullable();
            $t->string('signature')->nullable(); $t->string('photo')->nullable();
            $t->boolean('is_required')->default(true); $t->boolean('is_approved')->default(false);
            $t->timestamps();
        });

        $esquema->create('plan_documents', function ($t) {
            $t->id(); $t->unsignedBigInteger('plan_id'); $t->unsignedBigInteger('document_id');
            $t->boolean('is_selected')->default(true); $t->boolean('is_required')->default(false);
            $t->boolean('is_approved')->default(false); $t->timestamps();
        });

        $esquema->create('worker_signature_events', function ($t) {
            $t->id(); $t->string('signable_type')->nullable(); $t->unsignedBigInteger('signable_id')->nullable();
            $t->string('role_signed'); $t->dateTime('signed_at');
            $t->decimal('latitude', 10, 6)->nullable(); $t->decimal('longitude', 10, 6)->nullable();
            $t->boolean('used_ai')->default(false); $t->boolean('manual_override')->default(false);
            $t->string('method'); $t->string('device_id')->nullable(); $t->string('ip_address')->nullable();
            $t->text('user_agent')->nullable(); $t->string('country_code')->nullable();
            $t->string('region')->nullable(); $t->string('city')->nullable(); $t->timestamps();
        });

        // ── formatos llenados ────────────────────────────────────────────────
        foreach (['f1', 'f2', 'f3', 'f4'] as $f) {
            $esquema->create("{$f}_documents", function ($t) use ($f) {
                $t->id(); $t->unsignedBigInteger('document_id'); $t->unsignedBigInteger('plan_id');
                $t->string('slug')->nullable();

                if ($f === 'f1') {
                    $t->text('adrights')->nullable(); $t->text('eqtools')->nullable();
                }

                $t->integer('observations')->default(0);
                $t->boolean('is_confirmed')->default(false); $t->boolean('is_deleted')->default(false);
                $t->timestamps();
            });
        }

        foreach (['f1', 'f2'] as $f) {
            $esquema->create("{$f}_document_activities", function ($t) use ($f) {
                $t->id(); $t->unsignedBigInteger("{$f}_document_id"); $t->text('name'); $t->timestamps();
            });

            $esquema->create("{$f}_document_dangers", function ($t) use ($f) {
                $t->id(); $t->unsignedBigInteger("{$f}_document_activity_id");
                $t->text('name_danger'); $t->text('name_risk'); $t->text('name_control');
                $t->unsignedBigInteger('probability_id'); $t->unsignedBigInteger('severity_id');
                $t->integer('risk_value'); $t->timestamps();
            });
        }

        $esquema->create('f1_document_equipments', function ($t) {
            $t->id(); $t->unsignedBigInteger('f1_document_id'); $t->unsignedBigInteger('ast_equipment_id'); $t->timestamps();
        });

        $esquema->create('f1_document_objetives', function ($t) {
            $t->id(); $t->unsignedBigInteger('f1_document_id'); $t->unsignedBigInteger('ast_objetive_id'); $t->timestamps();
        });

        $esquema->create('f2_document_answers', function ($t) {
            $t->id(); $t->unsignedBigInteger('f2_document_id'); $t->unsignedBigInteger('ptf_question_id');
            $t->integer('answer'); $t->timestamps();
        });

        $esquema->create('f3_document_workers', function ($t) {
            $t->id(); $t->unsignedBigInteger('f3_document_id'); $t->unsignedBigInteger('plan_worker_id');
            $t->string('correction_measure')->nullable(); $t->date('deadline_date')->nullable();
            $t->string('correction_verification')->nullable(); $t->timestamps();
        });

        $esquema->create('f3_document_answers', function ($t) {
            $t->id(); $t->unsignedBigInteger('f3_document_worker_id'); $t->unsignedBigInteger('epp_item_id');
            $t->integer('answer')->nullable(); $t->timestamps();
        });

        $esquema->create('f4_document_tools', function ($t) {
            $t->id(); $t->unsignedBigInteger('f4_document_id'); $t->text('name');
            $t->boolean('is_enabled')->nullable(); $t->text('correction_measure')->nullable();
            $t->text('responsible')->nullable(); $t->timestamps();
        });

        $esquema->create('f4_document_items', function ($t) {
            $t->id(); $t->unsignedBigInteger('f4_document_tool_id'); $t->unsignedBigInteger('ihm_item_id');
            $t->integer('answer')->nullable(); $t->timestamps();
        });
    }

    /**
     * Datos inventados con la misma forma que los reales, incluidas las rarezas
     * que hay que probar: codigos de plan repetidos, la misma persona en dos
     * empresas, un aprobador que ya no existe, fotos que son el marcador
     * "detected_by_IA" y planes de otro pais que no deben migrarse.
     */
    public static function sembrar(): void
    {
        $viejo = DB::connection('legacy');
        $ahora = '2026-01-15 10:00:00';

        $viejo->table('companies')->insert([
            ['id' => 1, 'num_doc' => '20100000001', 'name' => 'ALFA', 'complete_name' => 'Alfa Contratistas SAC', 'is_active' => true, 'is_deleted' => false,
             'deleted_description' => null, 'created_at' => '2019-03-04 10:00:00', 'updated_at' => '2019-03-04 10:00:00'],
            ['id' => 2, 'num_doc' => '20100000002', 'name' => 'BETA', 'complete_name' => 'Beta Servicios SAC', 'is_active' => true, 'is_deleted' => false,
             'deleted_description' => null, 'created_at' => '2020-07-15 08:30:00', 'updated_at' => '2020-07-15 08:30:00'],
            // Dada de baja en la v1: tiene que llegar borrada, no viva.
            ['id' => 3, 'num_doc' => '20100000003', 'name' => 'GAMMA', 'complete_name' => 'Gamma EIRL', 'is_active' => false, 'is_deleted' => true,
             'deleted_description' => 'Ya no trabaja con nosotros', 'created_at' => '2018-01-02 09:00:00', 'updated_at' => '2021-11-30 17:45:00'],
            // RUC tecleado con guiones y espacios, como se podia en la v1.
            ['id' => 4, 'num_doc' => '20-1000 00004', 'name' => 'DELTA', 'complete_name' => 'Delta SAC', 'is_active' => true, 'is_deleted' => false,
             'deleted_description' => null, 'created_at' => '2022-05-20 12:00:00', 'updated_at' => '2022-05-20 12:00:00'],
        ]);

        // El trabajador 3 esta en dos empresas (filas 3 y 4, mismo documento):
        // en destino tiene que ser una sola identidad.
        // El cargo al que apuntan los trabajadores de abajo.
        $viejo->table('positions')->insert([
            ['id' => 1, 'country_id' => 1, 'name_es' => 'Tecnico', 'name_en' => 'Technician', 'name_pt' => 'Tecnico', 'is_signature_approver' => false, 'is_active' => true, 'is_deleted' => false],
            ['id' => 2, 'country_id' => 1, 'name_es' => 'Supervisor', 'name_en' => 'Supervisor', 'name_pt' => 'Supervisor', 'is_signature_approver' => true, 'is_active' => true, 'is_deleted' => false],
            // De otro pais: no debe migrarse, como el resto de catalogos.
            ['id' => 3, 'country_id' => 6, 'name_es' => 'Mecanico', 'name_en' => 'Mechanic', 'name_pt' => 'Mecanico', 'is_signature_approver' => false, 'is_active' => true, 'is_deleted' => false],
        ]);

        // El catalogo de la v1: plano, con la bandera. El 5 es Venezuela, que
        // en el volcado real son 9 de los 11 extranjeros.
        $viejo->table('nationalities')->insert([
            ['id' => 1, 'name' => 'Peru', 'flag' => 'pe', 'is_active' => true, 'is_deleted' => false],
            ['id' => 5, 'name' => 'Venezuela', 'flag' => 've', 'is_active' => true, 'is_deleted' => false],
        ]);

        $viejo->table('workers')->insert([
            ['id' => 1, 'num_doc' => '10000001', 'name' => 'Trabajador', 'lastname' => 'Uno', 'company_id' => 1, 'position_id' => 1, 'nationality_id' => 1, 'signature' => 'firma-uno.webp', 'is_deleted' => false],
            // Extranjero: la nacionalidad es lo que decide que lleve carne y no DNI.
            ['id' => 2, 'num_doc' => '10000002', 'name' => 'Trabajador', 'lastname' => 'Dos', 'company_id' => 1, 'position_id' => 1, 'nationality_id' => 5, 'signature' => null, 'is_deleted' => false],
            ['id' => 3, 'num_doc' => '10000003', 'name' => 'Trabajador', 'lastname' => 'Tres', 'company_id' => 1, 'position_id' => 1, 'nationality_id' => 1, 'signature' => null, 'is_deleted' => false],
            ['id' => 4, 'num_doc' => '10000003', 'name' => 'Trabajador', 'lastname' => 'Tres', 'company_id' => 2, 'position_id' => 1, 'nationality_id' => 1, 'signature' => null, 'is_deleted' => false],
        ]);

        $viejo->table('supervisors')->insert([
            ['id' => 1, 'num_doc' => '20000001', 'name' => 'Supervisor', 'lastname' => 'Uno', 'nationality_id' => 1, 'signature' => null, 'is_deleted' => false],
        ]);

        $viejo->table('hse_supervisors')->insert([
            ['id' => 1, 'num_doc' => '30000001', 'name' => 'Hse', 'lastname' => 'Uno', 'nationality_id' => 1, 'signature' => null, 'is_deleted' => false],
        ]);

        $viejo->table('user_details')->insert([
            ['id' => 1, 'user_id' => 1, 'name' => 'Usuario', 'lastname' => 'Uno', 'cellphone' => null],
            ['id' => 2, 'user_id' => 2, 'name' => 'Usuario', 'lastname' => 'Dos', 'cellphone' => '999999999'],
        ]);

        $viejo->table('profiles')->insert([
            ['id' => 1, 'name' => 'Super', 'is_deleted' => false],
            ['id' => 2, 'name' => 'Admin', 'is_deleted' => false],
            ['id' => 3, 'name' => 'Company Supervisor', 'is_deleted' => false],
            ['id' => 4, 'name' => 'User Regular', 'is_deleted' => false],
        ]);

        // El catalogo de la v1 es multipais: el 6 (Brasil) no se migra.
        $viejo->table('work_types')->insert([
            ['id' => 1, 'country_id' => 1, 'name_es' => 'Estandar', 'is_active' => true, 'is_deleted' => false],
            ['id' => 2, 'country_id' => 6, 'name_es' => 'Padrao', 'is_active' => true, 'is_deleted' => false],
        ]);
        $viejo->table('locations')->insert([
            ['id' => 1, 'country_id' => 1, 'name' => 'Sede Norte', 'is_active' => true, 'is_deleted' => false],
            ['id' => 2, 'country_id' => 6, 'name' => 'Sede Brasil', 'is_active' => true, 'is_deleted' => false],
        ]);
        $viejo->table('areas')->insert([
            ['id' => 1, 'country_id' => 1, 'name' => 'Area Uno', 'is_active' => true, 'is_deleted' => false],
        ]);
        $viejo->table('workstations')->insert([
            ['id' => 1, 'country_id' => 1, 'location_id' => 1, 'name' => 'Puesto 1', 'is_active' => true, 'is_deleted' => false],
            ['id' => 2, 'country_id' => 6, 'location_id' => 2, 'name' => 'Puesto Brasil', 'is_active' => true, 'is_deleted' => false],
        ]);
        $viejo->table('approval_rules')->insert([
            ['id' => 1, 'country_id' => 1, 'name_es' => 'Ejecutante', 'approver_type' => 'Worker', 'priority_level' => 1, 'is_required' => true, 'is_active' => true, 'is_deleted' => false],
            ['id' => 2, 'country_id' => 1, 'name_es' => 'Autorizante', 'approver_type' => 'Supervisor', 'priority_level' => 2, 'is_required' => true, 'is_active' => true, 'is_deleted' => false],
            ['id' => 3, 'country_id' => 1, 'name_es' => 'Seguridad', 'approver_type' => 'HseSupervisor', 'priority_level' => 3, 'is_required' => false, 'is_active' => true, 'is_deleted' => false],
        ]);
        $viejo->table('documents')->insert([
            ['id' => 1, 'country_id' => 1, 'name' => 'ast_documents', 'db_name' => 'F1Document'],
            ['id' => 2, 'country_id' => 1, 'name' => 'ptf_documents', 'db_name' => 'F2Document'],
            ['id' => 3, 'country_id' => 1, 'name' => 'epp_documents', 'db_name' => 'F3Document'],
            ['id' => 4, 'country_id' => 1, 'name' => 'ihm_documents', 'db_name' => 'F4Document'],
        ]);
        $viejo->table('work_type_documents')->insert([
            ['id' => 1, 'work_type_id' => 1, 'document_id' => 1, 'is_required' => true],
            ['id' => 2, 'work_type_id' => 1, 'document_id' => 2, 'is_required' => false],
        ]);

        $catalogo = fn (array $nombres) => collect($nombres)
            ->map(fn ($n, $i) => ['id' => $i + 1, 'name_es' => $n, 'name_pt' => $n, 'name_en' => $n, 'is_active' => true, 'is_deleted' => false])
            ->all();

        $viejo->table('ast_activities')->insert($catalogo(['Izaje', 'Limpieza']));
        $viejo->table('ast_dangers')->insert($catalogo(['Carga suspendida']));
        // `ast_risks` es la consecuencia: la columna que va entre el peligro y
        // el control y que la migracion no traia.
        $viejo->table('ast_risks')->insert($catalogo(['Golpe por caida de carga']));
        $viejo->table('ast_controls')->insert($catalogo(['Delimitar area']));
        $viejo->table('ast_equipments')->insert($catalogo(['Grua', 'Escalera']));
        $viejo->table('ast_objetives')->insert($catalogo(['Fin de trabajo']));
        $viejo->table('epp_items')->insert($catalogo(['Casco', 'Guantes']));
        $viejo->table('ihm_items')->insert($catalogo(['Condiciones generales', 'Guardas', 'Mango empunadora']));
        $viejo->table('ihm_tools')->insert($catalogo(['Amoladora']));
        // Dos bloques, como el papel: cada pregunta cuelga del suyo. El titulo
        // del primero es UNO DE LOS CINCO CONOCIDOS, para probar que el
        // migrador le pega el icono del repositorio.
        $viejo->table('ptf_question_titles')->insert([
            ['id' => 1, 'name_es' => '1. ¡DETÉNTE y piensa antes de actuar!', 'name_pt' => '1. PARE e pense antes de agir!', 'name_en' => '1. STOP and think before acting!', 'is_active' => true, 'is_deleted' => false],
            ['id' => 2, 'name_es' => 'Bloque propio del cliente', 'name_pt' => '', 'name_en' => '', 'is_active' => true, 'is_deleted' => false],
        ]);
        $viejo->table('ptf_questions')->insert([
            // En orden cruzado a proposito: la pregunta 1 es del bloque 2. El
            // orden del papel es por bloque, no por id.
            ['id' => 1, 'name_es' => 'Conoces la emergencia?', 'name_pt' => '', 'name_en' => '', 'ptf_question_title_id' => 2, 'is_active' => true, 'is_deleted' => false],
            ['id' => 2, 'name_es' => 'Tienes permiso?', 'name_pt' => '', 'name_en' => '', 'ptf_question_title_id' => 1, 'is_active' => true, 'is_deleted' => false],
        ]);
        // La version vieja de una pregunta editada: desactivada, no borrada.
        // Es exactamente lo que el formulario de la v1 nunca enseñaba y lo que
        // el migrador barria por error.
        $viejo->table('ptf_questions')->insert([[
            'id' => 90, 'name_es' => 'Pregunta vieja desactivada', 'name_pt' => '', 'name_en' => '',
            'ptf_question_title_id' => 1, 'is_active' => false, 'is_deleted' => false,
        ]]);

        $viejo->table('severities')->insert([['id' => 1, 'name' => 'c1', 'is_deleted' => false], ['id' => 3, 'name' => 'c3', 'is_deleted' => false]]);
        $viejo->table('probabilities')->insert([['id' => 1, 'name' => 'p1', 'is_deleted' => false], ['id' => 2, 'name' => 'p2', 'is_deleted' => false]]);

        // Lo que la v1 enseñaba en los selectores de la matriz: la clave es
        // `severities.<id>` —el id de la fila, no la clave interna— porque asi
        // la genera la vista (`I18n.t("severities.#{id}")`). Nombres inventados
        // pero con la forma de los reales, INCLUIDA la mugre: en la base real
        // los valores estan guardados como «--- Catastrófico\n», con los
        // guiones del YAML del que se pegaron y un salto de linea final. La
        // migracion debe quedarse solo con el nombre; los asserts de
        // MigrateLegacyDataTest exigen el texto limpio. A proposito faltan y
        // sobran cosas: `probabilities.2` no tiene fila en es (su clave
        // interna p2 debe quedarse fuera del mapa), el en solo cubre una
        // severidad (los mapas pueden ser parciales) y el pt existe pero no
        // debe colarse en ningun mapa: la pantalla no lo pide.
        $viejo->table('translations')->insert([
            ['id' => 1, 'locale' => 'es', 'key' => 'severities.1',    'value' => "--- Catastrófico\n",   'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 2, 'locale' => 'es', 'key' => 'severities.3',    'value' => "--- Temporal\n",       'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 3, 'locale' => 'es', 'key' => 'probabilities.1', 'value' => "--- Podría suceder\n", 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 4, 'locale' => 'en', 'key' => 'severities.1',    'value' => "--- Catastrophic\n",   'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 5, 'locale' => 'pt', 'key' => 'probabilities.2', 'value' => "--- Raro (pt)\n",      'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        // En la v1 el valor de riesgo esta tabulado celda a celda: 1 es lo peor.
        $viejo->table('risks')->insert([
            ['id' => 1, 'severity_id' => 1, 'probability_id' => 1, 'risk_value' => 1, 'is_deleted' => false],
            ['id' => 2, 'severity_id' => 1, 'probability_id' => 2, 'risk_value' => 4, 'is_deleted' => false],
            ['id' => 3, 'severity_id' => 3, 'probability_id' => 1, 'risk_value' => 9, 'is_deleted' => false],
            ['id' => 4, 'severity_id' => 3, 'probability_id' => 2, 'risk_value' => 16, 'is_deleted' => false],
        ]);

        $plan = fn (array $extra) => array_merge([
            'country_id' => 1, 'num_os' => 'OS-1', 'description' => 'Trabajo de prueba',
            'date_start' => '2026-01-15 08:00:00', 'date_end' => '2026-01-15 17:00:00',
            'company_id' => 1, 'area_id' => 1, 'location_id' => 1, 'workstation_id' => 1,
            'work_type_id' => 1, 'user_id' => 2, 'is_locked' => false, 'is_done' => true,
            'is_active' => true, 'is_deleted' => false, 'deleted_description' => null,
            'created_at' => '2026-01-15 08:00:00', 'updated_at' => '2026-01-15 18:00:00',
        ], $extra);

        $viejo->table('plans')->insert([
            $plan(['id' => 1, 'slug' => 'plan-1', 'code' => 'PE26-1501-0800']),
            // Mismo codigo que el anterior: en la v1 se repetian 196 veces.
            $plan(['id' => 2, 'slug' => 'plan-2', 'code' => 'PE26-1501-0800']),
            $plan(['id' => 3, 'slug' => 'plan-3', 'code' => 'PE26-1501-0900', 'is_deleted' => true, 'deleted_description' => 'anulado']),
            // Plan de otro pais: no se migra.
            $plan(['id' => 4, 'slug' => 'plan-4', 'code' => 'BR26-1501-0800', 'country_id' => 6]),
        ]);

        $viejo->table('plan_workers')->insert([
            // Foto y firma reales: hay fichero detras.
            ['id' => 1, 'plan_id' => 1, 'worker_id' => 1, 'slug' => 'pw-1', 'signature' => 'firma-1.webp', 'photo' => 'foto-1.webp', 'is_approved' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            // Los marcadores de la v1: no existe ningun fichero.
            ['id' => 2, 'plan_id' => 1, 'worker_id' => 2, 'slug' => 'pw-2', 'signature' => 'signed_by_IA', 'photo' => 'detected_by_IA', 'is_approved' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            // Firmo pero no dejo evento: se le crea uno.
            ['id' => 3, 'plan_id' => 2, 'worker_id' => 3, 'slug' => 'pw-3', 'signature' => 'signed_by_IA', 'photo' => 'detected_by_IA', 'is_approved' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            // Ni firma ni foto: no genera evento.
            ['id' => 4, 'plan_id' => 2, 'worker_id' => 4, 'slug' => 'pw-4', 'signature' => null, 'photo' => null, 'is_approved' => false, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        $viejo->table('plan_approvals')->insert([
            ['id' => 1, 'plan_id' => 1, 'approval_rule_id' => 1, 'slug' => 'pa-1', 'approver_id' => 1, 'approver_type' => 'Worker', 'signature' => 'firma-apro-1.webp', 'photo' => 'detected_by_IA', 'is_required' => true, 'is_approved' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 2, 'plan_id' => 1, 'approval_rule_id' => 2, 'slug' => 'pa-2', 'approver_id' => null, 'approver_type' => 'Supervisor', 'signature' => null, 'photo' => null, 'is_required' => true, 'is_approved' => false, 'created_at' => $ahora, 'updated_at' => $ahora],
            // Aprobador que ya no existe en la v1: la firma no se puede atribuir.
            ['id' => 3, 'plan_id' => 2, 'approval_rule_id' => 2, 'slug' => 'pa-3', 'approver_id' => 99, 'approver_type' => 'Supervisor', 'signature' => 'signed_by_IA', 'photo' => 'detected_by_IA', 'is_required' => true, 'is_approved' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        $viejo->table('worker_signature_events')->insert([
            ['id' => 1, 'signable_type' => 'PlanWorker', 'signable_id' => 1, 'role_signed' => 'worker', 'signed_at' => $ahora, 'device_id' => 'dev-5528afcf', 'ip_address' => '190.238.40.88', 'user_agent' => 'Mozilla/5.0 (Linux; Android 10; SM-A105M) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/117.0.0.0 Mobile Safari/537.36', 'latitude' => -12.298051, 'longitude' => -76.836012, 'used_ai' => true, 'manual_override' => false, 'method' => 'face_recognition', 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 2, 'signable_type' => 'PlanWorker', 'signable_id' => 2, 'role_signed' => 'worker', 'signed_at' => $ahora, 'device_id' => 'dev-5528afcf', 'ip_address' => '190.238.40.88', 'user_agent' => 'Mozilla/5.0 (Linux; Android 10; SM-A105M) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/117.0.0.0 Mobile Safari/537.36', 'latitude' => -12.298051, 'longitude' => -76.836012, 'used_ai' => false, 'manual_override' => true, 'method' => 'manual', 'created_at' => $ahora, 'updated_at' => $ahora],
            // Apunta a un plan_worker que ya no existe: se descarta y se cuenta.
            ['id' => 3, 'signable_type' => 'PlanWorker', 'signable_id' => 999, 'role_signed' => 'worker', 'signed_at' => $ahora, 'device_id' => 'dev-5528afcf', 'ip_address' => '190.238.40.88', 'user_agent' => 'Mozilla/5.0 (Linux; Android 10; SM-A105M) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/117.0.0.0 Mobile Safari/537.36', 'latitude' => -12.298051, 'longitude' => -76.836012, 'used_ai' => false, 'manual_override' => false, 'method' => 'manual', 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        // ── un formato de cada tipo, sobre el plan 1 ──────────────────────────
        $doc = fn (int $documentId, array $extra = []) => array_merge([
            'document_id' => $documentId, 'plan_id' => 1, 'slug' => 'doc-' . $documentId,
            'observations' => 0, 'is_confirmed' => true, 'is_deleted' => false,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ], $extra);

        $viejo->table('f1_documents')->insert([$doc(1, ['id' => 1, 'adrights' => 'Permiso de altura', 'eqtools' => 'Arnes'])]);
        $viejo->table('f1_document_activities')->insert([
            ['id' => 1, 'f1_document_id' => 1, 'name' => 'Izaje de carga', 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 2, 'f1_document_id' => 1, 'name' => 'Sin peligros', 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
        $viejo->table('f1_document_dangers')->insert([
            ['id' => 1, 'f1_document_activity_id' => 1, 'name_danger' => 'Carga suspendida', 'name_risk' => 'Golpe', 'name_control' => 'Delimitar', 'probability_id' => 2, 'severity_id' => 3, 'risk_value' => 6, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
        $viejo->table('f1_document_equipments')->insert([['id' => 1, 'f1_document_id' => 1, 'ast_equipment_id' => 1, 'created_at' => $ahora, 'updated_at' => $ahora]]);
        $viejo->table('f1_document_objetives')->insert([['id' => 1, 'f1_document_id' => 1, 'ast_objetive_id' => 1, 'created_at' => $ahora, 'updated_at' => $ahora]]);

        $viejo->table('f2_documents')->insert([$doc(2, ['id' => 1])]);
        $viejo->table('f2_document_answers')->insert([
            ['id' => 1, 'f2_document_id' => 1, 'ptf_question_id' => 1, 'answer' => 1, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 2, 'f2_document_id' => 1, 'ptf_question_id' => 2, 'answer' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        $viejo->table('f3_documents')->insert([$doc(3, ['id' => 1])]);
        $viejo->table('f3_document_workers')->insert([
            ['id' => 1, 'f3_document_id' => 1, 'plan_worker_id' => 1, 'correction_measure' => 'Cambiar casco', 'deadline_date' => '2026-01-31', 'correction_verification' => 'ok', 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
        $viejo->table('f3_document_answers')->insert([
            ['id' => 1, 'f3_document_worker_id' => 1, 'epp_item_id' => 1, 'answer' => 1, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 2, 'f3_document_worker_id' => 1, 'epp_item_id' => 2, 'answer' => null, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        $viejo->table('f4_documents')->insert([$doc(4, ['id' => 1])]);
        $viejo->table('f4_document_tools')->insert([
            ['id' => 1, 'f4_document_id' => 1, 'name' => 'Amoladora', 'is_enabled' => false, 'correction_measure' => 'Retirar', 'responsible' => 'Jefe', 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
        // Los tres valores de la v1, para que la migracion los cruce enteros:
        // 1 es el check, 0 es la equis y 2 es la raya. Antes estaban solo el 1 y
        // el 2, asi que el caso que de verdad importa —el incumplimiento, que es
        // el 0— no lo cubria nadie de extremo a extremo, y el 2 se afirmaba como
        // «No cumple» cuando es «No aplica».
        $viejo->table('f4_document_items')->insert([
            ['id' => 1, 'f4_document_tool_id' => 1, 'ihm_item_id' => 1, 'answer' => 1, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 2, 'f4_document_tool_id' => 1, 'ihm_item_id' => 2, 'answer' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['id' => 3, 'f4_document_tool_id' => 1, 'ihm_item_id' => 3, 'answer' => 2, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    /**
     * Llena `users` como en la base completa: correo real, hash de Devise y
     * perfil. El primer volcado vino sin esto, y la migracion tiene que
     * aprovecharlo cuando esta.
     *
     * El hash es bcrypt de "secreto123" con prefijo `$2a$`, que es el que
     * escribe Devise. Que PHP lo acepte es justo lo que se quiere comprobar.
     */
    public static function conBaseCompleta(): void
    {
        $viejo = \Illuminate\Support\Facades\DB::connection('legacy');
        $hash = str_replace('$2y$', '$2a$', password_hash('secreto123', PASSWORD_BCRYPT));

        $viejo->table('users')->insert([
            ['id' => 1, 'email' => 'jefe@empresa.test',       'encrypted_password' => $hash, 'real_password' => 'secreto123', 'profile_id' => 2, 'country_id' => 1, 'is_hidden' => false],
            ['id' => 2, 'email' => 'supervisor@empresa.test', 'encrypted_password' => $hash, 'real_password' => null,          'profile_id' => 3, 'country_id' => 1, 'is_hidden' => false],
            ['id' => 3, 'email' => 'campo@empresa.test',      'encrypted_password' => $hash, 'real_password' => null,          'profile_id' => 4, 'country_id' => 1, 'is_hidden' => true],
        ]);

        $viejo->table('user_details')->insert([
            ['id' => 3, 'user_id' => 3, 'name' => 'Usuario', 'lastname' => 'Tres', 'cellphone' => null],
        ]);
    }
}
