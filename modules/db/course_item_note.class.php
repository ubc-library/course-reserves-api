<?php
class CourseItemNote {
  const EXCEPTION_NOTE_NOT_FOUND = 2101;
  const EXCEPTION_ITEM_NOT_FOUND = 2102;
  const EXCEPTION_ROLE_NOT_FOUND = 2103;
  const EXCEPTION_CANNOT_DELETE = 2104;
  const EXCEPTION_CANNOT_CREATE = 2105;
  const EXCEPTION_COURSE_NOT_FOUND = 2106;
  
  /**
   * * @var LICR
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
   * @param type $item_id          
   * @param type $audience          
   * @param type $content          
   */
  function add($item_id, $course_id, $note_id, $role_ids) {
    if (! $this->licr->note_mgr->exists ( $note_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote::add Note [$note_id] not found", self::EXCEPTION_NOTE_NOT_FOUND );
    }
    if (! $this->licr->item_mgr->exists ( $item_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote::add Item [$item_id] not found", self::EXCEPTION_ITEM_NOT_FOUND );
    }
    if (! $this->licr->course_mgr->exists ( $course_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote::add Course [$course_id] not found", self::EXCEPTION_COURSE_NOT_FOUND );
    }
    if (! is_array ( $role_ids ))
      $role_ids = array (
          $role_ids 
      );
    foreach ( $role_ids as $role_id ) {
      if (! $this->licr->role_mgr->name_by_id ( $role_id )) {
        throw new LICRCourseItemNoteException ( "CourseItemNote::add Role [$role_id] not found", self::EXCEPTION_ROLE_NOT_FOUND );
      }
    }
    $alreadyInTransaction = $this->db->inTransaction ();
    if (! $alreadyInTransaction) {
      if (! $this->db->beginTransaction ()) {
        throw new LICRCourseItemNoteException ( 'Cannot begin transaction', self::EXCEPTION_CANNOT_CREATE );
      }
    }
    $sql = "INSERT INTO `course_item_note` SET `item_id`=:item_id, `course_id`=:course_id, `note_id`=:note_id";
    $bind = array (
        'item_id' => $item_id,
        'course_id' => $course_id,
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
    if (! $alreadyInTransaction) {
      $this->db->commit ();
    }
    return TRUE;
  }
  function delete($item_id, $note_id) {
    // architectural question, do we delete the note too
    // i think maybe not, that can happen at the next level of abstraction
    // definitely we wouldn't do it if we reused notes, but that would be dumb
    // note that Note::delete actually does delete from here though
    // so probably this function should be avoided to minimize the possibility
    // of orphaning notes in the `note` table
    if (! $this->licr->note_mgr->exists ( $note_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote::delete Note [$note_id] not found", self::EXCEPTION_NOTE_NOT_FOUND );
    }
    if (! $this->licr->item_mgr->exists ( $item_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote::delete Item [$item_id] not found", self::EXCEPTION_ITEM_NOT_FOUND );
    }
    if ($this->db->beginTransaction ()) {
      $sql = "DELETE FROM `note_role` WHERE `note_id`=?";
      $this->db->execute ( $sql, $note_id );
      $sql = "DELETE FROM `course_item_note` WHERE `item_id`=? AND `note_id`=?";
      $res = $this->db->execute ( $sql, array (
          $item_id,
          $note_id 
      ) );
      $this->db->commit ();
    } else {
      throw new LICRCourseItemNoteException ( "CourseItemNote::delete Cannot begin transaction", self::EXCEPTION_CANNOT_DELETE );
    }
    return $res->rowCount ();
  }
  function get_notes($item_id, $course_id, $role_ids) {
    if ($item_id && ! $this->licr->item_mgr->exists ( $item_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote::get_notes Item [$item_id] not found", self::EXCEPTION_ITEM_NOT_FOUND );
    }
    if (! $this->licr->course_mgr->exists ( $course_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote::get_notes Course [$course_id] not found.", self::EXCEPTION_COURSE_NOT_FOUND );
    }
    if ($item_id && ! $this->licr->course_item_mgr->exists ( $course_id, $item_id )) {
      throw new LICRCourseItemNoteException ( "CourseItemNote:get_notes Item [$item_id] is not in course [$course_id]", self::EXCEPTION_ITEM_NOT_FOUND );
    }
    if (! is_array ( $role_ids ))
      $role_ids = array (
          $role_ids 
      );
    foreach ( $role_ids as $role_id ) {
      if (! $this->licr->role_mgr->name_by_id ( $role_id )) {
        throw new LICRCourseItemNoteException ( "CourseItemNote::get_notes Role [$role_id] not found", self::EXCEPTION_ROLE_NOT_FOUND );
      }
    }
    $bind = array (
        'course_id' => $course_id 
    );
    if ($item_id) {
      $bind ['item_id'] = $item_id;
    }
    $sql = "
      SELECT 
        DISTINCT(N.`note_id`) 
        ,N.`content`
        ,N.`timestamp`
        ,U.`firstname`
        ,U.`lastname`
        ,U.`user_id`
        ,CIN.`item_id`
      FROM 
        `course_item_note` CIN
        JOIN `note` N USING(`note_id`)
        JOIN `note_role` NR USING(`note_id`)
        JOIN `role` R USING(`role_id`)
        JOIN `user` U USING(`user_id`)
      WHERE
        CIN.`course_id`=:course_id
        AND NR.`role_id` IN(" . implode ( ',', $role_ids ) . ")
    ";
    if ($item_id) {
      $sql .= "
        AND CIN.`item_id`=:item_id
          ";
    }
    $res = $this->db->queryRows ( $sql, $bind );
    $ret = array ();
    foreach ( $res as $i => $note ) {
      $ret [$note ['item_id']] = array ();
    }
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
    foreach ( $res as $i => $note ) {
      $nres = $this->db->queryRows ( $sql, $note ['note_id'] );
      $ret [$note ['item_id']] [] = array_merge ( $note, array (
          'role' => $nres 
      ) );
    }
    return $ret;
  }
}
class LICRCourseItemNoteException extends Exception {
}
