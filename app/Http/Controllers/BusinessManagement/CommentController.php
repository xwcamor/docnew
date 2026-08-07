<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CommentController — comentarios de usuario polimórficos sobre un transformador
 * o una muestra de prueba. Allowlist explícita de tipos comentables (nunca se
 * acepta un FQCN del cliente). El borrado lo limita el autor o un admin/super.
 *
 * Las rutas ya están protegidas por permisos: index→comments.view,
 * store→comments.create|diagnosis_notes.create (el store afina cuál según el tipo:
 * la nota del diagnosticador exige diagnosis_notes.create), destroy→comments.delete.
 * La resolución del comentable usa la query tenant-scoped del modelo, así que un
 * id de otro tenant simplemente no se encuentra (404).
 */
class CommentController extends Controller
{
    /** slug seguro → modelo comentable. */
    private const TYPES = [
    ];

    public function index(Request $request)
    {
        $data = $request->validate([
            'type'    => ['required', Rule::in(array_keys(self::TYPES))],
            'id'      => ['required', 'integer'],
            'context' => ['nullable', 'string', 'max:60'],
        ]);

        $model = self::TYPES[$data['type']];
        $commentable = $model::findOrFail($data['id']);

        $items = Comment::where('commentable_type', $model)
            ->where('commentable_id', $commentable->id)
            ->when(($data['context'] ?? null) !== null, fn ($q) => $q->where('context', $data['context']))
            ->with(['user:id,name,country_id', 'user.country:id,iso_code'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($c) => $this->present($c));

        return response()->json(['comments' => $items]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'    => ['required', Rule::in(array_keys(self::TYPES))],
            'id'      => ['required', 'integer'],
            'context' => ['nullable', 'string', 'max:60'],
            'body'    => ['required', 'string', 'max:5000'],
        ]);

        // Permiso por contexto: la "Nota del diagnosticador" (sobre el transformer)
        // exige diagnosis_notes.create; un comentario por muestra exige
        // comments.create. Así un cargador de muestras comenta SU muestra sin poder
        // firmar la nota del especialista. (El middleware ya garantizó que tiene al
        // menos uno de los dos; aquí se hace valer el que corresponde al objeto.)
        $perm = $data['type'] === 'transformer' ? 'diagnosis_notes.create' : 'comments.create';
        abort_unless($request->user()->can($perm), 403);

        $model = self::TYPES[$data['type']];
        // findOrFail aplica el global scope de tenant → cross-tenant = 404.
        $commentable = $model::findOrFail($data['id']);

        $comment = Comment::create([
            'commentable_type' => $model,
            'commentable_id'   => $commentable->id,
            'context'          => $data['context'] ?? null,
            'user_id'          => $request->user()->id,
            'body'             => $data['body'],
            // Idioma con el que se escribió (locale activo). No cambia luego.
            'lang'             => app()->getLocale(),
            // Mismo tenant que el objeto comentado (BelongsToTenant lo refuerza).
            'tenant_id'        => $commentable->tenant_id ?? null,
        ]);

        return response()->json($this->present($comment->load(['user:id,name,country_id', 'user.country:id,iso_code'])), 201);
    }

    public function destroy(Request $request, Comment $comment)
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('super') || $user->hasRole('admin');
        if ($comment->user_id !== $user->id && ! $isAdmin) {
            abort(403);
        }

        $comment->deleted_by = $user->id;
        $comment->save();
        $comment->delete();

        return response()->json(['ok' => true]);
    }

    /** Forma de un comentario para el front (autor + fecha legible). */
    private function present(Comment $c): array
    {
        return [
            'id'         => $c->id,
            'body'       => $c->body,
            'context'    => $c->context,
            'user_id'    => $c->user_id,
            'author'     => $c->user?->name ?? '—',
            // Contexto del comentario (el texto no se traduce): idioma en que se
            // escribió + país del autor, para que el lector sepa de dónde viene.
            'lang'       => $c->lang ? strtoupper($c->lang) : null,
            'country'    => $c->user?->country?->iso_code,
            'created_at' => optional($c->created_at)->format('Y-m-d H:i'),
        ];
    }
}
