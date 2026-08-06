source "https://rubygems.org"

ruby "3.3.6"

gem "rails", "~> 7.2.1"
gem "mysql2", "~> 0.5"
gem "puma", ">= 6.0"

# Vistas
gem "sprockets-rails"
gem "importmap-rails"
gem "turbo-rails"
gem "stimulus-rails"

# Autenticacion y permisos
gem "devise"
gem "cancancan"

# Consultas, paginacion, exportacion
gem "ransack"
gem "kaminari"
gem "caxlsx_rails"

# Un unico motor de PDF (ver CLAUDE.md)
gem "wicked_pdf"
gem "wkhtmltopdf-binary"

# Soft delete y auditoria
gem "discard", "~> 1.3"
gem "audited", "~> 5.4"

gem "image_processing", "~> 1.2"
gem "bootsnap", require: false

group :development, :test do
  gem "debug", platforms: %i[mri windows]
  gem "i18n-tasks", "~> 1.0"
  gem "rubocop-rails", require: false
end

group :test do
  gem "capybara"
  gem "selenium-webdriver"
end
