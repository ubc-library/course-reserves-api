<?php
class Status {
  const EXCEPTION_NAME_ALREADY_EXISTS = 1401;
  const EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER = 1402;
  const EXCEPTION_STATUS_IN_USE = 1403;
  const EXCEPTION_INVALID_STATUS_CATEGORY = 1404;
  
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
  private $cache = array ();
  
  /**
   * default statuses from config
   * 
   * @var array
   */
  private $statuses = array ();
  
  /**
   *
   * @var array
   */
  private $validStatusCategories = array (
      'New',
      'InProcess',
      'Complete' 
  );
  
  /**
   * default transitions from config
   * 
   * @var array
   */
  private $transitions = array ();
  function __construct($licr) {
    $this->licr = $licr;
    $this->db = $licr->db;
    
    $sql = "SELECT `status_id`, `name`, `category`, `visible_to_student`, `cancelled` FROM `status`";
    $res = $this->db->execute ( $sql );
    while ( $row = $res->fetch () ) {
      $this->cache [$row ['status_id']] = array (
          'name' => $row ['name']
          ,'category' => $row ['category']
          ,'visible_to_student' => $row ['visible_to_student']
          ,'cancelled'=>$row['cancelled'] 
      );
    }
    $sql = "SELECT `current_status_id`, `next_status_id`, `event` FROM `transition`";
    $res = $this->db->execute ( $sql );
    while ( $row = $res->fetch () ) {
      if (! isset ( $this->transitions [$row ['current_status_id']] )) {
        $this->transitions [$row ['current_status_id']] = array ();
      }
      $this->transitions [$row ['current_status_id']] [$row ['next_status_id']] = $row ['event'];
    }
  }
  function exists($status_id) {
    return isset ( $this->cache [$status_id] );
  }
  
  /**
   * Create new status given a name and category
   * This should rarely, if ever, be called.
   * Return value should be 1; 0 would indicate a database error.
   *
   * @param string $name          
   * @return int
   * @throws LICRStatusException
   */
  function create($name, $category = 'InProcess', $visible_to_student = 0, $cancelled=0) {
    if (preg_match ( '/^[0-9]/', $name )) {
      throw new LICRStatusException ( "Status::create name must not begin with a number", self::EXCEPTION_NAME_MUST_NOT_START_WITH_NUMBER );
    }
    if (! in_array ( $category, $this->validStatusCategories )) {
      throw new LICRStatusException ( "Status::create [$category] not a valid status category", self::EXCEPTION_INVALID_STATUS_CATEGORY );
    }
    $sql = "SELECT COUNT(*) FROM `status` WHERE `name`=?";
    $num = $this->db->queryOneVal ( $sql, $name );
    if ($num) {
      throw new LICRStatusException ( 'Status::create Status with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS );
    }
    $sql = "INSERT INTO `status` SET `name`=:name, `category`=:category, `visible_to_student`=:vts, `cancelled`=:cancelled";
    $res = $this->db->execute ( $sql, array (
        'name' => $name
        ,'category' => $category
        ,'vts' => $visible_to_student
        ,'cancelled'=>$cancelled 
    ) );
    $status_id = $this->db->lastInsertId ();
    $this->cache [$status_id] = array (
        'name' => $name
        ,'category' => $category
        ,'visible_to_student' => $visible_to_student
        ,'cancelled'=>$cancelled 
    );
    return $res->rowCount ();
  }
  
  /**
   * Get status name from status_id
   *
   * @param int $status_id          
   * @return string
   * @throws LICRStatusException
   */
  function name_by_id($status_id) {
    if (isset ( $this->cache [$status_id] )) {
      return $this->cache [$status_id] ['name'];
    }
    return FALSE;
  }
  function visible($status_id) {
    if (isset ( $this->cache [$status_id] )) {
      return $this->cache [$status_id] ['visible_to_student'];
    }
    return FALSE;
  }
  function cancelled($status_id) {
    if (isset ( $this->cache [$status_id] )) {
      return $this->cache [$status_id] ['cancelled'];
    }
    return FALSE;
  }
  function id_by_name($name) {
    foreach ( $this->cache as $status_id => $status ) {
      if ($status ['name'] === $name)
        return $status_id;
    }
    return false;
  }
  
  /**
   * Update a status
   * 
   * @param int $status_id          
   * @param string $name          
   * @return int
   */
  function update($status_id, $name = NULL, $category = NULL, $visible_to_student = NULL, $cancelled=NULL) {
    $vals = array ();
    $bind = array ('status_id'=>$status_id);
    if (! is_null ( $name )) {
      $sql = "SELECT COUNT(*) FROM `status` WHERE `name`=?";
      $num = $this->db->queryOneVal ( $sql, $name );
      if ($num) {
        throw new LICRStatusException ( 'Status::rename Status with this name already exists', self::EXCEPTION_NAME_ALREADY_EXISTS );
      }
      $vals [] = '`name`=:name';
      $bind ['name'] = $name;
    }
    if(!is_null($category)){
      $vals[]='`category`=:category';
      $bind['category']=$category;
      $this->cache[$status_id]['category']=$category;
    }
      if(!is_null($visible_to_student)){
      $vals[]='`visible_to_student`=:visible_to_student';
      $bind['visible_to_student']=$visible_to_student;
      $this->cache[$status_id]['visible_to_student']=$visible_to_student;
      }
      if(!is_null($cancelled)){
      $vals[]='`cancelled`=:cancelled';
      $bind['cancelled']=$cancelled;
      $this->cache[$status_id]['cancelled']=$cancelled;
      
    }
    $sql = 'UPDATE `status` SET '.implode(',',$vals).' WHERE `status_id`=:status_id';
    $res = $this->db->execute ( $sql, $bind );
    return $res->rowCount ();
  }
  function delete($status_id) {
    // can't delete a status that is in use...
    $sql = "SELECT COUNT(*) FROM `course_item` WHERE `status_id`=?";
    $num = $this->db->queryOneVal ( $sql, $status_id );
    if ($num) {
      throw new LICRstatusException ( "Cannot delete status [$status_id], it is in use.", self::EXCEPTION_TYPE_IN_USE );
    }
    $sql = "DELETE FROM `status` WHERE `status_id`=?";
    $res = $this->db->execute ( $sql, $status_id );
    unset ( $this->cache [$status_id] );
    return $res->rowCount ();
  }
  function list_all() {
    // be nice, sort alphabetically
    $tmp = $this->cache;
    asort ( $tmp );
    $ret = array ();
    foreach ( $tmp as $id => $status ) {
      $ret [] = array (
          'status_id' => $id
          ,'status_name' => $status ['name']
          ,'category' => $status ['category']
          ,'visible_to_student' => $status ['visible_to_student']
          ,'cancelled'=>$status['cancelled'] 
      );
    }
    return $ret;
  }
  private function transition_exists($current_status_id, $next_status_id) {
    if (! isset ( $this->transitions [$current_status_id] ))
      return false;
    return in_array ( $next_status_id, $this->transitions [$current_status_id] );
  }
  function create_transition($current_status_id, $next_status_id, $event) {
    if ($this->transition_exists ( $current_status_id, $next_status_id )) {
      return true;
    }
    $sql = "
				INSERT IGNORE INTO `transition`
				SET
				`current_status_id`=:csi
				,`next_status_id`=:nsi
				,`event`=:event
				";
    $res = $this->db->execute ( $sql, array (
        'csi' => $current_status_id,
        'nsi' => $next_status_id,
        'event' => $event 
    ));
    if (! isset ( $this->transitions [$current_status_id] )) {
      $this->transitions [$current_status_id] = array ();
    }
    $this->transitions [$current_status_id] [$next_status_id] = $event;
    return true;
  }
  function delete_transition($current_status_id, $next_status_id) {
    if ($this->transition_exists ( $current_status_id, $next_status_id ) === FALSE) {
      return true;
    }
    $sql = "
				DELETE FROM `transition`
				WHERE
				`current_status_id`=:csi
				AND `next_status_id`=:nsi
				";
    $res = $this->db->execute ( $sql, array (
        'csi' => $current_status_id,
        'nsi' => $next_status_id 
    ) );
    unset ( $this->transitions [$current_status_id] [$next_status_id] );
  }
  function next_status_options($status_id) {
		if (empty ( $this->transitions [$status_id] )) {
      return array ();
    }
    $ret = $this->transitions [$status_id];
    $next = array ();
    foreach ( $ret as $next_status_id => $event ) {
      $next [$next_status_id] = array (
          'transition_event' => $event,
          'status' => $this->name_by_id ( $next_status_id ) 
      );
    }
    return $next;
  }
  function next_status_options_from_category($category='New') {
  	$sql="SELECT `status_id` FROM `status` WHERE `category`=:category";
  	$status_ids=$this->db->queryOneColumn($sql,compact('category'));
    $next = array ();
  	foreach($status_ids as $status_id){
  	  if (empty ( $this->transitions [$status_id] )) {
  		  continue;
  	  }
  	  $ret = $this->transitions [$status_id];
  	  foreach ( $ret as $next_status_id => $event ) {
  		  $next [$next_status_id] = array (
  				'transition_event' => $event,
  				'status' => $this->name_by_id ( $next_status_id )
  		  );
  	  }
  	}
  	return $next;
  }
}
class LICRStatusException extends Exception {
}

/*exceptions 14xx */