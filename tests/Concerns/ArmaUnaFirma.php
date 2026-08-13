<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\Person;
use App\Models\PersonBiometric;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * El decorado minimo para poder firmar: una persona enrolada, dentro de un
 * plan, y alguien con permiso para pulsar el boton.
 *
 * Vive aparte porque lo necesitan pruebas de temas distintos —que pasa al
 * terminar de firmar, y que rastro deja esa firma— y montarlo dos veces es
 * pedir que las dos copias se separen.
 */
trait ArmaUnaFirma
{
    /** Lo que manda la pantalla al firmar. La firma trazada viaja porque es su primera vez. */
    protected function firmaDe(Person $persona, WorkPlanPerson $asignado): array
    {
        return [
            'signable_type' => 'work_plan_person',
            'signable_slug' => $asignado->slug,
            'person_slug'   => $persona->slug,
            'role_signed'   => 'worker',
            'descriptor'    => $this->descriptorEnrolado(),
            'photo'         => $this->imagen(),
            'signature'     => $this->imagen(),
        ];
    }

    /** El vector que quedo enrolado: comparado consigo mismo, distancia 0. */
    protected function descriptorEnrolado(): array
    {
        return array_map(fn (int $i) => round($i / 128, 4), range(0, 127));
    }

    /** @return array{0:User,1:Person,2:WorkPlanPerson} */
    protected function escenario(): array
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $usuario->givePermissionTo(
            Permission::firstOrCreate(['name' => 'form_submissions.sign', 'guard_name' => 'web']),
        );

        $empresa = Company::create($base + [
            'num_doc' => (string) random_int(20000000000, 20999999999),
            'name' => 'Contratista', 'complete_name' => 'Contratista SAC', 'is_active' => true,
        ]);

        $persona = Person::create($base + [
            'doc_type' => 'DNI', 'num_doc' => '40000002', 'name' => 'Ana', 'lastname' => 'Quispe',
        ]);

        PersonBiometric::create([
            'person_id'       => $persona->id,
            'face_descriptor' => [$this->descriptorEnrolado()],
            'enrolled_at'     => now(),
            'enrolled_by'     => $usuario->id,
            'is_active'       => true,
        ]);

        $tipo = WorkType::create(['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1, 'code' => 'MTTO']);
        $lugar = WorkLocation::create(['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1, 'name' => 'Planta']);

        $plan = WorkPlan::create($base + [
            'slug'             => Str::random(22),
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $lugar->id,
            'user_id'          => $usuario->id,
            'code'             => 'OT-' . random_int(1000, 9999),
            'description'      => 'Mantenimiento programado',
            'date_start'       => today(),
        ]);

        $asignado = WorkPlanPerson::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $persona->id,
        ]);

        return [$usuario, $persona, $asignado];
    }

    /** Una imagen minima y valida: vale de foto de evidencia y de firma trazada. */
    protected function imagen(): string
    {
        $img = imagecreatetruecolor(40, 20);
        imagefilledrectangle($img, 0, 0, 39, 19, imagecolorallocate($img, 120, 40, 200));

        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }

    /** Los padres de catalogo sin los que no se puede crear ni un usuario. */
    protected function sembrarPadresDeFirma(): void
    {
        \Illuminate\Support\Facades\DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }
}
