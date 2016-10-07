<?php

class Role {

  const EXCEPTION_NAME_ALREADY_EXISTS = 1301;
  const EXCEPTION_ROLE_NOT_FOUND = 1302;
  const EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER = 1303;

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
  private $cache;

  function __construct($licr) {
    $this->licr = $licr;
    $this->db = $licr->db;
    $sql = "SELECT `role_id`, `name` FROM `role`";
    $res = $this->db->queryAssoc($sql, 'role_id');
    foreach($res as $role_id=>$row){
      $this->cache[$role_id]=$row['name'];
    }
//    foreach(unserialize(ROLES) as $role){
//        if(!$this->id_by_name($role)){
//          $this->create($role);
//        }
//    }
  }

  function list_all(){
    return $this->cache;
  }

  /**
   * Create new role given a name.
   * This should rarely, if ever, be called.
   * Return value is the new role_id
   * 
   * @param string $name
   * @return int
   * @throws LICRRoleException 
   */
  function create($name) {
    if(preg_match('/^[0-9]/',$name)){
      throw new LICRRoleException("Role::create name must not begin with a number",self::EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER);
    }

    $sql = "SELECT COUNT(`role_id`) FROM `role` WHERE `name`=?";
    $num = $this->db->queryOneVal($sql, $name);
    if ($num) {
      throw new LICRRoleException('Role::create Role with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
    }
    $sql = "INSERT INTO `role` SET `name`=?";
    $res = $this->db->execute($sql, $name);
    $role_id=$this->db->lastInsertId();
    $this->cache[$role_id] = $name;
    return $role_id;
  }

  /**
   * Get role name from role_id
   * 
   * @param int $role_id
   * @return string
   * @throws LICRRoleException 
   */
  function name_by_id($role_id) {
    if (isset($this->cache[$role_id])) {
      return $this->cache[$role_id];
    }
    throw new LICRRoleException("Role::name_by_id Role [$role_id] not found", self::EXCEPTION_ROLE_NOT_FOUND);
  }

  /**
   * Rename a role
   * @param int $role_id
   * @param string $name
   * @return int 
   */
  function rename($role_id, $name) {
    $index=array_search($name,$this->cache);
    if ($index!==FALSE) {
      throw new LICRRoleException('Role::rename Role with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
    }
    $sql = 'UPDATE `role` SET `name`=:name WHERE `role_id`=:role_id';
    $res = $this->db->execute($sql, array('name' => $name, 'role_id' => $role_id));
    $this->cache[$role_id]['name'] = $name;
    return $res->rowCount();
  }

  /**
   * can return FALSE or 0-N
   * @param string $name
   * @return boolean|int
   */
  function id_by_name($name) {
    return array_search($name,$this->cache);
  }

  function delete($role_id){
    //don't delete if role is used
    $sql="
      SELECT COUNT(*) FROM `enrolment` WHERE `role_id`=?
      ";
    $count=$this->db->queryOneVal($sql, $role_id);
    if($count!=0){
      throw new LICRRoleException("Role cannot be deleted as it is used by one or more users",self::EXCEPTION_CANNOT_DELETE_ROLE);
    }
    $sql="DELETE FROM `role` WHERE `role_id`=?";
    $res=$this->db->execute($sql,$role_id);
    return $res->rowCount();
  }
}

class LICRRoleException extends Exception {
  
}