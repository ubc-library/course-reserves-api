<?php

class Note {

  const EXCEPTION_NOTE_NOT_FOUND = 1201;
  const EXCEPTION_DATABASE_ERROR = 1202;
  const EXCEPTION_USER_NOT_FOUND = 1203;
  
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

  function exists($note_id) {
    $sql = "SELECT COUNT(*) FROM `note` WHERE `note_id`=? LIMIT 1";
    return $this->db->queryOneVal($sql, $note_id);
  }

  function create($user_id,$content,$time=NULL) {
    $user_info=$this->licr->user_mgr->info_by_id($user_id);
    if(!$user_info){
        throw new LICRNoteException('Note::create unrecognized user',self::EXCEPTION_USER_NOT_FOUND);
    }
    if(is_null($time)||!strtotime($time)){
      $time=date('Y-m-d H:i:s');
    }
    $sql = "INSERT INTO `note` SET `content`=:content, `user_id`=:user_id, `timestamp`=:time";
    $this->db->execute($sql, array('content'=>$content,'user_id'=>$user_id, 'time'=>$time));
    $note_id = $this->db->lastInsertId();
    history_add('note', $note_id, "Created note: $content");
    return $note_id;
  }

  function get($note_id) {
    $sql = "
        SELECT
          `user_id`
          ,`timestamp` 
          ,`content` 
        FROM 
          `note` 
        WHERE 
          `note_id`=?";
    $content = $this->db->queryOneRow($sql, $note_id);
    if (!$content) {
      throw new LICRNoteException("Note::get note [$note_id] not found.", self::EXCEPTION_NOTE_NOT_FOUND);
    }
    return $content;
  }

  function updateContent($note_id, $content) {
    if (!$this->exists($note_id)) {
        throw new LICRNoteException("Note::update note [$note_id] not found.", self::EXCEPTION_NOTE_NOT_FOUND);
    }
    $sql = "UPDATE `note` SET `content`=? WHERE `note_id`=?";
    $res = $this->db->execute($sql, array($content, $note_id));
    $retval = $res->rowCount();
    if (!$retval && !$res) {
        throw new LICRNoteException("Note::update could not update note [$note_id], and error occurred.", self::EXCEPTION_DATABASE_ERROR);
    }
    history_add('note', $note_id, "Updated note content: $content");
    return TRUE;
  }

  function updateRoles($note_id, $role_ids){
    if($this->db->beginTransaction()){
      $sql="DELETE FROM `note_role` WHERE `note_id`=?";
      $this->db->execute($sql, $note_id);
      $sql="INSERT INTO `note_role` SET `note_id`=:note_id, `role_id`=:role_id";
      $bind=array('note_id'=>$note_id);
      foreach($role_ids as $role_id){
        $bind['role_id']=$role_id;
        $this->db->execute($sql,$bind);
      }
      $this->db->commit();
    }else{
      throw new LICRNoteException("Note::updateRoles Cannot start transaction",self::EXCEPTION_DATABASE_ERROR);
    }
    history_add('note', $note_id, "Updated note roles: ".implode(',',$role_ids));
    return TRUE;
  }
  
  function delete($note_id) {
    if (!$this->exists($note_id)) {
      throw new LICRNoteException("Note::delete note [$note_id] not found.", self::EXCEPTION_NOTE_NOT_FOUND);
    }
    if ($this->db->beginTransaction()) {
      $sql = "DELETE FROM `note` WHERE `note_id`=?";
      $this->db->execute($sql, $note_id);
      $sql = "DELETE FROM `tag_note` WHERE `note_id`=?";
      $this->db->execute($sql, $note_id);
      $sql = "DELETE FROM `course_item_note` WHERE `note_id`=?";
      $this->db->execute($sql, $note_id);
      $sql = "DELETE FROM `note_role` WHERE `note_id`=?";
      $this->db->execute($sql, $note_id);
      history_add('note', $note_id, "Deleted note: $content");
      $this->db->commit();
    } else {
      throw new LICRNoteException('Failed to begin database transaction', self::EXCEPTION_DATABASE_ERROR);
    }
  }

}

class LICRNoteException extends Exception {
  
}