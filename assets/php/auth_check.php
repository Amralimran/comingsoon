<?php
function getCurrentUserRoles() {
    return get_global_roles();
}

if (!function_exists('check_app_permission')) {
    function check_app_permission($appKey) {
        global $rootDoc;

        $metaFile = $rootDoc . '/apps/' . $appKey . '/meta.json';
        if (!file_exists($metaFile)) return false;

        $meta = json_decode(file_get_contents($metaFile), true);
        if (!is_array($meta)) return false;

        return can_access_app($appKey, $meta);
    }
}