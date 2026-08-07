<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC APP — identidad de personas.
 *
 * En el sistema v1 habia tres tablas (workers, supervisors, hse_supervisors) y
 * ademas una fila por cada empresa en la que la persona trabajaba: 391 filas
 * para 231 personas reales, cada una con su propia foto y firma. Aqui la
 * identidad es unica y lo que cambia por empresa es el vinculo.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('nationality_id')->nullable()->constrained('nationalities')->nullOnDelete();
            $table->string('doc_type', 20)->default('DNI');
            $table->string('num_doc')->index();
            $table->string('name')->index();
            $table->string('lastname')->index();
            $table->date('birthdate')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('legacy_id')->nullable()->comment('id en el sistema v1');
            $table->string('legacy_table')->nullable();
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_people_deleted_at');
            $table->index('created_at', 'idx_people_created_at');
        });

        Schema::create('person_company_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['person_id', 'company_id'], 'person_company_links_unique');
        });

        Schema::create('person_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role', 30)->comment('worker, supervisor, hse_supervisor');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['person_id', 'role'], 'person_roles_unique');
        });

        Schema::create('person_biometrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->json('face_descriptor');
            $table->decimal('threshold', 4, 3)->nullable()->comment('Si es nulo se usa el del tenant');
            $table->timestamp('enrolled_at');
            $table->unsignedBigInteger('enrolled_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['person_id', 'is_active'], 'idx_person_biometrics_active');
        });

        Schema::create('person_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('sha256', 64)->index();
            $table->string('source', 20)->comment('captured, imported, migrated');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'valid_to'], 'idx_person_signatures_current');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            // El indice unico que faltaba en la v1: alli la unicidad del documento
            // solo vivia en una validacion de Rails y ademas estaba limitada por empresa.
            DB::statement(
                "CREATE UNIQUE INDEX people_document_unique_active " .
                "ON people (tenant_id, country_id, doc_type, num_doc) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('person_signatures');
        Schema::dropIfExists('person_biometrics');
        Schema::dropIfExists('person_roles');
        Schema::dropIfExists('person_company_links');
        Schema::dropIfExists('people');
    }
};
