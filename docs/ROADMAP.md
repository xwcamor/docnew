# Estado

## Hecho

- [x] Convenciones del proyecto (`CLAUDE.md`) y documentacion de arquitectura
- [x] Esquema completo: 35 tablas, 108 indices, 56 claves foraneas, en 7 migraciones
- [x] `bin/schema_check`: valida las migraciones y genera el DDL; **el DDL se ejecuto contra un
      MySQL vacio y crea las 35 tablas y las 56 claves foraneas sin errores**
- [x] Sistema de diseno: tokens Fiori, 8 esquemas, modo oscuro, componentes base
- [x] Layout del shell (barra superior, menu lateral, franja de titulo, barra de acciones)
- [x] Concerns del core: `SoftDeletable`, `Sluggable`, `Translatable`, `Countryable`
- [x] Gemfile, configuracion de la aplicacion y de rutas por grupo

## Siguiente

- [ ] `bin/rails new` sobre este esqueleto: `boot.rb`, `environment.rb`, entornos, `bin/`
- [ ] Modelos del core y Devise
- [ ] `ResourceController` y los componentes de vista compartidos
- [ ] Generador `docapp:module`
- [ ] Primer modulo completo de punta a punta (`companies`) como referencia
- [ ] Motor de formatos: tipos de campo basicos y `upload_only`
- [ ] Servicio de verificacion facial en servidor + captura por tiempo de espera
- [ ] Scripts de migracion de datos (`docs/DATA-MIGRATION.md`)
- [ ] Suite de tests de sistema del flujo diario

## No se hace todavia

- App nativa ni API publica: el trabajo en campo sigue en navegador (tablets Android).
- Migrar AST y PTF al motor de formatos.
- Multi-pais mas alla de lo que ya existe: el 100 % de los planes de la v1 son de Peru.
