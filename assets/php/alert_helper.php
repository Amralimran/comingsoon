<?php
// assets/php/alert_helper.php

function alert($from, $to, $alerttext) {
    $rootDoc = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
    if (!is_dir($rootDoc . '/apps') && is_dir('/volume1/web/apps')) {
        $rootDoc = '/volume1/web';
    }

    $senderRoleOrId = strtolower(trim($from));
    $recipients = [];

    // Check if $to is a role alias starting with "auth_"
    if (str_starts_with(strtolower(trim($to)), 'auth_')) {
        $targetRole = str_replace('auth_', '', strtolower(trim($to)));
        
        $authJsonPath = $rootDoc . '/apps/staff/auth.json';
        $authEntries = file_exists($authJsonPath) ? json_decode(file_get_contents($authJsonPath), true) : [];
        
        if (is_array($authEntries)) {
            foreach ($authEntries as $entry) {
                if (is_array($entry) && count($entry) >= 2) {
                    $eId = str_pad(preg_replace('/[^0-9]/', '', $entry[0]), 3, '0', STR_PAD_LEFT);
                    $roles = array_map('strtolower', array_map('trim', array_slice($entry, 1)));
                    
                    if (in_array($targetRole, $roles)) {
                        $recipients[] = $eId;
                    }
                }
            }
        }
    } else {
        $recipients[] = str_pad(preg_replace('/[^0-9]/', '', $to), 3, '0', STR_PAD_LEFT);
    }

    if (empty($recipients)) {
        return ["status" => "error", "message" => "No recipient found for target: {$to}"];
    }

    $alertUsersFile = $rootDoc . "/apps/staff/alertusers.json";
    $alertUsers = file_exists($alertUsersFile) ? (json_decode(file_get_contents($alertUsersFile), true) ?: []) : [];

    foreach ($recipients as $targetEmpId) {
        $staffDir = $rootDoc . "/staff/{$targetEmpId}/";
        if (!is_dir($staffDir)) {
            mkdir($staffDir, 0755, true);
        }
        $alertBoxFile = $staffDir . "alertbox.json";
        $alertBox = file_exists($alertBoxFile) ? (json_decode(file_get_contents($alertBoxFile), true) ?: []) : [];

        $alertId = 'alt_' . mt_rand(10000, 99999);
        $newAlert = [
            "id" => $alertId,
            "from" => $senderRoleOrId,
            "text" => trim($alerttext),
            "time" => date('Y-m-d H:i:s')
        ];
        $alertBox[] = $newAlert;
        file_put_contents($alertBoxFile, json_encode($alertBox, JSON_PRETTY_PRINT));

        // Format: ["sender", "target_emp_id", "alert_text", "alert_id"]
        $alertUsers[] = [$senderRoleOrId, $targetEmpId, trim($alerttext), $alertId];
    }

    file_put_contents($alertUsersFile, json_encode($alertUsers, JSON_PRETTY_PRINT));

    return ["status" => "success", "message" => "Alert broadcasted successfully."];
}
function remove_alerts_of_type($rootDoc, $empId, $type) {
    // 1. alertusers.json — filter by (target, type)
    $alertUsersFile = $rootDoc . "/apps/staff/alertusers.json";
    if (file_exists($alertUsersFile)) {
        $fp = fopen($alertUsersFile, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $list = json_decode($raw, true);
            if (!is_array($list)) $list = [];

            $list = array_values(array_filter($list, function($u) use ($empId, $type) {
                if (!is_array($u)) return false;
                $uEmp  = auth_normalize_emp_id($u[1] ?? $u['target'] ?? '');
                $uType = $u['type'] ?? '';       // new shape
                if ($uEmp !== $empId) return true;
                if ($uType === $type) return false;
                // Legacy fallback: match by substring for un-migrated entries.
                $text = strtolower($u[2] ?? $u['text'] ?? '');
                if ($type === 'timesheets' && str_contains($text, 'timesheet')) return false;
                if ($type === 'expiry' && (str_contains($text, 'expiring') || str_contains($text, 'expired'))) return false;
                return true;
            }));

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // 2. alertbox.json — same idea, object shape
    $alertBoxFile = $rootDoc . "/staff/{$empId}/alertbox.json";
    if (file_exists($alertBoxFile)) {
        $box = json_decode(file_get_contents($alertBoxFile), true);
        if (is_array($box)) {
            $box = array_values(array_filter($box, function($a) use ($type) {
                if (!is_array($a)) return false;
                if (($a['type'] ?? '') === $type) return false;
                $text = strtolower($a['text'] ?? '');
                if ($type === 'timesheets' && str_contains($text, 'timesheet')) return false;
                if ($type === 'expiry' && (str_contains($text, 'expiring') || str_contains($text, 'expired'))) return false;
                return true;
            }));
            file_put_contents(
                $alertBoxFile,
                json_encode($box, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        }
    }
}