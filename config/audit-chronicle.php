<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Accepted categories
    |--------------------------------------------------------------------------
    |
    | Which AuditCategory values the Chronicle ledger records. A compliance
    | ledger accepts everything, so the default (null) means "every category".
    | Narrow it to a subset of AuditCategory::cases() to record only some — e.g.
    | [AuditCategory::Authn, AuditCategory::Authz, AuditCategory::Token] to keep
    | the ledger to security-relevant events and leave content in the feed.
    | Values are Kanvigo\Audit\Contracts\AuditCategory instances (or cases).
    |
    */

    'categories' => null,

    /*
    |--------------------------------------------------------------------------
    | Failure policy
    |--------------------------------------------------------------------------
    |
    | How the sink is dispatched and what a ledger-write failure means:
    |
    |   'fail-closed' — synchronous, inside the audited action's transaction; a
    |                   failed ledger write rolls the action back ("no guaranteed
    |                   record → no action"). This is the compliance-grade default.
    |                   Every audited mutation must run inside a DB transaction —
    |                   emitting one outside a transaction throws.
    |   'sync'        — inline after the action commits; a failed write is reported
    |                   and isolated, and the action still stands.
    |
    */

    'policy' => env('AUDIT_CHRONICLE_POLICY', 'fail-closed'),

    /*
    |--------------------------------------------------------------------------
    | Actor reference type
    |--------------------------------------------------------------------------
    |
    | An AuditEvent carries only an actor id (a user), not a type, but Chronicle
    | stores every actor as a (type, id) reference. This names the actor type so
    | the ledger can resolve it back. Set it to your User model class to enable
    | Chronicle's reference hydration, or keep a morph alias like "user".
    |
    */

    'actor_type' => env('AUDIT_CHRONICLE_ACTOR_TYPE', 'user'),

    /*
    |--------------------------------------------------------------------------
    | System subject
    |--------------------------------------------------------------------------
    |
    | Chronicle requires every entry to name a subject, but some audit events
    | have none of their own (e.g. a failed login before any user is resolved).
    | Such events fall back first to the actor as their own subject, and finally
    | to this synthetic system reference.
    |
    */

    'system_subject' => [
        'type' => 'system',
        'id' => 'system',
    ],

];
