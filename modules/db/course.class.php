<?php

class Course
{
    const EXCEPTION_PARAMETER_NOT_ARRAY = 301;
    const EXCEPTION_FIELD_MISSING = 302;
    const EXCEPTION_DUPLICATE_KEY = 303;
    const EXCEPTION_NO_CHANGES_SPECIFIED = 304;
    const EXCEPTION_COURSE_ALREADY_ACTIVE = 305;
    const EXCEPTION_COURSE_NOT_FOUND = 306;
    const EXCEPTION_PROGRAM_EXISTS = 307;
    const EXCEPTION_PROGRAM_NOT_FOUND = 308;
    const EXCEPTION_DATABASE_ERROR = 309;
    const EXCEPTION_NONUNIQUE = 310;
    /**
     * @var LICR
     */
    private $licr;

    /**
     * @var DB
     */
    private $db;

    public function __construct($licr)
    {
        $this->licr = $licr;
        $this->db = $licr->db;
    }

    private $fields = array(
        'default_branch_id',
        'title',
        'coursecode',
        'coursenumber',
        'section',
        'lmsid',
        'startdate',
        'enddate',
        'active',
        'seats'
        // ,'hash' (managed by system)
        // ,'shorturl' (ditto)
    );

    public function exists($course_id)
    {
        $sql = "SELECT COUNT(*) FROM `course` WHERE `course_id`=? LIMIT 1";
        return $this->db->queryOneVal($sql, $course_id);
    }

    /**
     * Create course, return course_id
     * Set inactive if not specified
     *
     * @param array $data
     * @return boolean integer
     * @throws LICRCourseException
     */
    public function create($data)
    {
        if (!is_array($data)) {
            throw new LICRCourseException ('Course::create parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        if (!isset ($data ['active'])) {
            $data ['active'] = 1;
        }
        if (preg_match('/^WS\./', $data ['lmsid'])) { // workshops invisible by default
            $data ['active'] = 0;
        }
        $bind = array();
        foreach ($this->fields as $field) {
            if (isset ($data [$field])) {
                $bind [$field] = $data [$field];
            } else {
                throw new LICRCourseException ("Course::create Missing $field", self::EXCEPTION_FIELD_MISSING);
            }
        }
        foreach (array_keys($bind) as $col) {
            $fields [] = "
			`$col`=:$col
			";
        }
        $hash = purl_hash('course', $data ['lmsid']);
        $sql = "
				INSERT INTO `course`
				SET
				";
        $sql .= implode(',', $fields);
        $sql .= "
				,`hash`=:hash
				,`shorturl`=:shorturl
				";
        $bind ['hash'] = $hash;
        $shorturl = make_short_BB_url("c.$hash");
        $bind ['shorturl'] = $shorturl;
        $res = $this->db->execute($sql, $bind, FALSE);
        if ($res->errorCode() == MYSQL_ERROR_DUPLICATE_KEY) {
            throw new LICRCourseException ('Course::create duplicate key error', self::EXCEPTION_DUPLICATE_KEY);
        }
        $course_id = $this->db->lastInsertId();
        history_add('course', $course_id, "Created course " . $data ['title'] . " (" . $hash . ")");
        return $course_id;
    }

    /**
     * Modify course
     *
     * @param integer $course_id
     * @param array $params
     * @return string boolean
     */
    public function modify($course_id, $params)
    {
        if (!is_array($params)) {
            throw new LICRCourseException ('Course::modify second parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        if (!$this->exists($course_id)) {
            throw new LICRCourseException ("Course::modify course [$course_id] not found", self::EXCEPTION_COURSE_NOT_FOUND);
        }
        $course_info = $this->info($course_id);
        $bind = array();
        foreach ($this->fields as $field) {
            if (!empty ($params [$field]) && $course_info [$field] != $params [$field]) {
                $bind [$field] = $params [$field];
            }
        }
        if (!$bind) {
            throw new LICRCourseException ('Course::modify No changes specified', self::EXCEPTION_NO_CHANGES_SPECIFIED);
        }
        $datesmodified = FALSE;
        foreach (array_keys($bind) as $col) {
            $fields [] = "
			`$col`=:$col
			";
            if ($col == 'startdate' || $col == 'enddate') {
                $datesmodified = TRUE;
            }
        }
        if ($datesmodified) {
            //LICR-171 (F10)
            //need to set status of docstore items to "Availability Period Changed - PDF"
            //1. get list of items
            //2. for each, set status
            $nsi = $this->licr->status_mgr->id_by_name('Availability Period Changed - PDF');
            $items = $this->licr->course_item_mgr->items_in_course($course_id);
            foreach ($items as $item_id=>$item) {
                if ($item['type_name'] == 'PDF') {
                     // LICR-222 Only apply this status when item is already available
                     $ci_info = $this->licr->course_item_mgr->info($course_id, $item_id);
                     $current_status_id = $ci_info['status_id'];
                     $sql = "SELECT `category` , `cancelled` FROM `status` WHERE `status_id`=?";
                     $res = $this->db->queryOneRow($sql,$current_status_id);
                     if($res['category'] == 'Complete' && $res['cancelled'] == 0){
                         $this->licr->course_item_mgr->update_status($course_id, $item_id, $nsi);
                     }
                }
            }
        }
        $sql = "
				UPDATE `course`
				SET
				";
        $sql .= implode(',', $fields);
        $sql .= "
				WHERE `course_id`=:course_id
				LIMIT 1
				";
        $bind ['course_id'] = $course_id;
        $res = $this->db->execute($sql, $bind, FALSE);
        if (!$res) {
            throw new LICRCourseException ("SQL: $sql Bind: " . var_export($bind, TRUE));
            return FALSE;
        }
        $changes = '';
        foreach ($bind as $key => $val) {
            $changes .= "$key: $val,";
        }
        $affected = $res->rowCount();
        if ($affected) {
            history_add('course', $course_id, "Modified course $course_id: " . trim($changes, ','));
        }
        return $affected;
    }

    /**
     * Mark course active
     * return success
     * throws if course not found
     * throws if course already active
     *
     * @param integer $course_id
     * @return boolean
     */
    public function activate($course_id)
    {
        try {
            $info = $this->info($course_id);
        } catch (LICRCourseException $lce) {
            if ($lce->getCode() == self::EXCEPTION_COURSE_NOT_FOUND) {
                throw new LICRCourseException ('Course::activate course not found', self::EXCEPTION_COURSE_NOT_FOUND);
            }
        }
        if ($info ['active']) {
            throw new LICRCourseException ('Course::activate course is already active', self::EXCEPTION_COURSE_ALREADY_ACTIVE);
        }
        $sql = "UPDATE `course` SET `active`=1 WHERE `course_id`=? LIMIT 1";
        $res = $this->db->execute($sql, $course_id);
        if ($res->rowCount() == 1) {
            history_add('course', $course_id, 'Activated');
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Mark course inactive
     * return success
     * throws if course not found
     * throws if course already active
     *
     * @param integer $course_id
     * @return boolean
     */
    public function deactivate($course_id)
    {
        try {
            $info = $this->info($course_id);
        } catch (LICRCourseException $lce) {
            if ($lce->getCode() == self::EXCEPTION_COURSE_NOT_FOUND) {
                throw new LICRCourseException ('Course::activate course not found', self::EXCEPTION_COURSE_NOT_FOUND);
            }
        }
        if (!$info ['active']) {
            throw new LICRCourseException ('Course::deactivate course is already inactive', self::EXCEPTION_COURSE_ALREADY_INACTIVE);
        }
        $sql = "UPDATE `course` SET `active`=0 WHERE `course_id`=? LIMIT 1";
        $res = $this->db->execute($sql, $course_id);
        if ($res->rowCount() == 1) {
            history_add('course', $course_id, 'Deactivated');
            return TRUE;
        }
        return FALSE;
    }

    /**
     * Return associative array of course info given course_id
     *
     * @param integer $course_id
     * @return array
     */
    public function info($course_identifier)
    {
        foreach ($this->fields as $field) {
            $fields [] = "
			`$field`
			";
        }
        $fields = implode(',', $fields);
        $sql = "
				SELECT
				" . $fields . "
  				,`hash`
  				,`shorturl`
  				,`course_id`
  				,B.`name` AS branch
  				,CA.`name` AS campus
				FROM
					`course` C
					JOIN `branch` B ON C.`default_branch_id`=B.`branch_id`
					JOIN `campus` CA USING(`campus_id`)
				WHERE
  				`course_id`=? OR `hash`=? OR `lmsid`=?
				";
        $res = $this->db->queryRows($sql, array(
            is_numeric($course_identifier) ? $course_identifier : -1,
            $course_identifier,
            $course_identifier
        ));
        if (!$res) {
            throw new LICRCourseException ('Course:info Course not found', self::EXCEPTION_COURSE_NOT_FOUND);
        }
        // var_dump($res);
        foreach ($res as $row) {
            if ($course_identifier === $row ['hash']) {
                $res = array(
                    $row
                );
                break;
            }
        }
        if (count($res) > 1) {
            throw new LICRCourseException ('Course:info Multiple results', self::EXCEPTION_NONUNIQUE);
        }
        $res = array_values($res);
        $res = $res [0];
        $course_id = $res ['course_id'];
        $librarian_puids = $this->licr->idboxCall(array(
            'command' => 'CoursecodeLibrarians',
            'coursecode' => $res ['coursecode']
        ));
        $res ['librarians'] = $librarian_puids;
        $sql = "
				SELECT
  				R.`name` AS role
  				,`puid`
  				,`lastname`
  				,`firstname`
				FROM
  				`user` U
  				JOIN `enrolment` E USING(`user_id`)
  				JOIN `role` R USING(`role_id`)
				WHERE
  				(
    				R.`name`='Instructor'
    				OR R.`name`='TA'
    				)
  				AND E.`course_id`=:course_id
  				AND E.`active`=1
				";
        $instructors = $this->db->queryRows($sql, array(
            'course_id' => $course_id
        ));
        $res ['instructors'] = $instructors;
        $sql = "
				SELECT COUNT(`user_id`)
				FROM `enrolment` JOIN `role` USING(`role_id`)
				WHERE `enrolment`.`course_id`=:course_id
				AND `role`.`name`='Student'
				AND `enrolment`.`active`=1
				";
        $numStudents = $this->db->queryOneVal($sql, array(
            'course_id' => $course_id
        ));
        $res ['enrolled_seats'] = $numStudents;
        $sql = "
				SELECT COUNT(`item_id`) AS visible
				FROM `course_item` CI 
    		 JOIN `status` S USING(`status_id`)
    		 JOIN `course` C USING(`course_id`)
				WHERE
    		 CI.`course_id`=:course_id
				 AND S.`visible_to_student`=1
    		 AND IFNULL(CI.`startdate`,C.`startdate`) < NOW()
				 AND IFNULL(CI.`enddate`,C.`enddate`) > NOW()
				";
        $visible = $this->db->queryOneVal($sql, array(
            'course_id' => $course_id
        ));
        $res ['visible'] = $visible;
        /*
         * change here re: https://jira.library.ubc.ca:8443/browse/ARESSUPP-806?focusedCommentId=102994&page=com.atlassian.jira.plugin.system.issuetabpanels:comment-tabpanel#comment-102994
         */
        $sql = "
				SELECT COUNT(`item_id`) AS total
				FROM `course_item` CI JOIN `status` S USING(`status_id`)
				WHERE
				CI.`course_id`=:course_id
				AND S.cancelled=0
				";
        $total = $this->db->queryOneVal($sql, array(
            'course_id' => $course_id
        ));
        $res ['total'] = $total;
        if (!$res ['shorturl']) {
            require_once dirname(__FILE__) . '/../../core/utility.inc.php';
            $shorturl = make_short_BB_url('c.' . $res ['hash']);
            $res ['shorturl'] = $shorturl;
            $sql = "UPDATE `course` SET `shorturl`=:shorturl WHERE `course_id`=:course_id";
            $this->db->execute($sql, compact('shorturl', 'course_id'));
        }
        return $res;
    }

    public function list_all($start = 0, $perpage = 20, $current = TRUE, $active = TRUE)
    {
        $cols = array();
        if (!$start) {
            $start = 0;
        }
        if (!$perpage) {
            $perpage = 20;
        }
        foreach ($this->fields as $field) {
            $cols [] = "`$field`";
        }
        $sql = "
				SELECT
  				`course_id`
  				,	`course_id` as CID
  				, `course`." . implode(',`course`.', $cols) . "
					, (
					SELECT
					COUNT(`item_id`)
					FROM
					  `course_item` 
					  JOIN `status` USING(`status_id`)
					WHERE
						`course_id`= CID
  					AND `status`.`visible_to_student`=1
					) AS visible
				FROM
  				`course`
				WHERE
						";
        /*
         * $whereclause = array ( " `lmsid` NOT LIKE 'WS%' " );
         */
        $whereclause = array();
        if ($current) {
            $whereclause [] = "
					`course`.`startdate` < NOW()
					AND `course`.`enddate` > NOW()
					";
        }
        if ($active) {
            $whereclause [] = "
					`course`.`active`=1
					";
        }
        if ($whereclause) {
            $sql .= implode(' AND ', $whereclause);
        } else {
            $sql .= '1';
        }
        $sql .= "
				ORDER BY
				`course`.`title`
				";
        if ($start !== FALSE && $perpage) {
            $sql .= "
			LIMIT
			$start,$perpage
			";
        }
        $rows = $this->db->queryRows($sql);
        return $rows;
    }

    public function search($name_fragment, $current = TRUE, $activeonly = TRUE)
    {
        $sql = "
				SELECT
  				`course`.`course_id`
  				,`course`.`course_id` as CID
  				,`course`.`lmsid`
  				,`course`.`title`
  				,`course`.`startdate`
  				,`course`.`enddate`
  				,`branch`.`name` AS branch
  				,`campus`.`name` AS location
  				, (
    				SELECT
      				COUNT(`item_id`)
    				FROM
      				`course_item`
      				JOIN `status` USING(`status_id`)
          		JOIN `course` USING(`course_id`)
    				WHERE
      				`course`.`course_id`= CID
      				AND `status`.`visible_to_student`=1
          		AND IFNULL(`course_item`.`startdate`,`course`.`startdate`)<NOW()
          		AND IFNULL(`course_item`.`enddate`,`course`.`enddate`)>NOW()
      		) AS visible
  				, (
    				SELECT
      				COUNT(`item_id`)
    				FROM
      				`course_item`
      				JOIN `status` USING(`status_id`)
    				WHERE
      				`course_id`= CID
      				AND `status`.`cancelled`=0
  				) AS total
				FROM
          `course`
  				JOIN `branch` ON `course`.`default_branch_id`=`branch`.`branch_id`
  				JOIN `campus` ON `branch`.`campus_id`=`campus`.`campus_id`
				WHERE";
        if ($activeonly) {
            $sql .= "
					`course`.`active`=1
					AND ";
        }
        $sql .= "
				(
				`title` LIKE :titlelike
				OR `lmsid` LIKE :lmslike";
        if (is_numeric($name_fragment)) {
            $sql .= "
					OR `course_id`=:course_id";
        }
        $sql .= "
				)";
        if ($current) {

            $sql .= "
		    AND `course`.`enddate` > NOW()
			";
        }
        $sql.="
            LIMIT 500
        ";
        $qnf = $this->db->likeQuote($name_fragment);
        $bind = array(
            'titlelike' => $qnf,
            'lmslike' => $qnf
        );
        if (is_numeric($name_fragment)) {
            $bind ['course_id'] = $name_fragment;
        }
        $res = $this->db->queryRows($sql, $bind);
        return $res;
    }

    public function hash($course_id)
    {
        $sql = "SELECT `hash` FROM `course` WHERE `course_id`=?";
        $res = $this->db->queryOneVal($sql, $course_id);
        if (!$res) {
            throw new LICRCourseException ("Item not found", self::EXCEPTION_COURSE_NOT_FOUND);
        }
        return $res;
    }

    /**
     * @param int $src_course_id
     * @param int $dest_course_id
     * @return boolean
     * @throws LICRCourseException
     */
    public function copy($src_course_id, $dest_course_id, $src_item_ids = FALSE)
    {
        if (!$this->exists($src_course_id)) {
            throw new LICRCourseException ("Course::copy source course ID [$src_course_id] not found", self::EXCEPTION_COURSE_NOT_FOUND);
        }
        if (!$this->exists($dest_course_id)) {
            throw new LICRCourseException ("Course::copy destination course ID [$dest_course_id] not found", self::EXCEPTION_COURSE_NOT_FOUND);
        }
        $success = TRUE;
        $roles = array(
            $this->licr->role_mgr->id_by_name('Administrator')
        );
        $roles [] = $this->licr->role_mgr->id_by_name('Instructor');
        $roles [] = $this->licr->role_mgr->id_by_name('Library Staff');
        if ($this->db->beginTransaction()) {
            try {
                // ITEMS
                if (!$src_item_ids) {
                    $src_item_ids = $this->licr->course_item_mgr->items_in_course($src_course_id);
                    $src_item_ids = array_keys($src_item_ids);
                }
                $new_instances = array();
                $pdf_status_new = $this->licr->status_mgr->id_by_name(CLONED_DOCSTORE_STATUS);
                $status_new = $this->licr->status_mgr->id_by_name(DEFAULT_ITEM_STATUS);
                $copied = array();
                foreach ($src_item_ids as $src_item_id) {
                    /* @var $info array */
                    $info = $this->licr->course_item_mgr->info($src_course_id, $src_item_id);
                    if (!is_array($info)) {
                        continue;
                    }
                    if ($info ['cancelled']) {
                        continue;
                    }
                    $iinfo = $this->licr->item_mgr->info($src_item_id);
                    if (strpos($iinfo ['physical_format'], 'pdf') !== FALSE) { // a docstore link
                        // in this case we need to duplicate the item and blank the URI field
                        // item_mgr::duplicate copies tags so it's OK to set the new_instances key
                        // to the duplicated item_id
                        $dup_item_id = $this->licr->item_mgr->duplicate($src_item_id);
                        $this->licr->item_mgr->modify($dup_item_id, array(
                            'uri' => 'docstore-skeleton-source-item-' . $src_item_id
                        ));
                        $info ['item_id'] = $dup_item_id;
                        $info ['course_id'] = $dest_course_id;
                        $info ['branch_id'] = $info ['processing_branch_id'];
                        $info ['location'] = '';
                        $info ['status_id'] = $pdf_status_new;
                        if (gv('puid')) {
                            $info ['requested_by'] = $this->licr->user_mgr->info_by_puid(gv('puid'));
                            $info ['requested_by'] = $info ['requested_by'] ['user_id'];
                        }
                        $info ['startdate'] = NULL;
                        $info ['enddate'] = NULL;
                        $info ['request_time'] = date('Y-m-d H:i:s');
                        try {
                            $new_instances [$dup_item_id] = $this->licr->course_item_mgr->create($info);
                        } catch (LICRCourseItemException $lcie) {
                            throw $lcie;
                        }
                        $copied [$src_item_id] = $dup_item_id;
                        history_add('course_item', $new_instances [$dup_item_id], "(Docstore) Item Copied from course [$src_course_id] to course [$dest_course_id]"); // SKK - I changed to $new_instance from $instance_id
                    } else { // NOT DOCSTORE
                        // echo "c$src_course_id i$src_item_id ";
                        // var_dump($info);
                        $info ['course_id'] = $dest_course_id;
                        $info ['item_id'] = $src_item_id;
                        $info ['branch_id'] = $info ['processing_branch_id'];
                        $info ['location'] = '';
                        $info ['status_id'] = $status_new;
                        if (gv('puid')) {
                            $info ['requested_by'] = $this->licr->user_mgr->info_by_puid(gv('puid'));
                            $info ['requested_by'] = $info ['requested_by'] ['user_id'];
                        }
                        $info ['request_time'] = date('Y-m-d H:i:s');
                        $info ['startdate'] = NULL;
                        $info ['enddate'] = NULL;
                        try {
                            $new_instances [$src_item_id] = $this->licr->course_item_mgr->create($info);
                        } catch (LICRCourseItemException $lcie) {
                            if ($lcie->getCode() == CourseItem::EXCEPTION_COURSE_ITEM_EXISTS) {
                                continue;
                            }
                            throw $lcie;
                        }
                        $copied [$src_item_id] = $src_item_id;
                        history_add('course_item', $new_instances [$src_item_id], "Item Copied from course [$src_course_id] to course [$dest_course_id]");
                    }
                    // TAGS
                    // TODO need to write this per-item, not as a whole-course thing. Use tag_item_mgr->list_item_tags($src_item_id). Also i think this needs to be moved out of the loop
                    $src_tags = $this->licr->tag_mgr->list_by_course($src_course_id, 0);
                    // error_log('source tags to copy: '.var_export($src_tags,true));
                    foreach ($src_tags as $src_tag_info) {
                        $src_tag_id = $src_tag_info ['tag_id'];
                        $info = $this->licr->tag_mgr->info($src_tag_id);
                        $dest_tag_id = $this->licr->tag_mgr->create($info ['name'], $dest_course_id, 0, 0);
                        $tagged_items = $this->licr->tag_item_mgr->list_items($src_tag_id);
                        foreach ($tagged_items as $src_item_id) {
                            if (isset ($copied [$src_item_id])) {
                                $this->licr->tag_item_mgr->add_item($copied [$src_item_id], $dest_tag_id);
                            }
                        }
                    }
                }
                // NOTE
                foreach ($new_instances as $src_item_id => $instance_id) {
                    $note_id = $this->licr->note_mgr->create($this->licr->user_mgr->info_by_puid(gv('puid', '000000000000'))['user_id'], "Item Copied from course [$src_course_id] to course [$dest_course_id]");
                    $this->licr->course_item_note_mgr->add($src_item_id, $dest_course_id, $note_id, $roles);
                }
                // NOTES (to student only)
                if ($copied) {
                    $src_item_ids = implode(',', array_keys($copied));
                    $sql = "
              SELECT 
                CIN.`item_id`,
                N.`content`,
                N.`user_id`,
                R.`role_id`
              FROM 
                `course_item_note` CIN
                JOIN `note` N USING(`note_id`)
                JOIN `note_role` NR USING(`note_id`)
                JOIN `role` R USING(`role_id`)
              WHERE 
                R.`name`='Student'
                AND CIN.`item_id` IN($src_item_ids)
                AND CIN.`course_id`=:src_course_id
                ";
                    $res = $this->db->execute($sql, compact('src_course_id'));
                    while ($row = $res->fetch()) {
                        $target_item_id = $copied [$row ['item_id']];
                        $note_id = $this->licr->note_mgr->create($row ['user_id'], $row ['content']);
                        $this->licr->course_item_note_mgr->add($target_item_id, $dest_course_id, $note_id, $row ['role_id']);
                    }
                }
            } catch (Exception $e) {
                $this->db->rollBack();
                throw new LICRCourseItemException ('Cannot complete database transaction; nested exception: ' . $e->getCode() . ' ' . $e->getMessage(), self::EXCEPTION_DATABASE_ERROR);
                // $success = FALSE;
                // break;
            }
            $this->db->commit();
        } else {
            throw new LICRCourseItemException ('Cannot begin database transaction', self::EXCEPTION_DATABASE_ERROR);
        }
        history_add('course', $dest_course_id, "Content copied from course [$src_course_id]");
        return $success ? count($new_instances) : 0;
    }

    /* PROGRAMS (aka UBC Medical and Dental "cohorts" */
    public function program_create($name, $gradyear)
    {
        // return program_id
        $sql = "
				SELECT `program_id` FROM `program` WHERE `name`=:name AND `gradyear`=:gradyear
				";
        $res = $this->db->queryOneVal($sql, compact('name', 'gradyear'));
        if ($res) {
            throw new LICRCourseException ('Program already exists', self::EXCEPTION_PROGRAM_EXISTS);
        }
        $sql = "
				INSERT INTO `program` SET `name`=:name, `gradyear`=:gradyear;
				";
        $this->db->execute($sql, compact('name', 'gradyear'));
        $program_id = $this->db->lastInsertId();
        history_add('program', $program_id, "Created program [$name - $gradyear]");
        return $program_id;
    }

    public function program_update($id, $name, $gradyear)
    {
        // return program_id
        $sql = "
				SELECT `program_id` FROM `program` WHERE `name`=:name AND `gradyear`=:gradyear AND `program_id`!=:id
				";
        $res = $this->db->queryOneVal($sql, compact('name', 'gradyear', 'id'));
        if ($res) {
            throw new LICRCourseException ('Program already exists', self::EXCEPTION_PROGRAM_EXISTS);
        }
        $sql = "
				UPDATE `program` SET `name`=:name, `gradyear`=:gradyear WHERE `program_id`=:id;
				";
        $this->db->execute($sql, compact('name', 'gradyear', 'id'));
        history_add('program', $id, "Updated program [$name - $gradyear]");

        return TRUE;
    }

    public function program_delete($program_id)
    {
        // return success,fail
        $sql = "
				DELETE FROM `course_program` WHERE `program_id`=:program_id
				";
        $s1 = $this->db->execute($sql, compact('program_id'));
        $ret = $s1->rowCount();
        $sql = "
				DELETE FROM `program` WHERE `program_id`=:program_id
				";
        $s2 = $this->db->execute($sql, compact('program_id'));
        return $ret + $s2->rowCount();
    }

    public function program_exists($program_id)
    {
        $sql = "SELECT `program_id` FROM `program` WHERE `program_id`=:program_id";
        $res = $this->db->queryOneVal($sql, compact('program_id'));
        return $res;
    }

    public function program_add_course($program_id, $course_id)
    {
        // return true/false
        if (!$this->exists($course_id)) {
            throw new LICRCourseException ("Course [$course_id] not found", self::EXCEPTION_COURSE_NOT_FOUND);
        }
        if (!$this->program_exists($program_id)) {
            throw new LICRCourseException ("Program [$program_id] not found", self::EXCEPTION_PROGRAM_NOT_FOUND);
        }
        $sql = "INSERT IGNORE INTO `course_program`
				SET `course_id`=:course_id
				,`program_id`=:program_id
				";
        $stmt = $this->db->execute($sql, compact('course_id', 'program_id'));
        history_add('program', $program_id, "Added course ID [$course_id]");
        history_add('course', $course_id, "Added to program [$program_id]");
        return $stmt->rowCount();
    }

    public function program_delete_course($program_id, $course_id)
    {
        if (!$this->exists($course_id)) {
            throw new LICRCourseException ("Course [$course_id] not found", self::EXCEPTION_COURSE_NOT_FOUND);
        }
        if (!$this->program_exists($program_id)) {
            throw new LICRCourseException ("Program [$program_id] not found", self::EXCEPTION_PROGRAM_NOT_FOUND);
        }
        $sql = "
				DELETE FROM `course_program`
				WHERE
				`course_id`=:course_id
				AND `program_id`=:program_id
				LIMIT 1
				";
        $stmt = $this->db->execute($sql, compact('course_id', 'program_id'));
        history_add('program', $program_id, "Removed course ID [$course_id]");
        history_add('course', $course_id, "Removed from program [$program_id]");
        return $stmt->rowCount();
    }

    public function program_get_enrolled($puid)
    {
        // return list of program_id=>[program_name, grad year] where puid is enrolled in a course in the program
        $sql = "
				SELECT DISTINCT 
          P.`program_id`, 
          P.`name`, 
          P.`gradyear`
				FROM
				  `program` P
				  JOIN `course_program` CP USING(`program_id`)
          JOIN `enrolment` E USING(`course_id`)
          JOIN `user` U USING(`user_id`)
          JOIN `course` C USING(`course_id`)
				WHERE
  				U.`puid`=:puid
  				AND E.active=1
--          AND C.`enddate`>NOW()
				";
        $res = $this->db->queryAssoc($sql, 'program_id', compact('puid'));
        return $res;
    }

    public function program_list_courses($program_id)
    {
        // return list of [course_id, title] in program
        $sql = "
				SELECT C.`course_id`,C.`title`, C.`coursecode`, C.`coursenumber`
				FROM `course` C JOIN `course_program` CP USING(`course_id`)
				WHERE CP.`program_id`=:program_id
				";
        $res = $this->db->queryAssoc($sql, 'course_id', compact('program_id'));
        $shortcodes = array();
        foreach ($res as $course_id => $data) {
            $cc = trim($data ['coursecode']);
            $cn = trim($data ['coursenumber']);
            $cc = $cc ? $cc : $data ['title'];
            $cn = $cn ? $cn : '---';
            $shortcode = "$cc $cn";
            if (!isset ($shortcodes [$shortcode])) {
                $shortcodes [$shortcode] = array();
            }
            $shortcodes [$shortcode] [] = $course_id;
            $res [$course_id] ['historical'] = 0;
        }
        /*
         * if ($res) { // get other offerings, from the past or whatever $sql = " SELECT C.`course_id`,C.`title`, C.`coursecode`, C.`coursenumber` FROM `course` C WHERE C.`course_id` != :course_id AND C.`coursecode` = :coursecode AND C.`coursenumber` = :coursenumber AND C.`coursecode` != ' ' AND C.`coursenumber` != ' ' "; foreach ( $res as $course_id => $data ) { $hres = $this->db->queryAssoc ( $sql, 'course_id', array ( 'course_id' => $course_id, 'coursecode' => $data ['coursecode'], 'coursenumber' => $data ['coursenumber'] ) ); foreach ( $hres as $course_id => $data ) { $cc = trim ( $data ['coursecode'] ); $cn = trim ( $data ['coursenumber'] ); $cc = $cc ? $cc : $data ['title']; $cn = $cn ? $cn : 'XXX'; $shortcode = "$cc $cn"; if (! isset ( $shortcodes [$shortcode] )) $shortcodes [$shortcode] = array (); $shortcodes [$shortcode] [] = $course_id; $data ['historical'] = 1; $res [$course_id] = $data; } } }
         */
        $ret = array(
            'courses' => $res,
            'shortcodes' => $shortcodes
        );
        return $ret;
    }

    public function program_find($name)
    {
        // in order to avoid the complication of different year offerings,
        // only return most recent. Good people should use the program_id.
        $sql = "SELECT `program_id` FROM `program` WHERE `name`=:name ORDER BY `gradyear` DESC";
        $res = $this->db->queryOneVal($sql, compact('name'));
        return $res;
    }

    public function program_info($program_id)
    {
        $sql = "SELECT `program_id`, `name`, `gradyear` FROM `program` WHERE `program_id`=:program_id";
        $res = $this->db->queryOneRow($sql, compact('program_id'));
        return $res;
    }

    public function program_list_all()
    {
        // list all program_id=>program_name
        $sql = "
				SELECT `program_id`,`name`,`gradyear`
				FROM `program`
				ORDER BY `gradyear` DESC, `name` ASC
				";
        $res = $this->db->queryRows($sql);
        return $res;
    }

    public function program_from_course($course_id)
    {
        $sql = "SELECT `program_id` FROM `course_program` WHERE `course_id`=:course_id ORDER BY `program_id` DESC";
        $program_ids = $this->db->queryOneColumn($sql, compact('course_id'));
        return $program_ids;
    }
}

class LICRCourseException extends Exception
{
}

