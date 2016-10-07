<?php

class Type {

  const EXCEPTION_NAME_ALREADY_EXISTS = 1501;
  const EXCEPTION_TYPE_NOT_FOUND = 1502;
  const EXCEPTION_TYPE_MUST_NOT_START_WITH_NUMBER = 1503;
  const EXCEPTION_TYPE_IN_USE = 1504;

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

  /**
   *
   * @var array
   */
  private $cache;

  function __construct($licr) {
    $this->licr = $licr;
    $this->db = $licr->db;
    $sql = "SELECT `type_id`, `name`, `physical` FROM `type`";
    $res = $this->db->execute($sql);
    $this->cache = array();
    while ($row = $res->fetch()) {
      $this->cache[$row['type_id']] = array('name'=>$row['name'],'physical'=>$row['physical']);
    }
  }

  function list_all() {
    return $this->cache;
  }

  function exists($type_id) {
    return isset($this->cache[$type_id]);
  }

  /**
   * Create new type given a name, and whether it is physical.
   * This should rarely, if ever, be called.
   * Return value should be 1; 0 would indicate a database error.
   * 
   * @param string $name
   * @return int
   * @throws LICRTypeException 
   */
  function create($name, $physical) {
    if (preg_match('/^[0-9]/', $name)) {
      throw new LICRTypeException("Type::create name must not begin with a number", self::EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER);
    }
    $sql = "SELECT COUNT(*) FROM `type` WHERE `name`=?";
    $num = $this->db->queryOneVal($sql, $name);
    if ($num) {
      throw new LICRTypeException('Type::create Type with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
    }
    $sql = "INSERT INTO `type` SET `name`=:name, `physical`=:physical";
    $this->db->execute($sql, array('name' => $name, 'physical'=>$physical));
    $type_id = $this->db->lastInsertId();
    $this->cache[$type_id] = $name;
    return $type_id;
  }

  /**
   * Get type name from type_id
   * 
   * @param int $type_id
   * @return string
   * @throws LICRTypeException 
   */
  function name_by_id($type_id) {
    if (isset($this->cache[$type_id])) {
      return $this->cache[$type_id]['name'];
    }
    $sql = "SELECT `name` FROM `type` WHERE `type_id`=?";
    $name = $this->db->queryOneVal($sql, $type_id);
    if (!$name) {
      throw new LICRTypeException('Type::name_by_id Type not found', self::EXCEPTION_TYPE_NOT_FOUND);
    }
    return $name;
  }

  /**
   * Rename a type, or set boolean properties
   * @param int $type_id
   * @param string $name
   * @return TRUE 
   */
  function update($type_id, $name = NULL, $physical=FALSE) {
    if ($name) {
      $sql = "SELECT COUNT(*) FROM `type` WHERE `name`=?";
      $num = $this->db->queryOneVal($sql, $name);
      if ($num) {
        throw new LICRTypeException('Type::rename Type with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
      }
      $sql = 'UPDATE `type` SET `name`=:name WHERE `type_id`=:type_id';
      $res = $this->db->execute($sql, array('name' => $name, 'type_id' => $type_id));
      $this->cache[$type_id] = $name;
      history_log('type', $type_id, 'Renamed to $name');
    }
    $sql = 'UPDATE `type` SET `phjysical`=:physical WHERE `type_id`=:type_id';
    $res = $this->db->execute($sql, array('physical' => $physical, 'type_id' => $type_id));
    $this->cache[$type_id]['name'] = $name;
    $this->cache[$type_id]['physical'] = $physical;
    history_log('type', $type_id, 'Renamed to $name, '.($physical?'':'not').' physical');
    return TRUE;
  }

  /**
   * 
   * @param int $type_id
   * @return int
   * @throws LICRTypeException
   */
  function delete($type_id) {
    //can't delete a type that is in use...
    $sql = "SELECT COUNT(*) FROM `item` WHERE `type_id`=?";
    $num = $this->db->queryOneVal($sql, $type_id);
    if ($num) {
      throw new LICRTypeException("Cannot delete type [$type_id], it is in use.", self::EXCEPTION_TYPE_IN_USE);
    }
    $sql = "DELETE FROM `type` WHERE `type_id`=?";
    $res = $this->db->execute($sql, $type_id);
    unset($this->cache[$type_id]);
    return $res->rowCount();
  }

  /**
   * can return FALSE 
   * @param string $name
   * @return boolean|int
   */
  function id_by_name($name) {
    foreach($this->cache as $id=>$ti){
      if($name==$ti['name']){
        return $id;
      }
    }
    return false;
  }
  
  function is_physical($type_id){
    return $this->cache[$type_id]['physical'];
  }
}

class LICRTypeException extends Exception {
  
}
