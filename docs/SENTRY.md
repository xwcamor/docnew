# Sentry — error tracking en producción

Guía paso a paso para activar Sentry. El skeleton ya está preparado: `.env.example` tiene las claves, el código NO requiere cambios. Solo seguir esta guía cuando llegues a prod.

> **Costo**: Sentry tiene tier free generoso (~5k eventos/mes). Para arrancar con tráfico bajo no necesitas pagar.

---

## 1. Crear cuenta + proyecto Sentry

1. Registrarse en [sentry.io](https://sentry.io)
2. Crear un proyecto nuevo de tipo **Laravel**
3. Copiar el **DSN** del proyecto — algo como `https://abc123@o123.ingest.sentry.io/456`

## 2. Instalar el SDK

```bash
composer require sentry/sentry-laravel
php artisan sentry:publish --dsn=YOUR_DSN_HERE
```

El comando `sentry:publish` agrega configuración a `config/sentry.php` y un test command. Verificarlo con:

```bash
php artisan sentry:test
```

Si el test funciona, verás el evento de prueba en tu dashboard de Sentry.

## 3. Configurar `.env` en el server

```ini
SENTRY_LARAVEL_DSN=https://abc123@o123.ingest.sentry.io/456

# Sample rates (0.0 = nada, 1.0 = todo)
SENTRY_TRACES_SAMPLE_RATE=0.1     # 10% de las requests para perf tracing
SENTRY_PROFILES_SAMPLE_RATE=0.0   # profiling, opcional
SENTRY_SEND_DEFAULT_PII=false     # no enviar IP/email/headers por defecto
```

> **En dev**: dejar `SENTRY_LARAVEL_DSN` vacío. Sentry se ignora silenciosamente y no envía nada.

## 4. Decidir qué excepciones se reportan

Por default Sentry captura todas las excepciones no-manejadas. Para excluir las que no aportan signal (ValidationException, 404, etc.):

Editar `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    if (app()->bound('sentry')) {
        $exceptions->reportable(function (Throwable $e) {
            \Sentry\Laravel\Integration::captureUnhandledException($e);
        });
        // No reportar estos tipos a Sentry — son "errores esperados".
        $exceptions->dontReport([
            \Illuminate\Validation\ValidationException::class,
            \Illuminate\Auth\AuthenticationException::class,
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        ]);
    }
})
```

## 5. Capturar eventos custom

En cualquier código:

```php
try {
    riskyOperation();
} catch (\Throwable $e) {
    if (app()->bound('sentry')) {
        \Sentry\captureException($e);
    }
    // Re-throw o handle como prefieras
    throw $e;
}
```

## 6. Performance + Tracing (opcional)

Para ver qué requests son lentas en prod:

```ini
SENTRY_TRACES_SAMPLE_RATE=0.1   # 10% de las requests, suficiente para detectar problemas
```

Más muestreo = más datos pero más coste. 0.1 (10%) es buen balance.

---

## Frontend (Vue)

Si quieres capturar errores del frontend también:

```bash
npm install --save @sentry/vue
```

En `resources/js/app.js`:

```js
import * as Sentry from '@sentry/vue';

if (import.meta.env.VITE_SENTRY_DSN) {
    Sentry.init({
        app,
        dsn: import.meta.env.VITE_SENTRY_DSN,
        tracesSampleRate: 0.1,
        environment: import.meta.env.MODE,
    });
}
```

Agregar `VITE_SENTRY_DSN` al `.env` (mismo DSN o uno separado para el proyecto JS de Sentry).

---

## Filtros que recomiendo

- **Por release**: agrupa los errores por versión del deploy
- **Por user**: si activas `send_default_pii: true`, puedes ver qué user disparó cada error
- **Issue alerting**: configurar email/Slack para errores nuevos (no para los recurrentes — se vuelve ruido)

---

## Estado actual del proyecto

- `.env.example` tiene las claves `SENTRY_*` listas
- El código NO está pegado a Sentry — funciona sin él
- Cuando llegues a producción, solo hay que ejecutar los pasos 1-4 indicados arriba

---

## Documentación relacionada

- [`ENV.md`](ENV.md) — variables `SENTRY_*` que necesitas en `.env`
- [`../README-PROD.md`](../README-PROD.md) — deploy a producción donde encaja Sentry
- [`DEPLOY.md`](DEPLOY.md) — stack proyectado para producción
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes que Sentry ayudaría a capturar
