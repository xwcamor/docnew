<?php

namespace Database\Seeders;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\Country;
use App\Models\EvidenceFile;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\PersonBiometric;
use App\Models\PersonCompanyLink;
use App\Models\PersonRole;
use App\Models\Position;
use App\Models\SignatureEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Models\Workstation;
use App\Models\WorkType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Un dia de trabajo completo, de punta a punta: la empresa contratista, la
 * cuadrilla, el plan del dia, dos formatos (uno con campos y otro que es solo
 * la foto del papel) y las firmas con su evidencia.
 *
 * Sirve de demostracion y de prueba: si el modelo de datos no cierra, este
 * seeder falla.
 */
class DocufizDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::first();
        $country = Country::first();
        $user    = User::first();

        if (! $tenant || ! $country || ! $user) {
            $this->command->warn('Faltan tenant, pais o usuario: se omite la demo de DOCUFIZ.');

            return;
        }

        $base = ['tenant_id' => $tenant->id, 'created_by' => $user->id];

        // ── Catalogos de obra ────────────────────────────────────────────
        $cargoObrero = Position::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id,
            'code' => 'OPERARIO',
        ]);

        $cargoSupervisor = Position::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id,
            'code' => 'SUPERVISOR',
        ]);

        $sede = WorkLocation::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id, 'name' => 'Planta Lurin',
        ]);

        $puesto = Workstation::create($base + [
            'slug' => Str::random(22), 'work_location_id' => $sede->id, 'name' => 'Celda 3',
        ]);

        $tipoTrabajo = WorkType::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id, 'code' => 'MANTENIMIENTO',
        ]);

        // ── Empresa contratista ──────────────────────────────────────────
        $empresa = Company::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id,
            // Del catalogo del pais, no escrito aqui: ver
            // `DocumentType::deLaEmpresaDe()`.
            'doc_type' => \App\Models\DocumentType::deLaEmpresaDe($country->id),
            'num_doc' => '20521314649', 'name' => 'SERCE',
            'complete_name' => 'SERVICIOS DE CONSTRUCCIONES ELECTRICAS PERU SAC',
            'is_active' => true,
        ]);

        // ── Personas: una identidad, sus roles y su vinculo con la empresa ─
        $trabajador = $this->crearPersona($base, $country, $nacionalidad, '10199705', 'Ezequiel Luis', 'Duenas Patricio');
        $supervisor = $this->crearPersona($base, $country, $nacionalidad, '10748163', 'Willy Edgar', 'Lara Aguilar');

        PersonRole::create(['person_id' => $trabajador->id, 'role' => PersonRole::WORKER]);
        PersonRole::create(['person_id' => $supervisor->id, 'role' => PersonRole::SUPERVISOR]);

        PersonCompanyLink::create([
            'person_id' => $trabajador->id, 'company_id' => $empresa->id,
            'position_id' => $cargoObrero->id, 'started_on' => now()->subYear(),
        ]);
        PersonCompanyLink::create([
            'person_id' => $supervisor->id, 'company_id' => $empresa->id,
            'position_id' => $cargoSupervisor->id, 'started_on' => now()->subYears(3),
        ]);

        // Biometria: 3 muestras de 128 valores, como las captura el enrolamiento.
        foreach ([$trabajador, $supervisor] as $persona) {
            PersonBiometric::create([
                'person_id' => $persona->id,
                'face_descriptor' => collect(range(1, 3))
                    ->map(fn () => collect(range(1, 128))->map(fn () => round(mt_rand(-100, 100) / 100, 4))->all())
                    ->all(),
                'enrolled_at' => now(), 'enrolled_by' => $user->id, 'is_active' => true,
            ]);
        }

        // ── Plan del dia ─────────────────────────────────────────────────
        $plan = WorkPlan::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id,
            'company_id' => $empresa->id, 'work_type_id' => $tipoTrabajo->id,
            'work_location_id' => $sede->id, 'workstation_id' => $puesto->id,
            'user_id' => $user->id, 'code' => 'PE26-0807-0001', 'num_os' => '08072026',
            'description' => 'Mantenimiento preventivo de celda de media tension',
            'date_start' => today(),
        ]);

        $planTrabajador = WorkPlanPerson::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id, 'person_id' => $trabajador->id,
        ]);

        $regla = ApprovalRule::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id,
            'approver_role' => PersonRole::SUPERVISOR, 'priority_level' => 1, 'is_required' => true,
        ]);

        $aprobacion = WorkPlanApproval::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'approval_rule_id' => $regla->id, 'person_id' => $supervisor->id,
        ]);

        // ── Formato con campos propios ───────────────────────────────────
        $ast = FormTemplate::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id, 'code' => 'AST',
            'kind' => FormTemplate::STRUCTURED, 'status' => 'published', 'version' => 1,
            'published_at' => now(),
        ]);

        $seccion = FormSection::create(['form_template_id' => $ast->id, 'position' => 1]);

        FormField::create([
            'form_section_id' => $seccion->id, 'code' => 'actividad',
            'field_type' => 'text', 'is_required' => true, 'position' => 1,
        ]);
        FormField::create([
            'form_section_id' => $seccion->id, 'code' => 'matriz_riesgo',
            'field_type' => 'risk_matrix', 'is_required' => true, 'position' => 2,
            'config' => ['severidades' => 5, 'probabilidades' => 5],
        ]);

        // ── Formato que es solo la foto del papel: la "HOJA X" ───────────
        $hojaX = FormTemplate::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id, 'code' => 'HOJA-X',
            'kind' => FormTemplate::UPLOAD_ONLY, 'status' => 'published', 'version' => 1,
            'published_at' => now(), 'requires_signature' => true,
        ]);

        $ast->workTypes()->attach($tipoTrabajo->id, ['is_required' => true]);
        $hojaX->workTypes()->attach($tipoTrabajo->id, ['is_required' => false]);

        $entrega = FormSubmission::create($base + [
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $ast->id, 'template_version' => $ast->version,
            'status' => 'submitted', 'submitted_by' => $user->id, 'submitted_at' => now(),
        ]);

        $entrega->answers()->create([
            'form_field_id' => $ast->fields()->where('code', 'actividad')->first()->id,
            'value_text' => 'Limpieza y ajuste de conexiones en celda 3',
        ]);

        // ── Firmas: una reconocida y otra capturada por tiempo de espera ──
        $firmaTrabajador = SignatureEvent::create([
            'signable_type' => WorkPlanPerson::class, 'signable_id' => $planTrabajador->id,
            'person_id' => $trabajador->id, 'role_signed' => PersonRole::WORKER,
            'signed_at' => now(), 'method' => SignatureEvent::FACE_RECOGNITION,
            'used_ai' => true, 'match_distance' => 0.3812, 'threshold_used' => 0.5,
            'latitude' => -12.1687, 'longitude' => -76.9662,
            'device_id' => 'tablet-obra-01', 'ip_address' => '10.0.0.5',
            'country_code' => 'PE', 'city' => 'Lurin', 'tenant_id' => $tenant->id,
        ]);

        EvidenceFile::create([
            'signature_event_id' => $firmaTrabajador->id, 'kind' => EvidenceFile::FACE,
            'file_path' => 'evidencias/demo/rostro-trabajador.webp',
            'sha256' => hash('sha256', 'rostro-trabajador'), 'byte_size' => 48211,
            'width' => 400, 'height' => 400, 'taken_at' => now(),
        ]);

        $planTrabajador->update(['is_approved' => true]);

        // El supervisor no fue reconocido a tiempo: se capturo igual y queda
        // pendiente de revision. La firma NO se bloquea.
        $firmaSupervisor = SignatureEvent::create([
            'signable_type' => WorkPlanApproval::class, 'signable_id' => $aprobacion->id,
            'person_id' => $supervisor->id, 'role_signed' => PersonRole::SUPERVISOR,
            'signed_at' => now(), 'method' => SignatureEvent::TIMEOUT_CAPTURE,
            'used_ai' => false, 'threshold_used' => 0.5, 'pending_review' => true,
            'latitude' => -12.1687, 'longitude' => -76.9662,
            'device_id' => 'tablet-obra-01', 'tenant_id' => $tenant->id,
        ]);

        EvidenceFile::create([
            'signature_event_id' => $firmaSupervisor->id, 'kind' => EvidenceFile::FACE,
            'file_path' => 'evidencias/demo/rostro-supervisor.webp',
            'sha256' => hash('sha256', 'rostro-supervisor'), 'byte_size' => 51102,
            'width' => 400, 'height' => 400, 'taken_at' => now(),
        ]);

        $aprobacion->update(['is_approved' => true]);

        $this->command->info(sprintf(
            'DOCUFIZ demo: plan %s · %d persona(s) · %d formato(s) · %d firma(s), %d pendiente(s) de revision.',
            $plan->code,
            $plan->people()->count(),
            FormTemplate::count(),
            SignatureEvent::count(),
            SignatureEvent::pendingReview()->count(),
        ));
    }

    private function crearPersona(array $base, $country, $nacionalidad, string $numDoc, string $nombre, string $apellidos): Person
    {
        return Person::create($base + [
            'slug' => Str::random(22), 'country_id' => $country->id,
            // La nacionalidad ES un pais: no hay catalogo aparte.
            'nationality_id' => $country->id, 'doc_type' => 'DNI',
            'num_doc' => $numDoc, 'name' => $nombre, 'lastname' => $apellidos,
        ]);
    }
}
