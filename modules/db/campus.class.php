<?php

class Campus {

  const EXCEPTION_NAME_ALREADY_EXISTS = 201;
  const EXCEPTION_CAMPUS_NOT_FOUND = 202;
  const EXCEPTION_CANNOT_DELETE_CAMPUS = 203;
  const EXCEPTION_MISSING_PARAMETER = 204;
  const EXCEPTION_CAMPUS_NAME_AMBIGUOUS = 205;
  const EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER = 207;

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

  function exists($campus_id) {
    $sql = "SELECT COUNT(*) FROM `campus` WHERE `campus_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, $campus_id);
    return $num;
  }

  /**
   * Create new campus given a name.
   * 
   * @param string $name
   * @return int new campus_id
   * @throws LICRCampusException 
   */
  function create($name) {
    if (preg_match('/^[0-9]/', $name)) {
      throw new LICRCourseException("Campus::create name must not begin with a number", self::EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER);
    }
    $sql = "SELECT COUNT(*) FROM `campus` WHERE `name`=:campus_name LIMIT 1";
    $num = $this->db->queryOneVal($sql, array('campus_name' => $name));
    if ($num) {
      throw new LICRCampusException('Campus::create Campus with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
    }
    $sql = "INSERT INTO `campus` SET `name`=:name";
    $res = $this->db->execute($sql, array('name' => $name));
    $campus_id = $this->db->lastInsertId();
    history_add(
            'campus', $campus_id, "Created campus " . $name
    );
    return $campus_id;
  }

  /**
   * 
   * @param int $campus_id
   * @param string $name
   * @param int $campus_id
   * @return int
   * @throws LICRCampusException
   */
  function update($campus_id, $name) {
    if (!$name) {
      throw new LICRCampusException('Campus::update missing name', self::EXCEPTION_MISSING_PARAMETER);
    }
    if (!$this->exists($campus_id)) {
      throw new LICRCampusException('Campus::update campus_id [' . $campus_id . '] does not exist', self::EXCEPTION_CAMPUS_NOT_FOUND);
    }
    $sql = "SELECT COUNT(*) FROM `campus` WHERE `name`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, $name);
    if ($num) {
      throw new LICRCampusException('Campus::update Campus with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
    }
    $bind = array('name' => $name, 'campus_id' => $campus_id);
    $sql = "UPDATE `campus` SET `name`=:name WHERE `campus_id`=:campus_id";
    $res = $this->db->execute($sql, $bind);
    $retval = $this->db->rowCount();
    if ($retval) {
      history_add(
              'campus', $campus_id, "Renamed campus to " . $name
      );
    }
    return $retval;
  }

  /**
   * 
   * @param int $campus_id
   * @return int
   * @throws LICRCampusException
   */
  function delete($campus_id) {
    //WARNING: campus_id is a FK for branch
    //We should prevent deleting a campus that has existing branches
    $sql = "SELECT COUNT(*) FROM `branch` WHERE `campus_id`=? LIMIT 1";
    $num = $this->db->queryOneVal($sql, $campus_id);
    if ($num) {
      throw new LICRCampusException("Campus::delete Cannot delete campus [$campus_id] as it is referred to by a branch", self::EXCEPTION_CANNOT_DELETE_CAMPUS);
    }
    $sql = "DELETE FROM `campus` WHERE `campus_id`=? LIMIT 1";
    $res = $this->db->execute($sql, $campus_id);
    $retval = $res->rowCount();
    if ($retval) {
      history_add(
              'campus', $campus_id, "Deleted campus"
      );
    }
    return $retval;
  }

  /**
   * Get campus name from campus_id
   * 
   * @param int $campus_id
   * @return array
   * @throws LICRCampusException 
   */
  function name_by_id($campus_id) {
    $sql = "
      SELECT 
        `name`
      FROM 
        `campus`
      WHERE 
        `campus_id`=?
    ";
    $info = $this->db->queryOneVal($sql, $campus_id);
    if (!$info) {
      throw new LICRCampusException('Campus::info Campus not found', self::EXCEPTION_CAMPUS_NOT_FOUND);
    }
    return $info;
  }

  function id_by_name($campus_name) {
    $sql = "
        SELECT `campus_id` FROM `campus` WHERE `name`=?
      ";
    $res = $this->db->queryRows($sql, $campus_name);
    if (count($res) == 0) {
      throw new LICRCampusException("Campus::id_by_name campus name not found", self::EXCEPTION_CAMPUS_NOT_FOUND);
    }
    if (count($res) > 1) {
      throw new LICRCampusException("Campus::id_by_name Campus name is ambiguous!", self::EXCEPTION_CAMPUS_NAME_AMBIGUOUS);
    }
    return $res[0]['campus_id'];
  }

  /**
   * 
   * @param int $campus_id
   * @return array
   */
  function list_branches($campus_id = FALSE) {
    if ($campus_id) {
      $sql = "
        SELECT
          `branch_id`
          ,`name`
        FROM 
          `branch`
        WHERE
          `branch`.`campus_id`=?
        ORDER BY `name`
      ";
      $res = $this->db->queryRows($sql, $campus_id);
    } else {
      $sql = "
        SELECT
          `branch_id`,
          `name`
        FROM 
          `branch`
        ORDER BY `name`
      ";
      $res = $this->db->queryRows($sql);
    }
    return $res;
  }

  function list_all() {
    $sql = "
        SELECT 
          `campus_id`
          ,`name`
        FROM
          `campus`
        ORDER BY
          `name`
        ";
    $res = $this->db->queryAssoc($sql, 'campus_id');
    return $res;
  }

}

class LICRCampusException extends Exception {
  
}
