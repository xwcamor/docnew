# Identificador publico de 22 caracteres. Los id numericos no se exponen en URLs.
module Sluggable
  extend ActiveSupport::Concern

  SLUG_LENGTH = 22

  included do
    before_validation :assign_slug, on: :create
    validates :slug, presence: true, uniqueness: true
  end

  class_methods do
    def find_by_slug!(value) = find_by!(slug: value)
  end

  def to_param = slug

  private

  def assign_slug
    return if slug.present?

    self.slug = loop do
      candidate = SecureRandom.alphanumeric(SLUG_LENGTH)
      break candidate unless self.class.unscoped.exists?(slug: candidate)
    end
  end
end
