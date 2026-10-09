<?php

declare(strict_types=1);

return [

    'navigation' => [
        'rules' => 'Geo Lock Rules',
    ],

    'rules' => [
        'singular' => 'Geo lock rule',
        'plural' => 'Geo lock rules',
        'unnamed' => 'Rule #:id',
        'create' => 'Create geo lock rule',
        'edit' => 'Edit :name',
    ],

    'location' => [
        'unknown' => 'Unknown location',
    ],

    'countries' => [
        'other' => 'Other',
        'continents' => [
            'af' => 'Africa',
            'an' => 'Antarctica',
            'as' => 'Asia',
            'eu' => 'Europe',
            'na' => 'North America',
            'oc' => 'Oceania',
            'sa' => 'South America',
        ],
    ],

    'denial' => [
        'generic' => 'Access to this panel is not allowed from your location.',
        'blacklist' => 'Access to this panel is not allowed from :country.',
        'whitelist' => 'Access to this panel is only allowed from approved locations.',
        'unresolvable' => 'Your location could not be determined, so access has been denied.',
        'resolver_failure' => 'The geolocation service is currently unavailable, so access has been denied.',
        'untrusted_proxy' => 'Your request carries proxy headers that this application does not trust, so access has been denied.',
    ],

    'enums' => [
        'rule_type' => [
            'whitelist' => 'Whitelist',
            'blacklist' => 'Blacklist',
            'whitelist_description' => 'Only the selected locations may access the panel; everything else is blocked.',
            'blacklist_description' => 'The selected locations are blocked; everything else is allowed.',
        ],
        'outcome' => [
            'allowed' => 'Allowed',
            'bypassed' => 'Bypassed',
            'no_rules' => 'No applicable rules',
            'denied_blacklist' => 'Blocked (blacklist)',
            'denied_whitelist' => 'Blocked (whitelist)',
            'denied_unresolvable' => 'Blocked (unknown location)',
            'denied_resolver_failure' => 'Blocked (resolver failure)',
            'denied_untrusted_proxy' => 'Blocked (untrusted proxy)',
            'disabled' => 'Geo-lock disabled',
            'allowed_description' => 'The visitor matched an applicable rule.',
            'bypassed_description' => 'The visitor was granted the emergency bypass.',
            'no_rules_description' => 'No active rule applies to the visitor.',
            'denied_blacklist_description' => 'The visitor location matches a blacklist rule.',
            'denied_whitelist_description' => 'The visitor location matches none of the whitelist rules that bind them.',
            'denied_unresolvable_description' => 'The visitor location could not be resolved and the configured behaviour is to block.',
            'denied_resolver_failure_description' => 'The geolocation service failed and the configured behaviour is to block.',
            'denied_untrusted_proxy_description' => 'The request carries proxy headers that are not backed by a TrustedProxy configuration.',
            'disabled_description' => 'Geo-locking is switched off in the configuration.',
        ],
        'unresolvable' => [
            'allow' => 'Allow (fail open)',
            'deny' => 'Deny (fail closed)',
            'allow_description' => 'Visitors whose location cannot be determined keep their access.',
            'deny_description' => 'Visitors whose location cannot be determined are blocked.',
        ],
        'resolver_failure' => [
            'whitelist_only' => 'Block whitelist-bound visitors only',
            'fail_closed' => 'Block every bound visitor',
            'fail_open' => 'Never block',
            'whitelist_only_description' => 'Recommended: visitors bound by a whitelist rule are blocked until the service is restored.',
            'fail_closed_description' => 'Every visitor bound by a rule is blocked while the service is down.',
            'fail_open_description' => 'Geo-locking is suspended while the service is down.',
        ],
    ],

    'form' => [
        'sections' => [
            'rule' => 'Rule',
            'rule_description' => 'Roles, rule type and the locations the rule applies to.',
        ],
        'name' => 'Rule name',
        'name_placeholder' => 'Editors outside of the US',
        'type' => 'Rule type',
        'priority' => 'Priority',
        'priority_helper' => 'Rules with a higher priority are evaluated first. Blacklist rules always win.',
        'roles' => 'Roles',
        'roles_helper' => 'Leave empty to apply the rule to every role.',
        'roles_free_text_helper' => 'No role provider was detected: type the role names, separated by commas.',
        'roles_placeholder' => 'editor, moderator',
        'countries' => 'Countries',
        'countries_helper' => 'ISO 3166-1 alpha-2 country codes.',
        'regions' => 'Specific regions / states',
        'regions_helper' => 'Optional ISO 3166-2 subdivisions. Leave empty to cover the whole country.',
        'is_active' => 'Rule active',
        'is_active_helper' => 'Turn the rule off without deleting it.',
        'description' => 'Description',
    ],

    'table' => [
        'name' => 'Rule',
        'type' => 'Type',
        'roles' => 'Roles',
        'every_role' => 'Every role',
        'countries' => 'Countries',
        'regions' => 'Regions',
        'priority' => 'Priority',
        'is_active' => 'Active',
        'blocks' => 'Blocks',
        'updated_at' => 'Updated',
        'empty_heading' => 'No geo-lock rule yet',
        'empty_description' => 'Create your first rule to restrict panel access by country, region or role.',
    ],

    'actions' => [
        'settings' => 'Bulk settings',
        'new_rule' => 'New rule',
        'activate' => 'Activate',
        'deactivate' => 'Deactivate',
        'toggled' => 'Rule updated',
        'my_location' => 'My location',
        'my_location_body' => 'IP :ip - :country / :region',
        'simulate' => 'Test an IP address',
        'simulate_ip' => 'IP address',
        'simulate_role' => 'Role',
        'simulate_all_roles' => 'Every role',
        'simulate_result' => ':location - :message',
        'flush_cache' => 'Flush cache',
        'flushed' => 'Rule and location caches flushed',
    ],

    'settings' => [
        'title' => 'Geo Lock Settings',
        'subheading' => 'Define which roles can access this panel from which countries and regions.',
        'navigation_label' => 'Geo Lock Settings',
        'add_rule' => 'Add New Rule',
        'save' => 'Save changes',
        'saved' => 'Geo-lock rules saved',
        'location' => [
            'heading' => 'Your current location',
            'description' => 'This is what the middleware resolves for your own request.',
            'ip' => 'IP address',
            'country' => 'Country',
            'region' => 'Region',
            'decision' => 'Decision',
            'summary' => ':rules rules (:active active) - resolver: :resolver',
            'matched_rule' => 'Matched rule: :rule',
        ],
    ],

    'widgets' => [
        'stats' => [
            'heading' => 'Geo lock overview',
            'active_rules' => 'Active rules',
            'active_rules_description' => 'Rules currently enforced',
            'blocked_today' => 'Blocked today',
            'blocked_today_description' => 'Denied requests since midnight',
            'blocked_week' => 'Blocked this week',
            'blocked_week_description' => 'Denied requests over the last 7 days',
            'countries' => 'Countries blocked',
            'countries_description' => 'Distinct countries denied over the last 7 days',
        ],
        'recent' => [
            'heading' => 'Recent geo-lock decisions',
            'description' => 'The audit trail of granted, bypassed and denied requests.',
            'empty' => 'No decision recorded yet',
            'empty_description' => 'Decisions appear here once the middleware has processed a request.',
            'columns' => [
                'when' => 'When',
                'outcome' => 'Outcome',
                'ip' => 'IP address',
                'location' => 'Location',
                'country' => 'Country',
                'roles' => 'Roles',
                'rule' => 'Rule',
                'url' => 'URL',
            ],
        ],
        'chart' => [
            'heading' => 'Access over time',
            'filters' => [
                'week' => 'Last 7 days',
                'fortnight' => 'Last 14 days',
                'month' => 'Last 30 days',
            ],
            'datasets' => [
                'blocked' => 'Blocked',
                'allowed' => 'Allowed',
            ],
        ],
    ],

];
