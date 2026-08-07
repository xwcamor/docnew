<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC APP — motor de formatos.
 *
 * En la v1 cada formato (AST, PTF, EPP, IHM) era una tabla, un modelo, un
 * controlador de 150-250 lineas, entre 8 y 12 vistas y dos plantillas PDF:
 * entre 3 y 5 dias de trabajo por formato. Aqui un formato es configuracion.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('code')->index();
            $table->string('kind', 20)->default('structured')->comment('structured, upload_only, hybrid');
            $table->string('status', 20)->default('draft')->comment('draft, published, archived');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('requires_signature')->default(true);
            $table->string('pdf_template')->nullable()->comment('Plantilla propia cuando el diseno es fijo por ley');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_form_templates_deleted_at');
            $table->index('created_at', 'idx_form_templates_created_at');
        });

        Schema::create('form_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_section_id')->constrained('form_sections')->cascadeOnDelete();
            $table->string('code');
            $table->string('field_type', 30)->comment(
                'text, textarea, number, date, time, select, multiselect, checkbox, radio, table, ' .
                'photo, file, signature, person_checklist, tool_checklist, risk_matrix, question_bank'
            );
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->json('config')->nullable()->comment('Opciones, limites y catalogo de origen');
            $table->json('visibility_rule')->nullable();
            $table->timestamps();
            $table->unique(['form_section_id', 'code'], 'form_fields_code_unique');
        });

        Schema::create('work_type_form_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_type_id')->constrained('work_types')->cascadeOnDelete();
            $table->foreignId('form_template_id')->constrained('form_templates')->cascadeOnDelete();
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['work_type_id', 'form_template_id'], 'work_type_form_templates_unique');
        });

        // Captura: guarda la version de plantilla con la que se lleno, para que
        // publicar una version nueva no altere lo ya firmado.
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('work_plan_id')->constrained('work_plans')->cascadeOnDelete();
            $table->foreignId('form_template_id')->constrained('form_templates')->restrictOnDelete();
            $table->unsignedInteger('template_version');
            $table->string('status', 20)->default('draft')->comment('draft, submitted, confirmed');
            $table->text('observations')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_form_submissions_deleted_at');
            $table->index('created_at', 'idx_form_submissions_created_at');
            $table->unique(['work_plan_id', 'form_template_id'], 'form_submissions_unique');
        });

        Schema::create('form_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained('form_submissions')->cascadeOnDelete();
            $table->foreignId('form_field_id')->constrained('form_fields')->cascadeOnDelete();
            $table->unsignedInteger('row_index')->default(0)->comment('Para campos de tipo tabla');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 16, 4)->nullable();
            $table->timestamp('value_datetime')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();
            $table->unique(['form_submission_id', 'form_field_id', 'row_index'], 'form_answers_unique');
        });

        // El caso "HOJA X": el formato es una foto del papel.
        Schema::create('form_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained('form_submissions')->cascadeOnDelete();
            $table->foreignId('form_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            $table->string('file_path');
            $table->string('sha256', 64)->index();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX form_templates_code_version_unique_active " .
                "ON form_templates (tenant_id, country_id, UPPER(code), version) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_attachments');
        Schema::dropIfExists('form_answers');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('work_type_form_templates');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('form_sections');
        Schema::dropIfExists('form_templates');
    }
};
