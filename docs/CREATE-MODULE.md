# Como se anade un modulo

```bash
bin/rails g docapp:module Position --group=company_management --translates=name,description
```

Genera, y **hay que revisar uno por uno**:

```
db/migrate/xxx_create_positions.rb          tabla con slug, soft delete y timestamps
app/models/position.rb                      con los concerns que correspondan
app/controllers/company_management/positions_controller.rb
app/services/company_management/            vacio, para cuando aparezca logica
app/views/company_management/positions/     index, show, form, delete
config/routes/company_management.rb         entrada del recurso
config/locales/positions.{es,pt,en}.yml
config/permissions.yml                      positions.read/create/update/destroy/export
test/models/position_test.rb
test/system/positions_test.rb
```

Lo que el generador **no** hace y hay que hacer a mano: la entrada en el menu lateral, las claves
de traduccion de negocio y las relaciones con otras tablas.

## Antes de abrir el pull request

```bash
bin/schema_check     # si tocaste db/migrate
bin/rails test
bin/rails test:system
bundle exec i18n-tasks missing
bundle exec rubocop
```

## Anatomia de un controlador

Hereda de `ResourceController`, que resuelve el patron comun: filtrado por pais, busqueda con
Ransack, paginacion, exportacion y autorizacion. Un controlador que pasa de 80 lineas necesita un
servicio, no mas metodos privados.
