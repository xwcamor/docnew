class CreateEvidence < ActiveRecord::Migration[7.2]
  def change
    # Fuente unica de verdad de firmas y fotos. En la v1 esta tabla ya existia
    # con el diseno correcto pero no estaba conectada a la aplicacion, y el 83 %
    # de las fotos eran la cadena "detected_by_IA" en vez de un archivo.
    create_table :signature_events do |t|
      t.references :signable, polymorphic: true, null: false, index: false,
                   comment: "PlanPerson, PlanApproval o FormSubmission"
      t.references :person, null: false, foreign_key: true

      t.string   :role_signed, null: false
      t.datetime :signed_at,   null: false
      t.string   :method,      null: false,
                 comment: "face_recognition, timeout_capture, manual, reused, migrated"

      t.boolean  :used_ai,        null: false, default: false
      t.decimal  :match_distance, precision: 6, scale: 4, comment: "Distancia devuelta por el servidor"
      t.decimal  :threshold_used, precision: 4, scale: 3
      t.boolean  :pending_review, null: false, default: false
      t.boolean  :manual_override, null: false, default: false
      t.string   :override_reason
      t.bigint   :override_by_id
      t.datetime :reviewed_at
      t.bigint   :reviewed_by_id
      t.boolean  :evidence_missing, null: false, default: false,
                 comment: "Solo para lo migrado de la v1, donde no habia archivo"

      t.decimal  :latitude,  precision: 10, scale: 6
      t.decimal  :longitude, precision: 10, scale: 6
      t.string   :device_id
      t.string   :ip_address
      t.string   :user_agent
      t.string   :country_code
      t.string   :region
      t.string   :city
      t.timestamps
    end
    add_index :signature_events, %i[signable_type signable_id]
    add_index :signature_events, %i[pending_review signed_at]
    add_index :signature_events, %i[person_id signed_at]
    add_foreign_key :signature_events, :users, column: :override_by_id
    add_foreign_key :signature_events, :users, column: :reviewed_by_id

    create_table :evidence_files do |t|
      t.references :signature_event, null: false, foreign_key: true
      t.string   :kind,      null: false, comment: "face, signature"
      t.string   :file_path, null: false
      t.string   :sha256,    null: false, limit: 64
      t.integer  :byte_size, null: false
      t.integer  :width
      t.integer  :height
      t.datetime :taken_at
      t.timestamps
    end
    # Deduplicacion: el mismo archivo no se guarda dos veces.
    add_index :evidence_files, :sha256, unique: true
    add_index :evidence_files, %i[signature_event_id kind]
  end
end
