<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Facades\Lang;

/**
 * Deriva los nombres de :attribute de los mensajes de validación desde el MISMO
 * archivo de idioma que usan los labels del formulario, para que el error diga
 * exactamente lo que el usuario ve en el campo (ej. "Workspace", no "tenant_id"
 * ni un texto inventado). El request define $attributeNamespace (ej.
 * 'automations'); los campos cuyo label vive bajo otra clave se resuelven con
 * $attributeOverrides (clave de lang completa) o con los alias de abajo. Si no
 * se encuentra etiqueta, el campo se OMITE y Laravel cae al mapa global de
 * resources/lang/{es,en}/validation.php → 'attributes'. Por eso el trait solo
 * puede mejorar o igualar el texto, nunca empeorarlo.
 */
trait DerivesAttributesFromLang
{
    public function attributes(): array
    {
        $ns        = property_exists($this, 'attributeNamespace') ? $this->attributeNamespace : null;
        $overrides = property_exists($this, 'attributeOverrides') ? $this->attributeOverrides : [];
        $out       = [];

        foreach ($this->attributeRuleFields() as $field) {
            // 1) Override explícito (clave de lang completa del request).
            if (isset($overrides[$field]) && Lang::has($overrides[$field])) {
                $out[$field] = __($overrides[$field]);
                continue;
            }
            // 2) Clave del propio módulo  3) alias del módulo  4) etiqueta canónica.
            foreach ($this->attributeKeyCandidates($ns, $field) as $key) {
                if ($key && Lang::has($key)) {
                    $out[$field] = __($key);
                    break;
                }
            }
        }

        return $out;
    }

    /** Claves de campo de las reglas, sin los wildcard (lista.*.campo → al global). */
    protected function attributeRuleFields(): array
    {
        $fields = [];
        foreach (array_keys($this->rules()) as $key) {
            if (str_contains($key, '*')) {
                continue;
            }
            $fields[$key] = true;
        }

        return array_keys($fields);
    }

    /** Candidatos de clave de lang para un campo, en orden de preferencia. */
    protected function attributeKeyCandidates(?string $ns, string $field): array
    {
        // Alias dentro del MISMO módulo: el label usa otra clave que el nombre
        // del campo (ej. el FK tenant_id se rotula con la clave 'workspace').
        static $sameModuleAlias = [
            'tenant_id'   => ['workspace', 'tenant'],
            'country_id'  => ['country'],
            'customer_id' => ['customer'],
            'oil_type_id' => ['oil_type'],
            'region_id'   => ['region'],
            'language_id' => ['language'],
            'role_id'     => ['role'],
            'brand_id'    => ['brand'],
        ];
        // Etiqueta canónica de la entidad (cross-módulo): el 'singular' del dueño.
        static $canonical = [
            'tenant_id'         => 'tenants.singular',
            'country_id'        => 'countries.singular',
            'customer_id'       => 'customers.singular',
            'oil_type_id'       => 'oil_types.singular',
            'region_id'         => 'regions.singular',
            'language_id'       => 'languages.singular',
            'role_id'           => 'roles.singular',
            'brand_id'          => 'brands.singular',
            'locale_id'         => 'locales.singular',
            'default_locale_id' => 'locales.singular',
        ];

        $candidates = [];
        if ($ns) {
            $candidates[] = "$ns.$field";
            foreach ($sameModuleAlias[$field] ?? [] as $alias) {
                $candidates[] = "$ns.$alias";
            }
        }
        if (isset($canonical[$field])) {
            $candidates[] = $canonical[$field];
        }

        return $candidates;
    }
}
