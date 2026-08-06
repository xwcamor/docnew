# Mapa de carpetas

```
app/
  controllers/
    concerns/resource_controller.rb     patron CRUD comun (sustituye a copiarlo 57 veces)
    {grupo}_management/                 core, company, person, plan, form, auth, dashboard
  models/
    concerns/                           SoftDeletable, Sluggable, Translatable, Countryable
  services/{grupo}_management/          la logica de negocio, fuera de los controladores
  views/
    layouts/application.html.erb
    shared/components/                  tabla, filtros, modal, badge, barra de acciones
    {grupo}_management/{recurso}/
  assets/stylesheets/                   tokens.css (paleta) + fiori.css (componentes)
config/
  routes.rb                             solo hace draw() de un archivo por grupo
  routes/{grupo}_management.rb
  locales/{modulo}.{locale}.yml         un archivo por modulo, no un YAML gigante
  permissions.yml                       catalogo de pares recurso/accion
db/migrate/                             una migracion por bloque tematico
bin/schema_check                        valida las migraciones y genera el DDL
docs/
lib/generators/docapp/module/           generador de modulos
test/
  models/ · controllers/ · system/      un modulo sin test de sistema no se mergea
```

## Grupos de negocio

| Grupo | Contenido |
| --- | --- |
| `core_management` | paises, idiomas, ajustes, perfiles, accesos, usuarios |
| `company_management` | empresas, areas, ubicaciones, puestos de trabajo, cargos |
| `person_management` | personas, vinculos con empresas, biometria, firmas |
| `plan_management` | planes, personas del plan, aprobaciones, reglas de aprobacion |
| `form_management` | plantillas de formato, campos, capturas, adjuntos |
| `auth_management` | sesion, recuperacion de contrasena |
| `dashboard_management` | inicio, indicadores, bandeja de revision |
