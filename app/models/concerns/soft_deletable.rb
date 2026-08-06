# Una sola forma de borrado logico en todo el proyecto.
#
# En la v1 cada tabla llevaba is_active + is_deleted + deleted_description y
# cada modelo repetia el mismo callback: 25 tablas y 15 copias del mismo codigo,
# con reglas distintas sobre si NULL contaba como borrado.
module SoftDeletable
  extend ActiveSupport::Concern

  included do
    include Discard::Model
    self.discard_column = :discarded_at

    belongs_to :discarded_by, class_name: "User", optional: true

    scope :active, -> { kept.where(active: true) }
  end

  # El motivo del borrado es obligatorio: sin el no se sabe por que desaparecio
  # un trabajador de un plan firmado.
  def discard_with_reason!(reason, user)
    raise ArgumentError, "se requiere un motivo para eliminar" if reason.blank?

    update!(discard_reason: reason, discarded_by: user, discarded_at: Time.current)
  end

  def status_label
    return :deleted if discarded?

    active? ? :active : :blocked
  end
end
