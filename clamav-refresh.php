<?php

#
# File name: clamav-private-mirror-refresh.php
# Author: TOUCHWEB SAS (Vincent GUESNARD)
# Licence: GPL
# PHP rewrite of clamav-refresh.pl (with adaptations needed on 03/05/2021)
# 
# Dependancies : 
# - PHP-CLI (Tested on PHP 5.6 to PHP 8.0)
# - Freshclam (We assume you known how to configure it else check this : https://www.clamav.net/documents/freshclam-faq / https://manpages.debian.org/buster/clamav-freshclam/freshclam.1.en.html)
# - Sigtools (Clamav)
# - Dig (for DNS purposes) - apt-get install dns-utils on Debian
# - Wget
# 
# Version : 1.0.1
# 
# Changelog : https://github.com/touchweb-vincent/clamav-private-miror
#
#############################################################################
#
global $clamav_dns, $path_private_mirror, $version_minimum;


$path_private_mirror = '/home/www/clamav/';

$path_clamav_local_db = '/var/lib/clamav/';
$clamav_dns = 'current.cvd.clamav.net';
$clamav_wan_database = 'https://database.clamav.net/';

$files_to_check = array(
    'main.cvd',
    'daily.cvd',
    'bytecode.cvd'
);
$bin_to_check = array(
    'dig' => 'dns-utils',
    'freshclam' => 'clamav',
    'sigtool' => 'clamav',
    'wget' => 'wget'
);

//03/07/2021
$version_minimum = array(
    'clamav' => '0.103.1',
    'main.cvd' => '59',
    'daily.cvd' => '26100',
    'bytecode.cvd' => '332',
);
$version_script = '1.0.1';

function form_27B_6()
{
    echo "\nTHERE IS FATALS ERRORS - STOPPING PROCESS";
    die();
}

echo "\nSTARTING REFRESH CLAMAV PRIVATE MIRROR\n";

echo "\nWARNING 1 : DO NOT LAUNCH THIS SCRIPT MORE THAN ONE TIME PER DAY - Bandwidth of ClamAv's infrastructure thanks you ;)";
echo "\nWARNING 2 : MAKE SURE - YOU LAUNCH THIS SCRIPT AFTER FRESHCLAM DAILY UPDATE";
echo "\nWARNING 3 : MAKE SURE - YOU SET CompressLocalDatabase TO yes ON YOUR FRESHCLAM CONFIGURATION FILE (/etc/clamav/freshclam.conf on Debian)";
echo "\n";

if (!is_dir($path_private_mirror)) {
    echo "\nERROR - DIR : " . $path_private_mirror . ' DO NOT EXIST - CREATE FIRST : mkdir -p ' . $path_private_mirror;
    die();
}

if (PHP_SAPI !== 'cli') {
    echo "\nYOU SHOULD NOT EXPOSED THIS TO WAN TROUGH A FRONT-END.";
    form_27B_6();
}

function checkBin($bin, $paquet)
{
    $temp = array();
    exec('which ' . $bin, $temp);
    if (!empty($temp) && isset($temp[0])) {
        if (is_executable($temp[0])) {
            return true;
        } else {
            echo "\nERROR - " . $temp[0] . " MUST BE EXECUTABLE - YOU SHOULD SWITCH USER.";
            return false;
        }
    } else {
        echo "\nERROR - " . $bin . " NOT AVAILABLE ON YOUR SYSTEM - YOU SHOULD apt-get install " . $paquet . " (Debian)";
        return false;
    }
}

$check = true;
foreach ($bin_to_check as $bin => $paquet) {
    $check = $check && checkBin($bin, $paquet);
}
if (!$check) {
    form_27B_6();
}
$check = true;
foreach ($files_to_check as $file) {
    if (!is_file($path_clamav_local_db . $file)) {
        echo "\nERROR - FILE DO NOT EXIST ON YOUR LOCAL DATABASE : " . $path_clamav_local_db . $file;
        $check = false;
    }
}
if (!$check) {
    echo "\nTIPS : CHECK IF .cld FILES EXISTS INSTEAD OF .cvd AND IF YES : READ WARNING 3";
    form_27B_6();
}

function getLastVersionsFromDNS()
{
    global $clamav_dns, $path_private_mirror, $version_minimum;
    $temp = array();
    $cmd = 'dig -t "txt" ' . $clamav_dns . ' +short';
    exec($cmd, $temp);
    if (!empty($temp) && isset($temp[0]) && substr_count($temp[0], ':') > 6) {
        $temp2 = explode(":", str_replace('"', '', $temp[0]));
        // Check against corruptions
        if (version_compare($temp2[0], $version_minimum['clamav'], '<')) {
            echo "\nERROR - DNS ENTRY FROM " . $clamav_dns . " CORRUPTED - WRONG VERSION (SHOULD BE GREATER THAN " . $version_minimum['clamav'] . ") : '" . $temp2[0] . "'";
            return array('check' => 0);
        } elseif ($temp2[2] < $version_minimum['main.cvd']) {
            echo "\nERROR - DNS ENTRY FROM " . $clamav_dns . " CORRUPTED - WRONG VERSION ON main.cvd (SHOULE BE GREATER THAN " . $version_minimum['main.cvd'] . ") : '" . $temp2[1] . "'";
            return array('check' => 0);
        } elseif ($temp2[2] < $version_minimum['daily.cvd']) {
            echo "\nERROR - DNS ENTRY FROM " . $clamav_dns . " CORRUPTED - WRONG VERSION ON daily.cvd (SHOULE BE GREATER THAN " . $version_minimum['daily.cvd'] . ") : '" . $temp2[2] . "'";
            return array('check' => 0);
        } elseif ($temp2[7] < $version_minimum['bytecode.cvd']) {
            echo "\nERROR - DNS ENTRY FROM " . $clamav_dns . " CORRUPTED - WRONG VERSION ON bytecode.cvd (SHOULE BE GREATER THAN " . $version_minimum['bytecode.cvd'] . ") : '" . $temp2[7] . "'";
            return array('check' => 0);
        } elseif (count($temp2) != 8) {
            echo "\nERROR - DNS ENTRY FROM " . $clamav_dns . " CORRUPTED - WRONG NUMBER OF PARAMETERS (SHOULD BE EGUAL TO 8) : '" . count($temp2) . "'";
            return array('check' => 0);
        } else {
            fileWrite($path_private_mirror . 'dns.last', $temp[0]);
            return array(
                'check' => 1,
                'clamav' => $temp2[0],
                'main.cvd' => (int) $temp2[1],
                'daily.cvd' => (int) $temp2[2],
                'safebrowsing' => (int) $temp2[6],
                'bytecode.cvd' => (int) $temp2[7],
            );
        }
    }
    echo "\nERROR - DNS ENTRY FROM " . $clamav_dns . " CORRUPTED : " . $cmd;
    return array('check' => 0);
}

function getVersionFromFile($path_to_file)
{
    $temp = array();
    exec('sigtool -i ' . $path_to_file, $temp);
    if (!empty($temp) && isset($temp[0])) {
        foreach ($temp as $t) {
            if (substr($t, 0, 9) == 'Version: ') {
                return (int) str_replace(substr($t, 0, 9), '', $t);
            }
        }
    }
    echo "\nCORRUPTED VERSION ON : " . $path_to_file;
    return -42;
}

function fileWrite($path, $content)
{
    $h = fopen($path, 'w');
    if ($h) {
        fwrite($h, $content);
        fclose($h);
        return true;
    }
    return false;
}

$version_from_dns = getLastVersionsFromDNS();
if ($version_from_dns['check'] === 1) {
    // is first running ?
    $first_run = false;
    foreach ($files_to_check as $file) {
        if (!is_file($path_private_mirror . $file)) {
            $first_run = true;
        }
    }
    if ($first_run) {
        echo "\n\nFIRST RUN - INITIALISATION OF YOUR PRIVATE MIRROR ...";
        foreach ($files_to_check as $file) {
            if (is_file($path_clamav_local_db . $file)) {
                echo "\nCOPYING " . $path_clamav_local_db . $file . ' TO ' . $path_private_mirror . $file . ' ... ';
                passthru('cp ' . $path_clamav_local_db . $file . ' ' . $path_private_mirror . $file);
                if (is_file($path_private_mirror . $file)) {
                    echo " OK";
                } else {
                    echo " ERROR - SOMETHING WRONG HAPPENS - CHECK DIRECTORT RIGHTS";
                }
            }
        }
    } else {
        $file_to_get_from_freshclam = $cdiff_to_get_from_wan_database = array();

        // Checking current versions - double pass due to 2021/05/03 update AND the fact that on date - should change in a near futur - freshclam do not preserve cdiff ...
        echo "\n\n-------------------------------------------------------------------------------------";
        echo "\n| PASS 1/2 - CHECKING FOR CDIFF NEEDED AGAINST EXISTING FILES ON THE PRIVATE MIRROR |";
        echo "\n-------------------------------------------------------------------------------------\n";
        echo "\n     STEP 1/3 - EXTRACTING VERSIONS ...\n";
        foreach ($files_to_check as $file) {
            echo "\n          EXTRACTING VERSION ON " . $path_private_mirror . $file;
            $version = getVersionFromFile($path_private_mirror . $file);
            if ($version == -42) {
                echo "\n          ERROR - SOMETHING WRONG HAPPENS - YOU SHOULD RECREATE YOUR LOCAL PRIVATE MIRROR.";
                form_27B_6();
            } elseif ($version > $version_from_dns[$file]) {
                echo "\n          MARTY - WE'GOT A TEMPORAL PARADOX - YOUR VERSION IS GREATER THAN THE OFFICAL ONE";
                form_27B_6();
            } else {
                echo " : " . $version;
                $version_from_private_mirror[$file] = $version;
                if ($version < $version_from_dns[$file]) {
                    if ($version_from_private_mirror[$file] - $version_from_dns[$file] > 2000) {
                        echo "\n          MORE THAN 2000 DAYS WITHOUT UPDATE ? IT WILL COST LESS MONEY ON BANDWITH AT CLAMAV IF YOU DOWNLOAD THE FULL NEW DATABASE";
                        form_27B_6();
                    }
                }
            }
        }
        echo "\n\n     STEP 2/3 - VERSION COMPARE BETWEEN DNS ENTRY FROM CLAMAV AND LOCAL PRIVATE MIRROR\n";
        foreach ($version_from_private_mirror as $file => $version) {
            if ($version_from_dns[$file] != $version) {
                echo "\n          AVAILABLE UPDATE FOR " . $file;
                $cdiff_to_get_from_wan_database[] = $file;
            }
        }
        echo "\n\n     STEP 3/3 - DOWNLOADING CDIFF (IF NEEDED - CONSISTENCY PROBLEM CAN OCCURE DUE TO FRESHCLAM)\n";
        if (!empty($cdiff_to_get_from_wan_database)) {
            foreach ($cdiff_to_get_from_wan_database as $file) {
                $initial_version = $version_from_private_mirror[$file] + 1;
                for ($i = $initial_version; $i <= $version_from_dns[$file]; $i++) {
                    $temp_path = str_replace(array('.cvd', '.clv'), '', $file) . '-' . $i . '.cdiff';
                    if (!is_file($path_private_mirror . $temp_path)) {
                        $url = $clamav_wan_database . $temp_path;
                        echo "\n          DOWNLOADING " . $url . " ...\n";
                        passthru('cd ' . $path_private_mirror . ' && wget -U "PHP-ClamAV-Private-Miror-Refresh/' . $version_script . '" -nH -nd -N -nv ' . $url . ' 2>&1');
                        if (is_file($path_private_mirror . $temp_path) && filesize($path_private_mirror . $temp_path) > 2) {
                            echo "\n          DOWNLOAD OK";
                        } else {
                            echo "\n          DOWNLOAD ERROR - SOMETHING WRONG HAPPENS";
                        }
                    } else {
                        echo "\n          NOTHING TO DO. FILE ALREADY EXISTS : " . $path_private_mirror . $temp_path;
                    }
                }
            }
        } else {
            echo "\n          NOTHING TO DO.";
        }

        echo "\n\n---------------------------------------------------------------------------";
        echo "\n| PASS 2/2 - CHECKING FOR NEW FILE ON LOCAL DATABASE MANAGED BY FRESHCLAM |";
        echo "\n---------------------------------------------------------------------------\n";

        echo "\n     STEP 1/3 - EXTRACTING VERSIONS ...\n";
        foreach ($files_to_check as $file) {
            echo "\n          EXTRACTING VERSION ON " . $path_clamav_local_db . $file;
            $version = getVersionFromFile($path_clamav_local_db . $file);
            if ($version == -42) {
                echo "\n          ERROR - SOMETHING WRONG HAPPENS - YOU SHOULD CLEAN YOUR LOCAL CLAMAV DATABASE IN " . $path_clamav_local_db;
                form_27B_6();
            } elseif ($version < $version_from_private_mirror[$file]) {
                $version_from_local_db[$file] = $version;
                echo " : " . $version;
            } elseif ($version > $version_from_dns[$file]) {
                echo "\n          MARTY - WE'GOT A TEMPORAL PARADOX - YOUR VERSION IS GREATER THAN THE OFFICAL ONE";
                form_27B_6();
            } else {
                $version_from_local_db[$file] = $version;
                echo " : " . $version;
            }
        }

        echo "\n\n     STEP 2/3 - VERSION COMPARE BETWEEN LOCAL DATABASE MANAGED BY FRESHCLAM AND LOCAL PRIVATE MIRROR\n";
        foreach ($version_from_private_mirror as $file => $version) {
            if ($version_from_local_db[$file] != $version) {
                echo "\n          AVAILABLE UPDATE FOR " . $file;
                $file_to_get_from_freshclam[] = $file;
            }
        }
        echo "\n\n     STEP 3/3 - COPYING NEW FILE (IF NEEDED)\n";
        if (!empty($file_to_get_from_freshclam)) {
            foreach ($file_to_get_from_freshclam as $file) {
                echo "\n          COPYING " . $path_clamav_local_db . $file . ' TO ' . $path_private_mirror . $file . ' ...';
                passthru('cp ' . $path_clamav_local_db . $file . ' ' . $path_private_mirror . $file . '-temp');
                if (is_file($path_private_mirror . $file . '-temp')) {
                    passthru('rm ' . $path_private_mirror . $file);
                    passthru('mv ' . $path_private_mirror . $file . '-temp ' . $path_private_mirror . $file);
                    echo "OK";
                } else {
                    echo " ERROR - SOMETHING WRONG HAPPENS";
                }
            }
        } else {
            echo "\n          NOTHING TO DO.";
        }
    }
} else {
    form_27B_6();
}
echo "\n\nEND\n\n";
?>