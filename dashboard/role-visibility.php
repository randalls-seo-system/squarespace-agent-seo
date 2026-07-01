<?php
/**
 * LRG Dashboard — Role Visibility Matrix
 * All tabs visible to operator role for now.
 */

define('LRG_SIDEBAR_VISIBILITY', [
    'command-center'  => ['operator'],
    'search-console'  => ['operator'],
    'analytics'       => ['operator'],
    'leads'           => ['operator'],
    'roster'          => ['operator'],
    'worklog'         => ['operator'],
]);

function lrg_user_can_see_section(string $slug, ?string $role): bool {
    if (empty($role)) return false;
    $allowed = LRG_SIDEBAR_VISIBILITY[$slug] ?? ['operator'];
    return in_array($role, $allowed, true);
}
