class CreateFormEngine < ActiveRecord::Migration[7.2]
  def change
    # Definicion del formato. En la v1 cada formato era una tabla, un modelo, un
    # controlador, sus vistas y su PDF: 3 a 5 dias de trabajo por formato.
    create_table :form_templates do |t|
      t.references :country, null: false, foreign_key: true
      t.string   :slug, null: false, limit: 22
      t.string   :code, null: false
      t.string   :kind, null: false, default: "structured",
                 comment: "structured, upload_only, hybrid"
      t.string   :status, null: false, default: "draft", comment: "draft, published, archived"
      t.integer  :version, null: false, default: 1
      t.boolean  :requires_signature, null: false, default: true
      t.string   :pdf_template, comment: "Plantilla ERB propia cuando el diseno es fijo por ley"
      t.datetime :published_at
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :form_templates, :slug, unique: true
    add_index :form_templates, %i[country_id code version], unique: true
    add_index :form_templates, :discarded_at

    create_table :form_sections do |t|
      t.references :form_template, null: false, foreign_key: true
      t.integer :position, null: false, default: 0
      t.timestamps
    end

    create_table :form_fields do |t|
      t.references :form_section, null: false, foreign_key: true
      t.string  :code,       null: false
      t.string  :field_type, null: false,
                comment: "text, textarea, number, date, time, select, multiselect, checkbox, " \
                         "radio, table, photo, file, signature, person_checklist, tool_checklist, " \
                         "risk_matrix, question_bank"
      t.boolean :required, null: false, default: false
      t.integer :position, null: false, default: 0
      t.json    :config,         comment: "Opciones, limites y catalogo de origen"
      t.json    :visibility_rule, comment: "Condiciones para mostrar el campo"
      t.timestamps
    end
    add_index :form_fields, %i[form_section_id code], unique: true

    create_table :work_type_form_templates do |t|
      t.references :work_type,     null: false, foreign_key: true
      t.references :form_template, null: false, foreign_key: true
      t.boolean :required, null: false, default: true
      t.timestamps
    end
    add_index :work_type_form_templates, %i[work_type_id form_template_id],
              unique: true, name: "index_work_type_form_templates_uniqueness"

    # Captura. Guarda la version de plantilla con la que se lleno: cambiar un
    # formato no puede alterar lo ya firmado.
    create_table :form_submissions do |t|
      t.references :plan,          null: false, foreign_key: true
      t.references :form_template, null: false, foreign_key: true
      t.references :submitted_by,  foreign_key: { to_table: :users }
      t.string   :slug, null: false, limit: 22
      t.integer  :template_version, null: false
      t.string   :status, null: false, default: "draft", comment: "draft, submitted, confirmed"
      t.text     :observations
      t.datetime :submitted_at
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :form_submissions, :slug, unique: true
    add_index :form_submissions, %i[plan_id form_template_id], unique: true
    add_index :form_submissions, :discarded_at

    create_table :form_answers do |t|
      t.references :form_submission, null: false, foreign_key: true
      t.references :form_field,      null: false, foreign_key: true
      t.integer  :row_index, null: false, default: 0, comment: "Para campos de tipo tabla"
      t.text     :value_text
      t.decimal  :value_number, precision: 16, scale: 4
      t.datetime :value_datetime
      t.boolean  :value_boolean
      t.json     :value_json
      t.timestamps
    end
    add_index :form_answers, %i[form_submission_id form_field_id row_index],
              unique: true, name: "index_form_answers_uniqueness"

    # El caso "HOJA X": el formato es una foto del papel.
    create_table :form_attachments do |t|
      t.references :form_submission, null: false, foreign_key: true
      t.references :form_field,      foreign_key: true
      t.references :uploaded_by,     foreign_key: { to_table: :users }
      t.string   :file_path, null: false
      t.string   :sha256,    null: false, limit: 64
      t.string   :mime_type, null: false
      t.integer  :byte_size, null: false
      t.datetime :uploaded_at, null: false
      t.timestamps
    end
    add_index :form_attachments, :sha256
  end
end
