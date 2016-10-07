<?php
require_once('../core/curl.inc.php');
/**
 * licr.class.php
 * Provides high-level functions for the API
 */
if (!defined('LICR_DB_INIT')) {
    $here = dirname(__FILE__) . '/';
    $dbinclude = $here . 'db/';
    require_once($here . '../config.inc.php');
    require_once($dbinclude . 'db.inc.php');
    try {
        $licrdb = new DB ($mysql_dsn, $mysql_user, $mysql_password) or die ('Failed to establish connection to LICR database');
    } catch (PDOException $p) {
        //var_export($p);
        die ('Database connection failure');
    }
    require($here . 'hash.inc.php');
    require($dbinclude . 'history.inc.php');
    foreach (array(
                 'branch',
                 'campus',
                 'course',
                 'course_item',
                 'course_item_note',
                 'email_queue',
                 'enrolment',
                 'tag',
                 'tag_item',
                 'tag_note',
                 'item',
                 'loanperiod',
                 'metrics',
                 'note',
                 'role',
                 'status',
                 'type',
                 'user'
             ) as $class) {
        require("$dbinclude$class.class.php");
    }
    $licr = new LICR ($licrdb, IDBOX_API);
    define('LICR_DB_INIT', TRUE);
}

/**
 * The LICR class provides API functions to api.php.
 * All public methods
 * return either LICR::result or LICR::exception_result which are
 * standard arrays of [success=>boolean,message=>string,data=>mixed (optional)]
 *
 * @package LICRpackage
 */
class LICR
{

    /**
     * Database object
     *
     * @var DB
     */
    public $db;

    /*
* These can't be 'protected' because we use code like e.g. $this->licr->campus_mgr->exists($campus_id) in the 'subclasses'.
*/

    /**
     * @var Branch
     */
    public $branch_mgr;

    /**
     * @var Course
     */
    public $course_mgr;

    /**
     * @var Campus
     */
    public $campus_mgr;

    /**
     * @var CourseItem
     */
    public $course_item_mgr;

    /**
     * @var CourseItemNote
     */
    public $course_item_note_mgr;

    /**
     * @var EmailQueue
     */
    public $email_queue_mgr;
    /**
     * @var Enrolment
     */
    public $enrolment_mgr;

    /**
     * @var Tag
     */
    public $tag_mgr;

    /**
     * @var TagItem
     */
    public $tag_item_mgr;

    /**
     * @var TagNote
     */
    public $tag_note_mgr;

    /**
     * @var Item
     */
    public $item_mgr;

    /**
     * @var Loanperiod
     */
    public $loanperiod_mgr;

    /**
     * @var Metrics
     */
    public $metrics_mgr;

    /**
     * @var Note
     */
    public $note_mgr;

    /**
     * @var Role
     */
    public $role_mgr;

    /**
     * @var Status
     */
    public $status_mgr;

    /**
     * @var Type
     */
    public $type_mgr;

    /**
     * @var User
     */
    public $user_mgr;

    /**
     * @var string
     */
    public $idbox = FALSE;
    public $memcache = FALSE;

    /**
     * Constructor.
     *
     * @param DB $licrdb
     *          database connection to MySQL LICR databse
     * @param string $idbox
     *          URI of IDBox API (or false)
     */
    public function __construct($licrdb, $idbox = FALSE)
    {
        $this->db = $licrdb;

        $this->branch_mgr = new Branch ($this);
        $this->campus_mgr = new Campus ($this);
        $this->course_mgr = new Course ($this);
        $this->course_item_mgr = new CourseItem ($this);
        $this->course_item_note_mgr = new CourseItemNote ($this);
        $this->email_queue_mgr = new EmailQueue ($this);
        $this->enrolment_mgr = new Enrolment ($this);
        $this->tag_mgr = new Tag ($this);
        $this->tag_item_mgr = new TagItem ($this);
        $this->tag_note_mgr = new TagNote ($this);
        $this->item_mgr = new Item ($this);
        $this->loanperiod_mgr = new Loanperiod ($this);
        $this->metrics_mgr = new Metrics ($this);
        $this->note_mgr = new Note ($this);
        $this->role_mgr = new Role ($this);
        $this->status_mgr = new Status ($this);
        $this->type_mgr = new Type ($this);
        $this->user_mgr = new User ($this);

        $this->idbox = $idbox;
        $this->memcache = new Memcached ('LICR' . ENVIRONMENT);
        $sl = $this->memcache->getServerList();
        if (empty ($sl)) {
            $this->memcache->addServer('localhost', 11211);
        }
    }

    public function idboxCall($params)
    {
        if ($this->idbox) {
            $command = $params['command'];
            unset($params['command']);
            $sp = serialize($params);
            // error_log("IDB called with $sp");
            $sph = 'idbox_' . $command . '_' . md5($sp);
            if ($ret = $this->memcache->get($sph)) {
                return $ret;
            }
            $params['command'] = $command;
            $q = curlPost($this->idbox, $params);
            $q = json_decode($q, TRUE);
            if (!isset ($q ['data'])) {
                return FALSE;
            }
            $this->memcache->add($sph, $q ['data'], 600);
            return $q ['data'];
        } else {
            return FALSE;
        }
    }


    /**
     * Return course information given an LMS ID.
     *
     * @param string|bool $courseID
     *
     * @return array
     */
    public function DeleteCourseItemRequests($courseID = false)
    {
        # api.php sets optional fields to null, we have to re-set them to the parameter defaults

        if (!$courseID) {
            return $this->result(FALSE, 'No CourseID was given', 0);
        }

        $course_id = $courseID;

        $sql = "
  SELECT DISTINCT(`item_id`)
  FROM `course_item`
  WHERE `course_id`=:course_id
  ORDER BY `item_id`
";

        $item_ids = $this->db->queryOneColumn($sql, compact('course_id'));
        if (!$item_ids) {
            die("Course is empty.\n");
        }
        //0. identify items that are used in other courses
        $sql = "
  SELECT DISTINCT(`item_id`)
  FROM `course_item` 
  WHERE `course_id`!=:course_id AND `item_id` IN(" . implode(',', $item_ids) . ")
  ORDER BY `item_id`
";

        $dont_delete_item_ids = $this->db->queryOneColumn($sql, compact('course_id'));

        $delete_these = array();
        foreach ($item_ids as $item_id) {
            if (!in_array($item_id, $dont_delete_item_ids)) {
                $delete_these[] = $item_id;
            }
        }

        $whatWasAttempted = "Item IDs\n" . implode(', ', $item_ids) . "\n"
            . "Keep\n" . implode(', ', $dont_delete_item_ids) . "\n"
            . "Delete\n" . implode(', ', $delete_these) . "\n";


        //1. delete attached notes and history

        $sql = "
    DELETE CIN, NR
    FROM
      `course_item_note` CIN
      JOIN `note` N USING(`note_id`)
      JOIN `note_role` NR USING(`note_id`)
    WHERE
      CIN.`course_id`=:course_id
  ";
        $this->db->execute($sql, compact('course_id'));

        $sql = "
    DELETE
    FROM
      `course_item_note`
    WHERE
      `course_id`=:course_id
  ";
        $this->db->execute($sql, compact('course_id'));

        $sql = "
    DELETE H 
    FROM
      `course_item` CI 
      JOIN `history` H ON CI.`instance_id`=H.`id`
    WHERE
      H.`table`='course_item'
      AND CI.`course_id`=:course_id
  ";
        $this->db->execute($sql, compact('course_id'));

        $sql = "
  DELETE TI
  FROM
    `tag` T
    JOIN `tag_item` TI USING(`tag_id`)
  WHERE
    T.`course_id`=:course_id
";
        $this->db->execute($sql, compact('course_id'));

        $sql = "
  DELETE 
  FROM
    `tag`
  WHERE
    `course_id`=:course_id
";
        $this->db->execute($sql, compact('course_id'));

        $sql = "
  DELETE
  FROM
    `history`
  WHERE
    `id`=:course_id
    AND `table`='course'
";
        $this->db->execute($sql, compact('course_id'));

        //3. delete actual course_items

        $sql = "
  DELETE
  FROM
    `course_item`
  WHERE
    `course_id`=:course_id
";
        $this->db->execute($sql, compact('course_id'));

        //2. delete items that can be deleted
        if ($delete_these) {
            $delete_these = implode(',', $delete_these);

            $sql = "
  DELETE
  FROM `history`
  WHERE
    `id` IN($delete_these)
    AND `table`='item'
";
            $this->db->execute($sql);

            $sql = "
  DELETE
  FROM `item_additionalaccess`
  WHERE
    `item_id` IN($delete_these)
";
            $this->db->execute($sql);

            $sql = "
  DELETE
  FROM `metrics`
  WHERE
    `item_id` IN($delete_these)
";
            $this->db->execute($sql);

            $sql = "
  DELETE
  FROM `item`
  WHERE
    `item_id` IN($delete_these)
";
            $this->db->execute($sql);
        }

        return $this->result(TRUE, 'Attempted Delete. Response Returned', ['what_was_attempted' => $whatWasAttempted]);
    }


    /**
     * @param string $firstname
     * @param string $lastname
     * @param string $puid
     * @param string|bool $email
     * @param string|bool $libraryid
     *
     * @return array
     */
    public function CreateUser($firstname, $lastname, $puid, $email = FALSE, $libraryid = FALSE)
    {
        try {
            $user_id = $this->user_mgr->create(array(
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'puid' => $puid,
                'libraryid' => $libraryid
            ));
        } catch (LICRUserException $lue) {
            if ($lue->getCode() == User::EXCEPTION_USER_EXISTS) {
                $info = $this->user_mgr->info_by_puid($puid);
                return $this->result(TRUE, 'User created', $info ['user_id']);
            }
            return $this->exception_result($lue);
        }
        if ($user_id) {
            return $this->result(TRUE, 'User created', $user_id);
        }
        return $this->result(FALSE, 'CreateUser: Failed to create user');
    }

    /**
     * The DB layer allows you to change a puid given a user_id
     * but for sane usage we should treat the puid as immutable,
     * so here it is used as the user identifier
     *
     * @param string $puid
     * @param string $firstname
     *          optional
     * @param string $lastname
     *          optional
     * @param string $email
     *          optional
     */
    public function UpdateUser($puid, $firstname = FALSE, $lastname = FALSE, $email = FALSE, $libraryid = FALSE)
    {
        $info = $this->user_mgr->info_by_puid($puid);
        if (!$info) {
            // change: if user doesn't exist, just create it.
            if ($firstname && $lastname) {
                return $this->CreateUser($firstname, $lastname, $puid, $email, $libraryid);
            }
            return $this->result(FALSE, 'UpdateUser: User not found with PUID ' . $puid);
        }
        $data = array(
            'firstname' => ($firstname ? $firstname : $info ['firstname']),
            'lastname' => ($lastname ? $lastname : $info ['lastname']),
            'email' => ($email ? $email : $info ['email']),
            'puid' => $puid,
            'libraryid' => ($libraryid ? $libraryid : $info ['libraryid'])
        );
        try {
            $res = $this->user_mgr->modify($info ['user_id'], $data);
        } catch (LICRUserException $le) {
            if ($le->getCode() == User::EXCEPTION_NO_CHANGE_SPECIFIED) {
                return $this->result(TRUE, "UpdateUser: No change made");
            } else {
                return $this->exception_result($le);
            }
        }
        if (!$res) {
            return $this->result(FALSE, "UpdateUser: Failed to update user information", 0);
        }
        return $this->result(TRUE, "UpdateUser: Updated user information.", $res);
    }

    /**
     * Get details of a specific user.
     * Note the API uses puid instead of user_id
     * as a security measure, since scanning by sequential numbers would be
     * possible
     *
     * @param string $puid
     * @return array
     */
    public function GetUserInfo($puid)
    {
        $userinfo = $this->user_mgr->info_by_puid($puid);
        $src = 'licr';
        if (!$userinfo) {
            $userinfo = $this->idboxCall(array(
                'command' => 'PersonInfo',
                'puid' => $puid
            ));
            $src = 'idbox';
        }
        if (!$userinfo) {
            return $this->result(FALSE, 'UserInfo: user not found');
        }
        $userinfo ['source'] = $src;
        return $this->result(TRUE, "User information for $puid", $userinfo);
    }

    /**
     * Create a new course
     *
     * @param string $course
     * @param string $title
     * @param string $coursecode
     * @param string $coursenumber
     * @param string $startdate
     * @param string $enddate
     * @param string|int $branch
     * @param string|int $campus
     * @param string $section
     * @return array
     */
    public function CreateCourse($course, $title, $coursecode, $coursenumber, $startdate, $enddate, $branch = FALSE, $campus = FALSE, $section = '*', $seats = 0)
    {
        if (!$branch || $branch == 'Unknown') {
            $branch = $this->idboxCall(array(
                'command' => 'CoursecodeBranches',
                'coursecode' => $coursecode
            ));
        }
        $branch_id = $this->id_of_branch($branch, $campus);
        if (is_array($branch_id)) {
            $branch = $this->idboxCall(array(
                'command' => 'CoursecodeBranches',
                'coursecode' => $coursecode
            ));
            $branch_id = $this->id_of_branch($branch, $campus);
            if (is_array($branch_id)) {
                return $branch_id;
            }
        }
        $ok = FALSE;
        $existing = FALSE;
        $startdate = date('Y-m-d', strtotime($startdate));
        $enddate = date('Y-m-d', strtotime($enddate));
        if ($startdate > $enddate) {
            return $this->result(FALSE, "Start date is after end date.");
        }
        // if this program is still being used in 2100, feel free to
        // change this to 2101-01-01
        // if ($startdate < '2000-01-01' || $startdate > '2100-01-01') {
        // return $this->result(FALSE, "Start date $startdate not in acceptable range.");
        // }
        // if ($enddate < '2000-01-01' || $enddate > '2100-01-01') {
        // return $this->result(FALSE, "End date $enddate out of acceptable range.");
        // }
        if ($startdate < '2001-01-01') {
            $startdate = '2001-01-01';
        }
        if ($enddate > '2100-01-01') {
            $enddate = '2100-01-01';
        }
        try {
            $existing = $this->course_mgr->info($course);
        } catch (LICRCourseException $lce) {
            $ok = TRUE; // this is what we want
        }
        if (!$ok) {
            return $this->UpdateCourse($course, $title, $coursecode, $coursenumber, $startdate, $enddate, $section, $branch, $seats);
        }
        $data = array(
            'default_branch_id' => $branch_id,
            'title' => $title,
            'coursecode' => $coursecode,
            'coursenumber' => $coursenumber,
            'section' => $section,
            'lmsid' => $course,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'active' => 1,
            'seats' => 1 * $seats
        );
        try {
            $res = $this->course_mgr->create($data);
        } catch (LICRCourseException $lce) {
            return $this->exception_result($lce);
        }
        return $this->result(TRUE, 'Course created.', $res);
    }

    /**
     * Copy course items from a given course id into a new course id.
     *
     * @param int $course
     * @return type
     */
    public function CopyCourse($from, $to, $item_id_multi = FALSE)
    {
        if ($item_id_multi) {
            if (!is_array($item_id_multi)) {
                $item_id_multi = explode(',', $item_id_multi);
            }
        } else {
            $item_id_multi = FALSE;
        }
        $count = $this->course_mgr->copy($from, $to, $item_id_multi);
        if ($count === FALSE) {
            return $this->result(FALSE, "LMS ID [$from] or [$to] not found.");
        }
        return $this->result(TRUE, "Course items copied from LMS ID $from to LMS ID $to", $count);
    }

    /**
     * Return course information given an LMS ID.
     *
     * @param string|int $course
     * @return type
     */
    public function GetCourseInfo($course)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "LMS ID $course not found.", 0);
        }
        return $this->result(TRUE, "Course information for LMS ID $course", $course_info);
    }


    /**
     * Return course information given an LMS ID.
     *
     * @param string|int $course
     * @return type
     */
    public function GetCourseItems($status_ids = false, $type_ids = false, $branch_id = false, $isHidden = false, $isArchive = false, $limit = 1000, $offset = 0, $orderBy = '')
    {
        # api.php sets optional fields to null, we have to re-set them to the parameter defaults
        #
        if (empty($branch_id)) {
            $branch_id = false;
        }

        if (empty($limit)) {
            $limit = 1000;
        }

        if (empty($offset)) {
            $offset = 0;
        }

        $course_items = $this->course_item_mgr->getCourseItems($status_ids, $type_ids, $branch_id, $isHidden, $isArchive, $limit, $offset, $orderBy);
        if (!$course_items) {
            return $this->result(FALSE, "No Course Items found within the given parameters", 0);
        }
        return $this->result(TRUE, "Course Items Found", $course_items);
    }


    /**
     * @param string|int $item
     *          Item identifier (hash,id)
     * @return Ambigous <multitype:, multitype:number boolean string mixed >
     */
    public function GetCoursesByItem($item)
    {
        $item_id = $this->id_of_item($item);
        if (is_array($item_id)) {
            return $this->result(FALSE, "ITEM $item not found.");
        }
        $courses = $this->course_item_mgr->courses_by_item_id($item_id);
        return $this->result(TRUE, "Courses using Item $item", $courses);
    }

    /**
     * TODO (returns way too much info)
     *
     * @param string $branch
     */
    public function GetHomepageData($branch = FALSE)
    {
        if ($branch) {
            $branch_id = $this->id_of_branch($branch);
            if (is_array($branch_id)) {
                return $branch_id;
            }
        } else {
            $branch_id = NULL;
        }
        $course_items = $this->course_item_mgr->get_list($branch_id);
        return $this->result(TRUE, "Course items for location [$branch]", $course_items);
    }

    public function GetHomepageDataNewAndInProcess($branch = FALSE)
    {
        if ($branch) {
            $branch_id = $this->id_of_branch($branch);
            if (is_array($branch_id)) {
                return $branch_id;
            }
        } else {
            $branch_id = NULL;
        }
        $course_items = $this->course_item_mgr->get_new_and_inprocess($branch_id);
        return $this->result(TRUE, "Current course items for location [$branch] with New or InProcess status", $course_items);
    }

    public function GetHomepageDataComplete($branch = FALSE)
    {
        if ($branch) {
            $branch_id = $this->id_of_branch($branch);
            if (is_array($branch_id)) {
                return $branch_id;
            }
        } else {
            $branch_id = NULL;
        }
        $course_items = $this->course_item_mgr->get_complete($branch_id);
        return $this->result(TRUE, "Current course items for location [$branch] with Complete status", $course_items);
    }

    public function GetHomepageDataArchive($branch = FALSE)
    {
        if ($branch) {
            $branch_id = $this->id_of_branch($branch);
            if (is_array($branch_id)) {
                return $branch_id;
            }
        } else {
            $branch_id = NULL;
        }
        $course_items = $this->course_item_mgr->get_archive($branch_id);
        return $this->result(TRUE, "Archive course items for location [$branch]", $course_items);
    }

    /**
     * Returns array of [category][type]=count
     *
     * @return Ambigous <multitype:, multitype:number boolean string mixed >
     */
    public function GetCICounts()
    {
        $ret = $this->course_item_mgr->get_counts();
        return $this->result(TRUE, 'Course item counts', $ret);
    }

    public function Enrolment($puid)
    {
        $user_id = $this->id_of_user($puid);
        if (!$user_id) {
            return $this->result(FALSE, "User $puid not found");
        }
        $res = $this->enrolment_mgr->user_courses($puid);
        return $this->result(TRUE, "Enrolment for $puid", $res);
    }

    /**
     * @param string|int $course
     * @param string $title
     * @param string $coursecode
     * @param string $coursenumber
     * @param string $startdate
     * @param string $enddate
     * @param string $section
     * @return array
     */
    public function UpdateCourse($course, $title = NULL, $coursecode = NULL, $coursenumber = NULL, $startdate = NULL, $enddate = NULL, $section = NULL, $branch = NULL, $seats = NULL)
    {
        $current_info = $this->course_mgr->info($course);
        if (!$current_info) {
            return $this->result(FALSE, 'Course not found');
        }
        foreach (array(
                     'title',
                     'coursecode',
                     'coursenumber',
                     'startdate',
                     'enddate',
                     'section',
                     'seats'
                 ) as $field) {
            if ($$field) {
                $current_info [$field] = $$field;
            }
        }
        $default_branch_id = $this->id_of_branch($branch);
        if (!is_array($default_branch_id)) {
            $current_info ['default_branch_id'] = $default_branch_id;
        } else {
            $current_info ['default_branch_id'] = FALSE;
        }
        try {
            $course_id = $current_info ['course_id'];
            unset ($current_info ['course_id']);
            $res = $this->course_mgr->modify($course_id, $current_info);
        } catch (LICRCourseException $lce) {
            if ($lce->getCode() == 304) {
                return $this->result(TRUE, "Course not modified", 1);
            }
            // var_dump($current_info);
            return $this->exception_result($lce);
        }
        if ($res !== FALSE) {
            return $this->result(TRUE, "Course updated.", 1);
        }
        return $this->result(FALSE, 'Failed to update course');
    }

    /**
     * Mark course active (i.e.
     * shows to instructors).
     *
     * @todo automatically mark courses inactive after session end date
     * @param string|int $course
     * @return array
     */
    public function ActivateCourse($course)
    {
        $info = $this->course_mgr->info($course);
        if (!$info) {
            return $this->result(FALSE, "Course [$course] not found or no information");
        }
        $course_id = $info ['course_id'];
        try {
            $result = $this->course_mgr->activate($course_id);
        } catch (LICRCourseException $lce) {
            return $this->exception_result($lce);
        }
        if ($result) {
            return $this->result(TRUE, "Course [$course] activated.");
        }
        return $this->result(FALSE, "Failed to activate course [$course].");
    }

    /**
     * Mark course inactive (use this to "delete" a course)
     *
     * @param string|int $course
     * @return array
     */
    public function DeactivateCourse($course)
    {
        $info = $this->course_mgr->info($course);
        if (!$info) {
            return $this->result(FALSE, "Course [$course] not found or no information");
        }
        $course_id = $info ['course_id'];
        try {
            $result = $this->course_mgr->deactivate($course_id);
        } catch (LICRCourseException $lce) {
            return $this->exception_result($lce);
        }
        if ($result) {
            return $this->result(TRUE, "Course [$course] deactivated.");
        }
        return $this->result(FALSE, "Failed to deactivate course [$course]");
    }

    /**
     * List all items in a course, optionally with the given status
     * optionally with visible items(1), notvisible(0), or all (null)
     *
     * @param string|int $course
     * @param boolean $approvedonly
     * @param string|int $status_id_or_name
     * @return type
     */
    public function ListCIs($course, $visible = NULL, $status = 0)
    {
        $status_id = 0;
        if ($status) {
            $status_id = $this->id_of_status($status);
            if (is_array($status_id)) {
                return $status_id;
            }
        }
        $info = $this->course_mgr->info($course);
        if (!$info) {
            return $this->result(FALSE, "Course [$course] not found or no information");
        }
        $course_id = $info ['course_id'];
        $items = $this->course_item_mgr->items_in_course($course_id, $status_id, $visible);
        return $this->result(TRUE, 'Items', $items);
    }

    /**
     * List all items in a course, optionally with the given status
     *
     * @param string|int $course
     * @param boolean $approvedonly
     * @param string|int $status_id_or_name
     * @return type
     */
    public function ListStudentCIs($course)
    {
        $info = $this->course_mgr->info($course);
        if (!$info) {
            return $this->result(FALSE, "Course [$course] not found or no information");
        }
        $course_id = $info ['course_id'];
        $items = $this->course_item_mgr->student_items_in_course($course_id);
        return $this->result(TRUE, 'Items', $items);
    }

    function ListInstructorCIs($course)
    {
        $info = $this->course_mgr->info($course);
        if (!$info) {
            return $this->result(FALSE, "Course [$course] not found or no information");
        }
        $course_id = $info ['course_id'];
        $items = $this->course_item_mgr->instructor_items_in_course($course_id);
        return $this->result(TRUE, 'Items', $items);
    }

    /**
     * List the librarian(s) associated to a course (via the coursecode)
     *
     * @param string|int $course
     * @return array
     */
    public function ListCourseLibrarians($course)
    {
        $info = $this->course_mgr->info($course);
        if (!$info) {
            return $this->result(FALSE, "Course [$course] not found or no information");
        }
        $coursecode = $info ['coursecode'];
        $librarians = $this->idboxCall(array(
            'command' => 'CoursecodeLibrarians',
            'coursecode' => $coursecode
        ));
        return $this->result(TRUE, "Librarians for course [$course] (coursecode [$coursecode])", $librarians);
    }

    /**
     * Returns an array of tags given a course
     *
     * @param string|int $course
     * @return array
     */
    public function ListTags($course, $student = 1)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $tags = $this->tag_mgr->list_by_course($course_id, $student);
        return $this->result(TRUE, "Tags in course [$course]", $tags);
    }

    /**
     * Enrol a user
     *
     * @param string|int $course
     * @param string $puid
     * @param string|int $role
     * @return array
     */
    public function Register($course, $puid_or_libraryid, $role, $sis_role = 'UBC_Student')
    {
        if (preg_match('/^[0-9]+$/', $puid_or_libraryid)) { // a library number
            $puid = $this->user_mgr->puid_by_libraryid($puid_or_libraryid);
            if (!$puid) {
                return $this->result(FALSE, "Register: PUID not found for library ID  [$puid_or_libraryid]");
            }
        } else {
            $puid = $puid_or_libraryid;
        }
        $enrollee_info = $this->user_mgr->info_by_puid($puid);
        $course_info = $this->course_mgr->info($course);
        if (!$enrollee_info) {
            return $this->result(FALSE, "Register: No information for user [$puid]");
        }
        $user_id = $enrollee_info ['user_id'];
        if (!$course_info) {
            return $this->result(FALSE, "Register: No information for course [$course]");
        }
        $course_id = $course_info ['course_id'];
        $role_id = $this->id_of_role($role);
        if (is_array($role_id)) {
            return $role_id;
        }
        try {
            $result = $this->enrolment_mgr->enrol($course_id, $user_id, $role_id, $sis_role);
        } catch (LICREnrolmentException $lee) {
            return $this->exception_result($lee);
        }
        if ($result) {
            return $this->result(TRUE, "Registered [$puid] in course [$course] with role [$role]");
        }
        return $this->result(FALSE, "Register: Failed to register user [$puid] in course [$course] with role [$role]");
    }

    /**
     * Enrol (multiple) user(s)
     *
     * @param string|int $course
     * @param string $id_multi
     * @param string|int $role
     * @return array
     */
    public function RegisterMultiple($course, $id_multi, $role, $sis_role = 'UBC_Student')
    {
        $ids = preg_split('/(\s+|,)/', $id_multi);
        $ids = (array)$ids;
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Register: No information for course [$course]");
        }
        $course_id = $course_info ['course_id'];
        $role_id = $this->id_of_role($role);
        if (is_array($role_id)) {
            return $role_id;
        }
        $results = ['success' => [], 'fail' => []];
        foreach ($ids as $id) {
            if (preg_match('/^[0-9]+$/', $id)) { // a library number
                $puid = $this->user_mgr->puid_by_libraryid($id);
                if (!$puid) {
                    $results['fail'][] = "No PUID found for Library ID " . $id . ".";
                    continue;
                }
            } else {
                $puid = $id;
            }
            $enrollee_info = $this->user_mgr->info_by_puid($puid);
            if (!$enrollee_info) {
                $results['fail'][] = "No information for " . $id . ".";
                continue;
            }
            $user_id = $enrollee_info ['user_id'];
            try {
                $result = $this->enrolment_mgr->enrol($course_id, $user_id, $role_id, $sis_role);
            } catch (LICREnrolmentException $lee) {
                $results['fail'] = "Failed to register " . $id . " in course " . $course . " (Exception: " . $lee->getMessage() . ").";
            }
            if ($result) {
                $results['ok'][] = $id . " registered into course " . $course;
            } else {
                $results['fail'] = "Failed to register " . $id . " in course " . $course . ".";
            }
        }
        if ($results['fail']) {
            return $this->result(FALSE, "Register failed for some IDs:\n" . implode("\n", $results['fail']));
        } else {
            return $this->result(TRUE, "All IDs registered.");
        }
    }

    /**
     * Unenrol user from course
     *
     * @param string $course
     * @param string $puid
     * @return array
     */
    public function Deregister($course, $puid)
    {
        $enrollee_info = $this->user_mgr->info_by_puid($puid);
        $course_info = $this->course_mgr->info($course);
        if (!$enrollee_info) {
            return $this->result(FALSE, "Deregister: No information for user [$puid]");
        }
        $user_id = $enrollee_info ['user_id'];
        if (!$course_info) {
            return $this->result(FALSE, "Deregister: No information for course [$course]");
        }
        $course_id = $course_info ['course_id'];
        try {
            $result = $this->enrolment_mgr->unenrol($course_id, $user_id);
        } catch (LICREnrolmentException $lee) {
            return $this->exception_result($lee);
        }
        if ($result) {
            return $this->result(TRUE, 'Deregister: User deregistered.');
        }
        return $this->result(FALSE, "Deregister: Failed to deregister user [$puid] from course [$course]");
    }

    /**
     * Create a new item.
     * $bibdata is (probably) a serialized record
     * as returned by the catalog
     *
     * @param string $title
     * @param string $callnumber
     * @param string $bibdata
     * @param string $uri
     * @param string|int $type
     * @param string $filelocation
     * @param string $citation
     * @param
     *          string external_store to handle docstore items (method TBD)
     * @return array
     */
    public function CreateItem($title, $callnumber, $bibdata, $uri, $type /* must map to type_id */
        , $filelocation, $author, $citation = '', $physical_format = NULL, $dedupe = TRUE)
    {
        $type_id = $this->id_of_type($type);
        if (is_array($type_id)) {
            return $type_id;
        }
        $data = array(
            'title' => $title,
            'callnumber' => $callnumber,
            'bibdata' => $bibdata,
            'uri' => $uri,
            'type_id' => $type_id,
            'filelocation' => $filelocation,
            'author' => $author,
            'citation' => $citation,
            'physical_format' => $physical_format
        );
        try {
            $result = $this->item_mgr->create($data, $dedupe);
        } catch (LICRItemException $lie) {
            return $this->exception_result($lie);
        }
        if ($result) {
            return $this->result(TRUE, 'CreateItem: Item Created', $result);
        }
        return $this->result(FALSE, "CreateItem: Failed to create item");
    }

    /**
     * @param int $item_id
     * @param string $title
     * @return array
     */
    public function SetItemTitle($item_id, $title)
    {
        return $this->UpdateItem($item_id, $title);
    }

    public function SetItemAuthor($item_id, $author)
    {
        return $this->UpdateItem($item_id, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, $author);
    }

    public function SetItemDoNotCheck($item_id, $boolean)
    {
        return $this->result(TRUE, 'setbool', $this->item_mgr->set_do_not_check($item_id, $boolean));
    }

    /**
     * @param int $item_id
     * @param string $callnumber
     * @return array
     */
    public function SetItemCallNumber($item_id, $callnumber)
    {
        return $this->UpdateItem($item_id, NULL, $callnumber);
    }

    public function SetCIRequired($course, $item, $required)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $item_id = $this->id_of_item($item);
        if (is_array($item_id)) {
            return $item_id;
        }
        $res = $this->course_item_mgr->set_required($course_id, $item_id, $required);
        return $this->result(TRUE, "SetItemRequired Course [$course_id] item [$item_id] [$required]", $res);
    }

    /**
     * @param int $item_id
     * @param string $bibdata
     * @return array
     */
    public function SetItemBibData($item_id, $bibdata)
    {
        return $this->UpdateItem($item_id, NULL, NULL, $bibdata, NULL, NULL, NULL, NULL, NULL);
    }

    /**
     * @param int $item_id
     * @param string $uri
     * @return array
     */
    public function SetItemURI($item_id, $uri)
    {
        return $this->UpdateItem($item_id, NULL, NULL, NULL, $uri);
    }

    /**
     * @param int $item_id
     * @param string|int $type_name_or_id
     * @return type
     */
    public function SetItemType($item_id, $type)
    {
        $type_id = $this->id_of_type($type);
        if (is_array($type_id)) {
            return $type_id;
        }
        return $this->UpdateItem($item_id, NULL, NULL, NULL, NULL, $type_id);
    }

    /**
     * @param int $item_id
     * @param string $filelocation
     * @return array
     */
    public function SetItemFileLocation($item_id, $filelocation)
    {
        return $this->UpdateItem($item_id, NULL, NULL, NULL, NULL, NULL, $filelocation);
    }

    /**
     * @param int $item_id
     * @param string $title
     * @param string $callnumber
     * @param string $bibdata
     * @param string $uri
     * @param string|int $type_id_or_name
     * @param string $filelocation
     * @return array
     */
    public function UpdateItem($item_id, $title = NULL, $callnumber = NULL, $bibdata = NULL, $uri = NULL, $type = NULL, $filelocation = NULL, $citation = NULL, $physical_format = NULL, $author = NULL)
    {
        $current_info = $this->item_mgr->info($item_id);
        if (!$current_info) {
            return $this->result(FALSE, 'Item not found');
        }

        # var exports
        #@file_put_contents(__DIR__ . '/_export.log', json_encode(['$current_info' => $current_info]) . PHP_EOL , FILE_APPEND);

        // if a type has been passed by name to update, find the id
        $type_id = FALSE;
        if ($type) {
            $type_id = $this->id_of_type($type);
            if (is_array($type_id)) {
                return $type_id;
            }
        }

        #@file_put_contents(__DIR__ . '/_export.log', json_encode(['$type_id' => $type_id]) . PHP_EOL , FILE_APPEND);

        $data = array();
        foreach (array(
                     'title',
                     'callnumber',
                     'bibdata',
                     'uri',
                     'type_id',
                     'filelocation',
                     'citation',
                     'physical_format',
                     'author'
                 ) as $field) {
            if ($$field) {
                $data [$field] = $$field;
            } else {
                $data [$field] = $current_info [$field];
            }
            // var_export($data);
        }

        #@file_put_contents(__DIR__ . '/_export.log', json_encode(['$data' => $data]) . PHP_EOL , FILE_APPEND);

        // return $this->result ( FALSE, $current_info['bibdata'] );
        try {
            $res = $this->item_mgr->modify($item_id, $data);
        } catch (LICRItemException $lie) {
            return $this->exception_result($lie);
        }
        if ($res) {
            return $this->result(TRUE, "Item modified", $res);
        }
        return $this->result(TRUE, 'Item not modified', $res);
    }

    public function UpdateItemPhysicalFormat($item_id, $course, $type_id, $physical_format)
    {
        $current_info = $this->item_mgr->info($item_id);
        if (!$current_info) {
            return $this->result(FALSE, 'Item not found');
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        try {
            $res = $this->item_mgr->change_physical_format($item_id, $course_id, $type_id, $physical_format);
        } catch (LICRItemException $lie) {
            return $this->exception_result($lie);
        }
        if ($res) {
            return $this->result(TRUE, "Item modified", $res);
        }
        return $this->result(TRUE, 'Item not modified', $res);
    }

    /**
     * @param int $item_id
     * @return array
     */
    public function GetItemInfo($item_id)
    { // now may be an array or CSV or course number prepended with 'c'
        $info = $this->item_mgr->info($item_id);
        if ($info) {
            return $this->result(TRUE, 'Item', $info);
        }
        return $this->result(FALSE, "Item [$item_id] not found");
    }

    public function GetCIInfo($course, $item_id = FALSE)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        if ($item_id && !$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item not found");
        }
        try {
            $info = $this->course_item_mgr->info($course_id, $item_id);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Course-item", $info);
    }

    /**
     * i.e.
     * add an item to a course
     *
     * @param string $course
     * @param int $item_id
     * @param string|int $loanperiod
     * @param string $requestor
     *          puid of requesting instructor
     */
    public function RequestItem($course, $item_id, $loanperiod, $requestor, $startdate = NULL, $enddate = NULL)
    {
        $requestor_info = $this->user_mgr->info_by_puid($requestor);
        if (!$requestor_info) {
            $requestor_info = $this->user_mgr->info_by_puid('ARESIMPORT');
            // return $this->result ( FALSE, "Requesting user [$requestor] not found" );
        }
        $requestor_id = $requestor_info ['user_id'];
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item not found");
        }
        $loanperiod_id = $this->id_of_loanperiod($loanperiod);
        if (is_array($loanperiod_id)) {
            return $loanperiod_id;
        }
        try {
            $res = $this->course_item_mgr->request($course_id, $item_id, $loanperiod_id, $requestor_id, $startdate, $enddate);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        if ($res) {
            return $this->result(TRUE, "Item modified", $res);
        }
        return $this->result(FALSE, "Error requesting item.", 0);
    }

    /**
     * @param string|int $course
     * @param int $item_id
     * @return array
     */
    public function DerequestItem($course, $item_id)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item not found");
        }
        try {
            $cancelled_status = $this->status_mgr->id_by_name('Request Cancelled by Instructor');
            $res = $this->course_item_mgr->update_status($course_id, $item_id, $cancelled_status);
            // $res = $this->course_item_mgr->deactivate ( $course_id, $item_id );
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        if ($res) {
            return $this->result(TRUE, "Item cancelled");
        }
        return $this->result(FALSE, "Error cancelling item.");
    }

    public function SetCIHidden($course, $item_id, $hidden)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item not found");
        }
        try {
            $res = $this->course_item_mgr->hidden($course_id, $item_id, $hidden);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        if ($res) {
            return $this->result(TRUE, "Item $item_id hidden in course $course");
        }
        return $this->result(FALSE, "Error hiding item.");
    }

    /**
     * supply item_ids in desired sequence
     *
     * @param string|int $course
     * @param string|array $item_id_list
     * @return array
     */
    public function SetCISequence($item_id_multi, $course)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        if (!is_array($item_id_multi)) {
            if (!preg_match('/[0-9]+(,[0-9]+)*/', $item_id_multi)) {
                return $this->result(FALSE, 'Please supply comma-separated item IDs');
            }
            $item_id_multi = explode(',', $item_id_multi);
        }
        try {
            $this->course_item_mgr->sequence($course_id, $item_id_multi);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE);
    }

    /**
     * @param string|int $course
     * @param int $item_id
     * @param string|int $status
     * @return array
     */
    public function SetCIStatus($course, $item_id, $status)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found", 0);
        }
        $course_id = $course_info ['course_id'];
        $status_id = $this->id_of_status($status);
        if (is_array($status_id)) {
            return $status_id;
        }
        $data = array(
            'status_id' => $status_id
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Status set to [$status]", 1);
    }

    /**
     * Set the "fair dealing" flag
     *
     * @param string|int $course
     * @param int $item_id
     * @param boolean $boolean
     * @return array
     */
    public function SetCIFairDealing($course, $item_id, $boolean)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        $data = array(
            'fairdealing' => $boolean
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item fair dealing set to " . ($boolean ? 'Yes' : 'No'));
    }

    /**
     * Set the "transactional" flag
     *
     * @param string|int $course
     * @param int $item_id
     * @param boolean $boolean
     * @return array
     */
    public function SetCITransactional($course, $item_id, $boolean)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        $data = array(
            'transactional' => $boolean
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item transactional set to " . ($boolean ? 'Yes' : 'No'));
    }

    /**
     * @param string|int $course
     * @param int $item_id
     * @param string|int $branch
     * @param string|int $campus
     *          optional, only if branch is not branch_id
     * @return array
     */
    public function SetCIBranch($course, $item_id, $branch, $campus = FALSE)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course [$course] not found");
        }
        $course_id = $course_info ['course_id'];
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] not found");
        }
        $branch_id = $this->id_of_branch($branch, $campus);
        if (is_array($branch_id)) {
            return $branch_id;
        }
        $data = array(
            'branch_id' => $branch_id
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item branch set to $branch", 1);
    }

    /*
* location is freetext. Arguably, probably, location should be a property of an item, not a course_item.
*/
    public function SetCILocation($course, $item_id, $location, $campus = FALSE)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course [$course] not found");
        }
        $course_id = $course_info ['course_id'];
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] not found");
        }
        $data = array(
            'location' => $location
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item location set to $location", 1);
    }

    public function SetCIDates($course, $item_id, $startdate, $enddate)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course [$course] not found");
        }
        $course_id = $course_info ['course_id'];
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] not found");
        }
        $data = array(
            'startdate' => $startdate,
            'enddate' => $enddate
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item dates set to $startdate - $enddate", 1);
    }

    /**
     * @param string|int $course
     * @param int $item_id
     * @param string|int $branch
     * @param string|int $campus
     * @return array
     */
    public function SetCIProcessingBranch($course, $item_id, $branch, $campus = FALSE)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        $branch_id = $this->id_of_branch($branch, $campus);
        if (is_array($branch_id)) {
            return $branch_id;
        }
        $data = array(
            'processing_branch_id' => $branch_id
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item processing branch set to $branch", 1);
    }

    /**
     * @param string|int $course
     * @param int $item_id
     * @param string|int $branch
     * @param string|int $campus
     * @return array
     */
    public function SetCIPickupBranch($course, $item_id, $branch, $campus = FALSE)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        $branch_id = $this->id_of_branch($branch, $campus);
        if (is_array($branch_id)) {
            return $branch_id;
        }
        $data = array(
            'pickup_branch_id' => $branch_id
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item pickup branch set to $branch", 1);
    }

    /**
     * @param string|int $course
     * @param int $item_id
     * @param string $loanperiod
     * @return array
     */
    public function SetCILoanPeriod($course, $item_id, $loanperiod)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        $loanperiod_id = $this->id_of_loanperiod($loanperiod);
        if (is_array($loanperiod_id)) {
            return $loanperiod_id;
        }
        $data = array(
            'loanperiod_id' => $loanperiod_id
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item loan period set to $loanperiod", 1);
    }

    /**
     * A 'range' is a page range, set of chapters, etc.
     *
     * @param string|int $course
     * @param int $item_id
     * @param string $range
     *          e.g. 'Chapter 1', 'pp.32-37'
     */
    public function SetCIRange($course, $item_id, $range)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        $data = array(
            'range' => $range
        );
        try {
            $this->course_item_mgr->modify($course_id, $item_id, $data);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Item range set to $range");
    }

    /**
     * @param int $item_id
     * @param string $citation
     */
    public function SetItemCitation($item_id, $citation)
    {
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item $item_id not found");
        }
        try {
            $this->item_mgr->modify($item_id, array(
                'citation' => $citation
            ));
        } catch (LICRCourseItemException $lie) {
            return $this->exception_result($lie);
        }
        return $this->result(TRUE, "Item citation set.");
    }

    /**
     * Add a new item type to the system.
     * Admin only; use sparingly
     *
     * @param string $name
     * @return array
     */
    public function CreateType($name, $physical)
    {
        try {
            $type_id = $this->type_mgr->create($name, $physical);
        } catch (LICRTypeException $lte) {
            return $this->exception_result($lte);
        }
        return $this->result(TRUE, 'Type', $type_id);
    }

    /**
     * Change name for this type
     *
     * @param string|int $type
     * @param string $name
     * @return type
     */
    public function RenameType($type, $name)
    {
        $type_id = $this->id_of_type($type);
        if (is_array($type_id)) {
            return $type_id;
        }
        try {
            $res = $this->type_mgr->update($type_id, $name);
        } catch (LICRTypeException $lte) {
            return $this->exception_result($lte);
        }
        return $this->result(TRUE, "Updated type $type", $res);
    }

    /**
     * Probably never use this
     *
     * @param string|int $type
     * @return array
     */
    public function DeleteType($type)
    {
        $type_id = $this->id_of_type($type);
        if (is_array($type_id)) {
            return $type_id;
        }
        try {
            $res = $this->type_mgr->delete($type_id);
        } catch (LICRTypeException $lte) {
            return $this->exception_result($lte);
        }
        return $this->result($res);
    }

    public function CreateTransition($current_status, $next_status)
    {
        $current_status_id = $this->id_of_status($current_status);
        if (is_array($current_status_id)) {
            return $current_status_id;
        }
        $next_status_id = $this->id_of_status($next_status);
        if (is_array($next_status_id)) {
            return $next_status_id;
        }
        try {
            $this->status_mgr->create_transition($current_status_id, $next_status_id);
        } catch (LICRStatusException $lse) {
            return $this->exception_result($lse);
        }
        return $this->result(TRUE, "Transition created");
    }

    public function GetNextStatuses($status)
    {
        $status_id = $this->id_of_status($status);
        if (is_array($status_id)) {
            return $status_id;
        }
        $next_statuses = $this->status_mgr->next_status_options($status_id);
        return $this->result(TRUE, 'Next statuses', $next_statuses);
    }

    public function GetNextStatusesByCategory($category = 'New')
    {
        if (!trim($category)) {
            $category = 'New';
        }
        $next_statuses = $this->status_mgr->next_status_options_from_category($category);
        return $this->result(TRUE, 'Next statuses', $next_statuses);
    }

    public function DeleteTransition($current_status, $next_status)
    {
        $current_status_id = $this->id_of_status($current_status);
        if (is_array($current_status_id)) {
            return $current_status_id;
        }
        $next_status_id = $this->id_of_status($next_status);
        if (is_array($next_status_id)) {
            return $next_status_id;
        }
        try {
            $this->status_mgr->delete_transition($current_status_id, $next_status_id);
        } catch (LICRStatusException $lse) {
            return $this->exception_result($lse);
        }
        return $this->result(TRUE, 'Transition deleted');
    }

    /**
     * @param string $name
     * @return array
     */
    public function CreateRole($name)
    {
        try {
            $role_id = $this->role_mgr->create($name);
        } catch (LICRRoleException $lte) {
            return $this->exception_result($lte);
        }
        return $this->result(TRUE, 'Role', $role_id);
    }

    /**
     * @param string|int $role
     * @param string $name
     * @return array
     */
    public function RenameRole($role, $name)
    {
        $role_id = $this->id_of_role($role);
        if (is_array($role_id)) {
            return $role_id;
        }
        try {
            $res = $this->role_mgr->rename($role_id, $name);
        } catch (LICRRoleException $lte) {
            return $this->exception_result($lte);
        }
        return $this->result(TRUE, "Renamed to $name", $res);
    }

    /**
     * @param string|int $role
     * @return array
     */
    public function DeleteRole($role)
    {
        $role_id = $this->id_of_role($role);
        if (is_array($role_id)) {
            return $role_id;
        }
        try {
            $res = $this->role_mgr->delete($role_id);
        } catch (LICRTypeException $lte) {
            return $this->exception_result($lte);
        }
        return $this->result(TRUE, "Deleted", $res);
    }

    /**
     * @param string $name
     * @return array
     */
    public function CreateStatus($name, $category = 'InProcess', $visible_to_student = FALSE)
    {
        try {
            $status_id = $this->status_mgr->create($name, $category, $visible_to_student);
        } catch (LICRStatusException $lse) {
            return $this->exception_result($lse);
        }
        return $this->result(TRUE, 'Status', $status_id);
    }

    /**
     * @param string|int $status
     * @param string $name
     * @return array
     */
    public function UpdateStatus($status, $name = NULL, $category = NULL, $visible_to_student = NULL)
    {
        $status_id = $this->id_of_status($status);
        if (is_array($status_id)) {
            return $status_id;
        }
        try {
            $res = $this->status_mgr->rename($status_id, $name);
        } catch (LICRStatusException $lse) {
            return $this->exception_result($lse);
        }
        return $this->result($res);
    }

    /**
     * @param string|int $status
     * @return array
     */
    public function DeleteStatus($status)
    {
        $status_id = $this->id_of_status($status);
        if (is_array($status_id)) {
            return $status_id;
        }
        try {
            $res = $this->status_mgr->delete($status_id);
        } catch (LICRStatusException $lse) {
            return $this->exception_result($lse);
        }
        return $this->result($res);
    }

    /**
     * @param string|int $course
     * @param string $name
     * @param string $startdate
     * @param string $enddate
     * @return type
     */
    public function CreateTag($course, $name, $startdate = FALSE, $enddate = FALSE)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course not found");
        }
        $course_id = $course_info ['course_id'];
        try {
            $tag_id = $this->tag_mgr->create($name, $course_id, $startdate, $enddate);
        } catch (LICRTagException $lge) {
            return $this->exception_result($lge);
        }
        return $this->result(TRUE, 'Tag created', $tag_id);
    }

    /**
     * @param string|int $tag
     * @param string|int $course
     * @param string $name
     * @param string $startdate
     * @param string $enddate
     * @return array
     */
    public function UpdateTag($tag, $course = FALSE, $name = NULL, $startdate = NULL, $enddate = NULL)
    {
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        try {
            $res = $this->tag_mgr->update($tag_id, $name, $startdate, $enddate);
        } catch (LICRTagException $lge) {
            return $this->exception_result($lge);
        }
        return $res;
    }

    /**
     * @param int $item_id
     * @param string|int $tag
     * @param string|int $course
     *          optional if tag is tag_id
     * @return array
     */
    public function AddItemToTag($item_id, $tag, $course = FALSE)
    {
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] does not exist");
        }
        try {
            $res = $this->tag_item_mgr->add_item($item_id, $tag_id);
        } catch (LICRTagItemException $lgie) {
            return $this->exception_result($lgie);
        }
        return $this->result(TRUE, "Added.", $res);
    }

    public function AddItemAlternateURL($item_id, $url, $description, $format)
    {
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] not found", 0);
        }
        $alt_url_id = $this->item_mgr->add_alternate_url($item_id, $description, $url, $format);
        if ($alt_url_id) {
            return $this->result(TRUE, "Alternate access URL added", $alt_url_id);
        } else {
            return $this->result(FALSE, "Error, alternate URL not added", 0);
        }
    }

    public function UpdateItemAlternateURL($alternate_url_id, $url = FALSE, $description = FALSE, $format = FALSE)
    {
        try {
            $success = $this->item_mgr->update_alternate_url($alternate_url_id, $url, $description, $format);
        } catch (LICRItemException $lie) {
            return $this->exception_result($lie);
        }
        if ($success) {
            return $this->result(TRUE, "Alternate access URL updated", 1);
        } else {
            return $this->result(FALSE, "Error, alternate URL not updated", 0);
        }
    }

    public function DeleteItemAlternateURL($alternate_url_id)
    {
        $this->item_mgr->delete_alternate_url($alternate_url_id);
        return $this->result(TRUE, "Alternate access URL deleted", 0);
    }

    /**
     * @param int $item_id
     * @param string|int $tag
     * @param string|int $course
     *          optional
     * @return array
     */
    public function DeleteItemFromTag($item_id, $tag, $course = FALSE)
    {
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] does not exist");
        }
        try {
            $res = $this->tag_item_mgr->remove_item($item_id, $tag_id);
        } catch (LICRTagItemException $ltie) {
            return $this->exception_result($ltie);
        }
        return $this->result(TRUE, "Deleted.", $res);
    }

    /**
     * supply item_ids in desired sequence
     *
     * @param string|array $item_id_multi
     * @param string|int $tag
     * @param string|int $course
     *          optional
     * @return array
     */
    public function SetTagItemSequence($item_id_multi, $tag, $course = FALSE)
    {
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        if (!is_array($item_id_multi)) {
            if (!preg_match('/[0-9]+(,[0-9]+)*/', $item_id_multi)) {
                return $this->result(FALSE, 'Please supply comma-separated item IDs');
            }
            $item_id_multi = explode(',', $item_id_multi);
        }
        try {
            $this->tag_item_mgr->sequence($tag_id, $item_id_multi);
        } catch (LICRTagItemException $lgie) {
            return $this->exception_result($lgie);
        }
        return $this->result(TRUE);
    }

    /**
     * @param string|int $tag
     * @param string|int $course
     * @return array
     */
    function ListTagItems($tag, $course = FALSE)
    {
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        $items = $this->tag_item_mgr->list_items($tag_id);
        return $this->result(TRUE, 'Tag items', $items);
    }

    /**
     * @param string $campus_name
     * @return type
     */
    public function CreateCampus($campus_name)
    {
        try {
            $campus_id = $this->campus_mgr->create($campus_name);
        } catch (LICRCampusException $lce) {
            return $this->exception_result($lce);
        }
        if ($campus_id === FALSE) {
            return $this->result(FALSE, "Failed to create campus");
        }
        return $this->result(TRUE, "Created campus [$campus_name]", array(
            'campus_id' => $campus_id
        ));
    }

    /**
     * @param string|int $campus
     * @return array
     */
    public function DeleteCampus($campus)
    {
        $campus_id = $this->id_of_campus($campus);
        if (is_array($campus_id)) {
            return $campus_id;
        }
        try {
            $res = $this->campus_mgr->delete($campus_id);
        } catch (LICRCampusException $lce) {
            return $this->exception_result($lce);
        }
        if (!$res) {
            return $this->result(FALSE, "Failed to delete campus.");
        }
        return $this->result(TRUE, "Deleted campus [$campus]");
    }

    /**
     * @param string $name
     * @param string|int $campus
     * @return array
     */
    function RenameCampus($name, $campus)
    {
        $campus_id = $this->id_of_campus($campus);
        if (is_array($campus_id)) {
            return $campus_id;
        }
        try {
            $result = $this->campus_mgr->update($campus_id, $name);
        } catch (LICRCampusException $lce) {
            return exception_result($lce);
        }
        if ($result) {
            return $this->result(TRUE);
        }
        return $this->result(FALSE, "RenameCampus: Failed to change campus name");
    }

    /**
     * @param string $name
     * @param string|int $campus
     * @return array
     */
    public function CreateBranch($name, $campus)
    {
        $branch_id = $this->id_of_branch($name, $campus);
        if (!is_array($branch_id)) {
            return $this->result(FALSE, "Branch [$name] already exists.");
        }
        $campus_id = $this->id_of_campus($campus);
        if (is_array($campus_id)) {
            return $campus_id;
        }
        try {
            $branch_id = $this->branch_mgr->create($name, $campus_id);
        } catch (LICRBranchException $lbe) {
            return $this->exception_result($lbe);
        }
        return $this->result(TRUE, "Created branch [$name] in $campus.", $branch_id);
    }

    /**
     * @param string $name
     * @param string|int $branch
     * @param string|int $campus
     * @return array
     */
    public function RenameBranch($name, $branch, $campus = FALSE)
    {
        $branch_id = $this->id_of_branch($branch, $campus);
        if (is_array($branch_id)) {
            return $branch_id;
        }
        try {
            $result = $this->branch_mgr->update($branch_id, $name);
        } catch (LICRBranchException $lbe) {
            return exception_result($lbe);
        }
        if ($result) {
            return $this->result(TRUE, "Branch renamed to [$name]");
        }
        return $this->result(FALSE, "RenameBranch: Failed to change branch name");
    }

    /**
     * @param string|int $branch
     * @param string|int $campus
     * @param string|int $new_campus
     * @return type
     */
    public function MoveBranch($branch, $campus, $new_campus)
    {
        $branch_id = $this->id_of_branch($branch, $campus);
        if (is_array($branch_id)) {
            return $branch_id;
        }
        $new_campus_id = $this->id_of_campus($new_campus);
        if (is_array($new_campus_id)) {
            return $new_campus_id;
        }
        try {
            $result = $this->branch_mgr->update($branch_id, NULL, $new_campus_id);
        } catch (LICRBranchException $lbe) {
            return exception_result($lbe);
        }
        if ($result) {
            return $this->result(TRUE, "Branch moved to [$new_campus]");
        }
        return $this->result(FALSE, "MoveBranch: Failed to change campus of branch");
    }

    /**
     * @param string|int $branch
     * @param string|int $campus
     * @return array
     */
    function DeleteBranch($branch, $campus = FALSE)
    {
        $branch_id = $this->id_of_branch($branch, $campus);
        if (is_array($branch_id)) {
            return $branch_id;
        }
        try {
            $result = $this->branch_mgr->delete($branch_id);
        } catch (LICRBranchException $lbe) {
            return exception_result($lbe);
        }
        if ($result) {
            return $this->result(TRUE, "Deleted $branch.");
        }
        return $this->result(FALSE, "DeleteBranch: Failed to delete branch name");
    }

    /**
     * @param string $puid
     * @param int $item_id
     *          (or hash)
     * @return array
     */
    public function RegisterClick($puid, $item_id)
    {
        $user_info = $this->user_mgr->info_by_puid($puid);
        if (!$user_info) {
            return $this->result(FALSE, 'User not found');
        }
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, 'Item not found');
        }
        try {
            $this->metrics_mgr->click($user_info ['user_id'], $item_id);
        } catch (LICRMetricsException $lme) {
            return $this->exception_result($lme);
        }
        return $this->result(TRUE);
    }

    public function CourseReadsAll($course)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $res = $this->metrics_mgr->course_reads_all($course_id);
        return $this->result(TRUE, "Reading statistics for course [$course] (unresricted)", $res);
    }

    public function CourseReadsEnrolled($course)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $res = $this->metrics_mgr->course_reads_enrolled($course_id);
        return $this->result(TRUE, "Reading statistics for course [$course]", $res);
    }

    public function ItemReadsEnrolled($course, $item_id)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] does not exist", 0);
        }
        if (!$this->course_item_mgr->exists($course_id, $item_id)) {
            return $this->result(FALSE, "Item [$item_id] not found in course [$course]", 0);
        }
        $res = $this->metrics_mgr->item_reads_enrolled($course_id, $item_id);
        return $this->result(TRUE, "Item reads for item [$item_id] in course [$course]", $res);
    }

    public function ItemReadsEnrolledSummary($course, $item_id)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] does not exist", 0);
        }
        if (!$this->course_item_mgr->exists($course_id, $item_id)) {
            return $this->result(FALSE, "Item [$item_id] not found in course [$course]", 0);
        }
        $res = $this->metrics_mgr->item_reads_enrolled_summary($course_id, $item_id);
        return $this->result(TRUE, "Item reads for item [$item_id] in course [$course]", $res);
    }

    public function ItemReadsAll($item_id)
    {
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item [$item_id] does not exist", 0);
        }
        $res = $this->metrics_mgr->item_reads_all($item_id);
        return $this->result(TRUE, "Item reads for item [$item_id]", $res);
    }

    public function UserHasRead($puid, $item_id)
    {
        $user_info = $this->user_mgr->info_by_puid($puid);
        if (!$user_info) {
            return $this->result(FALSE, "User [$puid] not found.");
        }
        $user_id = $user_info ['user_id'];
        $res = $this->metrics_mgr->user_read_item($user_id, $item_id);
        return $this->result(TRUE, "User [$puid] has " . ($res ? '' : 'not ') . "read item [$item_id]", $res);
    }

    public function UserReadInCourse($puid, $course)
    {
        $user_info = $this->user_mgr->info_by_puid($puid);
        if (!$user_info) {
            return $this->result(FALSE, "User [$puid] not found.");
        }
        $user_id = $user_info ['user_id'];
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        if (!$this->enrolment_mgr->enrolment_status($course_id, $user_id)) {
            return $this->result(FALSE, "User [$puid] is not enrolled in course [$course]");
        }
        return $this->result(TRUE, "Completed course readings for user [$puid] in course [$course]", $this->metrics_mgr->user_read_course($user_id, $course_id));
    }

    /**
     * @return array
     */
    public function ListRoles()
    {
        $roles = $this->role_mgr->list_all();
        return $this->result(TRUE, 'Roles', $roles);
    }

    /**
     * @return array
     */
    public function ListStatuses()
    {
        $statuses = $this->status_mgr->list_all();
        return $this->result(TRUE, 'Statuses', $statuses);
    }

    /**
     * @return array
     */
    public function ListTypes()
    {
        $types = $this->type_mgr->list_all();
        return $this->result(TRUE, 'Types', $types);
    }

    /**
     * @return array
     */
    public function ListCampuses()
    {
        $campuses = $this->campus_mgr->list_all();
        return $this->result(TRUE, 'Campuses', $campuses);
    }

    /**
     * If no campus supplied, all branches are listed
     *
     * @param string|int $campus
     * @return array
     */
    public function ListBranches($campus = FALSE)
    {
        $campus_id = FALSE;
        if ($campus) {
            $campus_id = $this->id_of_campus($campus);
            if (is_array($campus_id)) {
                return $campus_id;
            }
        }
        $branches = $this->campus_mgr->list_branches($campus_id);
        return $this->result(TRUE, 'Branches', $branches);
    }

    /**
     * @param string|int $course
     * @param string|int $role
     * @return array
     */
    public function ListUsers($course, $role = FALSE)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, "Course [$course] not found");
        }
        $course_id = $course_info ['course_id'];
        if ($role) {
            $role_id = $this->id_of_role($role);
            if (is_array($role_id)) {
                return $role_id;
            }
        } else {
            $role_id = FALSE;
        }
        $users = $this->enrolment_mgr->users_in_course($course_id, $role_id);
        return $this->result(TRUE, 'Users', $users);
    }

    /**
     * @return array
     */
    public function ListCourses($start = 0, $perpage = 20, $current = TRUE)
    {
        $courses = $this->course_mgr->list_all($start, $perpage, $current);
        return $this->result(TRUE, 'Courses', $courses);
    }

    /**
     * @param int $item_id
     * @param string|array $roles_multi
     *          string is csv
     * @param
     *          string|int course
     * @return array
     */
    public function GetCINotes($roles_multi, $course, $item_id = FALSE)
    {
        if ($item_id && !$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, 'Item not found');
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        if (!is_array($roles_multi)) {
            $roles = preg_split('/\s*[,' . ASCII_US . ']\s*/', trim($roles_multi));
        } else {
            $roles = $roles_multi;
        }
        foreach ($roles as $i => $role) {
            $roles [$i] = $this->id_of_role($role);
            if (is_array($roles [$i])) {
                return $roles [$i];
            }
        }
        $notes = $this->course_item_note_mgr->get_notes($item_id, $course_id, $roles);
        return $this->result(TRUE, 'Notes', $notes);
    }

    /**
     * @param string|int $course
     * @param string|int $tag
     * @param string|array $roles_multi
     *          string is csv
     * @return type
     */
    public function GetTagNotes($roles_multi, $tag, $course = FALSE)
    {
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        if (!is_array($roles_multi)) {
            $roles = preg_split('/\s*[,' . ASCII_US . ']\s*/', trim($roles_multi));
        } else {
            $roles = $roles_multi;
        }
        foreach ($roles as $i => $role) {
            $roles [$i] = $this->id_of_role($role);
            if (is_array($roles [$i])) {
                return $roles [$i];
            }
        }
        foreach ($roles as $role_id) {
            $notes [] = $this->tag_note_mgr->get_notes($tag_id, $role_id);
        }
        return $this->result(TRUE, 'Notes', $notes);
    }

    public function GetTagInfo($tag, $course = FALSE)
    {
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        $info = $this->tag_mgr->info($tag_id);
        return $this->result(TRUE, "Tag information for [$tag]", $info);
    }

    /**
     * @param int $instance_id
     * @return array
     */
    public function ResolveInstanceID($instance_id)
    {
        try {
            $data = $this->course_item_mgr->resolve_instance_id($instance_id);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        if ($data) {
            return $this->result(TRUE, "Instance Resolved", $data);
        }
        return $this->result(FALSE, "Instance not found", 0);
    }

    /**
     * a PURL is of the form [icg].XXXXXX
     *
     * @param string $hash
     * @return array
     */
    public function GetByHash($hash)
    {
        $m = array();
        if (!preg_match('/([ict])\.([a-zA-Z0-9]{6})/', $hash, $m)) {
            return $this->result(FALSE, "Invalid short code");
        }
        $table = $m [1];
        $hash = $m [2];
        $data = FALSE;
        switch ($table) {
            case 'i' :
                $data = $this->item_mgr->info($hash);
                break;
            case 'c' :
                $data = $this->course_mgr->info($hash);
                break;
            case 't' :
                $data = $this->tag_mgr->info($hash);
                break;
        }
        if (!$data) {
            return $this->result(FALSE, "Failed to find object corresponding to short code [$table.$hash]");
        }
        return $this->result(TRUE, "Found", $data);
    }

    public function GetRole($puid, $course)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $user_info = $this->user_mgr->info_by_puid($puid);
        if (!$user_info) {
            return $this->result(FALSE, "User $puid not found.");
        }
        $user_id = $user_info ['user_id'];
        $res = $this->enrolment_mgr->enrolment_status($course_id, $user_id);
        return $this->result(TRUE, 'Role and Active Status', $res);
    }

    public function GetPUID($libraryid)
    {
        $puid = $this->user_mgr->puid_by_libraryid($libraryid);
        if ($puid) {
            return $this->result(TRUE, "PUID for Library ID [$libraryid]", array(
                'puid' => $puid
            ));
        } else {
            return $this->result(FALSE, "No PUID found for Library ID [$libraryid]", 0);
        }
    }

    /**
     * @param string $content
     * @param string|array $roles_multi
     * @param int $item_id
     * @param string|int $course
     * @return array
     */
    public function AddCINote($author_puid, $content, $roles_multi, $item_id, $course, $time = NULL)
    {
        $author_info = $this->user_mgr->info_by_puid($author_puid);
        if (!$author_info) {
            return $this->result(FALSE, "Note author PUID [$author_puid] not found");
        }
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item not found");
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        if (!$this->course_item_mgr->exists($course_id, $item_id)) {
            return $this->result(FALSE, "Item [$item_id] is not in course [$course].");
        }
        if (!is_array($roles_multi)) {
            $roles = preg_split('/\s*[,' . ASCII_US . ']\s*/', trim($roles_multi));
        } else {
            $roles = $roles_multi;
        }
        $set_status = FALSE;
        foreach ($roles as $i => $role) {
            if ($roles[$i] == 'Library Staff') {
                $set_status = TRUE;
            }
            $roles [$i] = $this->id_of_role($role);
            if (is_array($roles [$i])) {
                return $this->result(FALSE, "Bad role $role");
            }
        }
        try {
            $note_id = $this->note_mgr->create($author_info ['user_id'], $content, $time);
            $this->course_item_note_mgr->add($item_id, $course_id, $note_id, $roles);
        } catch (LICRCourseItemNoteException $lcine) {
            return $this->exception_result($lcine);
        } catch (LICRNoteException $lne) {
            return $this->exception_result($lne);
        }
        //B1 (LICR-179)
        if ($set_status) {
            $set_status = FALSE;
            $info = $this->item_mgr->info($item_id);
            $type_id = $info['type_id'];
            $physical = $this->type_mgr->is_physical($type_id);
            if (!$physical) {
                $cinfo = $this->course_mgr->info($course_id);
                foreach ($cinfo['instructors'] as $iinfo) {
                    if ($iinfo['puid'] == $author_puid) {
                        $set_status = TRUE;
                    }

                }
                if ($set_status) {
                    $new_status = $this->status_mgr->id_by_name('Non-Physical with Processing Note');
                    if ($new_status) {
                        $this->course_item_mgr->update_status($course_id, $item_id, $new_status);
                    }
                }
            }
        }
        return $this->result(TRUE, "Note added.", 1);
    }

    /**
     * @param string $content
     * @param string|array $roles_multi
     * @param string|int $tag
     * @param string|int $course
     * @return array
     */
    public function AddTagNote($author_puid, $content, $roles_multi, $tag, $course = FALSE, $time = NULL)
    {
        $author_info = $this->user_mgr->info_by_puid($author_puid);
        if (!$author_info) {
            return $this->result(FALSE, "Note author PUID [$author_puid] not found");
        }
        $tag_id = $this->id_of_tag($tag, $course);
        if (is_array($tag_id)) {
            return $tag_id;
        }
        if (!is_array($roles_multi)) {
            $roles = preg_split('/\s*[,' . ASCII_US . ']\s*/', trim($roles_multi));
        } else {
            $roles = $roles_multi;
        }
        foreach ($roles as $i => $role) {
            $roles [$i] = $this->id_of_role($role);
            if (is_array($roles [$i])) {
                return $this->result(FALSE, "Bad role $role");
            }
        }
        try {
            $note_id = $this->note_mgr->create($author_info ['user_id'], $content, $time);
            $this->tag_note_mgr->add($tag_id, $note_id, $roles);
        } catch (LICRTagNoteException $lgne) {
            return $this->exception_result($lgne);
        } catch (LICRNoteException $lne) {
            return $this->exception_result($lne);
        }
        return $this->result(TRUE, "Note added.");
    }

    /**
     * works for tag and item notes
     *
     * @param int $note_id
     * @param string $content
     * @param string|array $roles_multi
     * @return array
     */
    public function UpdateNote($note_id, $content = FALSE, $roles_multi = FALSE)
    {
        if (!$this->note_mgr->exists($note_id)) {
            return $this->result(FALSE, "Note [$note_id] not found");
        }

        if ($roles_multi) {
            if (!is_array($roles_multi)) {
                $roles = preg_split('/\s*[,' . ASCII_US . ']\s*/', trim($roles_multi));
            } else {
                $roles = $roles_multi;
            }
            foreach ($roles as $i => $role) {
                $roles [$i] = $this->id_of_role($role);
                if (is_array($roles [$i])) {
                    return $this->result(FALSE, "Bad role $role");
                }
            }
        }
        if ($content) {
            try {
                $this->note_mgr->updateContent($note_id, $content);
            } catch (LICRNoteException $lne) {
                return $this->exception_result($lne);
            }
        }
        if ($roles) {
            try {
                $this->note_mgr->updateRoles($note_id, $roles);
            } catch (LICRNoteException $lne) {
                return $this->exception_result($lne);
            }
        }

        return $this->result(TRUE, "Note $note_id updated.");
    }

    /**
     * @param int $note_id
     * @return array
     */
    public function DeleteNote($note_id)
    {
        try {
            $this->note_mgr->delete($note_id);
        } catch (LICRNoteException $lne) {
            return $this->exception_result($lne);
        }
        return $this->result(TRUE, "Note [$note_id] deleted.");
    }

    public function SearchItems($search_string, $branch_ids = NULL, $status_ids = NULL, $type_ids = NULL)
    {
        $res = $this->item_mgr->search($search_string, $branch_ids, $status_ids, $type_ids);
        return $this->result(TRUE, 'Item search result', $res);
    }

    public function SearchUsers($search_string, $course = FALSE, $sis_role = FALSE)
    {
        if ($course) {
            $course_id = $this->id_of_course($course);
            if (is_array($course_id)) {
                return $course_id;
            }
        } else {
            $course_id = -1;
        }
        $res = $this->user_mgr->search($search_string, $course_id, $sis_role);
        return $this->result(TRUE, 'User search result', $res);
    }

    public function SearchCourses($search_string, $current = TRUE, $activeonly = TRUE)
    {
        $res = $this->course_mgr->search($search_string, $current, $activeonly);
        return $this->result(TRUE, 'Course search result', $res);
    }

    public function ListUserCourses($puid)
    {
        if (!$this->user_mgr->info_by_puid($puid)) {
            return $this->result(FALSE, "User with puid [$puid] not found.");
        }
        $res = $this->enrolment_mgr->user_courses($puid);
        return $this->result(TRUE, "Course enrolment for user $puid", $res);
    }

    public function ListCourseEnrolment($course)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $student_role_id = $this->role_mgr->id_by_name('Student');
        $res = $this->enrolment_mgr->users_in_course($course_id, $student_role_id);
        return $this->result(TRUE, "Users enrolled in course $course", $res);
    }

    public function ListUserContacts($puid)
    {
        if (!$this->user_mgr->info_by_puid($puid)) {
            return $this->result(FALSE, "User with puid [$puid] not found.");
        }
        $depts = $this->enrolment_mgr->user_departments($puid);
        $contacts = array();
        if ($this->idbox) {
            foreach ($depts as $d) {
                $contacts [$d] = array();
                $librarians = $this->idboxCall(array(
                    'command' => 'CoursecodeLibrarians',
                    'coursecode' => $d
                ));
                foreach ($librarians as $r) {
                    $contacts [$d] [] = array(
                        'name' => $r ['firstname'] . ' ' . $r ['lastname'],
                        'email' => $r ['email']
                    );
                }
            }
        }
        ksort($contacts);
        return $this->result(TRUE, "Library contacts", $contacts);
    }

    public function SetCIField($course, $item_id, $field, $value)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        if (!$this->item_mgr->exists($item_id)) {
            return $this->result(FALSE, "Item with ID [$item_id] does not exist.");
        }
        try {
            $res = $this->course_item_mgr->set_field($course_id, $item_id, $field, $value);
        } catch (LICRCourseItemException $lcie) {
            return $this->exception_result($lcie);
        }
        return $this->result(TRUE, "Course_Item.$field updated for course $course, item $item_id", $res);
    }

    public function ListLoanPeriods()
    {
        return $this->result(TRUE, "Loan Periods", $this->loanperiod_mgr->list_all());
    }

    public function Subscribe($puid, $course)
    {
        if (!$this->user_mgr->info_by_puid($puid)) {
            return $this->result(FALSE, "User with puid [$puid] not found.");
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $res = $this->enrolment_mgr->subscribe($puid, $course_id);
        return $this->result(TRUE, "Subscription result", $res);
    }

    public function Unsubscribe($puid, $course)
    {
        if (!$this->user_mgr->info_by_puid($puid)) {
            return $this->result(FALSE, "User with puid [$puid] not found.");
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $res = $this->enrolment_mgr->unsubscribe($puid, $course_id);
        return $this->result(TRUE, "Subscription result", $res);
    }

    public function IsSubscribed($puid, $course)
    {
        if (!$this->user_mgr->info_by_puid($puid)) {
            return $this->result(FALSE, "User with puid [$puid] not found.");
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $res = $this->enrolment_mgr->is_subscribed($puid, $course_id);
        return $this->result(TRUE, "Subscription result", $res);
    }

    public function RefreshNames()
    {
        // utility function to de-crappify names from CTC3
        $res = $this->user_mgr->refreshnames();
        $ret = $this->result(TRUE, "Names updated.", $res);
        // var_dump($ret);
        return $ret;
    }

    /**
     * @param string $table
     *          Table referred to by history entry
     * @param string|int $id
     *          ID (e.g. item id or user PUID)
     * @return Ambigous <multitype:, multitype:number boolean string mixed >
     */
    public function GetHistory($table, $id)
    {
        switch ($table) {
            case 'user' :
                $info = $this->id_of_user($id);
                if ($info) {
                    $id = $info ['user_id'];
                }
                break;
            case 'course' :
                $info = $this->id_of_course($id);
                if ($info) {
                    $id = $info ['course_id'];
                }
                break;
            case 'item' :
                $info = $this->id_of_item($id);
                if (!is_numeric($info)) {
                    return $this->result(FALSE, 'Item not found', 0);
                }
                $id = $info;
                break;
        }

        $res = history_get($table, $id);
        return $this->result(TRUE, "History for $table/$id", $res);
    }

    public function AddHistory($puid, $table, $id, $message, $time = NULL)
    {
        history_add($table, $id, $message, $puid, $time, TRUE);
        return $this->result(TRUE, 'Added.', 1);
    }

    public function CreateProgram($name, $gradyear)
    {
        try {
            $program_id = $this->course_mgr->program_create($name, $gradyear);
        } catch (LICRCourseException $lce) {
            return $this->exception_result($lce);
        }
        return $this->result(TRUE, "Program [$name] created.", $program_id);
    }

    public function UpdateProgram($id, $name, $gradyear)
    {
        try {
            $success = $this->course_mgr->program_update($id, $name, $gradyear);
        } catch (LICRCourseException $lce) {
            return $this->exception_result($lce);
        }
        return $this->result(TRUE, "Program [$id] updated.", $success);
    }

    public function DeleteProgram($program)
    {
        $program_id = $this->id_of_program($program);
        $res = $this->course_mgr->program_delete($program_id);
        if ($res) {
            return $this->result(TRUE, "Program [$program] deleted.", $res);
        } else {
            return $this->result(FALSE, "Failed to delete program [$program]");
        }
    }

    public function AddCourseToProgram($program, $course)
    {
        $program_id = $this->id_of_program($program);
        if (is_array($program_id)) {
            return $program_id;
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        try {
            $success = $this->course_mgr->program_add_course($program_id, $course_id);
        } catch (LICRCourseException $lce) {
            return $this->exception_result($lce);
        }
        return $this->result(TRUE, "Add course [$course] to program [$program]", $success);
    }

    public function RemoveCourseFromProgram($program, $course)
    {
        $program_id = $this->id_of_program($program);
        if (is_array($program_id)) {
            return $program_id;
        }
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        try {
            $success = $this->course_mgr->program_delete_course($program_id, $course_id);
        } catch (LICRCourseException $lce) {
            return $this->exception_result($lce);
        }
        return $this->result(TRUE, "Removed course [$course] from program [$program]", $success);
    }

    public function GetEnrolledPrograms($puid)
    {
        $programs = $this->course_mgr->program_get_enrolled($puid);
        return $this->result(TRUE, "Program enrollment for [$puid]", $programs);
    }

    public function ListAllPrograms()
    {
        $programs = $this->course_mgr->program_list_all();
        return $this->result(TRUE, "All defined programs", $programs);
    }

    public function GetProgramInfo($program)
    {
        $program_id = $this->id_of_program($program);
        if (is_array($program_id)) {
            return $program_id;
        }
        $res = $this->course_mgr->program_info($program_id);
        return $this->result(TRUE, "Program information", $res);
    }

    public function ListProgramCourses($program)
    {
        $program_id = $this->id_of_program($program);
        if (is_array($program_id)) {
            return $program_id;
        }
        $courses = $this->course_mgr->program_list_courses($program_id);
        return $this->result(TRUE, "Courses in program [$program]", $courses);
    }

    public function ListProgramTags($program)
    {
        $program_id = $this->id_of_program($program);
        if (is_array($program_id)) {
            return $program_id;
        }
        $tags = $this->tag_mgr->list_program_tags($program_id);
        return $this->result(TRUE, "Tags in program [$program]", $tags);
    }

    public function ListProgramCIsByTag($program, $tag_name)
    {
        $program_id = $this->id_of_program($program);
        if (is_array($program_id)) {
            return $program_id;
        }
        $course_items = $this->tag_mgr->list_program_items($program_id, $tag_name);
        return $this->result(TRUE, "Course items in program [$program]", $course_items);
    }

    public function GetProgramsByCourse($course)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $program_ids = $this->course_mgr->program_from_course($course_id);
        return $this->result(TRUE, "Programs containing course [$course]", $program_ids);
    }

    public function MakeBBURL($hash)
    {
        if (!preg_match('/^[ict]\.[b-zB-Z0-9]{6}$/', $hash)
            && !preg_match('/^p\.[0-9]+$/', $hash)
        ) {
            return $this->result(FALSE, "Invalid hash");
        }
        $bburl = make_short_BB_url($hash);
        return $this->result(TRUE, "Short URL for hash [$hash]", $bburl);
    }

    public function PrependedURL($url)
    {
        $prepended = CRRESOLVE . urlencode(BB1 . urlencode(BB2 . urlencode($url)));
        return $this->result(TRUE, "Prepended URL", $prepended);
    }

    public function ListBrokenItems()
    {
        return $this->result(TRUE, 'Items with broken URLs', $this->item_mgr->list_broken());
    }

    public function CountBrokenItems()
    {
        return $this->result(TRUE, 'Number of items with broken URLs', $this->item_mgr->count_broken());
    }

    // meta functions to move processing to server
    public function CRInstructorHome($course_id)
    {
        return $this->result(TRUE, "Instructor item view for course $course_id", $this->course_item_mgr->instructor_home($course_id));
    }

    public function CRStudentHome($course_id, $puid)
    {
        return $this->result(TRUE, "Student item view for course $course_id", $this->course_item_mgr->student_home($course_id, $puid));
    }

    //Reporting
    public function Report_Physical($course)
    {
        $course_id = $this->id_of_course($course);
        if (is_array($course_id)) {
            return $course_id;
        }
        $res = $this->course_item_mgr->report_physical($course_id);
        return $this->result(TRUE, "Physical items in course [$course]", $res);
    }


    // ///////////////////////////////PRIVATE//////////////////////////
    /**
     * @param string|int $item_type
     * @return int
     */
    private function id_of_type($item_type)
    {
        if (!is_numeric($item_type)) {
            $type_id = $this->type_mgr->id_by_name($item_type);
            if ($type_id === FALSE) {
                return $this->result(FALSE, "Type [$item_type] not found.");
            }
        } else {
            $type_id = $item_type;
            if (!$this->type_mgr->exists($type_id)) {
                return $this->result(FALSE, "Type [$item_type] not found.");
            }
        }
        return $type_id;
    }

    /**
     * @param string|int $item_type
     * @return int
     */
    private function id_of_loanperiod($loanperiod)
    {
        if (!is_numeric($loanperiod)) {
            $loanperiod_id = $this->loanperiod_mgr->id_by_name($loanperiod);
            if ($loanperiod_id === FALSE) {
                return $this->result(FALSE, "Loan period [$loanperiod] not found (!Num).");
            }
        } else {
            $loanperiod_id = $loanperiod;
            if (!$this->loanperiod_mgr->exists($loanperiod)) {
                return $this->result(FALSE, "Loan period [$loanperiod] not found (Num).");
            }
        }
        return $loanperiod_id;
    }

    /**
     * @param string|int $role
     * @return int
     */
    private function id_of_role($role)
    {
        if (!is_numeric($role)) {
            $role_id = $this->role_mgr->id_by_name($role);
            if ($role_id === FALSE) {
                return $this->result(FALSE, "Role [$role] not found.");
            }
        } else {
            $role_id = $role;
            if (!$this->role_mgr->name_by_id($role_id)) {
                return $this->result(FALSE, "Role [$role] not found.");
            }
        }
        return $role_id;
    }

    /**
     * @param string|int $status
     * @return int
     */
    private function id_of_status($status)
    {
        if (!is_numeric($status)) {
            $status_id = $this->status_mgr->id_by_name($status);
            if ($status_id === FALSE) {
                return $this->result(FALSE, "Status [$status] not found.");
            }
        } else {
            $status_id = $status;
            if (!$this->status_mgr->exists($status_id)) {
                return $this->result(FALSE, "Status [$status] not found.");
            }
        }
        return $status_id;
    }

    /**
     * @param string|int $campus
     * @return int
     */
    private function id_of_campus($campus)
    {
        if (is_numeric($campus)) {
            $campus_id = $campus;
            if (!$this->campus_mgr->exists($campus_id)) {
                return $this->result(FALSE, "Campus not found");
            }
        } else {
            $campus_id = $this->campus_mgr->id_by_name($campus);
            if (!$campus_id) {
                return $this->result(FALSE, "Campus not found");
            }
        }
        return $campus_id;
    }

    /**
     * @param string|int $branch_name
     * @param string|int $campus_name
     * @return int
     */
    private function id_of_branch($branch_name, $campus_name = FALSE)
    {
        if (is_numeric($branch_name)) {
            $branch_id = $branch_name;
            if (!$this->branch_mgr->exists($branch_id)) {
                return $this->result(FALSE, "Branch $branch_name not found");
            }
        } else {
            try {
                $branch_id = $this->branch_mgr->get_id($branch_name, $campus_name);
            } catch (LICRBranchException $lbe) {
                return $this->exception_result($lbe);
            }
            if (!$branch_id) {
                return $this->result(FALSE, "Branch $branch_name not found (2)");
            }
        }
        return $branch_id;
    }

    /**
     * @param string|int $tag
     * @param string|int $course
     * @return int
     */
    private function id_of_tag($tag, $course = FALSE)
    {
        if (is_numeric($tag)) {
            $tag_id = $tag;
            if (!$this->tag_mgr->exists($tag_id)) {
                return $this->result(FALSE, "Tag not found.");
            }
        } else {
            $course_id = $this->id_of_course($course);
            if (is_array($course_id)) {
                return $course_id;
            }
            try {
                $tag_info = $this->tag_mgr->info_by_course_id_and_name($course_id, $tag);
            } catch (LICRTagException $lge) {
                return $this->exception_result($lge);
            }
            $tag_id = $tag_info ['tag_id'];
        }
        return $tag_id;
    }

    /**
     * @param string|int $course
     * @return int
     */
    private function id_of_course($course)
    {
        $course_info = $this->course_mgr->info($course);
        if (!$course_info) {
            return $this->result(FALSE, 'Course not found');
        }
        $course_id = $course_info ['course_id'];
        return $course_id;
    }

    /**
     * @param string|int $program
     * @return int
     */
    private function id_of_program($program)
    {
        if ($this->course_mgr->program_exists($program)) {
            return $program;
        }
        $program_id = $this->course_mgr->program_find($program);
        if (!$program_id) {
            return $this->result(FALSE, 'Program not found');
        }
        return $program_id;
    }

    /**
     * @param string|int $item
     * @return int
     */
    private function id_of_item($item)
    {
        $item_info = $this->item_mgr->info($item);
        if (!$item_info) {
            return $this->result(FALSE, 'Item not found');
        }
        $item_id = $item_info ['item_id'];
        return $item_id;
    }

    /**
     * @param string|int $user
     * @return int
     */
    private function id_of_user($user)
    {
        $user_info = $this->user_mgr->info_by_puid($user);
        if (!$user_info) {
            $user_info = $this->user_mgr->info_by_id($user);
        }
        $user_id = $user_info ['user_id'];
        return $user_id;
    }

    /**
     * @param Exception $e
     * @return array
     */
    private function exception_result($e)
    {
        return array(
            'success' => FALSE,
            'message' => $e->getMessage(),
            'code' => $e->getCode()
        );
    }

    /**
     * Generic result structure
     *
     * @param boolean $success
     * @param string $message
     * @param mixed $data
     * @return array
     */
    private function result($success, $message = '', $data = FALSE)
    {
        $ret = array(
            'success' => $success,
            'message' => $message,
            'code' => 0,
            'data' => $data
        );
        return $ret;
    }

    /** One-off report for LOCRSUPP-339 **/
    function eBooksReport()
    {
        $sql = "
        SELECT item.item_id, item.title as item_title, item.author, 
item.bibdata, item.uri, item.physical_format, item.filelocation, item.shorturl, 
course.course_id, course.title as course_title, course.section, course.lmsid, course.startdate, course.enddate
        FROM `item` , `course_item`, `course`
        WHERE `physical_format` LIKE '%ebook%'
        AND item.item_id = course_item.item_id
        AND course.course_id = course_item.course_id
        ORDER BY item.item_id";
        $res = $this->db->queryRows($sql);
        return $this->result(TRUE, 'eBook report', $res);
    }

}

