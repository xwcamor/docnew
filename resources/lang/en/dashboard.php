<?php

return [
    'title'      => 'Panel',
    'hello'      => 'Hi, :name',
    'user'       => 'user',
    'role_super' => 'Platform panel',
    'role_admin' => "Today's panel",
    'role_user'  => "Today's panel",

    // ── Today on site ─────────────────────────────────────────────────────
    'today_title'      => 'Today on site',
    'today_subtitle'   => "Today's work plans and what each one is still missing.",
    'today_all_scope'  => 'You are seeing every workspace.',
    'plans_title'      => "Today's plans",
    'plans_none'       => 'No work plan is dated today.',
    'plans_none_hint'  => 'As soon as a plan is registered for today it shows up here with what it is missing.',
    'plans_new'        => 'New plan',
    'plans_see_all'    => 'See all of today',
    'plans_more'       => 'and :count more dated today',
    'plan_missing'     => 'Missing',
    'plan_no_company'  => 'No company',
    'plan_no_location' => 'No site',

    // Today's indicators
    'widget_plans_today'        => "Today's plans",
    'widget_signatures_pending' => 'Signatures pending',
    'widget_forms_pending'      => 'Forms not confirmed',
    'widget_plans_open'         => 'Unfinished plans',
    'widget_signatures_review'  => 'Signatures to review',

    'hint_plans_today' => '{0} All finished|{1} :count unfinished|[2,*] :count unfinished',
    'hint_signatures_pending' => ':workers from workers · :approvals from approvers',
    'hint_forms_pending'      => 'Handed in and still a draft',
    'hint_plans_open'         => 'Any date',
    'hint_signatures_review'  => 'Captured without recognising the face',

    // Plan state (colour plus word, never colour alone)
    'plan_state_done'    => 'Finished',
    'plan_state_closed'  => 'Closed',
    'plan_state_pending' => 'In progress',

    // ── Your workspace (tenant admin) ─────────────────────────────────────
    'workspace_title'    => 'Your workspace',
    'workspace_subtitle' => 'The account, not the site.',

    'widget_users_count'    => 'Users',
    'widget_automations'    => 'Active automations',
    'widget_auto_failures'  => 'Automation failures',
    'widget_plan_days_left' => 'Days of plan',

    'hint_users_count'   => 'In your workspace',
    'hint_automations'   => 'Rules that run on their own',
    'hint_auto_failures' => 'In the last 7 days',

    // ── Platform (super) ──────────────────────────────────────────────────
    'platform_title'    => 'Platform',
    'platform_subtitle' => 'The state of the whole system.',

    'widget_tenants_active' => 'Active workspaces',
    'widget_subs_active'    => 'Active subscriptions',
    'widget_subs_expiring'  => 'Expiring soon',
    'widget_autos_runs_24h' => 'Automations (24 h)',

    'hint_tenants_total' => ':count in total',
    'hint_subs_active'   => 'Running',
    'hint_subs_expiring' => 'Within 7 days',
    'hint_autos_failed'  => '{0} None failed|{1} :count failed|[2,*] :count failed',

    'expiring_soon'      => 'Subscriptions expiring soon',
    'recent_automations' => 'Recent automations',
    'no_automations_yet' => 'No automation runs yet.',
    'days_left'          => ':n days',
    'records_processed'  => 'Records processed',

    // ── No permission to see plans ────────────────────────────────────────
    'welcome_title' => 'Welcome',
    'welcome_body'  => 'The side menu lists the modules you can reach. The daily panel shows up here as soon as your profile can see work plans.',
];
