<?php

namespace App\Services\AuthManagement;

use App\Jobs\AuthManagement\Users\BulkUsersActionJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * UserService — encapsulates the side-effects of user CRUD so the controller
 * stays thin. All photo handling, password hashing, and audit-friendly soft
 * delete logic lives here.
 */
class UserService
{
    public function create(array $data, ?UploadedFile $photo = null): User
    {
        // Capturamos la password en plain ANTES de hashearla para mandarla
        // por email de bienvenida. El email se dispatchea via queue tras
        // persistir el user (despues del save).
        $plainPassword = $data['password'];

        $user = new User();
        $user->name        = $data['name'];
        $user->email       = $data['email'];
        $user->password    = Hash::make($plainPassword);
        $user->is_active   = $data['is_active'] ?? true;
        $user->country_id  = $data['country_id'];
        // locale_id obligatorio en el form actual, pero si llega vacio (flujos
        // futuros de signup / import sin locale), resolver desde el setting
        // `app.default_locale` (ISO code => buscar el Locale equivalente).
        $user->locale_id   = $data['locale_id'] ?? $this->resolveDefaultLocaleId();
        $user->created_by  = auth()->id();

        // tenant_id: si super eligio uno explicito para otro tenant, respetalo.
        // Si no, el trait BelongsToTenant lo autorelleta del tenant del creador.
        if (! empty($data['tenant_id'])) {
            $user->tenant_id = $data['tenant_id'];
        }

        if ($photo) {
            $user->photo = $this->storePhoto($photo, $user->name ?? 'user');
        }

        $user->save();

        // Welcome email — solo a users humanos. Los "system users" generados
        // automaticamente al crear tenants (api+slug@system.local) no reciben
        // welcome porque son cuentas de API tokens, no de personas.
        //
        // Lo despachamos con `afterCommit` para que si el caller envuelve este
        // create() en una transacción y hace rollback, el welcome NO se envíe
        // a un user que en realidad no quedó persistido. Si ya estamos fuera
        // de una transacción, afterCommit() ejecuta inmediatamente.
        if (!str_starts_with((string) $user->email, 'api+') || !str_ends_with((string) $user->email, '@system.local')) {
            DB::afterCommit(function () use ($user, $plainPassword) {
                $user->notify(new \App\Notifications\WelcomeNotification($plainPassword));
            });
        }

        return $user;
    }

    public function update(User $user, array $data, ?UploadedFile $photo = null): User
    {
        $user->name       = $data['name'];
        $user->email      = $data['email'];
        $user->is_active  = $data['is_active'] ?? $user->is_active;
        $user->country_id = $data['country_id'];
        $user->locale_id  = $data['locale_id'];

        if (array_key_exists('tenant_id', $data) && ! empty($data['tenant_id'])) {
            $user->tenant_id = $data['tenant_id'];
        }

        $passwordChanged = false;
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
            $passwordChanged = true;
        }

        if ($photo) {
            // Remove old photo if any
            if ($user->photo && Storage::disk('public')->exists($user->photo)) {
                Storage::disk('public')->delete($user->photo);
            }
            $user->photo = $this->storePhoto($photo, $user->name);
        }

        $user->save();

        // Aviso de seguridad si la clave cambio. El contexto es 'admin' o
        // 'self' segun si quien edita es el propio user o un admin.
        if ($passwordChanged) {
            $context = (auth()->id() === $user->id) ? 'self' : 'admin';
            $user->notify(new \App\Notifications\PasswordChangedNotification($context));
        }

        return $user;
    }

    /**
     * Soft-delete with audit info. saveQuietly() avoids a duplicate `updated`
     * audit log right before the `deleted` one.
     */
    public function delete(User $user, string $reason): void
    {
        $user->deleted_description = $reason;
        $user->deleted_by          = auth()->id();
        $user->is_active           = false;
        $user->saveQuietly();
        $user->delete();
    }

    public function restore(User $user): User
    {
        $user->deleted_description = null;
        $user->deleted_by          = null;
        $user->restore();
        return $user;
    }

    // ─── Bulk ops (auto-async > threshold) ─────────────────────────────────
    //
    // Si count(ids) excede el umbral, dispatchamos al job y devolvemos un
    // payload "queued" para que el controller redirija con mensaje de cola.
    // Bajo el umbral, corre inline (mantiene compat con tests existentes).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkUsersActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkUsersActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $users = User::whereIn('id', $ids)->get();
            $deleted = [];
            foreach ($users as $user) {
                $this->delete($user, $reason);
                $deleted[] = $user->id;
            }
            return ['queued' => false, 'count' => $users->count(), 'deleted' => $deleted];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkUsersActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $changed = 0;
            $users = User::whereIn('id', $ids)->get();
            foreach ($users as $user) {
                if ((bool) $user->is_active === $isActive) continue;
                $user->is_active = $isActive;
                $user->save();
                $changed++;
            }
            return ['queued' => false, 'count' => $count, 'changed' => $changed];
        });
    }

    /**
     * @return array{queued: bool, count: int, restored?: int}
     */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkUsersActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $users = User::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($users as $user) {
                $this->restore($user);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $users->count()];
        });
    }

    /**
     * Store the photo in storage/public/photos/{slug}/{timestamp}_{rand}.{ext}.
     * Returns the relative path that goes into users.photo column.
     *
     * El nombre se genera localmente — NO usamos `getClientOriginalName()` que
     * viene del cliente (podría tener path traversal, doble extensión tipo
     * `shell.php.jpg`, espacios o unicode raro). La extensión se valida con
     * `extension()` (toma el MIME real, no el nombre).
     */
    private function storePhoto(UploadedFile $file, string $userName): string
    {
        $slug = Str::slug($userName) . '-' . uniqid();
        $ext  = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        // Whitelist final defensiva — la validación ya pasó por mimes:jpg,jpeg,png,gif.
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $ext = 'jpg';
        }
        $filename = time() . '_' . Str::random(12) . '.' . $ext;
        return $file->storeAs("photos/{$slug}", $filename, 'public');
    }

    /**
     * Resuelve el locale_id por defecto desde el setting `app.default_locale`
     * (string ISO como 'es', 'en', 'pt'). Busca el Locale cuyo Language tenga
     * ese iso_code. Si no encuentra, devuelve el primer Locale activo. Si la
     * tabla esta vacia, lanza una excepcion (la BD requiere un id valido).
     */
    private function resolveDefaultLocaleId(): int
    {
        $iso = \App\Models\Setting::get('app.default_locale', 'es');
        $localeId = \App\Models\Locale::query()
            ->whereHas('language', fn ($q) => $q->where('iso_code', $iso))
            ->where('is_active', true)
            ->value('id');
        if ($localeId) return (int) $localeId;

        // Fallback: cualquier locale activo (evita FK error).
        $fallback = \App\Models\Locale::query()->where('is_active', true)->value('id');
        if (!$fallback) {
            throw new \RuntimeException('No hay Locales activos en la BD. Sembrar antes de crear users.');
        }
        return (int) $fallback;
    }
}
