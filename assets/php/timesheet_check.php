<?php
// assets/php/timesheet_check.php

function run_timesheet_check($targetRecipient = 'auth_hr', $target = 'timesheets') {
    $rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
        $rootDoc = '/volume1/web';
    }

    $todayDate = date('Y-m-d');
    $isRole = str_starts_with(strtolower(trim($targetRecipient)), 'auth_');

    if ($isRole) {
        $trackerDir = $rootDoc . "/apps/messages/";
    } else {
        $empId = str_pad(preg_replace('/[^0-9]/', '', $targetRecipient), 3, '0', STR_PAD_LEFT);
        $trackerDir = $rootDoc . "/staff/{$empId}/";
    }

    if (!is_dir($trackerDir)) {
        @mkdir($trackerDir, 0755, true);
    }
    
    $trackerFile = $trackerDir . ".last_{$target}_check_" . ($isRole ? 'role' : 'indiv');

    $runCheck = true;
    if (file_exists($trackerFile)) {
        $lastCheckedVal = trim(file_get_contents($trackerFile));
        if ($lastCheckedVal >= $todayDate) {
            $runCheck = false;
        }
    }

    // Check for an active pause marker.
    if ($runCheck) {
        $pauseFile = $trackerDir . ".pause_{$target}";
        if (file_exists($pauseFile)) {
            $pauseUntil = trim(file_get_contents($pauseFile));
            if ($pauseUntil >= $todayDate) {
                $runCheck = false;   // Paused — skip today
            }
        }
    }

    if ($runCheck) {
        require_once $rootDoc . '/assets/php/alert_helper.php';
        $portalEnvFile = $rootDoc . '/apps/env/portal_dates.json';
        $portalMilestones = file_exists($portalEnvFile) ? json_decode(file_get_contents($portalEnvFile), true) : ['2024-01-01'];
        sort($portalMilestones);
        $deploymentDateStr = reset($portalMilestones);
        $deploymentDto = new DateTime($deploymentDateStr);

        $staffFile = $rootDoc . '/apps/hr/staff.json';
        $staffData = file_exists($staffFile) ? json_decode(file_get_contents($staffFile), true) : [];

        if ($isRole) {
            $reportFile = $rootDoc . '/apps/messages/missing_timesheets.json';
            if (file_exists($reportFile)) {
                $missingRows = json_decode(file_get_contents($reportFile), true) ?: [];
                if (!empty($missingRows)) {
                    $alertText = 'Pending missing timesheets detected across staff records. '
                               . '<a href="javascript:void(0);" onclick="dismissAndNavigate(\'/apps/messages/index.php\')" style="color: #007bff; font-weight: bold; text-decoration: underline;">Open Messages</a> | '
                               . '<a href="javascript:void(0);" onclick="openPauseModal(\'auth_hr\', \'' . $target . '\')" style="color: #6c757d; text-decoration: underline;">Pause Notification</a>';
                    alert('system', $targetRecipient, $alertText);
                }
            }
        } else {
            $empId = str_pad(preg_replace('/[^0-9]/', '', $targetRecipient), 3, '0', STR_PAD_LEFT);
            $empFolder = $rootDoc . "/staff/{$empId}/timesheets";
            
            $rawJoined = $staffData["employee-{$empId}"]['joined_date'] ?? $deploymentDateStr;
            $startDto = ($rawJoined > $deploymentDateStr) ? new DateTime($rawJoined) : clone $deploymentDto;
            
            $cursorDto = clone $startDto;
            $missingCount = 0;

            while ($cursorDto < new DateTime()) {
                $yr = intval($cursorDto->format('Y'));
                $wk = intval($cursorDto->format('W'));
                $weekFile = $empFolder . "/{$yr}-W" . str_pad($wk, 2, '0', STR_PAD_LEFT) . ".json";
                
                $isSubmitted = false;
                if (file_exists($weekFile)) {
                    $tData = json_decode(file_get_contents($weekFile), true);
                    if (!empty($tData['is_locked']) || !empty($tData['entries'])) {
                        $isSubmitted = true;
                    }
                }

                if (!$isSubmitted) {
                    $missingCount++;
                }
                $cursorDto->modify('+1 week');
            }

            if ($missingCount > 0) {
                $alertText = 'You have not submitted <strong>' . $missingCount . ' week(s)</strong> of Timesheets. '
                           . '<a href="javascript:void(0);" onclick="dismissAndNavigate(\'/apps/timesheets/index.php?emp=' . $empId . '\')" style="color: #007bff; font-weight: bold; text-decoration: underline;">Open Timesheet</a> | '
                           . '<a href="javascript:void(0);" onclick="openPauseModal(\'' . $empId . '\', \'' . $target . '\')" style="color: #6c757d; text-decoration: underline;">Pause Notification</a>';
                alert('system', $targetRecipient, $alertText);
            }
        }

        file_put_contents($trackerFile, $todayDate);
    }
}