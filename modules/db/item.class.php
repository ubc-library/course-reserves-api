<?php

class Item
{
    const EXCEPTION_NAME_ALREADY_EXISTS = 901;
    const EXCEPTION_PARAMETER_NOT_ARRAY = 902;
    const EXCEPTION_MISSING_FIELD = 903;
    const EXCEPTION_ITEM_EXISTS = 904;
    const EXCEPTION_NO_CHANGE_SPECIFIED = 905;
    const EXCEPTION_ITEM_NOT_FOUND = 906;

    /**
     *
     * @var LICR
     */
    private $licr;

    /**
     *
     * @var DB
     */
    private $db;
    private $fields = array(
        'title',
        'author',
        'callnumber',
        'bibdata',
        'uri',
        'type_id',
        'physical_format',
        'filelocation',
        'citation'
    );
    private $internal_fields = array(
        'shorturl'
    );

    public function __construct($licr)
    {
        $this->licr = $licr;
        $this->db = $licr->db;
    }

    public function exists($item_id)
    {
        $sql = "SELECT COUNT(`item_id`) FROM `item` WHERE `item_id`=? LIMIT 1";
        $num = $this->db->queryOneVal($sql, $item_id);
        return $num;
    }

    private function bibdata_searchable($bibdata, $str = '')
    {
        if (is_array($bibdata)) {
            foreach ($bibdata as $k => $v) {
                $str .= $this->bibdata_searchable($v);
            }
        } else {
            if (strlen($bibdata) >= 3) {
                $str .= $bibdata . ' ';
            }
        }
        return $str;
    }

    public function create($data, $dedupe = FALSE)
    {
        if (!is_array($data)) {
            throw new LICRItemException ('Item::create parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        $bind = array(
            'physical_format' => NULL
        );
        foreach ($this->fields as $field) {
            if (isset ($data [$field])) {
                $bind [$field] = $data [$field];
            } else if ($field != 'physical_format') {
                throw new LICRItemException ("Item::create Missing field $field", self::EXCEPTION_MISSING_FIELD);
            }
        }

        // ////////////////////////////////////////
        // TODO Dedupe item (physical only), if item exists then just return existing item_id
        // ////////////////////////////////////////
        if ($dedupe) {
            if (trim($data ['callnumber']) && $this->licr->type_mgr->is_physical($data ['type_id'])) {
                $sql = "SELECT `item_id` FROM `item` WHERE (
					`callnumber`=:callnumber
					AND
					`type_id`=:type_id
					)";
                $iid = $this->db->queryOneVal($sql, array(
                    'callnumber' => $data ['callnumber'],
                    'type_id' => $data['type_id']
                ));
                if ($iid)
                    return $iid;
            }
        }
        // //end dedupe
        /* removed 20140813 -- too slow, causes Safari to bail on synchronous ajax call
            if ($bind ['uri']) {
              if ($resolved = $this->check_resolve_url ( $bind ['uri'] )) {
                $bind ['uri'] = $resolved;
              }
            }
            */
        if ($bind['bibdata']) {
            $bind['bibdata_search'] = trim($this->bibdata_searchable(unserialize($bind['bibdata'])));
        }
        foreach (array_keys($bind) as $col) {
            $fields [] = "
			`$col`=:$col
			";
        }
        $sql = "
				INSERT INTO `item`
				SET
				" . implode(',', $fields) . "
						,hash=:hash
						";
        $bind ['hash'] = purl_hash('item', $data ['bibdata']);
        $res = $this->db->execute($sql, $bind, false);
        if (!$res) {
            return false;
        }
        if ($res->errorCode() == MYSQL_ERROR_DUPLICATE_KEY) {
            throw new LICRItemException ('Item::create Item already exists', self::EXCEPTION_ITEM_EXISTS);
        }
        $item_id = $this->db->lastInsertId();
        history_add('item', $item_id, "Created item");
        return $item_id;
    }

    public function duplicate($item_id)
    {
        // return new item_id
        // function is not called directly from API
        // this is mainly to copy docstore items
        /*
            $copyfields = array_merge ( $this->fields, $this->internal_fields );
            $sql = "
                INSERT INTO `item`
                (`" . implode ( '`,`', $copyfields ) . "`)
                SELECT `" . implode ( '`,`', $copyfields ) . "`
                FROM `item`
                WHERE `item_id`=:item_id
            ";
            $this->db->execute ( $sql, compact ( 'item_id' ) );
            $new_item_id = $this->db->lastInsertId ();
            */
        $item_info = $this->info($item_id);
        //new hash created in item::create
        $item_info['shorturl'] = '';
        $new_item_id = $this->create($item_info, false);
        $sql = "
        INSERT INTO `item_additionalaccess`
        (`item_id`,`description`,`url`,`format`)
        SELECT " . $new_item_id . ",`description`,`url`,`format`
        FROM `item_additionalaccess`
        WHERE `item_id`=:item_id
        ";
        $this->db->execute($sql, compact('item_id'));
        return $new_item_id;
    }

    /**
     * Modify item
     *
     * @param integer $item_id
     * @param $data array
     * @return string boolean
     * @throws LICRItemException
     */
    public function modify($item_id, $data, $fromCPF = 0)
    {
        // var_export($data);
        if (!is_array($data)) {
            throw new LICRItemException ('Item::modify second parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        if (isset($data['physical_format']) && $fromCPF === 0) {
            //can't touch this
            unset($data['physical_format']);
            //throw new LICRItemException ( 'Item::modify cannot ')
        }
        $sql = "SELECT * FROM `item` WHERE `item_id`=:item_id";
        $item_info = $this->db->queryOneRow($sql, compact('item_id'));
    
        #@file_put_contents(__DIR__ . '/_export.log', json_encode(['$item_info' => $item_info]) . PHP_EOL , FILE_APPEND);
        
        //error_log(serialize($item_info));
        $bind = array();
        foreach ($this->fields as $field) {
            if (!empty ($data [$field]) && $data[$field] != $item_info[$field]) {
                $bind [$field] = $data [$field];
            }
        }
    
        #@file_put_contents(__DIR__ . '/_export.log', json_encode(['$bind' => $bind]) . PHP_EOL , FILE_APPEND);
        
        if (!$bind) {
            return 0;
            //throw new LICRItemException ( 'Item::modify No changes specified', self::EXCEPTION_NO_CHANGE_SPECIFIED );
        }
        if (isset($bind['uri'])) {
            $bind['http_status'] = 0;
            $bind['do_not_check'] = 0;
            //LICR-167 - set status to "Non-Physical with Processing Note", note is "URL changed by instructor from {old url}"
            // note the status change is caught & handled by LICR-179 in course_item::modify
            //1. get old url
            $olduri = $item_info['uri'];
            //2. find course usages
            $courses = $this->licr->course_item_mgr->courses_by_item_id($item_id);
            #@file_put_contents(__DIR__ . '/_export.log', json_encode(['$courses' => $courses]) . PHP_EOL , FILE_APPEND);
            
            foreach ($courses as $course_id => $courseData) {
                //3. add note
                $this->licr->AddCINote(
                    $_GET['puid'],
                    'URL Changed by instructor from ' . $olduri,
                    'Library Staff',
                    $item_id,
                    $course_id
                );
            }
        }
        if (isset($bind['bibdata'])) {
            $bind['bibdata_search'] = trim($this->bibdata_searchable(unserialize($bind['bibdata'])));
        }
        foreach (array_keys($bind) as $col) {
            $fields [] = "
		 `$col`=:$col
		 ";
        }
        $sql = "
				UPDATE `item`
				SET
				";
        $sql .= implode(',', $fields);
        $sql .= "
				WHERE `item_id`=:item_id 
				";
        $bind ['item_id'] = $item_id;
        //error_log($sql);
        //error_log(serialize($bind));
        #@file_put_contents(__DIR__ . '/_export.log', json_encode(['sql' => $sql, 'bind' => $bind]) . PHP_EOL , FILE_APPEND);
        $res = $this->db->execute($sql, $bind); // , false );
        if (!$res) {
            // return false;
        }
        $changes = '';
        foreach ($bind as $key => $val) {
            if ($key != 'item_id') {
                if ($key == 'bibdata') {
                    $changes .= "bibdata: [changed]";
                } else {
                    $changes .= "$key: $val \n";
                }
            }
        }
        if ($changes) {
            history_add('item', $item_id, "Modified item $item_id: \n" . trim($changes, ','));
        }
        return $res->rowCount();
    }

    public function change_physical_format($item_id, $course_id, $type_id, $physical_format)
    {
        $this->db->beginTransaction();
        $formatchange = true;
        $sql = "SELECT COUNT(*) FROM `course_item` WHERE `item_id`=:item_id";
        $count = $this->db->queryOneVal($sql, compact('item_id'));
        if ($count < 2) {
            $ret = $this->modify($item_id, compact('type_id'), 1);
            $ret = $this->modify($item_id, compact('physical_format'), 1);

            if ($ret) {
                $ret = $item_id;
            }
        } else {
            $new_item_id = $this->duplicate($item_id);
            try {
                $ret = $this->modify($new_item_id, compact('physical_format'), 1);
            } catch (LICRItemException $e) {
                $this->db->rollBack();
                throw($e);
            }
            try {
                $ret = $this->modify($new_item_id, compact('type_id'), 1);
            } catch (LICRItemException $e) {
                $this->db->rollBack();
                throw($e);
            }
            $sql = "
          UPDATE 
            `course_item` 
          SET 
            `item_id`=:new_item_id 
          WHERE 
            `course_id`=:course_id 
            AND `item_id`=:item_id
          ";
            $this->db->execute($sql, compact('new_item_id', 'item_id', 'course_id'));
            if ($ret) {
                $ret = $new_item_id;
            }
            $this->licr->course_item_mgr->_auto_transition($course_id, $new_item_id);
        }
        $this->db->commit();

        return $ret;
    }

    public function info($item_id_or_hash)
    {
        // error_log("Item::info($item_id_or_hash)");
        if (!$item_id_or_hash)
            return false;
        $multi = false;
        $course_id = false;
        if (!is_array($item_id_or_hash)) {
            if (strpos($item_id_or_hash, ',')) {
                $item_id_or_hash = explode(',', $item_id_or_hash);
            } elseif (preg_match('/^c[0-9]+$/', $item_id_or_hash)) {
                $course_id = ltrim($item_id_or_hash, 'c');
                $item_id_or_hash = array_keys($this->licr->course_item_mgr->items_in_course($course_id, 0, NULL));
            }
        }
        if (is_array($item_id_or_hash)) {

            $multi = true;
        }
        $cols = array();
        foreach ($this->fields as $field) {
            $cols [] = "`$field`";
        }
        if ($multi) {
            $iids = array();
            $ihs = array();
            foreach ($item_id_or_hash as $idoh) {
                if (is_numeric($idoh)) {
                    $iids [] = $idoh;
                } else {
                    $ihs [] = $idoh;
                }
            }
            $wherei = array();
            if (count($iids)) {
                $wherei [] = "I.`item_id` IN(" . implode(',', $iids) . ")";
            }
            if (count($ihs)) {
                $wherei [] = "I.`hash` IN('" . implode("','", $ihs) . "')";
            }
            if (!count($wherei))
                return false;
            $wherei = implode(' OR ', $wherei);
            if ($course_id) {
                $sql = "
  				SELECT
  				I." . implode(',I.', $cols) . "
  						,I.`item_id`
  						,I.`hash`
  						,I.`shorturl`
  						,I.`uri`
  						FROM
  						`item` I
  				    JOIN `course_item` CI USING(`item_id`)
  						WHERE
  				      $wherei
  						";
                $res = $this->db->queryRows($sql);
            } else {
                $sql = "
  				SELECT
  				    I." . implode(',I.', $cols) . "
  						,I.`item_id`
  						,I.`hash`
  						,I.`shorturl`
  						,I.`uri`
  						FROM
  						`item` I
  						WHERE
  						$wherei
  						";
                $res = $this->db->queryRows($sql);
            }
            if (empty ($res) || is_null($res)) {
                return false;
            }
        } else {
            $sql = "
  				SELECT
  				    I." . implode(',I.', $cols) . "
  						,I.`item_id`
  						,I.`hash`
  				    ,I.`shorturl`
  						,I.`uri`
  				    FROM
  						`item` I
  						WHERE
  						I.`item_id`=:item_id
  						OR I.`hash`=:hash
  						";
            $res = $this->db->queryOneRow($sql, array(
                'item_id' => (is_numeric($item_id_or_hash) ? $item_id_or_hash : -1),
                'hash' => $item_id_or_hash
            ));
            if (empty ($res) || is_null($res)) {
                return false;
            }
            $res = array(
                $res
            ); //
        }
        foreach ($res as $i => $row) {
            $item_id = $row ['item_id'];
            $sql = "SELECT `url`,`description`,`format`,`item_additionalaccess_id`
				FROM `item_additionalaccess`
				WHERE `item_id`=:item_id";
            $iaa = $this->db->queryAssoc($sql, 'item_additionalaccess_id', array(
                'item_id' => $item_id
            ));
            $res [$i] ['additional access'] = $iaa;
            $sql = "SELECT `course_id` FROM `course_item` WHERE `item_id`=:item_id";
            $res [$i] ['course_ids'] = $this->db->queryOneColumn($sql, compact('item_id'));
            $sql = "
				SELECT 
				`tag`.`tag_id`,`tag`.`name` 
				FROM `tag` JOIN `tag_item` USING(`tag_id`) 
				WHERE `tag_item`.`item_id`=:item_id
          ";
            $tags = $this->db->queryAssoc($sql, 'tag_id', compact('item_id'));
            $res [$i] ['tags'] = array();
            foreach ($tags as $tag_id => $tag_info) {
                $res [$i] ['tags'] [$tag_id] = ltrim($tag_info ['name'], '_');
            }
            if (!trim($row ['shorturl'])) {
                $res [$i] ['shorturl'] = make_short_BB_url('i.' . $res [$i] ['hash']);
                $sql = "UPDATE `item` SET `shorturl`=? WHERE `item_id`=?";
                $this->db->execute($sql, array(
                    $res [$i] ['shorturl'],
                    $item_id
                ));
            }
        }
        if (!$multi) {
            $ret = $res [0];
        } else {
            $ret = array();
            foreach ($res as $item) {
                $ret [$item ['item_id']] = $item;
            }
        }
        return $ret;
    }

    public function hash($item_id)
    {
        $sql = "SELECT `hash` FROM `item` WHERE `item_id`=?";
        $res = $this->db->queryOneVal($sql, $item_id);
        if (!$res) {
            throw new LICRItemException ("Item not found", self::EXCEPTION_ITEM_NOT_FOUND);
        }
        return $res;
    }

    public function search($search_string, $branch_ids, $status_ids, $type_ids)
    {
        $bind = array();
        if (preg_match('/^https?\:\/\//', $search_string)) {
            return $this->search_by_url($search_string, $branch_ids, $status_ids, $type_ids);
        }
        $exact = FALSE;
        if (strpos($search_string, '"') !== FALSE) {
            preg_match_all('/"([^"]*)"/', $search_string, $matches);
            if (isset($matches[1][0])) {
                $exact = $matches[1][0];
            }
        }
        $lq = $this->db->likeQuote($search_string);
        $sql = "
				SELECT
				  I.`item_id`
				  ,I.`title`
				  ,I.`author`
				  ,I.`bibdata`
				  ,I.`callnumber`
				  ,I.`uri`
				  ,I.`hash`
                  ,I.`physical_format`
                  , GROUP_CONCAT(C.`course_id`,'\x1f',C.`lmsid` SEPARATOR ', ') as lmsids
				FROM
				  `item` I
				  JOIN `course_item` CI USING(`item_id`)
                  JOIN `course` C USING(`course_id`)
				WHERE
    		    (";
        if ($exact) {
            $sql .= "
    		    
    		      I.`title` = :title
    		      OR I.`bibdata_search` = :bibdata
    		      OR I.`author` = :author
        ";
            $bind['title'] = $exact;
            $bind['bibdata'] = $exact;
            $bind['author'] = $exact;
        } else {
            $sql .= "
    		    
    		      I.`title` LIKE :title
    		      OR I.`bibdata_search` LIKE :bibdata
    		      OR I.`author` LIKE :author
        ";
            $bind['title'] = $lq;
            $bind['bibdata'] = $lq;
            $bind['author'] = $lq;
        }
        if (is_numeric($search_string)) {
            $sql .= "
                  OR I.`item_id`=:item_id";
            $bind['item_id'] = $search_string;
        }
        $sql .= "
                )
				";
        if ($branch_ids) {
            $sql .= "
			    AND CI.`processing_branch_id` IN (" . implode(',', $branch_ids) . ")
	  ";
        }
        if ($status_ids) {
            $sql .= "
				AND CI.`status_id` IN (" . implode(',', $status_ids) . ")
	  ";
        }
        if ($type_ids) {
            $sql .= "
				AND I.`type_id` IN (" . implode(',', $type_ids) . ")
	  ";
        }
        $sql .= "GROUP BY I.`item_id`";
        // echo $sql.'.'.$bind;
        $res = $this->db->queryAssoc($sql, 'item_id', $bind);
        // var_export($res);
        if (!$res)
            $res = array();
        return $res;
    }

    public function search_by_url($search_string, $branch_ids, $status_ids, $type_ids)
    {
        $bind = array();
        $sql = "
				SELECT
				  I.`item_id`
				  ,I.`title`
				  ,I.`author`
				  ,I.`bibdata`
				  ,I.`callnumber`
				  ,I.`uri`
				  ,I.`hash`
          ,I.`physical_format`
          , GROUP_CONCAT(C.`course_id`,'\x1f',C.`lmsid` SEPARATOR ', ') as lmsids
				FROM
				  `item` I
				  JOIN `course_item` CI USING(`item_id`)
          JOIN `course` C USING(`course_id`)
				WHERE
          I.`uri`=:search_string
        ";
        if ($branch_ids) {
            $sql .= "
					AND CI.`processing_branch_id` IN (" . implode(',', $branch_ids) . ")
							";
        }
        if ($status_ids) {
            $sql .= "
					AND CI.`status_id` IN (" . implode(',', $status_ids) . ")
							";
        }
        if ($type_ids) {
            $sql .= "
					AND I.`type_id` IN (" . implode(',', $type_ids) . ")
							";
        }
        $sql .= "GROUP BY I.`item_id`";
        // echo $sql.'.'.$bind;
        $res = $this->db->queryAssoc($sql, 'item_id', compact('search_string'));
        // var_export($res);
        if (!$res)
            $res = array();
        return $res;
    }

    public function add_alternate_url($item_id, $description, $url, $format)
    {
        $sql = "
				INSERT IGNORE INTO `item_additionalaccess`
				SET
				`item_id`=:item_id
				,`description`=:description
				,`url`=:url
				,`format`=:format
				";
        $res = $this->db->execute($sql, compact('item_id', 'description', 'url', 'format'));

        //LICR-167 - set status to "Non-Physical with Processing Note", note is "URL changed by instructor from {old url}"
        // note the status change is caught & handled by LICR-179 in course_item::modify
        //2. find course usages
        $courses = $this->licr->course_item_mgr->courses_by_item_id($item_id);
        foreach ($courses as $course_id) {
            //3. add note
            $this->licr->AddCINote(
                $_GET['puid'],
                'Additional access URL added by instructor',
                'Library Staff',
                $item_id,
                $course_id
            );
        }

        // return alternate_url_id
        return $this->db->lastInsertId();
    }

    public function update_alternate_url($alternate_url_id, $url, $description, $format)
    {
        $sql = "
				SELECT `item_id`, `url`, `description`, `format`
				FROM `item_additionalaccess`
				WHERE `item_additionalaccess_id`=:altid";
        $res = $this->db->queryOneRow($sql, array(
            'altid' => $alternate_url_id
        ));
        if (!$res) {
            throw new LICRItemException ("Additional access URL not found", self::EXCEPTION_ITEM_NOT_FOUND);
        }
        //LICR-167 - set status to "Non-Physical with Processing Note", note is "URL changed by instructor from {old url}"
        // note the status change is caught & handled by LICR-179 in course_item::modify
        //1. get old url
        $olduri = $res['url'];
        //2. find course usages
        $courses = $this->licr->course_item_mgr->courses_by_item_id($res['item_id']);
        foreach ($courses as $course_id) {
            //3. add note
            $this->licr->AddCINote(
                $_GET['puid'],
                'Additional Access URL Changed by instructor from ' . $olduri,
                'Library Staff',
                $res['item_id'],
                $course_id
            );
        }
        $bind = array(
            'altid' => $alternate_url_id,
            'url' => ($url ? $url : $res ['url']),
            'description' => ($description ? $description : $res ['description']),
            'format' => ($format ? $format : $res ['format'])
        );
        $sql = "
				UPDATE
				`item_additionalaccess`
				SET
				`description`=:description
				,`url`=:url
				,`format`=:format
				WHERE
				`item_additionalaccess_id`=:altid
				";
        $res = $this->db->execute($sql, $bind);
        return $res->rowCount();
    }

    public function delete_alternate_url($alternate_url_id)
    {
        $sql = "
				DELETE FROM `item_additionalaccess`
				WHERE `item_additionalaccess_id`=:alternate_url_id
				";
        $this->db->execute($sql, compact('alternate_url_id'));
        return TRUE;
    }

    public function has_access($puid, $item_id)
    {
        // get courses with item_id, see if puid is enrolled (if student)
        $user_info = $this->licr->user_mgr->info_by_puid($puid);
        $user_id = $user_info ['user_id'];
        $sql = "
				SELECT
  				`enrolment`.`course_id`
				FROM
  	 			`enrolment`
  				JOIN `role` USING(`role_id`)
  				JOIN `user` USING(`user_id`)
  				JOIN `course_item` USING(`course_id`)
  				JOIN `status` USING(`status_id`)
				WHERE
  				`enrolment`.`course_id` IN(
  	   			SELECT
  			     	`course_id`
  				  FROM
  				    `course_item`
  				  WHERE
  				    `item_id`=:item_id1
				  )
  				AND `user`.`puid`=:puid
  				AND `course_item`.`item_id`=:item_id2
  				AND (
    				`role`.`name`!='Student'
		    		OR (
      				`role`.`name`='Student'
      				AND `enrolment`.`active`=1
      				AND `status`.`visible_to_student`=1
    				)
		  		)
				";
        $res = $this->db->queryOneColumn($sql, array(
            'puid' => $puid,
            'item_id1' => $item_id,
            'item_id2' => $item_id
        ));
        if ($res)
            return $res;
        return FALSE;
    }

    private function check_resolve_url($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, TRUE);
        if (preg_match('/pdf$/i', $url)) {
            curl_setopt($ch, CURLOPT_NOBODY, TRUE);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/20.0.1599.69 Safari/537.36');
        $headers = curl_exec($ch);
        $curl_err = curl_errno($ch);
        $curl_info = curl_getinfo($ch);
        $endurl = $curl_info ['url'];
        if ($curl_err == 47) { // too many redirects
            // assume this is Proquest. Can't do much about it
            return $url;
        }
        if ($curl_err || $curl_info ['http_code'] != 200) {
            return FALSE;
        }
        if (preg_match('/gw2jh3xr2c/', $endurl)) {
            // summon
            return $endurl;
        }
        if (preg_match('/webcat.*?bibId=(.*)$/', $endurl, $m)) {
            return 'http://resolve.library.ubc.ca/cgi-bin/catsearch?bid=' . $m [1];
        }
        return $url;
    }

    public function list_broken()
    {
        $sql = "
        SELECT 
          DISTINCT(I.`item_id`)
--          I.`item_id`
        , I.`uri`
        , I.`original_uri`
        , I.`resolved_uri`
        , I.`http_status`
        , I.`title`
        , I.`author`
        , I.`callnumber`
        , T.`type_id`
        , T.`name` as type_name
        , I.`do_not_check`
        , I.`bibdata`
        FROM 
          `item` I
          JOIN `type` T USING(`type_id`)
    		  JOIN `course_item` CI USING(`item_id`)
    		  JOIN `course` C USING(`course_id`)
        WHERE
          (
            (
              I.`http_status` > 399
    		      AND
    		        I.`http_status` != 777
              AND
                I.`uri` != ''
            )
            OR
            (
              I.`uri`='' 
              AND 
              (
                T.`name`='Electronic Article'
                OR T.`name`='Web Page'
              )
            )
          )
 		      AND
		        IFNULL(CI.`enddate`,C.`enddate`) > NOW() 
          AND I.`do_not_check`=0
        ORDER BY 
          I.`item_id`";
        return $this->db->queryAssoc($sql, 'item_id');
    }

    public function count_broken()
    {
        $sql = "
        SELECT 
          COUNT( DISTINCT(I.`item_id`))
        FROM 
          `item` I 
    		  JOIN `course_item` CI USING(`item_id`)
    		  JOIN `course` C USING(`course_id`)
          JOIN `type` T USING(`type_id`)
        WHERE
          (
            (
              I.`http_status` > 399
    		      AND
    		        I.`http_status` != 777
              AND
                I.`uri` != ''
            )
            OR
            (
              I.`uri`='' 
              AND 
              (
                T.`name`='Electronic Article'
                OR T.`name`='Web Page'
              )
            )
          )
 		      AND
		        IFNULL(CI.`enddate`,C.`enddate`) > NOW() 
          AND I.`do_not_check`=0
        ";
        return $this->db->queryOneVal($sql);
    }

    public function set_do_not_check($item_id, $boolean)
    {
        $sql = "UPDATE `item` SET `do_not_check`=:boolean WHERE `item_id`=:item_id";
        $this->db->execute($sql, compact('item_id', 'boolean'));
        return $boolean;
    }
}

class LICRItemException extends Exception
{
}
