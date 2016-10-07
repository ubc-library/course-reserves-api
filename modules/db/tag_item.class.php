<?php

class TagItem {

  const EXCEPTION_TAG_NOT_FOUND = 701;
  const EXCEPTION_ITEM_NOT_FOUND = 702;
  const EXCEPTION_ITEM_ALREADY_IN_TAG = 703;

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

  function add_item($item_id, $tag_id) {
    if (!$this->licr->tag_mgr->exists($tag_id)) {
      throw new LICRTagItemException("TagItem::add_item tag [$tag_id] not found", self::EXCEPTION_TAG_NOT_FOUND);
    }
    if (!$this->licr->item_mgr->exists($item_id)) {
      throw new LICRTagItemException("TagItem::add_item item [$item_id] not found", self::EXCEPTION_ITEM_NOT_FOUND);
    }
    $sql="
        SELECT COUNT(`item_id`) FROM `tag_item`
        WHERE `item_id`=:item_id AND `tag_id`=:tag_id
      ";
    $res = $this->db->queryOneVal($sql, array('item_id' => $item_id, 'tag_id' => $tag_id));
    if($res){
      return $res;
    }
    $sql = "
      INSERT INTO `tag_item`
      SET `item_id`=:item_id, `tag_id`=:tag_id, `sequence`=-1
      ";
    $res = $this->db->execute($sql, array('item_id' => $item_id, 'tag_id' => $tag_id));
    $retval = $res->rowCount();
    if ($retval) {
      history_add('tag', $tag_id, "Added item $item_id");
      history_add('item', $item_id, "Added to tag $tag_id");
    }
    return $retval;
  }

  function remove_item($item_id, $tag_id) {
    if (!$this->licr->tag_mgr->exists($tag_id)) {
      throw new LICRTagItemException('TagItem::remove_item tag not found', self::EXCEPTION_TAG_NOT_FOUND);
    }
    if (!$this->licr->item_mgr->exists($item_id)) {
      throw new LICRTagItemException('TagItem::remove_item item not found', self::EXCEPTION_ITEM_NOT_FOUND);
    }
    $sql = "
      DELETE FROM `tag_item`
      WHERE `item_id`=:item_id AND `tag_id`=:tag_id
      ";
    $res = $this->db->execute($sql, array('item_id' => $item_id, 'tag_id' => $tag_id));
    $retval = $res->rowCount();
    if ($retval) {
      history_add('tag', $tag_id, "Removed item $item_id");
      history_add('item', $item_id, "Removed from tag $tag_id");
    }
    return $retval;
  }

  function list_items($tag_id) {
    if (!$this->licr->tag_mgr->exists($tag_id)) {
      throw new LICRTagItemException('TagItem::list_items tag not found', self::EXCEPTION_TAG_NOT_FOUND);
    }
    $sql = "
      SELECT `item_id` FROM `tag_item` 
      WHERE `tag_id`=?
      ORDER BY `sequence`
      ";
    $res = $this->db->queryOneColumn($sql, $tag_id);
    return $res;
  }

  function list_item_tags($item_id){
    $sql="
        SELECT `tag`.`tag_id`,`tag`.`name`
        FROM `tag` JOIN `tag_item` USING(`tag_id`)
        WHERE `tag_item`.`item_id`=:item_id
        ";
    $res=$this->db->queryAssoc($sql, 'tag_id', compact('item_id'));
    return $res;
  }
  
  function list_course_item_tags($course_id,$item_id){
    $sql="
        SELECT `tag`.`tag_id`,`tag`.`name`
        FROM `tag` JOIN `tag_item` USING(`tag_id`)
        WHERE `tag_item`.`item_id`=:item_id
    		AND `tag`.`course_id`=:course_id
        ";
    $res=$this->db->queryAssoc($sql, 'tag_id', compact('item_id','course_id'));
    foreach($res as $tag_id=>$tag_data){
      $res[$tag_id]=$tag_data['name'];
    }
    return $res;
  }
  
  function sequence($tag_id, $item_id_list) {
    $sql = "
      SELECT COUNT(*) FROM `tag_item`
      WHERE `tag_id`=?
      ";
    $icount = $this->db->queryOneVal($sql, $tag_id);
    if ($icount != count($item_id_list)) {
      throw new LICRTagItemException("TagItem::sequence item count mismatch", self::EXCEPTION_ITEM_COUNT);
    }
    $bind = array(
        'sequence' => 0
        , 'tag_id' => $tag_id
    );
    $sql = "
      UPDATE `tag_item` 
      SET `sequence`=:sequence 
      WHERE `tag_id`=:tag_id
      AND `item_id`=:item_id
      ";
    if ($this->db->beginTransaction()) {
      foreach ($item_id_list as $item_id) {
        $bind['sequence']++;
        $bind['item_id'] = $item_id;
        $this->db->query($sql, $bind);
      }
      $this->db->commit();
    }else{
      throw new LICRTagItemException('Cannot begin database transaction',self::EXCEPTION_DATABASE_ERROR);
    }
    return TRUE;
  }

}

class LICRTagItemException extends Exception {
  
}
