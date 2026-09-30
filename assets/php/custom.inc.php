<?php
$config['plugins'] = [];
$config['log_driver'] = 'stdout';
$config['zipdownload_selection'] = true;
$config['des_key'] = 'ywtaTWR3Zc+TW6iHJWAcQLVI';
$config['enable_spellcheck'] = true;
$config['spellcheck_engine'] = 'pspell';

// --- YOUR BRANDING STARTS HERE ---
$config['product_name'] = 'THEIMRANS Webmail';

$config['skin_logo'] = array(
    'login' => 'https://theimrans.tech/assets/images/logo.svg',
);
// --- YOUR BRANDING ENDS HERE ---

include(__DIR__ . '/config.docker.inc.php');
