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

    // Status labels (avisos del sistema)
    'status_unread'      => 'Sin leer',
    'status_read'        => 'Leído',

    // Item labels
    'generated'          => 'Generado',
    'downloaded'         => 'Descargado',
    'expires'            => 'Expira',
    'received'           => 'Recibido',

    // Actions
    'download'           => 'Descargar',
    'dismiss'            => 'Quitar',
    'mark_read'          => 'Marcar como leído',
    'mark_all_read'      => 'Marcar todo como leído',

    // Empty state
    'empty'              => 'No tienes notificaciones',
    'empty_hint'         => 'Aquí aparecen los archivos que exportes y los avisos del sistema.',
];
