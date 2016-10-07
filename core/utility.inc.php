<?php
//namespace Utility;
require_once('curl.inc.php');

define('ASCII_US', "\x1f");
define('ASCII_RS', "\x1e");
define('ASCII_GS', "\x1d");
define('ASCII_FS', "\x1c");

function _v(&$a, $k, $default)
{
    if (isset($a[$k])) {
        return $a[$k];
    }
    return $default;
}

function s_v(&$a, $k, $v)
{
    $a[$k] = $v;
}

function gv($k, $default = NULL)
{
    return _v($_GET, $k, $default);
}

function sgv($k, $v)
{
    s_v($_GET, $k, $v);
}

function pv($k, $default = NULL)
{
    return _v($_POST, $k, $default);
}

function spv($k, $v)
{
    s_v($_POST, $k, $v);
}

function sv($k, $default = NULL)
{
    return _v($_SESSION, $k, $default);
}

function ssv($k, $v)
{
    s_v($_SESSION, $k, $v);
}

function make_short_url($long_url)
{
    $go = curlPost('https://go.library.ubc.ca', array(
        'url' => $long_url
    ));
//        $go = http_post_fields('https://go.library.ubc.ca', array(
//            'url' => $long_url
//        ));
//        $go = http_parse_message($go);
    if (!$go) {
        return FALSE;
    }
    $go = json_decode($go, TRUE);
    if (!isset($go['shorturl'])) {
        return FALSE;
    }
    $go = $go ['shorturl'];
//  error_log ( "created short url $go from $longurl");
    return $go;
}

function make_short_BB_url($hash)
{
    $url = CRRESOLVE . urlencode(BB1 . urlencode(BB2 . urlencode(GET_JSP . '?hash=' . $hash)));
//        $go = http_post_fields('https://go.library.ubc.ca', array(
//            'url' => $url
//        ));
    $go = curlPost('https://go.library.ubc.ca', array(
        'url' => $url
    ));
//        $go = http_parse_message($go);
    if (!$go) {
        return FALSE;
    }
//        $go = json_decode($go->body, TRUE);
    $go = json_decode($go, TRUE);
    if (!isset($go['shorturl'])) {
        return FALSE;
    }
    $go = $go ['shorturl'];
//  error_log ( "created sort url $go from hash $hash$");
    return $go;
}

function authenticate($user)
{
    ssv('user', NULL);
    require_once('../modules/idboxapi.inc.php');

    $puid = idboxCall('GetPuid', array('cwl' => $user));
    if (!$puid) {
        return FALSE;
    }
    $groups = idboxCall('ListGroups', array('puid' => $puid));
    if (!in_array('CR-Admin', $groups)) {
        return FALSE;
    }
    ssv('user', idboxCall('PersonInfo', array('puid' => $puid)));
    ssv('puid', $puid);
    return true;
}

function redirect($url)
{
    ob_end_clean();
    header('Location: ' . $url);
    exit();
}
