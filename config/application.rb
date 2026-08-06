require_relative "boot"
require "rails/all"

Bundler.require(*Rails.groups)

module Docnew
  class Application < Rails::Application
    config.load_defaults 7.2

    config.time_zone = "America/Lima"
    config.active_record.default_timezone = :utc

    # Multi-idioma: castellano por defecto, con respaldo explicito. En la v1
    # default_locale estaba comentado y la app caia al :en de Rails.
    config.i18n.available_locales = %i[es pt en]
    config.i18n.default_locale = :es
    config.i18n.fallbacks = [:es, :en]
    config.i18n.load_path += Dir[Rails.root.join("config/locales/**/*.{rb,yml}")]

    config.generators do |g|
      g.test_framework :test_unit, fixture: false
      g.helper false
      g.assets false
    end
  end
end
