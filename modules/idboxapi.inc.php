<?php
    require_once('../config.inc.php');
    require_once('../core/curl.inc.php');

    function idboxCall($command, $params)
    {
        $data = $params;
        $data['command'] = $command;

//        $res = http_post_fields(IDBOX_API, $data);
        
        
        $response = curlPost(IDBOX_API, $data);

        error_log($response);
        
//        $res = http_parse_message($res);
//        $dec = json_decode($res->body, TRUE);
        $dec = json_decode($response, TRUE);
        if ($dec) {
            if ($dec['success']) {
                return $dec['data'];
            }
            return FALSE;
        }
        return $dec;
    }
