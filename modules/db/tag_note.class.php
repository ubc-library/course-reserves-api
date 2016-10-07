<?php
class TagNote {
  const EXCEPTION_NOTE_NOT_FOUND = 801;
  const EXCEPTION_TAG_NOT_FOUND = 802;
  const EXCEPTION_ROLE_NOT_FOUND = 803;
  const EXCEPTION_CANNOT_DELETE = 804;
  const EXCEPTION_CANNOT_CREATE = 805;
  
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
   * return success
   * 
   * @param int $tag_id          
   * @param int $note_id          
   * @param array $role_ids          
   */
  function add($tag_id, $note_id, $role_ids) {
    if (! $this->licr->note_mgr->exists ( $note_id )) {
      throw new LICRTagNoteException ( "TagNote::add Note [$note_id] not found", self::EXCEPTION_NOTE_NOT_FOUND );
    }
    if (! $this->licr->tag_mgr->exists ( $tag_id )) {
      throw new LICRTagNoteException ( "TagNote::add Tag [$tag_id] not found", self::EXCEPTION_TAG_NOT_FOUND );
    }
    if (! is_array ( $role_ids ))
      $role_ids = array (
          $role_ids 
      );
    foreach ( $role_ids as $role_id ) {
      if (! $this->licr->role_mgr->name_by_id ( $role_id )) {
        throw new LICRTagNoteException ( "TagNote::add Role [$role_id] not found", self::EXCEPTION_ROLE_NOT_FOUND );
      }
    }
    if ($this->db->beginTransaction ()) {
      $sql = "INSERT INTO `tag_note` SET `tag_id`=:tag_id, `note_id`=:note_id";
      $bind = array (
          'tag_id' => $tag_id,
          'note_id' => $note_id 
      );
      $this->db->execute ( $sql, $bind );
      $sql = "INSERT INTO `note_role` SET `note_id`=:note_id,`role_id`=:role_id";
      $bind = array (
          'note_id' => $note_id 
      );
      foreach ( $role_ids as $role_id ) {
        $bind ['role_id'] = $role_id;
        $this->db->execute ( $sql, $bind );
      }
      $this->db->commit ();
    } else {
      throw new LICRTagNoteException ( 'Cannot begin transaction', self::EXCEPTION_CANNOT_CREATE );
    }
    return TRUE;
  }
  function delete($tag_id, $note_id) {
    // architectural question, do we delete the note too
    // i think maybe not, that can happen at the next level of abstraction
    // definitely we wouldn't do it if we reused notes, but that would be dumb
    // note that Note::delete actually does delete from here though
    // so probably this function should be avoided to minimize the possibility
    // of orphaning notes in the `note` table
    if (! $this->licr->note_mgr->exists ( $note_id )) {
      throw new LICRTagNoteException ( "TagNote::delete Note [$note_id] not found", self::EXCEPTION_NOTE_NOT_FOUND );
    }
    if (! $this->licr->tag_mgr->exists ( $tag_id )) {
      throw new LICRTagNoteException ( "TagNote::delete Tag [$tag_id] not found", self::EXCEPTION_TAG_NOT_FOUND );
    }
    if ($this->db->beginTransaction ()) {
      $sql = "DELETE FROM `note_role` WHERE `note_id`=?";
      $this->db->execute ( $sql, $note_id );
      $sql = "DELETE FROM `tag_note` WHERE `tag_id`=? AND `note_id`=?";
      $res = $this->db->execute ( $sql, array (
          $tag_id,
          $note_id 
      ) );
      $this->db->commit ();
    } else {
      throw new LICRTagNoteException ( "TagNote::delete Cannot begin transaction", self::EXCEPTION_CANNOT_DELETE );
    }
    return $res->rowCount ();
  }
  
  function get_notes($tag_id, $role_ids) {
    if (! $this->licr->tag_mgr->exists ( $tag_id )) {
      throw new LICRTagNoteException ( "TagNote::get_notes Tag [$tag_id] not found", self::EXCEPTION_TAG_NOT_FOUND );
    }
    if (! is_array ( $role_ids ))
      $role_ids = array (
          $role_ids 
      );
    foreach ( $role_ids as $role_id ) {
      if (! $this->licr->role_mgr->name_by_id ( $role_id )) {
        throw new LICRTagNoteException ( "TagNote::get_notes Role [$role_id] not found", self::EXCEPTION_ROLE_NOT_FOUND );
      }
    }
    $sql = "
      SELECT 
        `note`.`note_id`, 
        `note`.`content`, 
        `note`.`timestamp`,
        `user`.`firstname`,
        `user`.`lastname`,
        `user`.`user_id`
      FROM 
        `tag_note`,
        JOIN `note` USING(`note_id`) 
        JOIN `note_role` USING(`note_id`)
        JOIN `role` USING(`role_id`)
        JOIN `user` USING(`user_id`)
      WHERE
          `tag_id`=?
          AND `note_role`.`role_id` IN(" . implode ( ',', $role_ids ) . ")
    ";
    $res = $this->db->queryAssoc ( $sql, 'note_id', array (
        $item_id,
        $course_id 
    ) );
    $sql = "
        SELECT 
        `role`.`role_id`
        ,`role`.`name`
        FROM 
        `role` JOIN `note_role` USING(`role_id`)
        WHERE 
        `note_role`.`note_id`=?
        ORDER BY `role`.`role_id`
        ";
    foreach ( $res as $note_id => $row ) {
      $nres = $this->db->queryRows ( $sql, $note_id );
      $res [$note_id] ['role'] = $nres;
    }
    return $res;
  }

}
class LICRTagNoteException extends Exception {
}

