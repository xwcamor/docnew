# Aislamiento por pais.
#
# La v1 repetia where(country_id: @current_country) en 41 controladores, y
# bastaba olvidarlo una vez para filtrar datos de otro pais.
module Countryable
  extend ActiveSupport::Concern

  included do
    belongs_to :country

    scope :for_country, ->(country) { where(country: country) }
  end

  class_methods do
    def scoped_find!(slug, country)
      for_country(country).find_by!(slug: slug)
    end
  end
end
