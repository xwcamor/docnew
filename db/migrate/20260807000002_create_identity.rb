class CreateIdentity < ActiveRecord::Migration[7.2]
  def change
    create_table :roles do |t|
      t.string  :name, null: false
      t.string  :code, null: false, comment: "super, admin, user"
      t.timestamps
    end
    add_index :roles, :code, unique: true

    create_table :profiles do |t|
      t.string   :name,        null: false
      t.string   :description
      t.boolean  :active,      null: false, default: true
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :profiles, :discarded_at

    # Permiso = par (recurso, accion) declarado en codigo, no texto libre de la base:
    # en la v1 esto se resolvia con constantize sobre un string editable.
    create_table :accesses do |t|
      t.string :resource_key, null: false, comment: "Clave declarada en config/permissions.yml"
      t.string :action_key,   null: false, comment: "read, create, update, destroy, export"
      t.timestamps
    end
    add_index :accesses, %i[resource_key action_key], unique: true

    create_table :profile_accesses do |t|
      t.references :profile, null: false, foreign_key: true
      t.references :access,  null: false, foreign_key: true
      t.timestamps
    end
    add_index :profile_accesses, %i[profile_id access_id], unique: true

    create_table :users do |t|
      t.references :role,     null: false, foreign_key: true
      t.references :profile,  null: false, foreign_key: true
      t.references :language, null: false, foreign_key: true
      t.references :country,  null: false, foreign_key: true
      t.bigint     :company_id
      t.string     :slug,     null: false, limit: 22

      t.string   :email,              null: false, default: ""
      t.string   :username,           null: false, default: ""
      t.string   :encrypted_password, null: false, default: ""
      t.string   :reset_password_token
      t.datetime :reset_password_sent_at
      t.datetime :remember_created_at
      t.integer  :sign_in_count, null: false, default: 0
      t.datetime :current_sign_in_at
      t.datetime :last_sign_in_at
      t.string   :current_sign_in_ip
      t.string   :last_sign_in_ip

      t.string   :name,      null: false
      t.string   :lastname,  null: false
      t.string   :cellphone
      t.string   :time_zone
      t.boolean  :active,    null: false, default: true
      t.boolean  :hidden,    null: false, default: false, comment: "No aparece en los listados"
      t.boolean  :display_signatures,    null: false, default: false
      t.boolean  :display_private_info,  null: false, default: false

      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :users, :email,    unique: true
    add_index :users, :username, unique: true
    add_index :users, :slug,     unique: true
    add_index :users, :reset_password_token, unique: true
    add_index :users, :discarded_at
  end
end
