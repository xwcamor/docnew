<?php

namespace Database\Factories;

use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Una regla del flujo de mentira, para pruebas.
 *
 * `country_id` no se inventa: una regla sin pais valido no resuelve nada, asi
 * que la prueba tiene que decir a que pais pertenece. `work_type_id` en nulo
 * es el caso por defecto — la regla vale para todos los tipos.
 */
class ApprovalRuleFactory extends Factory
{
    protected $model = ApprovalRule::class;

    public function definition(): array
    {
        return [
            'slug'           => Str::random(22),
            'work_type_id'   => null,
            'approver_role'  => ApproverRole::SUPERVISOR,
            'priority_level' => 1,
            'is_required'    => true,
            'is_active'      => true,
        ];
    }

    /** Acota la regla a un tipo de trabajo: ese tipo deja de heredar las generales. */
    public function forWorkType(int $workTypeId): self
    {
        return $this->state(fn () => ['work_type_id' => $workTypeId]);
    }

    public function optional(): self
    {
        return $this->state(fn () => ['is_required' => false]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
