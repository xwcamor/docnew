# Configurar correo (SMTP) — paso a paso

Guía para conectar el envío de emails del sistema (recuperación de contraseña, exports listos, alertas de suscripción, automatizaciones). Cubre **Gmail**, **Mailgun**, **Amazon SES** y **Postmark**.

> **TL;DR**: en `.env` necesitas 8 variables `MAIL_*`. La clave de correo es el campo `MAIL_PASSWORD` — para Gmail NO es tu contraseña personal, es una **App Password** que generas aparte. Pasos abajo.

---

## ¿Cuándo se envía correo?

El sistema manda email en estos casos:

| Evento | Destinatario | Trigger |
|---|---|---|
| Recuperación de contraseña | El usuario que la solicita | Botón "Olvidé mi contraseña" |
| Export listo / falló | Usuario que disparó el export | Cuando termina el job de queue |
| Suscripción por vencer (7 días antes) | Admin del workspace | Cron diario `subscriptions:check-expirations` |
| Plan cambiado | Admin del workspace | Cambio manual del super |
| Automatizaciones (acción `Email`) | Destinatarios configurados | Cron `automations:tick` cada minuto |

Todo respeta el toggle global `notifications.email_enabled` (en Settings) — si está en `false`, nada sale por email (siguen apareciendo en la campana del header).

---

## En desarrollo: `MAIL_MAILER=log`

Para dev no necesitas SMTP real. Pone esto en `.env`:

```ini
MAIL_MAILER=log
```

Los emails se escriben en `storage/logs/laravel.log` con el contenido completo (subject + body + destinatario). Util para verificar que el template se arma bien sin gastar cuota ni spamear inboxes.

Cuando estés listo para producción, cambia a `smtp` y configura los pasos abajo.

---

## 1. Gmail (la más común para arrancar)

Gmail tiene tier gratuito de **500 emails/día**. Suficiente para los primeros clientes. Para más volumen, salta a SES o Mailgun.

### Paso A — Activar 2FA en la cuenta Gmail

Las App Passwords solo funcionan con cuentas que tienen verificación en 2 pasos activa.

1. Abre [myaccount.google.com/security](https://myaccount.google.com/security)
2. En **Seguridad** → activa **Verificación en 2 pasos**
3. Confirma con tu teléfono

### Paso B — Generar la App Password

1. Una vez activa 2FA, abre [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
2. **Nombre de la app**: pon algo descriptivo como `MiSistema SMTP` (es solo para identificarla después)
3. Click en **Crear**
4. Google muestra una clave de 16 caracteres tipo `abcd efgh ijkl mnop`
5. **Cópiala completa SIN espacios** (`abcdefghijklmnop`) — esta es tu `MAIL_PASSWORD`

> Google solo muestra esta clave UNA vez. Si la pierdes, generas otra y revocas la vieja.

### Paso C — Editar `.env`

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME="micuenta@gmail.com"
MAIL_PASSWORD="abcdefghijklmnop"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="micuenta@gmail.com"
MAIL_FROM_NAME="${APP_NAME}"
```

`MAIL_USERNAME` y `MAIL_FROM_ADDRESS` deben ser **el mismo email** que generó la App Password.

### Paso D — Limpiar cache y probar

```powershell
php artisan config:clear
php artisan tinker
```

En el shell de tinker:

```php
Mail::raw('Funcionando!', fn($m) => $m->to('tu_email@gmail.com')->subject('Prueba SMTP'));
```

Si llega a tu bandeja → listo. Si falla, revisa `storage/logs/laravel.log` para el error exacto.

---

## 2. Mailgun (recomendado para volumen medio)

Mailgun permite 5000 emails/mes gratis los primeros 3 meses, luego paga por uso. Mejor reputación de envío que Gmail SMTP.

### Pasos

1. Crear cuenta en [mailgun.com](https://www.mailgun.com)
2. **Sending → Domains**: agrega tu dominio (ej. `mail.midominio.com`) o usa el sandbox de prueba
3. Configurar los DNS records (TXT + MX + CNAME) que Mailgun te indica
4. Verificar el dominio (puede tardar minutos)
5. **Sending → Sending API → SMTP**: copiar credenciales
   - Host: `smtp.mailgun.org`
   - Port: `587`
   - Username: `postmaster@mail.midominio.com`
   - Password: el que muestra el panel

### `.env`

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME="postmaster@mail.midominio.com"
MAIL_PASSWORD="la-clave-que-da-mailgun"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@midominio.com"
MAIL_FROM_NAME="${APP_NAME}"
```

---

## 3. Amazon SES (recomendado para producción)

Costo ~$0.10 por cada 1000 emails. Mejor opción a escala. Requiere salir del sandbox de SES (Amazon revisa la solicitud manualmente, suele tardar 24h).

### Pasos

1. AWS Console → **SES**
2. Verificar tu dominio (DKIM + SPF en DNS)
3. **SMTP Settings → Create SMTP Credentials**
4. Copiar el `SMTP Username` y `SMTP Password` (NO el access key normal)

### `.env`

```ini
MAIL_MAILER=smtp
MAIL_HOST=email-smtp.us-east-1.amazonaws.com
MAIL_PORT=587
MAIL_USERNAME="AKIA...XYZ"
MAIL_PASSWORD="la-clave-ses-smtp"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@midominio.com"
MAIL_FROM_NAME="${APP_NAME}"
```

Cambia `us-east-1` por la región que estés usando.

---

## 4. Postmark (excelente entregabilidad)

Caro pero el más confiable para correos transaccionales. ~$15/mes por 10k emails.

### `.env`

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.postmarkapp.com
MAIL_PORT=587
MAIL_USERNAME="postmark-server-token"
MAIL_PASSWORD="postmark-server-token"
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@midominio.com"
MAIL_FROM_NAME="${APP_NAME}"
```

`MAIL_USERNAME` y `MAIL_PASSWORD` son **el mismo token** (Postmark usa el server token como ambas credenciales).

---

## Problemas comunes

### "Authentication failed" con Gmail

- Verifica que tengas 2FA activa
- Asegurate de copiar la App Password SIN espacios (las muestra con espacios pero hay que pegarlas juntas)
- `MAIL_USERNAME` y `MAIL_FROM_ADDRESS` deben ser el mismo email que generó la App Password

### "Connection refused" o timeout

- Tu hosting bloquea el puerto 587 saliente. Prueba puerto 465 con `MAIL_ENCRYPTION=ssl` en lugar de `tls`
- Si estás en DigitalOcean, ellos bloquean por defecto el puerto 25 — usa 587 o 465

### Los emails llegan a spam

- Configurar SPF + DKIM + DMARC en el DNS del dominio
- Usar dominio propio en `MAIL_FROM_ADDRESS` (no `@gmail.com` si estás vendiendo a clientes)
- Empezar de a poco (no mandar 1000 emails el primer día desde una cuenta nueva)

### `MAIL_PASSWORD` con caracteres especiales

Si tu password tiene `$`, `"`, `\` o espacios, envolvela en comillas dobles **y** escapa los caracteres:

```ini
MAIL_PASSWORD="mi-clave\$rara"
```

### El email NO llega y NO hay error en logs

Verifica el toggle global en Settings:

- Como super, ir a `/system_management/settings`
- Buscar `notifications.email_enabled`
- Asegurar que esté en `true`

Si está en `false`, los emails se silencian sin error (es feature, no bug).

---

## Tras cambiar `.env`

Siempre que toques cualquier variable de mail:

```powershell
php artisan config:clear
php artisan queue:restart
```

`queue:restart` es importante porque los workers viejos siguen con el `.env` antiguo cargado en memoria hasta que se reinician.

---

## Seguridad — NO hardcodear credenciales

- `MAIL_PASSWORD` **siempre** en `.env`, NUNCA en la base de datos ni en el código
- Si pusheas accidentalmente la clave al repo, revócala en el panel del proveedor y genera una nueva
- `.env` está en `.gitignore` desde el inicio — verifica con `git status` que no aparezca antes de cualquier commit

---

## Documentación relacionada

- [`ENV.md`](ENV.md) — todas las variables `MAIL_*` del `.env` con su descripción
- [`AUTOMATIONS.md`](AUTOMATIONS.md) — automatizaciones que disparan correos (acción Email)
- [`USAGE.md`](USAGE.md) — flujos del sistema que envían correo (reset password, welcome, exports, suscripciones)
- [`CRONS-AND-SETTINGS.md`](CRONS-AND-SETTINGS.md) — setting `notifications.email_enabled` (toggle global)
- [`TROUBLESHOOTING.md`](TROUBLESHOOTING.md) — errores comunes de SMTP
- [`../README-PROD.md`](../README-PROD.md) — SMTP en producción (sección 9)
