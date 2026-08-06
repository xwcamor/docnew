class CreatePeople < ActiveRecord::Migration[7.2]
  def change
    # Una sola identidad por persona. En la v1 habia tres tablas (workers,
    # supervisors, hse_supervisors) y ademas una fila por cada empresa en la que
    # trabajaba: 391 filas para 231 personas reales.
    create_table :people do |t|
      t.references :country,     null: false, foreign_key: true
      t.references :nationality, null: false, foreign_key: true
      t.string   :slug,     null: false, limit: 22
      t.string   :doc_type, null: false, default: "DNI"
      t.string   :num_doc,  null: false
      t.string   :name,     null: false
      t.string   :lastname, null: false
      t.date     :birthdate
      t.boolean  :active,   null: false, default: true
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :people, :slug, unique: true
    # El indice unico que faltaba en la v1: alli la unicidad solo vivia en Ruby.
    add_index :people, %i[country_id doc_type num_doc], unique: true
    add_index :people, :discarded_at

    # La misma persona puede trabajar en varias empresas sin duplicar su identidad.
    create_table :person_company_links do |t|
      t.references :person,   null: false, foreign_key: true
      t.references :company,  null: false, foreign_key: true
      t.references :position, null: false, foreign_key: true
      t.date     :started_on
      t.date     :ended_on
      t.boolean  :active, null: false, default: true
      t.timestamps
    end
    add_index :person_company_links, %i[person_id company_id], unique: true

    create_table :person_roles do |t|
      t.references :person, null: false, foreign_key: true
      t.string  :role,   null: false, comment: "worker, supervisor, hse_supervisor"
      t.boolean :active, null: false, default: true
      t.timestamps
    end
    add_index :person_roles, %i[person_id role], unique: true

    # Biometria: un registro vigente por persona, con quien lo dio de alta.
    create_table :person_biometrics do |t|
      t.references :person, null: false, foreign_key: true
      t.json     :face_descriptor, null: false
      t.decimal  :threshold, precision: 4, scale: 3, comment: "Si es nulo se usa el del pais"
      t.datetime :enrolled_at, null: false
      t.bigint   :enrolled_by_id
      t.boolean  :active, null: false, default: true
      t.timestamps
    end
    add_index :person_biometrics, %i[person_id active]

    # Firma de referencia, versionada: nunca se sobrescribe la anterior.
    create_table :person_signatures do |t|
      t.references :person, null: false, foreign_key: true
      t.string   :file_path, null: false
      t.string   :sha256,    null: false, limit: 64
      t.string   :source,    null: false, comment: "captured, imported, migrated"
      t.datetime :valid_from, null: false
      t.datetime :valid_to
      t.timestamps
    end
    add_index :person_signatures, %i[person_id valid_to]

    add_foreign_key :person_biometrics, :users, column: :enrolled_by_id
  end
end
