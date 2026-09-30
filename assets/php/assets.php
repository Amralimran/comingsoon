<?php
// /assets/php/assets.php
//
// Central asset registry. Apps call portal_assets([...]) with the group
// names they need. One version constant invalidates every asset.
//
// Usage:
//   include_once $rootDoc . '/assets/php/assets.php';
//   portal_assets(['app', 'rtf']);
//
// Groups are declared in $GLOBALS['PORTAL_ASSET_GROUPS']. Each group
// expands to a list of asset entries. Groups are resolved in the order
// requested, and duplicates are emitted only once.

if (!defined('PORTAL_ASSET_VERSION')) {
    define('PORTAL_ASSET_VERSION', '0.011');
}

if (!isset($GLOBALS['PORTAL_ASSET_GROUPS'])) {
    // Each entry: ['type' => 'css'|'js', 'path' => '/assets/...']
    // Order within a group matters. Order of groups passed to portal_assets() matters.
    $GLOBALS['PORTAL_ASSET_GROUPS'] = [

        // ---- Base portal styling and semantics ----
        'css-base' => [
            ['type' => 'css', 'path' => '/assets/css/portal.css'],
        ],

        // ---- Tabulator (grid library) — CSS + JS ----
        'tables' => [
            ['type' => 'css', 'path' => '/assets/css/tabulator.min.css'],
            ['type' => 'js',  'path' => '/assets/js/tabulator.min.js'],
        ],

        // ---- Tabulator without JS (for pages that only need the styling) ----
        'tables-css' => [
            ['type' => 'css', 'path' => '/assets/css/tabulator.min.css'],
        ],

        // ---- Dialog component (showModalDialog) ----
        'dialog' => [
            ['type' => 'js', 'path' => '/assets/js/dialog.js'],
        ],

        // ---- RTF / plain editor ----
        'rtf' => [
            ['type' => 'css', 'path' => '/assets/css/rtf_modal.css'],
            ['type' => 'js',  'path' => '/assets/js/rtf_editor.js'],
        ],

        // ---- Convenience bundle: most apps need this set ----
        'app' => ['css-base', 'tables', 'dialog'],
    ];
}

/**
 * Emit link and script tags for the requested asset groups.
 *
 * @param array $groups Group names (strings) or nested arrays of group names.
 *                      Example: portal_assets(['app']);
 *                               portal_assets(['app', 'rtf']);
 *                               portal_assets(['css-base', 'tables-css', 'dialog']);
 */
function portal_assets($groups = []) {
    static $emitted = [];

    $flat = [];
    $stack = $groups;
    while (!empty($stack)) {
        $item = array_shift($stack);
        if (is_array($item)) {
            foreach ($item as $sub) array_unshift($stack, $sub);
            continue;
        }
        $flat[] = $item;
    }

    $entries = [];
    foreach ($flat as $name) {
        if (!isset($GLOBALS['PORTAL_ASSET_GROUPS'][$name])) {
            continue;
        }
        foreach ($GLOBALS['PORTAL_ASSET_GROUPS'][$name] as $entry) {
            if (is_string($entry)) {
                foreach ($GLOBALS['PORTAL_ASSET_GROUPS'][$entry] ?? [] as $sub) {
                    $entries[] = $sub;
                }
            } else {
                $entries[] = $entry;
            }
        }
    }

    foreach ($entries as $entry) {
        $type = $entry['type'] ?? '';
        $path = $entry['path'] ?? '';
        if (!$type || !$path) continue;

        $key = $type . '|' . $path;
        if (isset($emitted[$key])) continue;
        $emitted[$key] = true;

        $url = $path . '?v=' . rawurlencode(PORTAL_ASSET_VERSION);

        if ($type === 'css') {
            echo '<link href="' . htmlspecialchars($url) . '" rel="stylesheet">' . "\n";
        } elseif ($type === 'js') {
            echo '<script src="' . htmlspecialchars($url) . '"></script>' . "\n";
        }
    }
}