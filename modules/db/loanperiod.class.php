<?php

class Loanperiod {

  const EXCEPTION_NAME_ALREADY_EXISTS = 3001;
  const EXCEPTION_LOANPERIOD_NOT_FOUND = 3002;
  const EXCEPTION_LOANPERIOD_IN_USE = 3004;

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
    $sql = "SELECT `loanperiod_id`, `period` FROM `loanperiod`";
    $res = $this->db->execute($sql);
    $this->cache = array();
    while ($row = $res->fetch()) {
      $this->cache[$row['loanperiod_id']] = $row['period'];
    }
  }

  function list_all() {
    return $this->cache;
  }

  function exists($loanperiod_id) {
    return isset($this->cache[$loanperiod_id]);
  }

  /**
   * Create new loanperiod given a period.
   * This should rarely, if ever, be called.
   * Return value should be 1; 0 would indicate a database error.
   * 
   * @param string $period
   * @return int
   * @throws LICRLoanperiodException 
   */
  function create($period) {
    $sql = "SELECT COUNT(*) FROM `loanperiod` WHERE `period`=?";
    $num = $this->db->queryOneVal($sql, $period);
    if ($num) {
      throw new LICRLoanperiodException('Loanperiod::create Loanperiod with this period already exists', self::EXCEPTION_LOANPERIOD_ALREADY_EXISTS);
    }
    $sql = "INSERT INTO `loanperiod` SET `period`=:period";
    $this->db->execute($sql, array('period' => $period));
    $loanperiod_id = $this->db->lastInsertId();
    $this->cache[$loanperiod_id] = $period;
    return $loanperiod_id;
  }

  /**
   * Get loanperiod period from loanperiod_id
   * 
   * @param int $loanperiod_id
   * @return string
   * @throws LICRLoanperiodException 
   */
  function period_by_id($loanperiod_id) {
    if (isset($this->cache[$loanperiod_id])) {
      return $this->cache[$loanperiod_id];
    }
    $sql = "SELECT `period` FROM `loanperiod` WHERE `loanperiod_id`=?";
    $period = $this->db->queryOneVal($sql, $loanperiod_id);
    if (!$period) {
      throw new LICRLoanperiodException('Loanperiod::period_by_id Loanperiod not found', self::EXCEPTION_TYPE_NOT_FOUND);
    }
    return $period;
  }

  /**
   * Redefine a loanperiod
   * @param int $loanperiod_id
   * @param string $period
   * @return TRUE 
   */
  function update($loanperiod_id, $period) {
    $sql = "SELECT COUNT(*) FROM `loanperiod` WHERE `period`=?";
    $num = $this->db->queryOneVal($sql, $period);
    if ($num) {
      throw new LICRLoanperiodException('Loanperiod::update Loanperiod with this period already exists', self::EXCEPTION_NAME_ALREADY_EXISTS);
    }
    $sql = 'UPDATE `loanperiod` SET `period`=:period WHERE `loanperiod_id`=:loanperiod_id';
    $res = $this->db->execute($sql, array('period' => $period, 'loanperiod_id' => $loanperiod_id));
    $this->cache[$loanperiod_id] = $period;
    history_log('loanperiod', $loanperiod_id, "Redefined as [$period]");
    return TRUE;
  }

  /**
   * 
   * @param int $loanperiod_id
   * @return int
   * @throws LICRLoanperiodException
   */
  function delete($loanperiod_id) {
    //can't delete a loanperiod that is in use...
    $sql = "SELECT COUNT(*) FROM `course_item` WHERE `loanperiod_id`=?";
    $num = $this->db->queryOneVal($sql, $loanperiod_id);
    if ($num) {
      throw new LICRLoanperiodException("Cannot delete loanperiod [$loanperiod_id], it is in use.", self::EXCEPTION_LOANPERIOD_IN_USE);
    }
    $sql = "DELETE FROM `loanperiod` WHERE `loanperiod_id`=?";
    $res = $this->db->execute($sql, $loanperiod_id);
    unset($this->cache[$loanperiod_id]);
    return $res->rowCount();
  }

  
  /**
   * can return FALSE 
   * @param string $period
   * @return boolean|int
   */
  function id_by_name($period) {
    $loanperiod_id = array_search($period, $this->cache);
    return $loanperiod_id;
  }

}

class LICRLoanperiodException extends Exception {
  
}