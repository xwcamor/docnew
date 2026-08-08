<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\People\BulkPeopleActionJob;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PersonCompanyLink;
use Illuminate\Support\Facades\DB;

/**
 * PersonService â€” operaciones de negocio del modulo people.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class PersonService
{
    public function create(array $data): Person
    {
        $vinculo = $this->extraerVinculo($data);
        $roles   = $this->extraerRoles($data);

        return DB::transaction(function () use ($data, $vinculo, $roles) {
            $person = new Person($data);
            $person->created_by = auth()->id();
            $person->save();

            $this->guardarVinculo($person, $vinculo);
            // Sin rol, la persona no es nadie en obra: nace trabajadora.
            $this->guardarRoles($person, $roles ?? [PersonRole::WORKER]);

            return $person;
        });
    }

    public function update(Person $person, array $data): Person
    {
        $vinculo = $this->extraerVinculo($data);
        $roles   = $this->extraerRoles($data);

        return DB::transaction(function () use ($person, $data, $vinculo, $roles) {
            $person->update($data);
            $this->guardarVinculo($person, $vinculo);

            if ($roles !== null) {
                $this->guardarRoles($person, $roles);
            }

            return $person;
        });
    }

    /**
     * Saca del formulario los roles en obra, que tampoco son columnas de la
     * persona: viven en `person_roles`.
     *
     * @return array<int, string>|null  null = el formulario no los mandó
     */
    private function extraerRoles(array &$data): ?array
    {
        if (! array_key_exists('roles', $data)) {
            return null;
        }

        $roles = array_values(array_unique(array_filter((array) $data['roles'])));
        unset($data['roles']);

        return $roles;
    }

    /**
     * Deja exactamente los roles que se pidieron.
     *
     * Los que se quitan se **desactivan**, no se borran: una firma vieja apunta
     * al rol con el que se firmó y esa fila es la prueba de quién validó qué.
     *
     * @param  array<int, string>  $roles
     */
    private function guardarRoles(Person $person, array $roles): void
    {
        foreach ($roles as $rol) {
            $person->roles()->updateOrCreate(['role' => $rol], ['is_active' => true]);
        }

        $person->roles()
            ->when($roles !== [], fn ($q) => $q->whereNotIn('role', $roles))
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Saca del formulario la empresa y el cargo, que no son columnas de la
     * persona: viven en `person_company_links`.
     *
     * En el sistema anterior un trabajador ERA de una empresa y tenia UN cargo
     * (`workers.company_id` y `workers.position_id`, los dos NOT NULL). Aqui la
     * persona es una sola identidad y lo que se multiplica son los vinculos,
     * porque la misma persona puede ser tecnico en una contratista y supervisor
     * en otra — y en la v1 eso eran dos filas de `workers` con el mismo DNI.
     *
     * El formulario edita el vinculo de la empresa que se elija; los demas se
     * conservan y se ven en la ficha.
     */
    private function extraerVinculo(array &$data): array
    {
        $vinculo = [
            'company_id'  => $data['company_id'] ?? null,
            'position_id' => $data['position_id'] ?? null,
        ];

        unset($data['company_id'], $data['position_id']);

        return $vinculo;
    }

    private function guardarVinculo(Person $person, array $vinculo): void
    {
        if (blank($vinculo['company_id'])) {
            return;
        }

        $person->companyLinks()->updateOrCreate(
            ['company_id' => $vinculo['company_id']],
            ['position_id' => $vinculo['position_id'], 'is_active' => true],
        );
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(Person $person, string $reason): void
    {
        $person->deleted_description = $reason;
        $person->deleted_by          = auth()->id();
        $person->is_active           = false;
        $person->saveQuietly();
        $person->delete();
    }

    public function restore(Person $person): Person
    {
        $person->deleted_description = null;
        $person->deleted_by          = null;
        $person->restore();
        return $person;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(Person $person, string $reason): void
    {
        DB::transaction(function () use ($person, $reason) {
            $locked = Person::onlyTrashed()->where('id', $person->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Person {$person->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Person::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name'    => $locked->full_name,
                    'num_doc' => $locked->num_doc,
                    'slug' => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'people',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona la persona para dar de alta a alguien con los mismos datos de
     * contexto (mismo país y nacionalidad). El DOCUMENTO no se copia: es lo que
     * identifica a la persona y dos personas nunca comparten uno. El clon nace
     * con un documento provisional que hay que reemplazar al editarlo.
     */
    public function duplicate(Person $person): ?Person
    {
        return DB::transaction(function () use ($person) {
            $candidato = null;

            for ($i = 0; $i < 100; $i++) {
                $intento = 'COPIA-' . strtoupper(\Illuminate\Support\Str::random(8));
                $existe  = Person::query()
                    ->where('doc_type', $person->doc_type)
                    ->where('num_doc', $intento)
                    ->lockForUpdate()
                    ->exists();
                if (! $existe) { $candidato = $intento; break; }
            }
            if ($candidato === null) return null;

            $clone = new Person($person->only([
                'is_active', 'name', 'lastname', 'doc_type', 'country_id',
                'nationality_id', 'birthdate',
            ]));
            $clone->num_doc    = $candidato;
            $clone->created_by = auth()->id();
            $clone->save();

            // Y el contexto de trabajo, que es para lo que se duplica: dar de
            // alta al siguiente de la misma cuadrilla. Sin esto el clon nacía
            // sin empresa ni cargo —los dos obligatorios en el formulario— y
            // había que volver a elegirlos a mano.
            $vinculo = $person->companyLinks->firstWhere('is_active', true)
                ?? $person->companyLinks->first();
            if ($vinculo) {
                $this->guardarVinculo($clone, [
                    'company_id'  => $vinculo->company_id,
                    'position_id' => $vinculo->position_id,
                ]);
            }

            $this->guardarRoles($clone, $person->roles()->where('is_active', true)->pluck('role')->all());

            return $clone;
        });
    }

    // â”€â”€â”€ Bulk ops â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    //
    // Auto-async: si count(ids) excede el umbral, dispatchamos el job y
    // devolvemos un payload "queued" para que el controller redirija con
    // mensaje de cola. Bajo el umbral, corre inline. El umbral vive en
    // BulkPeopleActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkPeopleActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkPeopleActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $people  = Person::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($people as $person) {
                $this->delete($person, $reason);
                $deletedIds[] = $person->id;
            }
            return ['queued' => false, 'count' => $people->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkPeopleActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $people = Person::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($people as $person) {
                if ((bool) $person->is_active === $isActive) continue;
                $person->update(['is_active' => $isActive]);
                $changed++;
            }
            return ['queued' => false, 'count' => $count, 'changed' => $changed];
        });
    }

    /**
     * @return array{queued: bool, count: int, restored?: int}
     */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkPeopleActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $people = Person::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($people as $person) {
                $this->restore($person);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $people->count()];
        });
    }

    /**
     * Undo dentro del window de 60s. Defense in depth: solo restaura las filas
     * que matchean deleted_by = userId, no cualquier id del claim.
     *
     * @param int[] $claimIds
     * @return int[] ids efectivamente restaurados
     */
    public function undoLastDelete(array $claimIds, int $userId): array
    {
        $people = Person::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($people as $person) {
            $this->restore($person);
            $restored[] = $person->id;
        }
        return $restored;
    }

    /**
     * Batch update de name + is_active. Persistencia en transaccion para
     * atomicidad. Skip filas sin cambio real para evitar audit log noise.
     *
     * @return int touched count
     */
    public function editAllUpdate(array $changes): int
    {
        $touched = 0;

        DB::transaction(function () use ($changes, &$touched) {
            $ids   = array_column($changes, 'id');
            $byId  = Person::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $person = $byId[$change['id']] ?? null;
                if (!$person) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'lastname', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $person->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $person->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }

    /**
     * El alta de una persona desde un plan de trabajo.
     *
     * Esta es la correccion de fondo respecto al sistema viejo: alli cada alta
     * creaba un Worker nuevo por empresa, y la misma persona terminaba con una
     * identidad, una foto y una firma distintas en cada contratista (391 filas
     * para 231 personas reales). Aqui la identidad se busca primero por su
     * documento; si ya existe, solo se crea el vinculo con la empresa y la
     * persona conserva su biometria y su firma.
     *
     * @return array{persona: Person, creada: bool}
     */
    public function vincularOCrear(array $datos, int $companyId, ?int $positionId = null): array
    {
        return DB::transaction(function () use ($datos, $companyId, $positionId) {
            $persona = Person::withTrashed()
                ->where('country_id', $datos['country_id'])
                ->where('doc_type', $datos['doc_type'] ?? 'DNI')
                ->where('num_doc', $datos['num_doc'])
                ->first();

            $creada = false;

            if (! $persona) {
                $persona = Person::create($datos);
                $creada = true;
            } elseif ($persona->trashed()) {
                // Se restaura, no se duplica: la persona vuelve con su historial.
                $persona->restore();
            }

            PersonCompanyLink::updateOrCreate(
                ['person_id' => $persona->id, 'company_id' => $companyId],
                ['position_id' => $positionId, 'is_active' => true, 'started_on' => now()],
            );

            if (! $persona->hasRole(PersonRole::WORKER)) {
                PersonRole::updateOrCreate(
                    ['person_id' => $persona->id, 'role' => PersonRole::WORKER],
                    ['is_active' => true],
                );
            }

            return ['persona' => $persona->fresh(), 'creada' => $creada];
        });
    }
}
