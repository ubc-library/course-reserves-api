<?php
    if (!isset($_SESSION['history'])) {
        $_SESSION['history'] = TRUE;
    }

    function history_add($table, $id, $message, $puid = FALSE, $time = NULL, $force = FALSE)
    {
        global $licrdb;
        if (!$puid) {
            require_once(dirname(__FILE__) . '/../../core/utility.inc.php');
            $puid = gv('puid', '000000000000');
        }
        if (is_null($time)) {
            $time = date('Y-m-d H:i:s');
        }
        if ($force || (isset($_SESSION['history']) && empty($_REQUEST['nohistory']))) {
            $sql = "
				INSERT INTO `history`
				(`note`,`table`,`id`,`puid`,`time`)
				VALUES
				(
				:message
				,:table
				,:id
				,:puid
				,:time
				)
				";
            $bind = array(
                'message' => $message
                , 'table' => $table
                , 'id'    => $id
                , 'puid'  => $puid
                , 'time'  => $time
            );
            $licrdb->execute($sql, $bind);
        }
    }

    function history_get($table, $id)
    {
        global $licrdb;
        $sql = "
			SELECT
	    `history_id`
			,`time`
			, `note`
			, `puid`
			FROM
			`history`
			WHERE
			`table`=:table
			AND `id`=:id
			ORDER BY `history_id` DESC
			";
        $res = $licrdb->queryRows($sql, array(
            'table' => $table,
            'id'    => $id
        ));
        return $res;
    }
