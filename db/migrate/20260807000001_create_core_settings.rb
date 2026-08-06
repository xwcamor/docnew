class CreateCoreSettings < ActiveRecord::Migration[7.2]
  def change
    create_table :languages do |t|
      t.string  :name,   null: false
      t.string  :locale, null: false
      t.string  :flag,   null: false
      t.boolean :active, null: false, default: true
      t.timestamps
    end
    add_index :languages, :locale, unique: true

    create_table :countries do |t|
      t.references :language, null: false, foreign_key: true
      t.string  :name,      null: false
      t.string  :code,      null: false, comment: "ISO 3166-1 alfa-2"
      t.string  :flag,      null: false
      t.string  :currency,  null: false
      t.string  :time_zone, null: false
      t.boolean :active,    null: false, default: true
      t.timestamps
    end
    add_index :countries, :code, unique: true

    # Configuracion visual y de negocio por pais. Sustituye a la tabla settings
    # de la v1, donde los colores eran campos de texto libres.
    create_table :settings do |t|
      t.references :country, null: false, foreign_key: true
      t.string  :app_name,        null: false
      t.string  :logo
      t.string  :theme_scheme,    null: false, default: "sap", comment: "Ver docs/UI.md"
      t.string  :header_color_bg, comment: "Solo si se necesita salir del esquema"
      t.string  :sidebar_color_bg
      t.integer :num_doc_minimum, null: false, default: 8
      t.decimal :face_threshold,  null: false, default: "0.5", precision: 4, scale: 3,
                comment: "Distancia maxima aceptada en el reconocimiento facial"
      t.integer :face_timeout_seconds, null: false, default: 30,
                comment: "Tras este tiempo sin coincidencia se captura igual y se marca para revision"
      t.timestamps
    end
    add_index :settings, :country_id, unique: true

    # Traduccion de datos de catalogo. Reemplaza a las columnas name_es/name_pt/name_en
    # que la v1 repetia en 16 tablas.
    create_table :translated_texts do |t|
      t.references :translatable, polymorphic: true, null: false, index: false
      t.string :field,  null: false
      t.string :locale, null: false
      t.text   :value,  null: false
      t.timestamps
    end
    add_index :translated_texts, %i[translatable_type translatable_id field locale],
              unique: true, name: "index_translated_texts_uniqueness"
  end
end
