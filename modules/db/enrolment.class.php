<?php
class Enrolment {
  const EXCEPTION_USER_NOT_FOUND = 501;
  const EXCEPTION_COURSE_NOT_FOUND = 502;
  const EXCEPTION_ROLE_NOT_FOUND = 503;
  const EXCEPTION_NO_EMAIL = 504;
  
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
  function __construct($licr) {
    $this->licr = $licr;
    $this->db = $licr->db;
  }
  
  /**
   *
   * @param int $course_id          
   * @param int $user_id          
   * @param int $role_id          
   * @return int
   */
  function enrol($course_id, $user_id, $role_id, $sis_role = 'UBC_Student') {
    $sql = "SELECT COUNT(*) FROM `user` WHERE `user_id`=? LIMIT 1";
    $num = $this->db->queryOneVal ( $sql, $user_id );
    if (! $num) {
      throw new LICREnrolmentException ( "Enrolment::enrol No user with ID [$user_id] exists.", self::EXCEPTION_USER_NOT_FOUND );
    }
    $sql = "SELECT COUNT(*) FROM `course` WHERE `course_id`=? LIMIT 1";
    $num = $this->db->queryOneVal ( $sql, $course_id );
    if (! $num) {
      throw new LICREnrolmentException ( "Enrolment::enrol No course with ID [$course_id] exists.", self::EXCEPTION_COURSE_NOT_FOUND );
    }
    $sql = "SELECT `name` FROM `role` WHERE `role_id`=? LIMIT 1";
    $role_name = $this->db->queryOneVal ( $sql, $role_id );
    if (! $role_name) {
      throw new LICREnrolmentException ( "Enrolment::enrol No role with ID [$role_id] exists.", self::EXCEPTION_ROLE_NOT_FOUND_NOT_FOUND );
    }
    $subscribed=0;
    if($role_name=='Instructor') $subscribed=1;
    $status = $this->enrolment_status ( $course_id, $user_id );
    if (! $status ['exists']) {
      $sql = "
        INSERT INTO `enrolment`
        SET
          `course_id`=:course_id
          ,`user_id`=:user_id
          ,`role_id`=:role_id
          ,`active`=1
          ,`sis_role`=:sis_role
          ,`subscribed`=:subscribed
      ";
      $bind = array (
          'course_id' => $course_id,
          'user_id' => $user_id,
          'role_id' => $role_id,
          'sis_role' => $sis_role, 
          'subscribed' => $subscribed
      );
      // $course_info = $this->licr->course_mgr->info ( $course_id );
      // $user_info = $this->licr->user_mgr->info_by_id ( $user_id );
      // $role_name = $this->licr->role_mgr->name_by_id ( $role_id );
      // history_add(
      // 'user', $user_id, 'Enrolled in Class [' . $course_info['lmsid'] . '] with role [' . $role_name.']'
      // );
      // history_add(
      // 'course', $course_id, 'Enrolled User ['
      // . $user_info['lastname'] . ', ' . $user_info['firstname']
      // . ' PUID ' . $user_info['puid'] . '] with role ' . $role_name
      // );
    } else {
      $sql = "
        UPDATE `enrolment`
        SET
          `role_id`=:role_id
          ,`sis_role`=:sis_role
          ,`active`=1
          ,`subscribed`=:subscribed
          WHERE
          `course_id`=:course_id
          AND `user_id`=:user_id
      ";
      $bind = array (
          'role_id' => $role_id,
          'sis_role' => $sis_role,
          'course_id' => $course_id,
          'user_id' => $user_id, 
          'subscribed' => $subscribed
      );
      // $course_info = $this->licr->course_mgr->info ( $course_id );
      // $user_info = $this->licr->user_mgr->info_by_id ( $user_id );
      // $role_name = $this->licr->role_mgr->name_by_id ( $role_id );
      
      // history_add(
      // 'user', $user_id, 'Reactivated in Class [' . $course_info['lmsid'] . '] with role [' . $role_name.']'
      // );
      // history_add(
      // 'course', $course_id, 'Reactivated User ['
      // . $user_info['lastname'] . ', ' . $user_info['lastname']
      // . ' PUID ' . $user_info['puid'] . '] with role ' . $role_name
      // );
    }
    $res = $this->db->execute ( $sql, $bind );
    return $res->rowCount ();
  }
  
  /**
   *
   * @param int $course_id          
   * @param int $user_id          
   * @return array[exists,role_id,active]
   */
  function enrolment_status($course_id, $user_id) {
    $sql = "
      SELECT `role`.`role_id`, `role`.`name`, `active`, `sis_role`, `subscribed`
      FROM `enrolment` JOIN `role` USING(`role_id`)
      WHERE `course_id`=:course_id
        AND `user_id`=:user_id
    ";
    $res = $this->db->queryOneRow ( $sql, array (
        'course_id' => $course_id,
        'user_id' => $user_id 
    ) );
    if (! $res) {
      return array (
          'exists' => false,
          'role_id' => false,
          'role' => 'None',
          'active' => false,
          'sis_role' => 'None',
          'subscribed' => 0 
      );
    }
    return array (
        'exists' => true,
        'role_id' => $res ['role_id'],
        'role' => $res ['name'],
        'active' => $res ['active'],
        'sis_role' => $res ['sis_role'],
        'subscribed' => $res ['subscribed'] 
    );
  }
  
  /**
   *
   * @param int $course_id          
   * @param int $user_id          
   * @return int
   */
  function unenrol($course_id, $user_id) {
    $sql = "
      UPDATE `enrolment`
      SET `active`=0
      WHERE
        `course_id`=:course_id
        AND
        `user_id`=:user_id
      LIMIT 1
    ";
    $bind = array (
        'course_id' => $course_id,
        'user_id' => $user_id 
    );
    $res = $this->db->execute ( $sql, $bind );
    $course_info = $this->licr->course_mgr->info ( $course_id );
    $user_info = $this->licr->user_mgr->info_by_id ( $user_id );
    // history_add('user', $user_id, "Unenrolled from course [" . $course_info['lmsid'] . "]");
    // history_add('course', $course_id, "Unenrolled user ["
    // . $user_info['lastname'] . ', ' . $user_info['lastname']
    // . ' PUID ' . $user_info['puid'] . "]");
    return $res->rowCount ();
  }
  
  /**
   *
   * @param int $course_id          
   * @param int $role_id
   *          optional
   * @return array
   */
  function users_in_course($course_id, $role_id = FALSE) {
    if (! $role_id) {
      $sql = "
        SELECT 
          U.`user_id`
          ,U.`puid`
          ,U.`lastname`
          ,U.`firstname`
          ,U.`email`
          ,R.`role_id`
          ,R.`name` AS role
          ,E.`sis_role`
          FROM 
          `enrolment` E
          JOIN `user` U USING(`user_id`)
          JOIN `role` R USING(`role_id`)
        WHERE E.`course_id`=?
        AND E.`active`=1
        ORDER BY U.`lastname`,U.`firstname`
      ";
      $bind = $course_id;
    } else {
      $sql = "
        SELECT 
          U.`user_id`
          ,U.`puid`
          ,U.`lastname`
          ,U.`firstname`
          ,U.`email`
          ,R.`role_id`
          ,R.`name` AS role
          ,E.`sis_role`
        FROM 
          `enrolment` E
          JOIN `user` U USING(`user_id`)
          JOIN `role` R USING(`role_id`)
        WHERE E.`course_id`=?
          AND R.`role_id`=?
          AND E.`active`=1
        ORDER BY U.`lastname`,U.`firstname`
      ";
      $bind = array (
          $course_id,
          $role_id 
      );
    }
    $users = $this->db->queryAssoc ( $sql, 'puid', $bind );
    return $users;
  }
  function user_courses($puid) {
    $sql = "
      SELECT
        C.`course_id`
        ,C.`course_id` AS CID
        ,C.`title`
        ,C.`lmsid`
        ,C.`active`
        ,C.`enddate`
        ,R.`role_id`
        ,R.`name` AS role_name
        ,E.`sis_role`
        ,B.`name` as branch
        ,CA.`name` as location
        , (
        		SELECT
        		  COUNT(`item_id`)
        		FROM
        		  `course_item` 
        		  JOIN `status` USING(`status_id`)
      		    JOIN `course` USING(`course_id`)      		
        		WHERE
        		  `course_item`.`course_id`= CID
        		  AND `status`.`visible_to_student`=1
      		    AND IFNULL(`course_item`.`startdate`,`course`.`startdate`) < NOW()
      		    AND IFNULL(`course_item`.`enddate`,`course`.`enddate`) > NOW()
          ) AS visible  		
        , (
        		SELECT
        		  COUNT(`item_id`)
        		FROM
        		  `course_item` JOIN `status` USING(`status_id`)
        		WHERE
        		  `course_id`= CID
              AND `status`.`cancelled`=0
          ) AS total
      FROM
        `enrolment` E
        JOIN `course` C USING(`course_id`)
        JOIN `user` U USING(`user_id`)
        JOIN `role` R USING(`role_id`)
        JOIN `branch` B ON C.`default_branch_id`=B.`branch_id` 
        JOIN `campus` CA USING(`campus_id`)
      WHERE
        U.`puid`=?
      AND E.`active`=1
      AND C.`active`=1
      ORDER BY C.`title`
      ";
    $res = $this->db->queryAssoc ( $sql, 'course_id', $puid );
    return $res;
  }
  function user_departments($puid) {
    $sql = "
      SELECT
        DISTINCT C.`coursecode` as department
      FROM
        `enrolment` E
        JOIN `course` C USING(`course_id`)
        JOIN `user` U USING(`user_id`)
      WHERE
        U.`puid`=?
        AND E.`active`=1
        AND C.`coursecode` IS NOT NULL
        AND C.`coursecode` != ''
      ORDER BY C.`coursecode`
      ";
    $res = $this->db->queryOneColumn ( $sql, $puid );
    return $res;
  }
  function subscribe($puid, $course_id) {
    $uinfo=$this->licr->user_mgr->info_by_puid($puid);
    if(!$uinfo['email']){
      throw new LICREnrolmentException("Enrolment::subscribe No email configured for user $puid",self::EXCEPTION_NO_EMAIL);
    }
    $sql = "
        UPDATE `enrolment` E JOIN `user` U USING(`user_id`) 
        SET E.`subscribed`=1 
        WHERE
        U.`puid`=:puid
        AND E.`course_id`=:course_id
        ";
    $bind = array (
        'puid' => $puid,
        'course_id' => $course_id 
    );
    $res = $this->db->execute ( $sql, $bind );
    return $res->rowCount();
  }
  function unsubscribe($puid, $course_id) {
    $sql = "
        UPDATE `enrolment` E JOIN `user` U USING(`user_id`) 
        SET E.`subscribed`=0 
        WHERE
        U.`puid`=:puid
        AND E.`course_id`=:course_id
                ";
    $bind = array (
        'puid' => $puid,
        'course_id' => $course_id 
    );
    $res = $this->db->execute ( $sql, $bind );
    return $res->rowCount();
  }
  function is_subscribed($puid, $course_id) {
    $sql = "
        SELECT `subscribed`
        FROM `enrolment` JOIN `user` USING(`user_id`)
        WHERE
        `user`.`puid`=:puid
        AND `enrolment`.`course_id`=:course_id
        ";
    $bind = array (
        'puid' => $puid,
        'course_id' => $course_id 
    );
    return $this->db->queryOneVal ( $sql, $bind );
  }
  
  function cron_data(){
    $sql="
        SELECT 
          U.`email`
          ,U.`firstname`
          ,U.`lastname`
          ,C.`title` AS course_title
          ,C.`shorturl` as course_shorturl
          ,I.`title` AS item_title
          ,I.`author`
          ,I.`shorturl`
          ,I.`hash`
        FROM 
          `enrolment` E
          JOIN `course` C USING(`course_id`)
          JOIN `course_item` CI USING(`course_id`)
          JOIN `user` U USING(`user_id`)
          JOIN `item` I USING(`item_id`)
          JOIN `status` S1 ON CI.`status_id`=S1.`status_id`
          JOIN `status` S2 ON CI.`previous_status_id`=S2.`status_id` 
        WHERE 
          E.`subscribed`=1
          AND C.`startdate` < NOW()
          AND C.`enddate` > NOW()
          AND CI.`hidden`=0
          AND CI.`last_status_change` > DATE_SUB(NOW(), INTERVAL 1 DAY)
          AND S1.`visible_to_student`=1
          AND S2.`visible_to_student`=0
          AND U.`email` IS NOT NULL
          AND U.`email` !=''
        ORDER BY E.`user_id`
        ";
    $res=$this->db->execute($sql);
    $ret=array();
    while($row=$res->fetch()){
      $ident=$row['email']."\x1f".$row['firstname'].' '.$row['lastname'];
      $course_ident=$row['course_title']."\x1f".$row['course_shorturl'];
          if(!isset($ret[$ident])){
        $ret[$ident]=array();
      }
      if(!isset($ret[$ident][$course_ident])){
        $ret[$ident][$course_ident]=array();
      }
      unset($row['email']);
      unset($row['firstname']);
      unset($row['lastname']);
      unset($row['course_title']);
      if(!$row['shorturl']){
        $row['shorturl']=make_short_BB_url("i.".$row['hash']);
      }
      unset($row['hash']);
      $ret[$ident][$course_ident][]=$row;
    }
    return $ret;
  }
}
class LICREnrolmentException extends Exception {
}
