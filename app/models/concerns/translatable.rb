# Traduccion de datos de catalogo en una sola tabla.
#
# La v1 repetia name_es/name_pt/name_en en 16 tablas: anadir un idioma era un
# ALTER TABLE por tabla, y las vistas hacian "name_#{I18n.locale}" interpolado
# incluso dentro de un ORDER BY.
#
#   class Position < ApplicationRecord
#     include Translatable
#     translates :name, :description
#   end
#
#   position.name          # en el locale actual, con respaldo
#   position.name(:pt)     # en un locale concreto
module Translatable
  extend ActiveSupport::Concern

  included do
    has_many :translated_texts, as: :translatable, dependent: :destroy
    accepts_nested_attributes_for :translated_texts, allow_destroy: true
  end

  class_methods do
    def translates(*fields)
      @translated_fields = fields.map(&:to_sym)

      fields.each do |field|
        define_method(field) do |locale = nil|
          translation_for(field, locale)
        end

        define_method("#{field}=") do |value|
          write_translation(field, I18n.locale, value)
        end
      end
    end

    def translated_fields = @translated_fields || []

    # Ordenar por un campo traducido sin interpolar el locale en el SQL.
    def ordered_by_translation(field, locale = I18n.locale, direction: :asc)
      joins(:translated_texts)
        .where(translated_texts: { field: field.to_s, locale: locale.to_s })
        .order("translated_texts.value" => direction)
    end
  end

  def translation_for(field, locale = nil)
    locale = (locale || I18n.locale).to_s
    texts  = translated_texts.to_a
    exact  = texts.find { |t| t.field == field.to_s && t.locale == locale }
    return exact.value if exact

    I18n.fallbacks[locale].each do |fallback|
      hit = texts.find { |t| t.field == field.to_s && t.locale == fallback.to_s }
      return hit.value if hit
    end
    nil
  end

  def write_translation(field, locale, value)
    text = translated_texts.find { |t| t.field == field.to_s && t.locale == locale.to_s }
    return text.value = value if text

    translated_texts.build(field: field.to_s, locale: locale.to_s, value: value)
  end
end
