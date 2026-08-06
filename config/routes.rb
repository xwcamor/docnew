Rails.application.routes.draw do
  devise_for :users, controllers: { sessions: "auth_management/sessions" }

  root to: "dashboard_management/dashboards#show"

  # Un archivo de rutas por grupo de negocio (ver docs/CREATE-MODULE.md).
  draw(:core_management)
  draw(:company_management)
  draw(:person_management)
  draw(:plan_management)
  draw(:form_management)
end
