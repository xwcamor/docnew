<?php

namespace App\Http\Resources;

use App\Support\CifradoEnReposo;
use App\Support\ValoresAuditados;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Shape estándar de un audit log entry para el activity feed del Show de
 * cualquier módulo. Compartido — cuando clones a Patients/Doctors/etc, el
 * feed usa este mismo Resource sin tocar nada.
 *
 * Además del crudo (old/new_values), entrega `changes`: el diff HUMANIZADO de
 * las ediciones (etiqueta de campo traducida + valores legibles, FK resueltas
 * a su nombre, booleanos Sí/No, ocultando columnas de sistema). Así el
 * "Historial" se lee en idioma de usuario en TODOS los módulos, no solo trafos.
 *
 * Asume `with('user:id,name,email')` en el query origen para evitar N+1.
 */
class AuditLogResource extends JsonResource
{
    /** Columnas internas/de sistema: no son ediciones "humanas" → no se muestran. */
    protected const IGNORE = [
        // Indice ciego del documento: es un HMAC derivado de `num_doc`, no un
        // dato que nadie edite. Cambia solo cuando cambia el documento —que SI
        // sale, en la fila de al lado— asi que enseñarlo seria contar el mismo
        // cambio dos veces, una de ellas con 64 caracteres de hexadecimal.
        'num_doc_hash',
        'created_at', 'updated_at', 'deleted_at', 'slug', 'password', 'remember_token',
        'email_verified_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
        'terms_accepted_at', 'terms_accepted_version', 'terms_accepted_ip',
        'tenant_id', 'deleted_by', 'deleted_description',
        // Caché de flota (trafos), recalculada por el motor — no es edición de usuario.
        'health_index', 'health_rating', 'fault_type', 'gassing_rate',
        'paper_dp', 'paper_life_years', 'ieee_condition',
    ];

    /** FK que NO siguen la convención {col}_id → App\Models\{Studly(singular(col))}. */
    protected const FK_OVERRIDES = [
        'transformer_preservation_id' => \App\Models\TransformerPreservation::class,
        'customer_substation_id'      => \App\Models\CustomerSubstation::class,
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'event'      => $this->event,
            // Legibles, no en crudo: desde que hay columnas cifradas, el crudo
            // es base64. Ver `ValoresAuditados`.
            'old_values' => ValoresAuditados::legibles($this->old_values, $request->user()),
            'new_values' => ValoresAuditados::legibles($this->new_values, $request->user()),
            'changes'    => $this->event === 'updated' ? $this->humanizeChanges() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'user'       => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ] : null,
        ];
    }

    /** Diff legible de un evento 'updated'. */
    protected function humanizeChanges(): array
    {
        $old  = $this->old_values ?? [];
        $next = $this->new_values ?? [];

        // namespace i18n = tabla del modelo (users, customers, brands…).
        $ns = null;
        $type = $this->auditable_type;
        if ($type && class_exists($type)) {
            try {
                $ns = (new $type)->getTable();
            } catch (\Throwable $e) {
                $ns = null;
            }
        }

        $cache = [];
        $rows = [];
        foreach (array_keys(array_merge($old, $next)) as $col) {
            if (in_array($col, self::IGNORE, true)) {
                continue;
            }
            $before = $this->fmtValue($col, $old[$col] ?? null, $cache);
            $after  = $this->fmtValue($col, $next[$col] ?? null, $cache);
            if ($before === $after) {
                continue;
            }
            $rows[] = [
                'label'  => $this->fieldLabel($ns, $col),
                'before' => $before,
                'after'  => $after,
            ];
        }
        return $rows;
    }

    /**
     * Etiqueta traducida del campo. Prueba, en orden: {ns}.{col} → global.{col};
     * y para FK ({col}_id) también la versión sin sufijo ({ns}.{base} →
     * global.{base} → transformers.{base}). Si nada matchea, prettify sin "id".
     */
    protected function fieldLabel(?string $ns, string $col): string
    {
        $base = str_ends_with($col, '_id') ? substr($col, 0, -3) : null;

        $candidates = array_filter([
            $ns ? "{$ns}.{$col}" : null,
            "global.{$col}",
            $base && $ns ? "{$ns}.{$base}" : null,
            $base ? "global.{$base}" : null,
            $base ? "transformers.{$base}" : null,
        ]);

        foreach ($candidates as $key) {
            $t = __($key);
            if (is_string($t) && $t !== $key) {
                return $t;
            }
        }
        return ucfirst(str_replace('_', ' ', $base ?? $col));
    }

    /** Valor legible: null→—, bool→Sí/No, FK→nombre, enum fases, resto string. */
    protected function fmtValue(string $col, $value, array &$cache): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? __('global.yes') : __('global.no');
        }

        // Lo que va cifrado en la base llega aqui cifrado, y sin esto el
        // historial enseñaba un churro de base64 donde antes ponia el DNI.
        //
        // El trait de auditoria escribe los valores TAL Y COMO van a la tabla
        // —que es lo correcto: seria absurdo cifrar la columna y dejar una
        // copia en claro en la tabla de al lado— asi que el sobre hay que
        // abrirlo al PINTAR, no al guardar. Aqui, que es el unico sitio por
        // donde pasa el historial de todos los modulos.
        if (CifradoEnReposo::estaCifrado($value)) {
            return $this->enClaroSiSePuede($col, $value);
        }
        if ($col === 'phases') {
            return __('transformers.phases_' . $value);
        }
        if (str_ends_with($col, '_id')) {
            $name = $this->resolveFk($col, $value, $cache);
            if ($name !== null) {
                return $name;
            }
        }
        return (string) $value;
    }

    /**
     * Abre el sobre de un valor cifrado, con la misma regla de quien lo ve.
     *
     * EL DOCUMENTO SE ENMASCARA IGUAL QUE EN TODAS PARTES. Que un dato viaje
     * dentro del historial no lo hace menos privado: el enmascarado del listado
     * de personas no serviria de nada si el mismo DNI saliera entero en la
     * pestaña de al lado. Va por `PrivateInfo`, que es la unica regla del
     * sistema sobre esto —y que a proposito NO deja pasar al super por ser
     * super, sino por tener el permiso.
     *
     * Lo demas —el texto de consentimiento— sale entero: es justo lo que hay
     * que poder leer cuando alguien pregunta a que dijo que si.
     *
     * Si no abre con la clave de hoy no se inventa nada ni se revienta la
     * pantalla: se dice que no se puede leer. Es el sintoma de un `APP_KEY`
     * rotado sin re-cifrar, y en un historial es informacion, no una averia.
     */
    protected function enClaroSiSePuede(string $col, string $value): string
    {
        $abierto = ValoresAuditados::legibles([$col => $value], request()->user());

        return (string) ($abierto[$col] ?? __('audit_logs.unreadable'));
    }

    /** Resuelve un id de FK a su nombre por convención (con overrides). */
    protected function resolveFk(string $col, $id, array &$cache): ?string
    {
        $model = self::FK_OVERRIDES[$col]
            ?? ('App\\Models\\' . Str::studly(Str::singular(substr($col, 0, -3))));

        if (!class_exists($model)) {
            return null;
        }

        $key = $model . ':' . $id;
        if (!array_key_exists($key, $cache)) {
            try {
                $q = method_exists(new $model, 'trashed') ? $model::withTrashed() : $model::query();
                $m = $q->find($id);
                $cache[$key] = $m->name ?? $m->title ?? null;
            } catch (\Throwable $e) {
                $cache[$key] = null;
            }
        }
        return $cache[$key];
    }
}
