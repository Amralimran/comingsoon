<?php
// /assets/php/portal_timeline.php

function get_portal_timeline($rootDoc = null) {
    if (!$rootDoc) {
        $rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
            $rootDoc = '/volume1/web';
        }
    }
    
    $portalDatesFile = $rootDoc . '/apps/env/portal_dates.json';
    $milestones = file_exists($portalDatesFile) ? json_decode(file_get_contents($portalDatesFile), true) : [];
    
    if (!is_array($milestones) || empty($milestones)) {
        $milestones = [date('Y-m-d')];
    }
    sort($milestones);
    
    return [
        "deployment_date" => reset($milestones),
        "latest_milestone" => end($milestones),
        "milestones" => $milestones
    ];
}

// Check if a given date or period is in active editing mode (vs historical view-only)
function is_active_period($targetDateStr) {
    $timeline = get_portal_timeline();
    $latest = $timeline['latest_milestone'];
    
    // If target date is on or after the latest milestone/closing date, it's active.
    // Anything prior falls into the view-only archive.
    return strcmp($targetDateStr, $latest) >= 0;
}