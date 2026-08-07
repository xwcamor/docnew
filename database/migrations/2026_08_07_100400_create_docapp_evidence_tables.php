<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC APP — evidencias de firma.
 *
 * Fuente unica de verdad de firmas y fotos. En la v1 esta tabla ya existia con
 * el diseno correcto pero nunca se conecto a la aplicacion: el 83 % de las
 * fotos y el 96 % de las firmas eran la cadena "detected_by_IA" / "signed_by_IA"
 * en vez de un archivo.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('signature_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('signable');
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('role_signed', 30);
            $table->timestamp('signed_at');
            $table->string('method', 30)->comment(
                'face_recognition, timeout_capture, manual, reused, migrated'
            );

            $table->boolean('used_ai')->default(false);
            $table->decimal('match_distance', 6, 4)->nullable()->comment('Calculada en el servidor');
            $table->decimal('threshold_used', 4, 3)->nullable();

            // Si el reconocimiento no encuentra coincidencia en el tiempo de
            // espera configurado, se captura igual y se marca para revision:
            // nunca se bloquea el trabajo en campo.
            $table->boolean('pending_review')->default(false);
            $table->boolean('manual_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->boolean('evidence_missing')->default(false)
                ->comment('Solo para lo migrado de la v1, donde no existe archivo');

            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();
            $table->string('device_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('region')->nullable();
            $table->string('city')->nullable();

            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->timestamps();
            $table->index(['pending_review', 'signed_at'], 'idx_signature_events_review');
            $table->index(['person_id', 'signed_at'], 'idx_signature_events_person');
            $table->index('method', 'idx_signature_events_method');
        });

        Schema::create('evidence_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_event_id')->constrained('signature_events')->cascadeOnDelete();
            $table->string('kind', 20)->comment('face, signature');
            $table->string('file_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamps();
            // Deduplicacion: el mismo archivo no se guarda dos veces.
            $table->unique('sha256', 'evidence_files_sha256_unique');
            $table->index(['signature_event_id', 'kind'], 'idx_evidence_files_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_files');
        Schema::dropIfExists('signature_events');
    }
};
