<?php

return [
    'title'              => 'Notificaciones',
    'count_one'          => ':count notificación',
    'count_many'         => ':count notificaciones',
    'auto_delete_hint'   => 'Los archivos descargables se eliminan automáticamente al día siguiente',

    // Status labels (download jobs)
    'status_processing'  => 'Generando',
    'status_ready'       => 'Listo',
    'status_failed'      => 'Falló',
    'status_expired'     => 'Expirado',

    // Item labels
    'generated'          => 'Generado',
    'downloaded'         => 'Descargado',
    'expires'            => 'Expira',

    // Actions
    'download'           => 'Descargar',
    'dismiss'            => 'Quitar',

    // Empty state
    'empty'              => 'No tienes notificaciones',
    'empty_hint'         => 'Cuando exportes un reporte o se complete una tarea, aparecerá aquí.',
];
