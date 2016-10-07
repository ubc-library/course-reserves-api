<?php

/**
 * Class CourseItem
 */
class CourseItem
{
    const EXCEPTION_COURSE_NOT_FOUND = 401;
    const EXCEPTION_ITEM_NOT_FOUND = 402;
    const EXCEPTION_PARAMETER_NOT_ARRAY = 403;
    const EXCEPTION_MISSING_FIELD = 404;
    const EXCEPTION_NO_CHANGE_SPECIFIED = 405;
    const EXCEPTION_ITEM_COUNT = 406;
    const EXCEPTION_DATABASE_ERROR = 407;
    const EXCEPTION_COURSE_ITEM_EXISTS = 408;
    const EXCEPTION_COURSE_ITEM_NOT_FOUND = 409;
    const EXCEPTION_INVALID_STATUS_ID = 410;
    const EXCEPTION_BRANCH_NOT_FOUND = 411;
    const EXCEPTION_ALREADY_REQUESTED = 413;
    const EXCEPTION_BAD_FIELD = 414;
    const EXCEPTION_INVALID_TYPE_ID = 415;
    /**
     * @var LICR
     */
    private $licr;

    /**
     * @var DB
     */
    private $db;

    /**
     * CourseItem constructor.
     *
     * @param $licr
     */
    public function __construct($licr)
    {
        $this->licr = $licr;
        $this->db = $licr->db;
    }

    private $fields = array(
        'course_id',
        'item_id',
        'hidden',
        'sequence',
        'status_id',
        'fairdealing',
        'transactional',
        'branch_id',
        'location',
        'processing_branch_id',
        'pickup_branch_id',
        'loanperiod_id',
        'range',
        'required',
        'approved',
        'copyright_clearance',
        'copyright_determination',
        'simultaneous_user_limit',
        'cumulative_view_limit',
        'requested_by',
        'request_time',
        'startdate',
        'enddate'
    );

    /**
     * @param $course_id
     * @param $item_id
     * @param $loanperiod_id
     * @param $requested_by_id
     * @param null $startdate
     * @param null $enddate
     * @return array
     * @throws LICRCourseException
     * @throws LICRCourseItemException
     */
    public function request($course_id, $item_id, $loanperiod_id, $requested_by_id, $startdate = NULL, $enddate = NULL)
    {
        $sql = "SELECT COUNT(*)
				FROM `course_item`
				WHERE `course_id`=? AND `item_id`=?
				";
        $res = $this->db->queryOneVal($sql, array(
            $course_id,
            $item_id
        ));
        if ($res > 0) {
            throw new LICRCourseItemException ("CourseItem::request item already requested for this course", self::EXCEPTION_ALREADY_REQUESTED);
        }
        $course_info = $this->licr->course_mgr->info($course_id);
        $branch_id = $course_info ['default_branch_id'];
        if (!$this->licr->user_mgr->exists($requested_by_id)) {
            throw new LICRCourseItemException ("CourseItem::request requested_by user [$requested_by_id] not found");
        }
        $status_id = $this->licr->status_mgr->id_by_name('New Request');
        if (!$status_id) {
            throw new LICRCourseItemException ("CourseItem::request 'New Request' status has no status_id");
        }
        if ($startdate == $course_info['startdate']) {
            $startdate = NULL;
        }
        if ($enddate == $course_info['enddate']) {
            $enddate = NULL;
        }
        $data = array(
            'course_id' => $course_id,
            'item_id' => $item_id,
            'hidden' => 0,
            'sequence' => -1,
            'status_id' => $status_id,
            'fairdealing' => 0,
            'transactional' => 0,
            'branch_id' => $branch_id,
            'location' => 'Not specified',
            'processing_branch_id' => $branch_id,
            'pickup_branch_id' => $branch_id,
            'loanperiod_id' => $loanperiod_id,
            'range' => FALSE,
            'required' => 0,
            'approved' => 0,
            'copyright_clearance' => 'required',
            'copyright_determination' => '',
            'simultaneous_user_limit' => 0,
            'cumulative_view_limit' => 0,
            'requested_by' => $requested_by_id,
            'request_time' => date('Y-m-d H:i:s'),
            'startdate' => $startdate,
            'enddate' => $enddate
        );
        try {
            $instance_id = $this->create($data);
        } catch (LICRCourseException $lce) {
            // var_dump ( $lce->getMessage () );
            throw $lce;
        }
        return array(
            'instance_id' => $instance_id
        ); // instance_id
    }

    /**
     * Create courseitem, return courseitem_id
     * $data is an associative array containing
     * fields as listed above
     *
     * @param array $data
     *          associative array
     * @return boolean integer
     * @throws LICRCourseItemException
     */
    public function create($data)
    {
        if (!is_array($data)) {
            throw new LICRCourseItemException ('CourseItem::create parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        $bind = array(
            'status_id' => $this->licr->status_mgr->id_by_name('New Request'),
            'startdate' => NULL,
            'enddate' => NULL,
            'previous_status_id' => 1
        );
        foreach ($this->fields as $field) {
            if (array_key_exists($field, $data)) {
                $bind [$field] = $data [$field];
            } elseif (!array_key_exists($field, $bind)) {
                throw new LICRCourseItemException ("CourseItem::create Missing field $field", self::EXCEPTION_MISSING_FIELD);
            }
        }
        $course_info = $this->licr->course_mgr->info($data['course_id']);
        if ($bind['startdate'] == $course_info['startdate']) {
            $bind['startdate'] = NULL;
        }
        if ($bind['enddate'] == $course_info['enddate']) {
            $bind['enddate'] = NULL;
        }
        $fields = array();
        foreach (array_keys($bind) as $col) {
            $fields [] = "
			`$col`=:$col
			";
        }
        $sql = "
				INSERT IGNORE INTO `course_item`
				SET " . implode(',', $fields);
        $res = $this->db->execute($sql, $bind);
        $retval = $res->rowCount();
        $instance_id = $this->db->lastInsertId();
        if ($retval) {
            history_add('course_item', $instance_id, 'Created instance for course ' . $data ['course_id'] . ' item ' . $data ['item_id']);
            // history_add ( 'course', $data ['course_id'], "Added item " . $data ['item_id'] );
            // history_add ( 'item', $data ['item_id'], "Added to course " . $data ['course_id'] );
        } else {
            throw new LICRCourseItemException ('CourseItem::create CourseItem already exists ' . implode('::', $res->errorInfo()), self::EXCEPTION_COURSE_ITEM_EXISTS);
        }
        // echo "AT ".$data ['course_id'].' '.$data ['item_id'];
        $this->_auto_transition($data ['course_id'], $data ['item_id']);

        if ($retval) {
            return $instance_id;
        } else {
            return $this->exists_id($data ['course_id'], $data ['item_id']);
        }
    }

    /**
     * @param $course_id
     * @param $item_id
     * @return string
     */
    public function exists($course_id, $item_id)
    {
        $sql = "SELECT COUNT(*) FROM `course_item` WHERE `course_id`=? AND `item_id`=? LIMIT 1";
        $n = $this->db->queryOneVal($sql, array(
            $course_id,
            $item_id
        ));
        return $n;
    }

    /**
     * @param $course_id
     * @param $item_id
     * @return string
     */
    public function exists_id($course_id, $item_id)
    {
        $sql = "SELECT `instance_id` FROM `course_item` WHERE `course_id`=? AND `item_id`=? LIMIT 1";
        $instance_id = $this->db->queryOneVal($sql, array(
            $course_id,
            $item_id
        ));
        return $instance_id;
    }

    /**
     * @param $instance_id
     * @return array
     */
    public function exists_instance($instance_id)
    {
        $sql = "SELECT `course_id`,`item_id` FROM `course_item` WHERE `instance_id`=?";
        return $this->db->queryOneRow($sql, $instance_id);
    }

    /**
     * @param $course_id
     * @param $item_id
     * @param $required
     * @return int
     */
    public function set_required($course_id, $item_id, $required)
    {
        $required *= 1;
        // $course_id*=1;
        // $item_id*=1;
        if ($required > 1) {
            $required = 1;
        }
        $sql = "UPDATE
				`course_item`
				SET `required`=:required
				WHERE
				`course_id`=:course_id
				AND `item_id`=:item_id
				";
        $this->db->execute($sql, compact('course_id', 'item_id', 'required'));
        return 1;
    }

    /**
     * @param $course_id
     * @param $item_id
     * @param $field
     * @param $value
     * @return string
     * @throws LICRCourseException
     * @throws LICRCourseItemException
     */
    public function set_field($course_id, $item_id, $field, $value)
    {
        if ($field == 'course_id' || $field == 'item_id' || $field == 'instance_id') {
            throw new LICRCourseItemException ("Prevented from setting course_item field [$field]", self::EXCEPTION_BAD_FIELD);
        }
        if ($field == 'status_id') {
            throw new LICRCourseItemException ("Prevented from setting status_id (use SetCIStatus)", self::EXCEPTION_BAD_FIELD);
        }
        if ($field == 'loanperiod') {
            throw new LICRCourseItemException ("Prevented from setting loan period (use SetCILoanPeriod)", self::EXCEPTION_BAD_FIELD);
        }
        if (!in_array($field, $this->fields)) {
            throw new LICRCourseItemException ("Unrecognized field [course_item.$field]", self::EXCEPTION_BAD_FIELD);
        }
        if (!$this->exists($course_id, $item_id)) {
            throw new LICRCourseItemException ("Item [$item_id] does not exist in course [$course_id]", self::EXCEPTION_COURSE_ITEM_NOT_FOUND);
        }
        $instance_id = $this->instance_id($course_id, $item_id);
        if (!$instance_id) {
            throw new LICRCourseItemException ("Item [$item_id] in course [$course_id] has no instance_id (!?)", self::EXCEPTION_COURSE_ITEM_NOT_FOUND);
        }
        if ($field == 'startdate' || $field == 'enddate') {
            $course_info = $this->licr->course_mgr->info($course_id);
            if ($course_info[$$field] == $value) {
                $value = NULL;
            }
        }
        $sql = "
		SELECT
		`$field`
		FROM
		`course_item`
		WHERE
		`instance_id`=:instance_id
		";
        $oldval = $this->db->queryOneVal($sql, compact('instance_id'));
        $sql = "
		UPDATE
		`course_item`
		SET `$field`=:value
		WHERE
		`instance_id`=:instance_id
		";

        if (!$this->db->beginTransaction()) {
            throw new LICRCourseItemException ("Cannot initiate database transaction", self::EXCEPTION_DATABASE_ERROR);
        }
        $bind = compact('value', 'instance_id');
        $stmt = $this->db->execute($sql, $bind);
        // var_dump($sql);
        // var_dump($bind);
        // var_dump($stmt->errorInfo ());
        if ($errno = $stmt->errorCode() != '00000') {
            $this->db->rollBack();
            throw new LICRCourseItemException ("Database error $errno", self::EXCEPTION_DATABASE_ERROR);
        }
        if (!$this->db->commit()) {
            throw new LICRCourseItemException ("Course_Item::set_field failed to commit transaction", self::EXCEPTION_DATABASE_ERROR);
        }
        history_add('course_item', $instance_id, "Changed [$field] to [$value] from [$oldval]");
        return "$oldval -> $value";
    }

    /**
     * Modify course_item
     *
     * @param integer $course_id
     * @param integer $item_id
     * @param $data array
     * @return string boolean
     * @throws LICRCourseItemException
     */
    public function modify($course_id, $item_id, $data)
    {
        if (!$this->exists($course_id, $item_id)) {
            throw new LICRCourseItemException ("Item [$item_id] does not exist in course [$course_id]", self::EXCEPTION_COURSE_ITEM_NOT_FOUND);
        }
        if (!is_array($data)) {
            throw new LICRCourseItemException ('CourseItem::modify second parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        $course_info = $this->licr->course_mgr->info($course_id);
        $bind = array();
        foreach ($this->fields as $field) {
            if (!empty ($data [$field])) {
                if ($field == 'startdate' && $course_info['startdate'] == $data['startdate']) {
                    $data['startdate'] = NULL;
                }
                if ($field == 'enddate' && $course_info['enddate'] == $data['enddate']) {
                    $data['enddate'] = NULL;
                }
                $bind [$field] = $data [$field];
            }
        }
        if (!$bind) {
            throw new LICRCourseItemException ('CourseItem::modify No changes specified', self::EXCEPTION_NO_CHANGE_SPECIFIED);
        }
        // let's just get this over with
        if (isset ($bind ['status_id'])) {
            $this->update_status($course_id, $item_id, $bind ['status_id']);
            unset ($bind ['status_id']);
        }

        $bind ['approved'] = 0;
        $fields = array();
        foreach (array_keys($bind) as $col) {
            $fields [] = "
			`$col`=:$col
			";
        }
        $sql = "
				UPDATE `course_item`
				SET
				";
        $sql .= implode(',', $fields);
        $sql .= "
				WHERE
				`course_id`=:course_id
				AND `item_id`=:item_id
				";
        $bind ['course_id'] = $course_id;
        $bind ['item_id'] = $item_id;
        $res = $this->db->execute($sql, $bind);
        if (!$res) {
            throw new LICRCourseItemException ("Database error", self::EXCEPTION_DATABASE_ERROR);
        }
        $changes = '';
        foreach ($bind as $key => $val) {
            $changes .= "$key: $val,";
        }
        $retval = $res->rowCount();
        // if ($retval) {
        $instance_id = $this->exists_id($course_id, $item_id);
        history_add('course_item', $instance_id, "Modified item $item_id: " . trim($changes, ','));
        // }
        if (!empty ($bind ['loanperiod_id'])) {
            // Aha! must change status.
            $sql = "SELECT `next_status_id` FROM `transition` WHERE `rule`='loanperiod'";
            $nsi = $this->db->queryOneVal($sql);
            $this->update_status($course_id, $item_id, $nsi);
        }
        return $retval;
    }

    /**
     * @param $course_id
     * @param $item_id
     * @param $hidden
     * @return string
     * @throws LICRCourseItemException
     */
    public function hidden($course_id, $item_id, $hidden)
    {
        $instance_id = $this->exists_id($course_id, $item_id);
        if (!$instance_id) {
            throw new LICRCourseItemException ('Course_item not found', self::EXCEPTION_COURSE_ITEM_NOT_FOUND);
        }
        $sql = "
				UPDATE `course_item`
				SET `hidden`=:hidden
				WHERE `instance_id`=:instance_id
				LIMIT 1
				";

        $res = $this->db->execute($sql, compact('hidden', 'instance_id'));
        $count = $res->rowCount();
        if ($count) {
            history_add('course_item', $instance_id, "Item [$item_id] " . ($hidden ? 'not' : '') . ' hidden.', $hidden);
        }
        return "$count changes";
    }

    /**
     * @param $item_id
     * @return array
     */
    public function courses_by_item_id($item_id)
    {
        $sql = "
				SELECT
				`course`.`course_id`
				, `title`
				, `lmsid`
				, `hash`
				, `course`.`active`
				FROM
				`course_item`
				JOIN `course` USING(`course_id`)
				WHERE
				`course_item`.`item_id`=?
				";
        $res = $this->db->queryAssoc($sql, 'course_id', $item_id);
        return $res;
    }

    /**
     * @param $course_id
     * @return array|bool
     */
    public function student_items_in_course($course_id)
    {
        $bind = compact('course_id');
        $sql = "
				SELECT
				CI.`instance_id`
				,CI.`item_id`
				,S.`status_id`
				,S.`name` AS status
				,S.`cancelled`
				,S.`visible_to_student`
				,CI.`hidden`
				,CI.`fairdealing`
				,CI.`transactional`
				,CI.`processing_branch_id`
				,CI.`pickup_branch_id`
				,LP.`loanperiod_id`
				,LP.`period` AS loanperiod
				,CI.`range`
				,CI.`required`
				,CI.`approved`
				,CI.`copyright_clearance`
				,CI.`copyright_determination`
				,CI.`simultaneous_user_limit`
				,CI.`cumulative_view_limit`
				,CI.`requested_by`
				,CI.`request_time`
				,CI.`sequence`
				,CI.`requested_by` AS requestor_id
				FROM
				`course_item` CI
				JOIN `course` C USING(`course_id`)
				JOIN `status` S USING(`status_id`)
				JOIN `loanperiod` LP USING (`loanperiod_id`)
				WHERE
				CI.`course_id`=:course_id
				AND CI.`hidden`=0
				AND UNIX_TIMESTAMP(IFNULL(CI.`startdate`,C.`startdate`)) < UNIX_TIMESTAMP()
				AND UNIX_TIMESTAMP(IFNULL(CI.`enddate`,C.`enddate`)) > UNIX_TIMESTAMP()
				ORDER BY CI.`sequence`";
        $result = $this->db->queryRows($sql, $bind);
        if (!$result) {
            return FALSE;
        }
        // var_dump($result);
        foreach ($result as $i => $row) {
            $processing_branch_id = $row ['processing_branch_id'];
            $pickup_branch_id = $row ['pickup_branch_id'];
            try {
                $processing_branch_info = $this->licr->branch_mgr->info($processing_branch_id);
                $result [$i] ['processing_branch'] = $processing_branch_info ['name'];
            } catch (LICRBranchException $lbe) {
                $result [$i] ['processing_branch'] = "Undefined branch id [$processing_branch_id]";
            }
            try {
                $pickup_branch_info = $this->licr->branch_mgr->info($pickup_branch_id);
                $result [$i] ['pickup_branch'] = $pickup_branch_info ['name'];
            } catch (LICRBranchException $lbe) {
                $result [$i] ['pickup_branch'] = "Undefined branch id [$pickup_branch_id]";
            }
            $dates = $this->get_dates($course_id, $row ['item_id']);
            $result [$i] ['dates'] = $dates;
            $result [$i] ['tags'] = $this->licr->tag_item_mgr->list_course_item_tags($course_id, $row ['item_id']);
        }
        $ret = array();
        foreach ($result as $row) {
            $ret [$row ['item_id']] = $row;
        }
        return $ret;
    }

    /**
     * @param $course_id
     * @return array|bool
     */
    public function instructor_items_in_course($course_id)
    {
        $bind = compact('course_id');
        $sql = "
				SELECT
				CI.`instance_id`
				,CI.`item_id`
				,S.`status_id`
				,S.`name` AS status
				,S.`cancelled`
				,S.`visible_to_student`
				,CI.`hidden`
				,CI.`fairdealing`
				,CI.`transactional`
				,CI.`processing_branch_id`
				,CI.`pickup_branch_id`
				,LP.`loanperiod_id`
				,LP.`period` AS loanperiod
				,CI.`range`
				,CI.`required`
				,CI.`approved`
				,CI.`copyright_clearance`
				,CI.`copyright_determination`
				,CI.`simultaneous_user_limit`
				,CI.`cumulative_view_limit`
				,CI.`requested_by`
				,CI.`request_time`
				,CI.`sequence`
				,CI.`requested_by` AS requestor_id
				FROM
				`course_item` CI
				JOIN `course` C USING(`course_id`)
				JOIN `status` S USING(`status_id`)
				JOIN `loanperiod` LP USING(`loanperiod_id`)
				WHERE
				CI.`course_id`=:course_id
				AND CI.`hidden`=0
				ORDER BY CI.`sequence`";
        $result = $this->db->queryRows($sql, $bind);
        if (!$result) {
            return FALSE;
        }
        // var_dump($result);
        foreach ($result as $i => $row) {
            $processing_branch_id = $row ['processing_branch_id'];
            $pickup_branch_id = $row ['pickup_branch_id'];
            try {
                $processing_branch_info = $this->licr->branch_mgr->info($processing_branch_id);
                $result [$i] ['processing_branch'] = $processing_branch_info ['name'];
            } catch (LICRBranchException $lbe) {
                $result [$i] ['processing_branch'] = "Undefined branch id [$processing_branch_id]";
            }
            try {
                $pickup_branch_info = $this->licr->branch_mgr->info($pickup_branch_id);
                $result [$i] ['pickup_branch'] = $pickup_branch_info ['name'];
            } catch (LICRBranchException $lbe) {
                $result [$i] ['pickup_branch'] = "Undefined branch id [$pickup_branch_id]";
            }
            $dates = $this->get_dates($course_id, $row ['item_id']);
            $result [$i] ['dates'] = $dates;
            $result [$i] ['tags'] = $this->licr->tag_item_mgr->list_course_item_tags($course_id, $row ['item_id']);
        }
        $ret = array();
        foreach ($result as $row) {
            $ret [$row ['item_id']] = $row;
        }
        return $ret;
    }

    /**
     * @param $course_id
     * @param int $status_id
     * @param null $visible
     * @return array
     */
    public function items_in_course($course_id, $status_id = 0, $visible = NULL)
    {
        if ($status_id === 0 && is_null($visible)) {
            $sql = "
					SELECT
                      I.`item_id`
					, I.`title`
					, I.`author`
					, I.`bibdata`
					, I.`hash`
                    , I.`shorturl`
                    , T.`name` AS type_name
					, CI.`sequence`
					, S.`status_id`
					, S.`name` AS status_name
					, S.`cancelled`
					, CI.`hidden`
			        , IFNULL(CI.`startdate`,C.`startdate`) AS item_start
			        , IFNULL(CI.`enddate`,C.`enddate`) AS item_end
			    FROM
					`course_item` CI
					JOIN `item` I USING(`item_id`)
					JOIN `status` S USING(`status_id`)
    			    JOIN `course` C USING(`course_id`)
    			    JOIN `type` T USING(`type_id`)
				  WHERE
					CI.`course_id`=?
					ORDER BY
					CI.`sequence`";
            $res = $this->db->queryAssoc($sql, 'item_id', $course_id);
        } else {
            if ($status_id > 0) {
                $sql = "
					SELECT
					  I.`item_id`
					, I.`title`
					, I.`author`
					, I.`bibdata`
					, I.`hash`
                    , I.`shorturl`
                    , T.`name` AS type_name
                    , CI.`sequence`
					, S.`status_id`
					, S.`cancelled`
					, S.`name` AS status_name
					, CI.`hidden`
			        , IFNULL(CI.`startdate`,C.`startdate`) AS item_start
			        , IFNULL(CI.`enddate`,C.`enddate`) AS item_end
			    FROM
					`course_item` CI
					JOIN `item` I USING(`item_id`)
					JOIN `status` S USING(`status_id`)
    			    JOIN `type` T USING(`type_id`)
			    JOIN `course` C USING(`course_id`)
			    WHERE
					CI.`course_id`=:course_id
					AND CI.`status_id`=:status_id
					ORDER BY
					CI.`sequence`";
                $res = $this->db->queryAssoc($sql, 'item_id', compact('course_id', 'status_id'));
            } else { // visible
                $sql = "
					SELECT
					  I.`item_id`
					, I.`title`
					, I.`author`
					, I.`bibdata`
					, I.`hash`
                    , I.`shorturl`
                    , T.`name` AS type_name
                    , CI.`sequence`
					, S.`status_id`
					, S.`cancelled`
                    , S.`name` AS status_name
					, CI.`hidden`
			        , IFNULL(CI.`startdate`,C.`startdate`) AS item_start
			        , IFNULL(CI.`enddate`,C.`enddate`) AS item_end
			    FROM
					`course_item` CI
					JOIN `item` I USING(`item_id`)
					JOIN `status` S USING(`status_id`)
     			    JOIN `course` C USING(`course_id`)
    			    JOIN `type` T USING(`type_id`)
			    WHERE
					CI.`course_id`=:course_id
					AND CI.`hidden`=0
					AND S.`visible_to_student`=:visible
					ORDER BY
					CI.`sequence`";
                $res = $this->db->queryAssoc($sql, 'item_id', compact('course_id', 'visible'));
            }
        }
        foreach ($res as $item_id => $data) {
            $res [$item_id] ['tags'] = $this->licr->tag_item_mgr->list_course_item_tags($course_id, $item_id);
        }
        return $res;
    }

    /**
     * @param $course_id
     * @param bool $item_id
     * @return array|bool
     */
    public function info($course_id, $item_id = FALSE)
    {
        $bind = compact('course_id');
        if ($item_id) {
            $bind ['item_id'] = $item_id;
        }
        $sql = "
				SELECT
				CI.`instance_id`
				,CI.`item_id`
				,S.`status_id`
				,S.`name` AS status
				,S.`cancelled`
				,S.`visible_to_student`
				,CI.`hidden`
				,CI.`fairdealing`
				,CI.`transactional`
				,CI.`processing_branch_id`
				,CI.`pickup_branch_id`
				,LP.`loanperiod_id`
				,LP.`period` AS loanperiod
				,CI.`range`
				,CI.`required`
				,CI.`approved`
        ,CI.`location`
				,CI.`copyright_clearance`
				,CI.`copyright_determination`
				,CI.`simultaneous_user_limit`
				,CI.`cumulative_view_limit`
				,CI.`requested_by`
				,CI.`request_time`
				,CI.`sequence`
				,CI.`requested_by` AS requestor_id
        ,I.`shorturl`
        ,I.`uri`
				FROM
				`course_item` CI
				JOIN `status` S USING(`status_id`)
				JOIN `loanperiod` LP USING(`loanperiod_id`)
        JOIN `item` I USING(`item_id`)
				WHERE
				CI.`course_id`=:course_id
				";
        if ($item_id) {
            $sql .= "
					AND CI.`item_id`=:item_id
					";
        }
        $sql .= 'ORDER BY CI.`sequence`';
        $result = $this->db->queryRows($sql, $bind);
        if (!$result) {
            return FALSE;
        }
        // var_dump($result);
        foreach ($result as $i => $row) {
            $processing_branch_id = $row ['processing_branch_id'];
            $pickup_branch_id = $row ['pickup_branch_id'];
            try {
                $processing_branch_info = $this->licr->branch_mgr->info($processing_branch_id);
                $result [$i] ['processing_branch'] = $processing_branch_info ['name'];
            } catch (LICRBranchException $lbe) {
                $result [$i] ['processing_branch'] = "Undefined branch id [$processing_branch_id]";
            }
            try {
                $pickup_branch_info = $this->licr->branch_mgr->info($pickup_branch_id);
                $result [$i] ['pickup_branch'] = $pickup_branch_info ['name'];
            } catch (LICRBranchException $lbe) {
                $result [$i] ['pickup_branch'] = "Undefined branch id [$pickup_branch_id]";
            }
            $dates = $this->get_dates($course_id, $row ['item_id']);
            $result [$i] ['dates'] = $dates;
            $result [$i] ['tags'] = $this->licr->tag_item_mgr->list_course_item_tags($course_id, $row ['item_id']);
            try {
                $user_info = $this->licr->user_mgr->info_by_id($row['requested_by']);
                $result[$i]['requestor_puid'] = $user_info['puid'];
            } catch (LICRUserException $lue) {
                $result[$i]['requestor_puid'] = '(Unknown)';
            }
        }
        if ($item_id) {
            return $result [0];
        } else {
            $ret = array();
            foreach ($result as $row) {
                $ret [$row ['item_id']] = $row;
            }
            return $ret;
        }
    }

    /**
     * @param $course_id
     * @param $item_id_list
     * @return bool
     * @throws LICRCourseItemException
     */
    public function sequence($course_id, $item_id_list)
    {
        // $sql = "
        // SELECT COUNT(*) FROM `course_item`
        // WHERE `course_id`=?
        // ";
        // $icount = $this->db->queryOneVal ( $sql, $course_id );
        // if ($icount != count ( $item_id_list )) {
        // throw new LICRCourseItemException ( "CourseItem::sequence item count mismatch", self::EXCEPTION_ITEM_COUNT );
        // }
        $bind = array(
            'sequence' => 1,
            'course_id' => $course_id
        );
        if ($this->db->beginTransaction()) {
            $sql = "UPDATE `course_item` SET `sequence`=100000 WHERE `course_id`=:course_id";
            $this->db->execute($sql, compact('course_id'));
            $sql = "
					UPDATE `course_item`
					SET `sequence`=:sequence
					WHERE `course_id`=:course_id
					AND `item_id`=:item_id
					";
            foreach ($item_id_list as $item_id) {
                $bind ['sequence']++;
                $bind ['item_id'] = $item_id;
                $this->db->execute($sql, $bind);
            }
            $this->db->commit();
        } else {
            throw new LICRCourseItemException ('Cannot begin database transaction', self::EXCEPTION_DATABASE_ERROR);
        }
        return TRUE;
    }

    /**
     * @param $course_id
     * @param $item_id
     * @param $new_status_id
     * @return bool|mixed
     * @throws LICRCourseException
     * @throws LICRCourseItemException
     */
    public function update_status($course_id, $item_id, $new_status_id)
    {
        /* @var $info array */
        $info = $this->info($course_id, $item_id);
        if (!$info) {
            throw new LICRCourseItemException ("Course item not found (C $course_id I $item_id)", self::EXCEPTION_COURSE_ITEM_NOT_FOUND);
        }
        if (!$new_status_name = $this->licr->status_mgr->name_by_id($new_status_id)) {
            throw new LICRCourseItemException ("Invalid status_id $new_status_id", self::EXCEPTION_INVALID_STATUS_ID);
        }
        $old_status_id = $info ['status_id'];

        $sql = "
				UPDATE
				`course_item`
				SET
				`status_id`=:nsi,
				`previous_status_id`=:osi
				WHERE
				`course_id`=:cid
				AND `item_id`=:iid
				";
        $bind = array(
            'nsi' => 1 * $new_status_id,
            'osi' => 1 * $old_status_id,
            'cid' => 1 * $course_id,
            'iid' => 1 * $item_id
        );
        $this->db->execute($sql, $bind);
        history_add('course_item', $info ['instance_id'], 'Status change from [' . $info ['status'] . '] to [' . $new_status_name . ']');
        $new_status_id = $this->_auto_transition($course_id, $item_id, $new_status_id);

        if ($old_status_id != $new_status_id) {
            // email notification
            // error_log("New status for C$course_id/I$item_id: $old_status_id->$new_status_id");
            $sql = "SELECT `notify` FROM `status` WHERE `status_id`=:new_status_id";
            $do_notify = $this->db->queryOneVal($sql, compact('new_status_id'));
            if ($do_notify) {
                $contacts = array();
                /*
                        $branch_contact = $this->licr->idboxCall ( array (
                            'command' => 'GetReservesContact',
                            'branch_name' => $info ['processing_branch']
                        ) );
                        if ($branch_contact && $branch_contact ['email']) {
                          $contacts [] = $branch_contact ['email'];
                        } else {
                          error_log ( "No processing branch contact for I.$item_id in C.$course_id with processing branch [" . $info ['processing_branch'] . "]" );
                        }
                */
                $requested_by = array();
                if ($info ['requested_by']) {
                    $requested_by = $this->licr->user_mgr->search($info ['requested_by']);
                }
                if (count($requested_by) == 1) {
                    $contacts [] = $requested_by ['email'];
                } else {
                    $instructors = $this->licr->course_mgr->info($course_id)['instructors'];
                    foreach ($instructors as $instructor) {
                        $user_info = $this->licr->user_mgr->info_by_puid($instructor ['puid']);
                        $email = trim($user_info ['email']);
                        if ($email) {
                            $contacts [] = $email;
                        }
                    }
                }
                if ($contacts) {
                    $item_info = $this->licr->item_mgr->info($item_id);
                    $course_info = $this->licr->course_mgr->info($course_id);
                    $newstatusname = $this->licr->status_mgr->name_by_id($new_status_id);
                    $content_plain = "Course: " . $course_info ['title'] . "
Title:   " . $item_info ['title'] . "
Author:  " . $item_info ['author'] . "
Status:  " . $newstatusname . "
Previous Status: " . $info['status'] . "
Link:    " . $item_info ['shorturl'] . "
Item ID: " . $item_info['item_id'] . "
";
                    $content_html = "<p>Course: " . $course_info ['title'] . "
						Title: <em>" . $item_info ['title'] . "</em><br />
						Author: " . $item_info ['author'] . "<br />
						Status: <strong>$newstatusname</strong><br />
						Previous Status: " . $info['status'] . "<br />
						Link: <a href=\"" . $item_info ['shorturl'] . "\">" . $item_info ['shorturl'] . "</a><br />
            Item ID: " . $item_info['item_id'] . "</p>";
                    $this->licr->email_queue_mgr->enqueue($contacts, $content_plain, $content_html);
                } else {
                    error_log('email: nobody to notify!');
                }
            } else {
                // error_log('Not a noteworthy transition');
            }
        }
        return $new_status_id;
    }

    /*
     * Follow transition rules
     */
    /**
     * @param $course_id
     * @param $item_id
     * @param bool $status_id
     * @return bool|mixed
     * @throws LICRCourseItemException
     */
    public function _auto_transition($course_id, $item_id, $status_id = FALSE)
    {
        $message = '';
        if (!$status_id) {
            $ci_info = $this->info($course_id, $item_id);
            $status_id = $ci_info ['status_id'];
        }
        $old_status_id = $status_id;
        $new_status_id = $old_status_id;
        $sql = "
				SELECT
				T.`transition_id`,
				T.`next_status_id`,
				T.`event`,
				T.`rule`
				FROM
				`transition` T
				WHERE
				T.`current_status_id`=:csi
				AND T.`rule` IS NOT NULL
				";
        $res = $this->db->queryRows($sql, array(
            'csi' => $old_status_id
        ));
        if (empty ($res)) {
            return $old_status_id; // no automatic transition
        }
        $item_info = $this->licr->item_mgr->info($item_id);
        foreach ($res as $row) {
            $rule = $row ['rule'];
            if (trim($rule)) {
                $tid = $row ['transition_id'];
                $event = $row ['event'];
                $pnsi = $row ['next_status_id'];
                if ($rule === 'expire') {
                    if ($this->is_expired($course_id, $item_id)) {
                        $new_status_id = $pnsi;
                        $message = $event;
                        break;
                    }
                } else {
                    if ($rule === 'loanperiod') {
                        continue;
                    } else { // iteminfo-based transition
                        list ($field, $value) = explode('=', $rule);
                        if ($item_info [$field] == $value) {
                            $new_status_id = $pnsi;
                            $message = $event;
                            break;
                        }
                    }
                }
            }
        }
        if ($old_status_id != $new_status_id) {
            $instance_id = $this->instance_id($course_id, $item_id);
            history_add('course_item', $instance_id, "AUTOMATIC: " . $message);
            return $this->update_status($course_id, $item_id, $new_status_id);
        }
        return $status_id;
    }

    /**
     * @return array
     */
    public function get_counts()
    {
        $sql = "
				SELECT
				`status`.`category`
				,`type`.`name` AS type
				,`type`.`type_id`
				,COUNT(`instance_id`) AS count
				FROM
				`course_item`
				JOIN `status` USING(`status_id`)
				JOIN `item` USING(`item_id`)
				JOIN `type` USING(`type_id`)
				GROUP BY
				`status`.`category`
				,`item`.`type_id`
				ORDER BY
				`status`.`category`
				,`type`.`name`
				";
        $res = $this->db->query($sql);
        $ret = array(
            'New' => array(),
            'InProcess' => array(),
            'Complete' => array()
        );
        while ($row = $res->fetch()) {
            $ret [$row ['category']] [] = array(
                'type' => $row ['type'],
                'type_id' => $row ['type_id'],
                'count' => $row ['count']
            );
        }
        return $ret;
    }

    /**
     * @param bool $branch_id
     * @return array
     * @throws LICRCourseItemException
     */
    public function get_list($branch_id = FALSE)
    {
        $sql = "
				SELECT
				S.`category`
				,S.`status_id`
				,S.`name` AS status
				,T.`name` AS type
				,T.`type_id`
				,I.`callnumber`
				,I.`title`
				,CI.`range`
				,I.`author`
				,CI.`request_time`
				,CI.`loanperiod_id`
				,I.`item_id`
				,I.`physical_format`
				,I.`bibdata`
				,GROUP_CONCAT(
				  CI.`course_id`
				  ,CHAR(31)
				  ,C.`lmsid`
				  ORDER BY CI.`course_id` DESC
				  SEPARATOR ', '
				) AS course_id
				,CI.`processing_branch_id` AS branch_id
				,MAX(IFNULL(CI.`enddate`,C.`enddate`)) AS last_date
				FROM
				`course_item` CI
				JOIN `status` S USING(`status_id`)
				JOIN `item` I USING(`item_id`)
				JOIN `type` T USING(`type_id`)
				JOIN `course` C USING(`course_id`)
				WHERE
				  CI.`hidden`=0";
        if ($branch_id) {
            $sql .= "
					AND CI.`processing_branch_id`=?";
        }
        $sql .= "
				GROUP BY
				  CI.`status_id`, I.`item_id`
				ORDER BY
  				S.`category`
  				,T.`name`
  				,CI.`request_time` DESC
				";

        // var_dump($this->db->errorInfo());
        $ret = array(
            'New' => array(),
            'InProcess' => array(),
            'Complete' => array(),
            'Archive' => array()
        );

        #error_log('##### SQL ####' . PHP_EOL . preg_replace("/[\r\n]+/", "  ", $sql) . PHP_EOL . '##### SQL ####' . PHP_EOL);

        if ($branch_id) {
            if (!$this->licr->branch_mgr->exists($branch_id)) {
                throw new LICRCourseItemException ("Branch not found", self::EXCEPTION_BRANCH_NOT_FOUND);
            }

            try {
                $res = $this->db->execute($sql, $branch_id);
            } catch (Exception $e){
                error_log($e->getMessage() . PHP_EOL);
                return $ret;
            }
        } else {

            try {
                $res = $this->db->query($sql);
            } catch (Exception $e){
                error_log($e->getMessage() . PHP_EOL);
                return $ret;
            }

        }

        # at this point, we must have a $res or else it would have returned
        while ($row = $res->fetch()) {
            $type = $row ['type'];
            $category = $row ['category'];
            if (strtotime($row ['last_date']) < strtotime('now')) {
                $category = 'Archive';
            }
            $row['bibdata'] = unserialize($row['bibdata']);
            unset ($row ['type']);
            unset ($row ['category']);
            if (!isset ($ret [$category] [$type])) {
                $ret [$category] [$type] = array();
            }
            $ret [$category] [$type] [] = $row;
        }
        return $ret;
    }

    /**
     * @param bool $branch_id
     * @return array
     * @throws LICRCourseItemException
     */
    public function get_new_and_inprocess($branch_id = FALSE)
    {
        $sql = "
				SELECT
				S.`category`
				,S.`status_id`
				,S.`name` AS status
				,T.`name` AS type
				,T.`type_id`
				,I.`callnumber`
				,I.`title`
				,CI.`range`
				,I.`author`
				,CI.`request_time`
				,CI.`loanperiod_id`
				,I.`item_id`
				,I.`physical_format`
				,I.`bibdata`
				,GROUP_CONCAT(
				  CI.`course_id`
				  ,CHAR(31)
				  ,C.`lmsid`
				  ORDER BY CI.`course_id` DESC
				  SEPARATOR ', '
				) AS course_id
				,CI.`processing_branch_id` AS branch_id
				,MAX(IFNULL(CI.`enddate`,C.`enddate`)) AS last_date
				FROM
				`course_item` CI
				JOIN `status` S USING(`status_id`)
				JOIN `item` I USING(`item_id`)
				JOIN `type` T USING(`type_id`)
				JOIN `course` C USING(`course_id`)
				WHERE
				  CI.`hidden`=0
          AND S.`category` IN('InProcess','New')
        ";
        if ($branch_id) {
            $sql .= "
					AND CI.`processing_branch_id`=?";
        }
        $sql .= "
				GROUP BY
				  CI.`status_id`, I.`item_id`
        HAVING
          last_date > NOW()
        ORDER BY
  				S.`category`
  				,T.`name`
  				,CI.`request_time` DESC
  				,last_date DESC
				";
        if ($branch_id) {
            if (!$this->licr->branch_mgr->exists($branch_id)) {
                throw new LICRCourseItemException ("Branch not found", self::EXCEPTION_BRANCH_NOT_FOUND);
            }
            $res = $this->db->execute($sql, $branch_id);
        } else {
            $res = $this->db->query($sql);
        }
        // var_dump($this->db->errorInfo());
        $ret = array(
            'New' => array(),
            'InProcess' => array(),
            'Complete' => array(),
            'Archive' => array()
        );
        while ($row = $res->fetch()) {
            $type = $row ['type'];
            $category = $row ['category'];
            if (strtotime($row ['last_date']) < strtotime('now')) {
                $category = 'Archive';
            }
            unset ($row ['type']);
            unset ($row ['category']);
            $row['bibdata'] = unserialize($row['bibdata']);
            if (!isset ($ret [$category] [$type])) {
                $ret [$category] [$type] = array();
            }
            $ret [$category] [$type] [] = $row;
        }
        return $ret;
    }

    /**
     * @param bool $branch_id
     * @return array
     * @throws LICRCourseItemException
     */
    public function get_complete($branch_id = FALSE)
    {
        $sql = "
				SELECT
				S.`category`
				,S.`status_id`
				,S.`name` AS status
				,T.`name` AS type
				,T.`type_id`
				,I.`callnumber`
				,I.`title`
				,CI.`range`
				,I.`author`
				,CI.`request_time`
				,I.`item_id`
				,I.`physical_format`
				,I.`bibdata`
				,GROUP_CONCAT(
				  CI.`course_id`
				  ,CHAR(31)
				  ,C.`lmsid`
				  ORDER BY CI.`course_id` DESC
				  SEPARATOR ', '
				) AS course_id
				,CI.`processing_branch_id` AS branch_id
				,MAX(IFNULL(CI.`enddate`,C.`enddate`)) AS last_date
				FROM
				`course_item` CI
				JOIN `status` S USING(`status_id`)
				JOIN `item` I USING(`item_id`)
				JOIN `type` T USING(`type_id`)
				JOIN `course` C USING(`course_id`)
				WHERE
				  CI.`hidden`=0
          AND S.`category` ='Complete'
        ";
        if ($branch_id) {
            $sql .= "
					AND CI.`processing_branch_id`=?";
        }
        $sql .= "
				GROUP BY
				  CI.`status_id`, I.`item_id`
        HAVING
          last_date > NOW()
        ORDER BY
  				S.`category`
  				,T.`name`
  				,CI.`request_time` DESC
				";
        if ($branch_id) {
            if (!$this->licr->branch_mgr->exists($branch_id)) {
                throw new LICRCourseItemException ("Branch not found", self::EXCEPTION_BRANCH_NOT_FOUND);
            }
            $res = $this->db->execute($sql, $branch_id);
        } else {
            $res = $this->db->query($sql);
        }
        // var_dump($this->db->errorInfo());
        $ret = array(
            'New' => array(),
            'InProcess' => array(),
            'Complete' => array(),
            'Archive' => array()
        );
        while ($row = $res->fetch()) {
            $type = $row ['type'];
            $category = $row ['category'];
            if (strtotime($row ['last_date']) < strtotime('now')) {
                $category = 'Archive';
            }
            unset ($row ['type']);
            unset ($row ['category']);
            $row['bibdata'] = unserialize($row['bibdata']);
            if (!isset ($ret [$category] [$type])) {
                $ret [$category] [$type] = array();
            }
            $ret [$category] [$type] [] = $row;
        }
        return $ret;
    }


    /**
     * @param bool $branch_id
     *
     * @return array
     * @throws LICRCourseItemException
     */
    public function getCourseItems ($status_ids = false, $type_ids = false, $branch_id = false, $isHidden = false, $isArchive = false, $limit = 100, $offset = 0, $orderBy = '')
    {
        # if there is a branch_id, it should exist
        if ($branch_id) {
            if (!$this->licr->branch_mgr->exists ($branch_id)) {
                throw new LICRCourseItemException ("Branch not found", self::EXCEPTION_BRANCH_NOT_FOUND);
            }
        }

        # iterate over status_ids, mostly to not have to data-bind as it would be valid after this
        if($status_ids){
            $parts = explode(",", $status_ids);
            $new = [];
            foreach ($parts as $part){
                if (!$this->licr->status_mgr->exists($part)) {
                    throw new LICRCourseItemException ("Status not found [$part]", self::EXCEPTION_INVALID_STATUS_ID);
                }
                array_push($new, $part);
            }
            $status_ids = implode(',',$new);
        }

        # iterate over type_ids, mostly to not have to data-bind as it would be valid after this
        if($type_ids){
            $parts = explode(",", $type_ids);
            $new = [];
            foreach ($parts as $part){
                if (!$this->licr->type_mgr->exists($part)) {
                    throw new LICRCourseItemException ("Type not found [$part]", self::EXCEPTION_INVALID_TYPE_ID);
                }
                array_push($new, $part);
            }
            $type_ids = implode(',',$new);
        }

        # basic get query
        $sql = "
            SELECT SQL_CALC_FOUND_ROWS
                 S.`category`
                ,S.`status_id`
                ,T.`type_id`
                ,T.`name` AS type
                ,I.`callnumber`
                ,I.`title`
                ,CI.`range`
                ,I.`author`
                ,CI.`request_time`
                ,I.`item_id`
                ,I.`physical_format`
                ,I.`bibdata`
                ,GROUP_CONCAT(
                  CI.`course_id`
                  ,CHAR(31)
                  ,C.`lmsid`
                  ORDER BY CI.`course_id` ASC
                  SEPARATOR ', '
                ) AS lms_id
                ,CI.`processing_branch_id` AS branch_id
                ,CI.`course_id` AS course_id
                ,MAX(IFNULL(CI.`enddate`,C.`enddate`)) AS last_date
            FROM
              `course_item` CI";

        # if there is a branch, that join has to happen on the course_item table, so do that first
        if ($branch_id) {
            $sql .= " LEFT OUTER JOIN `branch` B ON CI.`processing_branch_id`=B.`branch_id`";
        }

        # these are part of the regular joins
        $sql .= " 
            JOIN `status` S USING(`status_id`)
            JOIN `item` I USING(`item_id`)
            JOIN `type` T USING(`type_id`)
            JOIN `course` C USING(`course_id`)
        ";

        # modified the where to be based on new isHidden flag
        $sql .= " WHERE CI.`hidden` = " . (int)$isHidden;

        # if we are using status_ids, fix em here
        if ($status_ids) {
            $sql .= " AND S.`status_id` IN ({$status_ids})";
        }

        # if we are using type_ids, fix em here
        if ($type_ids) {
            $sql .= " AND T.`type_id` IN ({$type_ids})";
        }

        # need to data-bind the branch_id as it was already checked above
        if ($branch_id) {
            $sql .= " AND CI.`processing_branch_id`= {$branch_id}";
        }

        # finally, the grouping, having and ordering - grouping
        $sql .= " 
            GROUP BY
                CI.`status_id`, I.`item_id`
            "
        ;

        # finally, the grouping, having and ordering - having
        if($isArchive) {
            $sql .= " 
                HAVING
                  last_date < NOW()
             ";
        } else {
            $sql .= " 
                HAVING
                  last_date > NOW()
             ";
        }

        # finally, the grouping, having and ordering - ordering
        if('' === $orderBy){
            $orderBy = " 
                    S.`category`
                    ,T.`name`
                    ,CI.`request_time` DESC
             ";
        } else {
            $orderBy = str_ireplace(',',' ', $orderBy);
            $orderBy = str_ireplace(';',',', $orderBy);
        }

        #error_log('##### ORDERBY ####' . PHP_EOL . preg_replace("/[\r\n]+/", "  ", $orderBy) . PHP_EOL . '##### ORDERBY ####' . PHP_EOL);

        $sql .= " 
            ORDER BY
                {$orderBy} 
            LIMIT {$offset},{$limit}
            ;"
        ;

        $res = $this->db->query ($sql);

        /*
        if ($branch_id) {
            $res = $this->db->execute ($sql, $branch_id);
        } else {
            $res = $this->db->query ($sql);
        }
        */

        $rows = $res->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = $this->db->query('SELECT FOUND_ROWS();')->fetch(PDO::FETCH_ASSOC);

        $limit = (int) $limit;

        if(empty($rowCount)){
            $rowCount = $limit;
        } else {
            $rowCount = (int) array_pop($rowCount);
        }

        /*
         * not sure why I did this...
        if($rowCount < $limit) {
            $limit = $rowCount;
        }
        */

        $ret = [
            'count' => $rowCount,
            'limit' => $limit,
            'offset' => $offset,
            'rows' => []
        ];

        foreach ($rows as $row) {

            $ret['rows'][] = $row;
            continue;

            // OYE! COPY THE BELOW TO THE CLIENT/WEB FRONT AND LET THE CLIENT PROCESS

            /*

            $type = $row ['type'];
            $category = $row ['category'];
            if (strtotime ($row ['last_date']) < strtotime ('now')) {
                $category = 'Archive';
            }
            unset ($row ['type']);
            unset ($row ['category']);
            $row['bibdata'] = unserialize ($row['bibdata']);
            if (!isset ($ret [$category] [$type])) {
                $ret [$category] [$type] = [];
            }
            $ret [$category] [$type] [] = $row;

            */
        }

        return $ret;
    }


    /**
     * @param bool $branch_id
     * @return array
     * @throws LICRCourseItemException
     */
    public function get_archive($branch_id = FALSE)
    {
        $sql = "
				SELECT
				S.`category`
				,S.`status_id`
				,S.`name` AS status
				,T.`name` AS type
				,T.`type_id`
				,I.`callnumber`
				,I.`title`
				,CI.`range`
				,I.`author`
				,CI.`request_time`
				,I.`item_id`
				,I.`physical_format`
				,I.`bibdata`
				,GROUP_CONCAT(
				  CI.`course_id`
				  ,CHAR(31)
				  ,C.`lmsid`
				  ORDER BY CI.`course_id` ASC
				  SEPARATOR ', '
				) AS course_id
				,CI.`processing_branch_id` AS branch_id
				,MAX(IFNULL(CI.`enddate`,C.`enddate`)) AS last_date
				FROM
				`course_item` CI";
        if ($branch_id) {
            $sql .= "
						JOIN `branch` B ON CI.`processing_branch_id`=B.`branch_id`
						";
        }
        $sql .= "
				JOIN `status` S USING(`status_id`)
				JOIN `item` I USING(`item_id`)
				JOIN `type` T USING(`type_id`)
				JOIN `course` C USING(`course_id`)
				WHERE
				  CI.`hidden`=0
        ";
        if ($branch_id) {
            $sql .= "
					AND CI.`processing_branch_id`=?";
        }
        $sql .= "
				GROUP BY
				  CI.`status_id`, I.`item_id`
        HAVING
          last_date < NOW()
        ORDER BY
  				S.`category`
  				,T.`name`
  				,CI.`request_time` DESC";
        if ($branch_id) {
            if (!$this->licr->branch_mgr->exists($branch_id)) {
                throw new LICRCourseItemException ("Branch not found", self::EXCEPTION_BRANCH_NOT_FOUND);
            }
            $res = $this->db->execute($sql, $branch_id);
        } else {
            $res = $this->db->query($sql);
        }
        // var_dump($this->db->errorInfo());
        $ret = array(
            'New' => array(),
            'InProcess' => array(),
            'Complete' => array(),
            'Archive' => array()
        );
        while ($row = $res->fetch()) {
            $type = $row ['type'];
            $category = $row ['category'];
            if (strtotime($row ['last_date']) < strtotime('now')) {
                $category = 'Archive';
            }
            unset ($row ['type']);
            unset ($row ['category']);
            $row['bibdata'] = unserialize($row['bibdata']);
            if (!isset ($ret [$category] [$type])) {
                $ret [$category] [$type] = array();
            }
            $ret [$category] [$type] [] = $row;
        }
        return $ret;
    }

    /**
     * @param $course_id
     * @param $item_id
     * @return bool
     */
    public function is_expired($course_id, $item_id)
    {
        // TODO
        return FALSE;
    }

    /**
     * @param bool $as_of
     */
    public function get_all_expiring($as_of = FALSE)
    {
        // ok...
        if (!$as_of) {
            $as_of = date('Y-m-d');
        }
        $sql = "
				SELECT
				`instance_id`
				FROM
				`course_item` CI
				JOIN `course` C USING(`course_id`)
				WHERE
				IFNULL(
				CI.`enddate`,
				C.`enddate`
				) < :as_of";
        $res = $this->db->execute($sql, array(
            'as_of' => $as_of
        ));
    }


    /**
     * returns calculated start and end dates for an item
     * @param $course_id
     * @param $item_id
     * @return array
     */
    public function get_dates($course_id, $item_id)
    {
        // CI.startdate and enddate take priority over C.startdate and enddate
        $sql = "
				SELECT
				C.`startdate` AS course_start,
				C.`enddate` AS course_end,
				CI.`startdate` AS course_item_start,
				CI.`enddate` AS course_item_end
				FROM
				`course_item` CI
				JOIN `course` C USING(`course_id`)
				WHERE
				CI.`item_id`=:item_id
				AND CI.`course_id`=:course_id
				";

        $res = $this->db->queryOneRow($sql, array(
            'course_id' => $course_id,
            'item_id' => $item_id
        ));

        return array(
            'course_start' => $res ['course_start'], // pair will be displayed, cannot be edited
            'course_end' => $res ['course_end'],
            'course_item_start' => $res ['course_item_start'] ? $res ['course_item_start'] : $res ['course_start'], // pair will have a datepicker
            'course_item_end' => $res ['course_item_end'] ? $res ['course_item_end'] : $res ['course_end']
        );
    }

    /**
     * @param $course_id
     * @param $item_id
     * @return string
     */
    public function instance_id($course_id, $item_id)
    {
        $sql = "SELECT `instance_id` FROM `course_item` WHERE `course_id`=:cid AND `item_id`=:iid";
        return $this->db->queryOneVal($sql, array(
            'cid' => $course_id,
            'iid' => $item_id
        ));
    }

    /**
     * @param $instance_id
     * @return array|bool
     */
    public function resolve_instance_id($instance_id)
    {
        $sql = "SELECT `course_id`, `item_id` FROM `course_item` WHERE `instance_id`=:iid";
        $res = $this->db->queryOneRow($sql, array(
            'iid' => $instance_id
        ));
        if (!$res) {
            $res = FALSE;
        }
        return $res;
    }

    /**
     * @param $course_id
     * @return array
     */
    public function report_physical($course_id)
    {
        $sql = "
        SELECT
          I.`callnumber`
         ,I.`title`
         ,I.`bibdata`
         ,I.`author`
         ,I.`physical_format`
         ,CI.`required`
				 ,IFNULL(CI.`startdate`,C.`startdate`) AS start
			 	 ,IFNULL(CI.`enddate`,C.`enddate`) AS end
        FROM
          `course_item` CI
          JOIN `course` C USING(`course_id`)
          JOIN `item` I USING(`item_id`)
        WHERE
          C.`course_id`=:course_id
          AND I.`physical_format` IN('physical_general','book_general','undetermined')
        ORDER BY 
          I.`title`,I.`author`
        ";

        $res = $this->db->execute($sql, compact('course_id'));
        $ret = array();
        while ($r = $res->fetch()) {
            $bibdata = unserialize($r['bibdata']);
            unset($r['bibdata']);
            $r['edition'] = $bibdata['item_edition'];
            $r['publisher'] = $bibdata['item_publisher'];
            $r['pubdate'] = $bibdata['item_pubdate'];
            $r['isxn'] = $bibdata['item_isxn'];
//      var_dump($bibdata);die();
            $ret[] = $r;
        }
        return $ret;
    }

    /**
     * @param $course_id
     * @return array
     */
    public function instructor_home($course_id)
    {
        $sql = "
				SELECT
				I.`item_id`
		    ,I.`item_id` AS this_item_id
				,I.`bibdata`
				,I.`title`
				,I.`author`
				,I.`uri`
				,I.`hash`
				,I.`callnumber` AS callnum
				,I.`shorturl` AS purl
				,S.`name` AS status
				,CI.`instance_id`
				,CI.`loanperiod_id`
				,CI.`sequence`
				,I.`physical_format` AS format
				,CI.`required`
				,C.`startdate` AS course_start
				,C.`enddate` AS course_end
				,IFNULL(CI.`startdate`,C.`startdate`) AS request_start
				,IFNULL(CI.`enddate`,C.`enddate`) AS request_end
		    ,(SELECT
		        COUNT(DISTINCT(M.`user_id`))
		      FROM 
		        `metrics` M 
		        JOIN `enrolment` E ON M.`user_id`=E.`user_id`
		        JOIN `role` R ON E.`role_id`=R.`role_id`
		      WHERE 
		        M.`item_id`=this_item_id
		        AND E.`course_id`=:course_id_2
		        AND R.`name`='Student'
		    ) AS times_read
				FROM
				`course_item` CI
				JOIN `course` C USING(`course_id`)
				JOIN `item` I USING(`item_id`)
				JOIN `status` S USING(`status_id`)
				WHERE
				CI.`course_id`=:course_id
    		AND (S.`cancelled`=0 OR S.`status_id`=30)
		    ORDER BY CI.`sequence` ASC
				";
        $items = $this->db->queryAssoc($sql, 'item_id', array(
            'course_id' => $course_id,
            'course_id_2' => $course_id
        ));
        $sql = "
				SELECT 
				CI.`item_id`
				,T.`tag_id`
				,CONCAT('t',MD5(T.`name`)) AS hash
				,TRIM(LEADING '_' FROM T.`name`) AS name
				FROM
				`course_item` CI
				JOIN `tag_item` TI USING(`item_id`)
				JOIN `tag` T USING(`tag_id`,`course_id`)
				WHERE
				CI.`course_id`=:course_id;
				";
        $tags = $this->db->queryRows($sql, compact('course_id'));
        $sql = "
				SELECT
				N.`note_id`
				,N.`content`
				,N.`timestamp`
				,U.`firstname`
				,U.`lastname`
				,U.`user_id`
				,CIN.`item_id`
				,GROUP_CONCAT(R.`role_id` ORDER BY R.`role_id` SEPARATOR ',') AS roles
				FROM
				`course_item_note` CIN
				JOIN `note` N USING(`note_id`)
				JOIN `note_role` NR USING(`note_id`)
				JOIN `role` R USING(`role_id`)
				JOIN `user` U USING(`user_id`)
				JOIN `course_item` CI USING(`course_id`,`item_id`)
				WHERE
				CIN.`course_id`=:course_id
				AND CI.`hidden`=0
				AND R.`name` IN('Student','TA','Library Staff','Instructor')
				GROUP BY N.`note_id`
				";
        $notes = $this->db->queryRows($sql, compact('course_id'));
        // ITEM_ADDITIONALACCESS
        $sql = "
		    SELECT 
  		    IA.`item_id`
		      ,IA.`item_additionalaccess_id`
  		    ,IA.`url`
  		    ,IA.`description`
  		    ,IA.`format`
				FROM 
  		    `item_additionalaccess` IA 
		      JOIN `course_item` CI USING(`item_id`)
				WHERE 
		      CI.`course_id`=:course_id";
        $iaa = $this->db->queryRows($sql, compact('course_id'));
        foreach ($items as $item_id => $item) {
            $items [$item_id] ['tags'] = array();
            $items [$item_id] ['note'] = array();
            $items [$item_id] ['additional_access'] = array();
        }
        foreach ($tags as $tag_info) {
            if (isset ($items [$tag_info ['item_id']])) {
                $items [$tag_info ['item_id']] ['tags'] [$tag_info ['tag_id']] = $tag_info;
            }
        }
        foreach ($notes as $item_id => $note_info) {
            if (isset ($items [$note_info ['item_id']])) {
                $items [$note_info ['item_id']] ['note'] [$note_info ['note_id']] = $note_info;
            }
        }
        foreach ($iaa as $item_id => $additional_access_info) {
            if (isset ($items [$additional_access_info ['item_id']])) {
                $items [$additional_access_info ['item_id']] ['additional_access'] [$additional_access_info ['item_additionalaccess_id']] = $additional_access_info;
            }
        }
        return $items;
    }

    /**
     * @param $course_id
     * @param $puid
     * @return array
     */
    public function student_home($course_id, $puid)
    {
        $user_id = $this->licr->user_mgr->info_by_puid($puid)['user_id'];
        $sql = "
				SELECT
  				I.`item_id`
  		    ,I.`item_id` AS this_item_id
  				,I.`bibdata`
  				,I.`title`
  				,I.`author`
  				,I.`uri`
  				,I.`hash`
  				,I.`callnumber` AS callnum
  				,I.`shorturl` AS purl
  				,S.`name` AS status
  				,CI.`instance_id`
  				,CI.`loanperiod_id`
  				,CI.`sequence`
  				,I.`physical_format` AS format
  				,CI.`required`
  				,C.`startdate` AS course_start
  				,C.`enddate` AS course_end
  				,IFNULL(CI.`startdate`,C.`startdate`) AS request_start
  				,IFNULL(CI.`enddate`,C.`enddate`) AS request_end
          ,(CI.`fairdealing` AND CI.`transactional`) AS encumbered
          ,S.`visible_to_student` AS svts
  		    ,(
  		      SELECT 
  		        COUNT(M.`time`) 
  		      FROM 
  		        `metrics` M 
  		      WHERE  
  		        M.`user_id`=:user_id
  		        AND M.`item_id`=this_item_id
  		    ) AS times_read
				FROM
  				`course_item` CI
  				JOIN `course` C USING(`course_id`)
  				JOIN `item` I USING(`item_id`)
  				JOIN `status` S USING(`status_id`)
				WHERE
				  CI.`course_id`=:course_id
				  AND CI.`hidden`=0
		    HAVING
          request_start < NOW()
          AND
          (
            (
    				  request_end > NOW()
              AND svts=1
            )OR(
              request_end < NOW()
              AND encumbered=0
            )
          )
				ORDER BY CI.`sequence` ASC
				";
        $items = $this->db->queryAssoc($sql, 'item_id', compact('course_id', 'user_id'));
        $sql = "
				SELECT
				CI.`item_id`
				,T.`tag_id`
				,CONCAT('t',MD5(T.`name`)) AS hash
				,TRIM(LEADING '_' FROM T.`name`) AS name
				FROM
				`course_item` CI
				JOIN `tag_item` TI USING(`item_id`)
				JOIN `tag` T USING(`tag_id`,`course_id`)
				JOIN `status` S USING(`status_id`)
				WHERE
				CI.`course_id`=:course_id
				AND CI.`hidden`=0
				AND S.`visible_to_student`=1
				";
        $tags = $this->db->queryRows($sql, compact('course_id'));
        $sql = "
				SELECT
				N.`note_id`
				,N.`content`
				,N.`timestamp`
				,U.`firstname`
				,U.`lastname`
				,U.`user_id`
				,CIN.`item_id`
				,GROUP_CONCAT(R.`role_id` ORDER BY R.`role_id` SEPARATOR ',') AS roles
				FROM
				`course_item_note` CIN
				JOIN `note` N ON CIN.`note_id`=N.`note_id`
				JOIN `note_role` NR ON CIN.`note_id`=NR.`note_id`
				JOIN `role` R ON NR.`role_id`=R.`role_id`
				JOIN `user` U ON N.`user_id`=U.`user_id`
				JOIN `course_item` CI ON CIN.`course_id`=CI.`course_id` AND CIN.`item_id`=CI.`item_id`
				JOIN `status` S ON CI.`status_id`=S.`status_id`
				WHERE
				CIN.`course_id`=:course_id
				AND CI.`hidden`=0
				AND S.`visible_to_student`=1
				AND R.`name` IN('Student')
				GROUP BY N.`note_id`
				";
        $notes = $this->db->queryRows($sql, compact('course_id'));
        // ITEM_ADDITIONALACCESS
        $sql = "
		    SELECT 
  		    IA.`item_id`
		      ,IA.`item_additionalaccess_id`
  		    ,IA.`url`
  		    ,IA.`description`
  		    ,IA.`format`
				FROM 
  		    `item_additionalaccess` IA 
		      JOIN `course_item` CI USING(`item_id`)
				WHERE 
		      CI.`course_id`=:course_id";
        $iaa = $this->db->queryRows($sql, compact('course_id'));
        foreach ($items as $item_id => $item) {
            $items [$item_id] ['tags'] = array();
            $items [$item_id] ['note'] = array();
            $items [$item_id] ['additional_access'] = array();
        }
        foreach ($tags as $tag_info) {
            if (isset ($items [$tag_info ['item_id']])) {
                $items [$tag_info ['item_id']] ['tags'] [$tag_info ['tag_id']] = $tag_info;
            }
        }
        foreach ($notes as $item_id => $note_info) {
            if (isset ($items [$note_info ['item_id']])) {
                $items [$note_info ['item_id']] ['note'] [$note_info ['note_id']] = $note_info;
            }
        }
        foreach ($iaa as $item_id => $additional_access_info) {
            if (isset ($items [$additional_access_info ['item_id']])) {
                $items [$additional_access_info ['item_id']] ['additional_access'] [$additional_access_info ['item_additionalaccess_id']] = $additional_access_info;
            }
        }
        return $items;
    }
}

/**
 * Class LICRCourseItemException
 */
class LICRCourseItemException extends Exception
{
}
