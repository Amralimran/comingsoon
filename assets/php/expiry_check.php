<?php
// assets/php/expiry_check.php

function run_expiry_check($targetRecipient = 'auth_hr', $target = 'expiry', $expiryJsonSubPath = '/apps/personal/expiry.json') {
    $rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
        $rootDoc = '/volume1/web';
    }

    $todayDate = date('Y-m-d');
    
    $isRole = str_starts_with(strtolower(trim($targetRecipient)), 'auth_');

    if ($isRole) {
        $trackerDir = $rootDoc . "/apps/personal/";
    } else {
        $empId = str_pad(preg_replace('/[^0-9]/', '', $targetRecipient), 3, '0', STR_PAD_LEFT);
        $trackerDir = $rootDoc . "/staff/{$empId}/";
    }

    if (!is_dir($trackerDir)) {
        mkdir($trackerDir, 0755, true);
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
        $expiryJsonPath = $rootDoc . $expiryJsonSubPath;
        if (file_exists($expiryJsonPath)) {
            $expiries = json_decode(file_get_contents($expiryJsonPath), true) ?: [];
            $todayObj = new DateTime($todayDate);
            
            $expiringDocNames = [];
            $targetEmpId = !$isRole ? str_pad(preg_replace('/[^0-9]/', '', $targetRecipient), 3, '0', STR_PAD_LEFT) : null;

            foreach ($expiries as $entry) {
                $parts = explode('_', $entry, 4);
                if (count($parts) >= 3) {
                    $eId = $parts[0];
                    $expDateStr = $parts[1];
                    $docName = $parts[2];
                    $remindWithin = isset($parts[3]) ? trim($parts[3]) : '';
                    
                    if ($remindWithin === '' || !is_numeric($remindWithin) || intval($remindWithin) <= 0) {
                        continue;
                    }
                    $thresholdDays = intval($remindWithin);

                    if ($targetEmpId && $eId !== $targetEmpId) {
                        continue;
                    }

                    $expObj = DateTime::createFromFormat('Y-m-d', $expDateStr);
                    if ($expObj) {
                        $daysLeft = (int)$todayObj->diff($expObj)->format('%r%a');
                        if ($daysLeft <= $thresholdDays) {
                            $expiringDocNames[] = $docName;
                        }
                    }
                }
            }

            if (!empty($expiringDocNames)) {
                require_once $rootDoc . '/assets/php/alert_helper.php';
                
                $expiringDocNames = array_unique($expiringDocNames);
                $docListStr = implode(', ', $expiringDocNames);

                // Generate a unique alert ID first so we can reference it in the links
                $alertId = 'alt_' . mt_rand(10000, 99999);

                if ($isRole) {
                    $alertText = 'Expiring Documents detected across staff records. '
                               . '<a href="javascript:void(0);" onclick="dismissAndNavigate(\'/apps/messages/index.php\')" style="color: #007bff; font-weight: bold; text-decoration: underline;">Open Messages</a> | '
                               . '<a href="javascript:void(0);" onclick="openPauseModal(\'auth_hr\', \'' . $target . '\')" style="color: #6c757d; text-decoration: underline;">Pause Notification</a>';
                } else {
                    $alertText = 'Your document(s) [<strong>' . htmlspecialchars($docListStr) . '</strong>] are expiring or have expired. '
                               . '<a href="javascript:void(0);" onclick="dismissAndNavigate(\'/apps/personal/index.php?emp=' . $targetEmpId . '\')" style="color: #007bff; font-weight: bold; text-decoration: underline;">Open Personal App</a> | '
                               . '<a href="javascript:void(0);" onclick="openPauseModal(\'' . $targetEmpId . '\', \'' . $target . '\')" style="color: #6c757d; text-decoration: underline;">Pause Notification</a>';
                }

                // Pass this specific $alertId into your alert function if your alert_helper accepts it, 
                // OR alert('system', $targetRecipient, $alertText) will generate its own ID. 
                // Wait! If alert() generates its own ID, let's make sure dismissAndNavigate uses whatever ID gets stored.
                alert('system', $targetRecipient, $alertText);
            }
        }

        file_put_contents($trackerFile, $todayDate);
    }
}