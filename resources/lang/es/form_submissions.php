<?php

return [
    /*
     * Textos del PDF firmado de una entrega de formato: el documento que la
     * empresa conserva. Es lo unico que se ve fuera del sistema, asi que aqui
     * no hay jerga tecnica ni nombres de columna.
     */
    'pdf' => [
        'title'         => 'Formato de seguridad',
        'plan'          => 'Plan de trabajo',
        'code'          => 'Código',
        'work_order'    => 'Orden de trabajo',
        'company'       => 'Empresa contratista',
        'tax_id'        => 'Documento',
        'work_type'     => 'Tipo de trabajo',
        'location'      => 'Ubicación',
        'date_start'    => 'Inicio',
        'date_end'      => 'Fin',
        'description'   => 'Descripción del trabajo',
        'submitted_at'  => 'Formato entregado',
        'form_version'  => 'Versión',
        'status'        => 'Estado',

        'statuses' => [
            'draft'     => 'Borrador',
            'submitted' => 'Entregado',
            'confirmed' => 'Confirmado',
        ],

        'content'   => 'Contenido del formato',
        'no_answer' => 'Sin respuesta',
        'observations' => 'Observaciones',

        // Adjuntos. En los formatos de solo subida la foto del papel ES el
        // documento, por eso sale a página completa.
        'attachments'   => 'Documento adjunto',
        'fingerprint'   => 'Huella SHA-256',
        'attachment_not_embeddable' => 'El adjunto no es una imagen y no puede incrustarse; queda identificado por su huella.',

        // Firmas registradas.
        'signatures'      => 'Firmas registradas',
        'no_signatures'   => 'Esta entrega no tiene firmas registradas.',
        // La firma trazada, no la cara: es lo que se espera ver al lado de
        // un nombre en un documento firmado, y ademas la cara solo existe
        // cuando el reconocimiento fallo.
        'signature'       => 'Firma',
        'signer'          => 'Firmante',
        'document_number' => 'Documento',
        'signed_at'       => 'Hora',
        'col_method'      => 'Método',
        'pending_review'  => 'PENDIENTE DE REVISIÓN',
        'reviewed'        => 'Verificada',
        // El porcentaje y no la distancia: quien lee el informe no tiene por
        // qué saber que «0.15» es bueno y «0.55» no.
        'match'           => 'Coincidencia de la cara :value %',
        'override_reason' => 'Motivo',

        'methods' => [
            'face_recognition' => 'Reconocimiento facial',
            'timeout_capture'  => 'Captura por tiempo de espera',
            'manual'           => 'Manual autorizada',
            'reused'           => 'Firma reutilizada',
            'migrated'         => 'Migrada del sistema anterior',
        ],


        // Pie de firmas formales del workspace.
        'formal_signers' => 'Firmas del workspace',

        // Pie de página: identificador con el que se verifica la entrega.
        'verify_id'    => 'Identificador de verificación',
        'generated_at' => 'Generado el',
        'page'         => 'Página',
    ],
];
