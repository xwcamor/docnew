class CreateOrganizations < ActiveRecord::Migration[7.2]
  def change
    %i[nationalities positions jobs areas].each do |table|
      create_table table do |t|
        t.references :country, null: false, foreign_key: true
        t.string   :code
        t.boolean  :active, null: false, default: true
        t.datetime :discarded_at
        t.bigint   :discarded_by_id
        t.string   :discard_reason
        t.timestamps
      end
      add_index table, :discarded_at
    end

    # positions define quien puede firmar como aprobador
    add_column :positions, :signature_approver, :boolean, null: false, default: false

    create_table :companies do |t|
      t.references :country, null: false, foreign_key: true
      t.string   :slug,          null: false, limit: 22
      t.string   :num_doc,       null: false
      t.string   :name,          null: false
      t.string   :complete_name, null: false
      t.boolean  :active,        null: false, default: true
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :companies, :slug, unique: true
    add_index :companies, %i[country_id num_doc], unique: true
    add_index :companies, :discarded_at

    create_table :locations do |t|
      t.references :country, null: false, foreign_key: true
      t.string   :name,   null: false
      t.boolean  :active, null: false, default: true
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end

    add_index :locations, :discarded_at

    create_table :workstations do |t|
      t.references :location, null: false, foreign_key: true
      t.string   :name,   null: false
      t.boolean  :active, null: false, default: true
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end

    add_index :workstations, :discarded_at

    create_table :work_types do |t|
      t.references :country, null: false, foreign_key: true
      t.string   :code,   null: false
      t.boolean  :active, null: false, default: true
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :work_types, %i[country_id code], unique: true
    add_index :work_types, :discarded_at

    add_foreign_key :users, :companies
  end
end
