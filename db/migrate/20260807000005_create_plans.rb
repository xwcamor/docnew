class CreatePlans < ActiveRecord::Migration[7.2]
  def change
    create_table :plans do |t|
      t.references :country,     null: false, foreign_key: true
      t.references :company,     null: false, foreign_key: true
      t.references :work_type,   null: false, foreign_key: true
      t.references :location,    null: false, foreign_key: true
      t.references :workstation, null: false, foreign_key: true
      t.references :area,        foreign_key: true
      t.references :user,        null: false, foreign_key: true, comment: "Quien lo registra"
      t.string   :slug,        null: false, limit: 22
      t.string   :code,        null: false
      t.string   :num_os
      t.text     :description, null: false
      t.date     :date_start
      t.date     :date_end
      t.boolean  :locked, null: false, default: false
      t.boolean  :done,   null: false, default: false
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :plans, :slug, unique: true
    add_index :plans, %i[country_id code], unique: true
    add_index :plans, %i[company_id created_at]
    add_index :plans, :discarded_at

    # Trabajadores asignados. Sin columnas de foto ni firma: la evidencia vive
    # en signature_events, que es la unica fuente de verdad.
    create_table :plan_people do |t|
      t.references :plan,   null: false, foreign_key: true
      t.references :person, null: false, foreign_key: true
      t.string   :slug, null: false, limit: 22
      t.boolean  :approved, null: false, default: false, comment: "Lo calcula el servidor"
      t.timestamps
    end
    add_index :plan_people, :slug, unique: true
    add_index :plan_people, %i[plan_id person_id], unique: true

    create_table :approval_rules do |t|
      t.references :country, null: false, foreign_key: true
      t.string   :approver_role,  null: false, comment: "worker, supervisor, hse_supervisor"
      t.integer  :priority_level, null: false
      t.boolean  :required, null: false, default: true
      t.boolean  :active,   null: false, default: true
      t.datetime :discarded_at
      t.bigint   :discarded_by_id
      t.string   :discard_reason
      t.timestamps
    end
    add_index :approval_rules, %i[country_id approver_role priority_level],
              unique: true, name: "index_approval_rules_uniqueness"
    add_index :approval_rules, :discarded_at

    create_table :plan_approvals do |t|
      t.references :plan,          null: false, foreign_key: true
      t.references :approval_rule, null: false, foreign_key: true
      t.references :person,        foreign_key: true
      t.string   :slug, null: false, limit: 22
      t.boolean  :required, null: false, default: true
      t.boolean  :approved, null: false, default: false, comment: "Lo calcula el servidor"
      t.timestamps
    end
    add_index :plan_approvals, :slug, unique: true
    add_index :plan_approvals, %i[plan_id approval_rule_id], unique: true
  end
end
