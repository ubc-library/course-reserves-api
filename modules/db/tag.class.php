<?php

class Tag {

  const EXCEPTION_TAG_ALREADY_EXISTS = 601;
  const EXCEPTION_TAG_NOT_FOUND = 602;
  const EXCEPTION_COURSE_NOT_FOUND = 603;
  const EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER = 604;

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

  function __construct(LICR $licr) {
    $this->licr = $licr;
    $this->db = $licr->db;
  }

  function exists($tag_id) {
    $sql = "SELECT COUNT(*) FROM `tag` WHERE `tag_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, $tag_id);
    return $num;
  }

  /**
   * Create new tag given a name and course_id.
   * 
   * @param string $name
   * @param int $course_id
   * @return int new tag_id
   * @throws LICRTagException 
   */
  function create($name, $course_id, $startdate, $enddate) {
    if (preg_match('/^[0-9]/', $name)) {
      $name="_$name";
    }
    if (!$this->licr->course_mgr->exists($course_id)) {
      throw new LICRTagException('Tag::create Course not found', self::EXCEPTION_COURSE_NOT_FOUND);
    }
    $sql = "SELECT `tag_id` FROM `tag` WHERE `name`=? and `course_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, array($name,$course_id));
    if ($num) {
      //throw new LICRTagException('Tag::create Tag with this name already exists in this course', self::EXCEPTION_TAG_ALREADY_EXISTS);
      return $num; // just return the existing tag_id
    }
    $sql = "
      INSERT INTO 
        `tag` 
      SET 
        `name`=:name 
        ,`course_id`=:course_id
        ,`startdate`=:startdate
        ,`enddate`=:enddate
        ,`hash`=:hash
        ,`shorturl`=:shorturl
    ";
    $hash = purl_hash('tag', $course_id . $name);
    $shorturl=make_short_BB_url("t.$hash");
    $bind = array(
        'name' => $name
        , 'course_id' => $course_id
        , 'startdate' => $startdate
        , 'enddate' => $enddate
        , 'hash' => $hash
        , 'shorturl' => $shorturl
    );
    $res = $this->db->execute($sql, $bind);
    $tag_id = $this->db->lastInsertId();
    history_add(
            'tag', $tag_id, "Created tag [$name] in course $course_id (hash $hash)"
    );
    return $tag_id;
  }

  /**
   * 
   * @param int $tag_id
   * @param string $name
   * @param int $course_id
   * @return int
   * @throws LICRTagException
   */
  function update($tag_id, $name = NULL, $startdate = NULL, $enddate = NULL) {
    if (!($name || $startdate || $enddate)) {
      throw new LICRTagException('Tag::update missing name or course_id', self::EXCEPTION_MISSING_PARAMETER);
    }
    if (!$this->exists($tag_id)) {
      throw new LICRTagException('Tag::update tag_id [' . $tag_id . '] does not exist', self::EXCEPTION_COURSE_NOT_FOUND);
    }
    $update = array();
    $bind = array('tag_id' => $tag_id);
    if ($name) {
      if(preg_match('/^[0-9]/',$name)){$name="_$name";}
      $sql = "SELECT COUNT(*) FROM `tag` WHERE `name`=? LIMIT 1";
      $num = $this->db->queryOneVal($sql, $name);
      if ($num) {
        throw new LICRTagException('Tag::update Tag with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
      }
      $update[] = " `name`=:name ";
      $bind['name'] = $name;
    }
    if ($startdate) {
      $update[] = " `startdate`=:startdate";
      $bind['startdate'] = $startdate;
    }
    if ($enddate) {
      $update[] = " `enddate`=:enddate";
      $bind['enddate'] = $enddate;
    }
    $sql = "UPDATE `tag` SET " . implode(',', $update) . ' WHERE `tag_id`=:tag_id';
    $res = $this->db->execute($sql, $bind);
    return $res->rowCount();
  }

  /**
   * 
   * @param int $tag_id
   * @return int
   * @throws LICRTagException
   */
  function delete($tag_id) {
    //WARNING: tag_id is a FK for course and course_item
    //Not an issue for course, really, but we should prevent deleting a tag if it is
    //referred to in a course_item
    $sql = "SELECT COUNT(*) FROM `tag_item` WHERE `tag_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, $tag_id);
    if ($num) {
      throw new LICRTagException("Tag::delete Cannot delete tag [$tag_id] as it is referenced by entry/ies in tag_item", self::EXCEPTION_CANNOT_DELETE_TAG);
    }
    $sql = "DELETE FROM `tag` WHERE `tag_id`=? LIMIT 1";
    $res = $this->db->execute($sql, $tag_id);
    return $res->rowCount();
  }

  /**
   * Get tag info from tag_id
   * 
   * @param int $tag_id
   * @return array
   * @throws LICRTagException 
   */
  function info($tag_identifier) {
    $sql = "
      SELECT 
        TRIM(LEADING '_' FROM `name`) AS name
        ,`tag_id`
        ,`course_id`
        ,`startdate`
        ,`enddate`
        ,`hash`
        ,`shorturl`
      FROM 
        `tag`
      WHERE 
        `".(is_numeric($tag_identifier)?'tag_id':'hash')."`=?
    ";
    $info = $this->db->queryOneRow($sql, array($tag_identifier));
    if (!$info) {
      throw new LICRTagException("Tag::info Tag [$tag_identifier] not found", self::EXCEPTION_TAG_NOT_FOUND);
    }
    $sql="
        SELECT
        `item_id` 
        FROM
        `tag_item`
        WHERE
        `tag_id`=:tag_id
        ";
    $item_ids=$this->db->queryOneColumn($sql,array('tag_id'=>$info['tag_id']));
    $info['items']=$item_ids;
    $info['name']=preg_replace('/^_/','',$info['name']);
    if(!trim($info['shorturl'])){
      $info['shorturl']=make_short_BB_url('t.'.$info['hash']);
      $sql="UPDATE `tag` SET `shorturl`=:shorturl WHERE `tag_id`=:tag_id";
      $this->db->execute($sql,array('tag_id'=>$info['tag_id'],'shorturl'=>$info['shorturl']));
    }
    return $info;
  }

  function info_by_course_id_and_name($course_id, $name) {
    if(preg_match('/^[0-9]/',$name)){$name="_$name";}
    $sql = "SELECT `tag_id` FROM `tag` WHERE `name`=? AND `course_id`=?";
    $tag_id = $this->db->queryOneVal($sql, array($name, $course_id));
    if (!$tag_id) {
      throw new LICRTagException('Tag::info Tag not found', self::EXCEPTION_TAG_NOT_FOUND);
    }
    return $this->info($tag_id);
  }

  function list_by_course($course_id, $isStudent=1) {
    $course_id*=1;
    $sql = "
        SELECT
          T.`tag_id`
        , TRIM(LEADING '_' FROM T.`name`) AS name
        , COUNT(TI.`item_id`) as count
        , T.`shorturl` 
        FROM 
    		  `course` C
    		  JOIN `course_item` CI USING(`course_id`)
    		  JOIN `tag_item` TI USING(`item_id`)
    		  JOIN `tag` T USING(`tag_id`,`course_id`)
    		  JOIN `status` S USING(`status_id`)
        WHERE 
          C.`course_id`=:course_id
    ";
    if($isStudent){
      $sql.="
      		AND S.`visible_to_student`=1
--    		  AND IFNULL(CI.`startdate`,C.`startdate`) < NOW()
--    		  AND IFNULL(CI.`enddate`,C.`enddate`) > NOW()
        ";
    }else{
      $sql.="
          AND S.`cancelled`=0
        ";
    }
    $sql.="
        GROUP BY T.`tag_id`
        HAVING COUNT(TI.`item_id`) > 0
        ORDER BY name
        ";
    $res= $this->db->queryRows($sql, compact('course_id'));
    //var_dump($this->db->errorInfo());
    return $res;
  }

  function hash($tag_id) {
    $sql = "SELECT `hash` FROM `tag` WHERE `tag_id`=?";
    $res = $this->db->queryOneVal($sql, $tag_id);
    if (!$res) {
      throw new LICRTagException("Item not found", self::EXCEPTION_TAG_NOT_FOUND);
    }
    return $res;
  }
  
  function list_program_tags($program_id){
        //this is to work around having different tag id's in different courses for the same tag
    //here we build up a "concordance" of tag name => array of tag_ids
    //This is student facing so we only choose where visible
    $sql="
        SELECT 
          TRIM(LEADING '_' FROM T.`name`) AS name, T.`tag_id`
        FROM
          `course_program` CP
          JOIN `course_item` CI ON CP.`course_id`=CI.`course_id` 
          JOIN `tag_item` TI USING(`item_id`)
          JOIN `tag` T ON T.`tag_id`=TI.`tag_id` AND T.`course_id`=CI.`course_id`
          JOIN `status` S USING(`status_id`)
          JOIN `course` C ON CI.`course_id`=C.`course_id`
        WHERE
        CP.`program_id`=:program_id
        AND S.`visible_to_student`=1
        AND CI.`hidden`=0
        AND IF(
          IFNULL(CI.`enddate`,C.`enddate`) < NOW(),
          (CI.`fairdealing`=0 AND CI.`transactional`=0),
          1
        )
        ORDER BY name
      ";
    
    $ret=array();
    $res=$this->db->execute($sql,compact('program_id'));
    while($row=$res->fetch()){
      if(empty($ret[$row['name']])) $ret[$row['name']]=array();
      if(!in_array($row['tag_id'],$ret[$row['name']])){
        $ret[$row['name']][]=$row['tag_id'];
      }
//      $ret['statuses'][]=$row['status'];
    }
    return $ret;
  } 
  
  function list_program_items($program_id,$tag_name){
    if(preg_match('/^[0-9]/',$tag_name)){
      $tag_name="_$tag_name";
    }
    $sql="
        SELECT CI.`item_id`, CI.`course_id`
        FROM
          `course_program` CP
          JOIN `course_item` CI ON CP.`course_id`=CI.`course_id` 
          JOIN `tag_item` TI USING(`item_id`)
          JOIN `tag` T USING(`tag_id`)
          JOIN `status` S USING(`status_id`)
          JOIN `course` C ON CI.`course_id`=C.`course_id`
        WHERE
        T.`name`=:tag_name
        AND CP.`program_id`=:program_id
        AND S.`visible_to_student`=1
        AND CI.`hidden`=0
        AND IF(
          IFNULL(CI.`enddate`,C.`enddate`) < NOW(),
          (CI.`fairdealing`=0 AND CI.`transactional`=0),
          1
        )
                ";
    $res=$this->db->queryRows($sql,compact('program_id','tag_name'));
    return $res;
  }
}

class LICRTagException extends Exception {
  
}
