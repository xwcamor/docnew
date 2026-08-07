<?php

return [
    // Acciones y estados del bloqueo de registros (trait Lockable).
    'lock'            => 'Bloquear',
    'unlock'          => 'Desbloquear',
    'locked'          => 'Registro bloqueado.',
    'unlocked'        => 'Registro desbloqueado.',
    'locked_tag'      => 'Bloqueado',
    'locked_by_super' => 'Bloqueado por el sistema',
    'locked_hint'     => 'Este registro está bloqueado: no se puede editar, eliminar ni importar hasta desbloquearlo.',
    'locked_by_super_hint' => 'Bloqueado por un administrador del sistema. Solo el sistema puede desbloquearlo.',
    'lock_confirm'    => '¿Bloquear este registro? Nadie podrá editarlo ni eliminarlo hasta desbloquearlo.',
    'unlock_confirm'  => '¿Desbloquear este registro?',
    'cannot_edit_locked'   => 'El registro está bloqueado. Desbloquéalo para editarlo.',
    'cannot_delete_locked' => 'El registro está bloqueado. Desbloquéalo para eliminarlo.',
    'bulk_skipped_locked'  => ':count bloqueado(s) se omitieron.',
];
