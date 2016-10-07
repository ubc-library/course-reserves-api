<?php

class Branch {

  const EXCEPTION_NAME_ALREADY_EXISTS = 101;
  const EXCEPTION_BRANCH_NOT_FOUND = 102;
  const EXCEPTION_CAMPUS_NOT_FOUND = 103;
  const EXCEPTION_CANNOT_DELETE_BRANCH = 104;
  const EXCEPTION_MISSING_PARAMETER = 105;
  const EXCEPTION_BRANCH_AMBIGUOUS = 106;
  const EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER = 107;

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

  function exists($branch_id) {
    $sql = "SELECT COUNT(*) FROM `branch` WHERE `branch_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, $branch_id);
    return $num;
  }

  /**
   * Create new branch given a name and campus_id.
   * 
   * @param string $name
   * @param int $campus_id
   * @return int new branch_id
   * @throws LICRBranchException 
   */
  function create($name, $campus_id) {
    if(preg_match('/^[0-9]/',$name)){
      throw new LICRBranchException("Branch::create name must not begin with a number",self::EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER);
    }
    $sql = "SELECT COUNT(*) FROM `branch` WHERE `name`=? AND `campus_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, array($name,$campus_id));
    if ($num) {
      throw new LICRBranchException('Branch::create Branch with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
    }
    if (!$this->licr->campus_mgr->exists($campus_id)) {
      throw new LICRBranchException('Branch::create campus_id [' . $campus_id . '] does not exist', self::EXCEPTION_CAMPUS_NOT_FOUND);
    }
    $sql = "INSERT INTO `branch` SET `name`=:name, `campus_id`=:campus_id";
    $res = $this->db->execute($sql, array('name' => $name, 'campus_id' => $campus_id));
    $branch_id = $this->db->lastInsertId();
    history_add(
            'branch', $branch_id, "Created branch " . $name
    );
    return $branch_id;
  }

  /**
   * 
   * @param int $branch_id
   * @param string $name
   * @param int $campus_id
   * @return int
   * @throws LICRBranchException
   */
  function update($branch_id, $name = NULL, $campus_id = NULL) {
    if (!($name || $campus_id)) {
      throw new LICRBranchException('Branch::update missing name or campus_id', self::EXCEPTION_MISSING_PARAMETER);
    }
    if (!$this->exists($branch_id)) {
      throw new LICRBranchException('Branch::update branch_id [' . $branch_id . '] does not exist', self::EXCEPTION_CAMPUS_NOT_FOUND);
    }
    $update = array();
    $bind = array('branch_id' => $branch_id);
    if ($name) {
      $sql = "SELECT COUNT(*) FROM `branch` WHERE `name`=? LIMIT 1";
      $num = $this->db->queryOneVal($sql, $name);
      if ($num) {
        throw new LICRBranchException('Branch::update Branch with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
      }
      $update[] = " `name`=:name ";
      $bind['name'] = $name;
    }
    if ($campus_id) {
      if (!$this->licr->campus_mgr->exists($campus_id)) {
        throw new LICRBranchException('Branch::update campus_id [' . $campus_id . '] does not exist', self::EXCEPTION_CAMPUS_NOT_FOUND);
      }
      $update[] = " `campus_id`=:campus_id";
      $bind['campus_id'] = $campus_id;
    }
    $sql = "UPDATE `branch` SET " . implode(',', $update) . ' WHERE `branch_id`=:branch_id';
    $res = $this->db->execute($sql, $bind);
    $retval = $res->rowCount();
    if ($retval) {
      history_add(
              'branch', $branch_id, "Updated branch: name $name, campus_id $campus_id"
      );
    }
    return $retval;
  }

  /**
   * 
   * @param int $branch_id
   * @return int
   * @throws LICRBranchException
   */
  function delete($branch_id) {
    //WARNING: branch_id is a FK for campus and course_item
    //Not an issue for campus, really, but we should prevent deleting a branch if it is
    //referred to in a course_item
    $sql = "SELECT COUNT(*) FROM `course_item` WHERE `branch_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, $branch_id);
    if ($num) {
      throw new LICRBranchException("Branch::delete Cannot delete branch [$branch_id] as it is referenced by entry/ies in course_item", self::EXCEPTION_CANNOT_DELETE_BRANCH);
    }
    $sql = "DELETE FROM `branch` WHERE `branch_id`=? LIMIT 1";
    $res = $this->db->execute($sql, $branch_id);
    $retval = $res->rowCount();
    if ($retval) {
      history_add(
              'branch', $branch_id, "Deleted branch"
      );
    }
    return $retval;
  }

  /**
   * Get branch name, campus_id, campus_name, inst._id, inst. from branch_id
   * 
   * @param int $branch_id
   * @return array
   * @throws LICRBranchException 
   */
  function info($branch_id) {
    $sql = "
      SELECT 
        `branch`.`name` 
        ,`campus`.`campus_id` 
        ,`campus`.`name` AS campus_name
      FROM 
        `branch`
        JOIN `campus` USING(`campus_id`)
      WHERE 
        `branch_id`=:branch_id
    ";
    $info = $this->db->queryOneRow($sql, compact('branch_id'));
    if (!$info) {
      //var_dump(debug_backtrace());die();
      throw new LICRBranchException("Branch::info Branch [$branch_id] not found", self::EXCEPTION_BRANCH_NOT_FOUND);
    }
    return $info;
  }

  function get_id($branch_name, $campus_name = FALSE) {
    if(!$branch_name) $branch_name='Unknown'; //problematic
    $sql = "
      SELECT `branch`.`branch_id`
      FROM `branch` ";
    $bind = array('branch_name' => $branch_name);
    if ($campus_name) {
      $sql.=",`campus` ";
      $bind['campus_name'] = $campus_name;
    }
    $sql.="WHERE `branch`.`name`=:branch_name ";
    if ($campus_name) {
      $sql.="AND `campus`.`campus_id`=`branch`.`campus_id`
      AND `campus`.`name`=:campus_name ";
    }
    $res = $this->db->queryOneColumn($sql, $bind);
    if (!$res) {
      throw new LICRBranchException("Branch::get_id Branch not found".$sql.var_export($bind,true), self::EXCEPTION_BRANCH_NOT_FOUND);
    }
    if (count($res) > 1) {
      throw new LICRBranchException("Branch::get_id Branch [$branch_name] is ambiguous", self::EXCEPTION_BRANCH_AMBIGUOUS);
    }
    return $res[0];
  }

}

class LICRBranchException extends Exception {
  
}
