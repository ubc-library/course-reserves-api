<?php

class Metrics
{

    const EXCEPTION_USER_NOT_FOUND = 1101;
    const EXCEPTION_ITEM_NOT_FOUND = 1102;

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

    function __construct($licr)
    {
        $this->licr = $licr;
        $this->db = $licr->db;
    }

    public function click($user_id, $item_id)
    {
        if (!$this->licr->user_mgr->exists($user_id)) {
            throw new LICRMetricsException('Metrics::click user not found', self::EXCEPTION_USER_NOT_FOUND);
        }
        if (!$this->licr->item_mgr->exists($item_id)) {
            throw new LICRMetricsException('Metrics::click item not found', self::EXCEPTION_ITEM_NOT_FOUND);
        }

        $sql = "INSERT IGNORE INTO `metrics` SET `user_id`=:user_id, `item_id`=:item_id";
        $res = $this->db->execute($sql, array('user_id' => $user_id, 'item_id' => $item_id));
        return $res->rowCount();
    }

    public function has_read($user_id, $item_id)
    {
        $sql = "SELECT COUNT(`time`) FROM `metrics` WHERE `user_id`=? AND `item_id`=?";
        $count = $this->db->queryOneVal($sql, array($user_id, $item_id));
        return $count;
    }

    public function item_reads_enrolled($course_id, $item_id)
    {
        $sql = "
      SELECT 
        U.`puid`,U.`lastname`,U.`firstname`,M.`time`
      FROM
        `user` U
        JOIN `metrics` M USING(`user_id`)
        JOIN `enrolment` E USING(`user_id`)
        JOIN `role` R USING(`role_id`)
      WHERE
        E.`course_id`=:course_id
        AND M.`item_id`=:item_id
        AND R.`name`='Student'
      ORDER BY
        U.`lastname`,U.`firstname` ASC
      ";
        $res = $this->db->queryRows($sql, array('course_id' => $course_id, 'item_id' => $item_id));
        return $res;
    }

    public function item_reads_enrolled_summary($course_id, $item_id)
    {
        $sql = "
      SELECT 
        DATE(M.`time`) AS day, COUNT(M.`user_id`) AS count
      FROM
        `user` U
        JOIN `metrics` M USING(`user_id`)
        JOIN `enrolment` E USING(`user_id`)
        JOIN `role` R USING(`role_id`)
      WHERE
        E.`course_id`=:course_id
        AND M.`item_id`=:item_id
        AND R.`name`='Student'
      GROUP BY
        DATE(M.`time`)
      ORDER BY
        M.`time` DESC
      ";
        $perday = $this->db->queryRows($sql, array('course_id' => $course_id, 'item_id' => $item_id));
        $sql = "
      SELECT 
        COUNT(*) AS total
      FROM
        `user` U
        JOIN `metrics` M USING(`user_id`)
        JOIN `enrolment` E USING(`user_id`)
        JOIN `role` R USING(`role_id`)
      WHERE
        E.`course_id`=:course_id
        AND M.`item_id`=:item_id
        AND R.`name`='Student'
      ";
        $total = $this->db->queryOneVal($sql, array('course_id' => $course_id, 'item_id' => $item_id));
        $res = array(
            'total' => $total,
            'perday' => $perday
        );
        return $res;
    }

    public function item_reads_all($item_id)
    {
        $sql = "
      SELECT 
        U.`puid`,U.`lastname`,U.`firstname`,MAX(M.`time`) AS time
      FROM
        `user` U
        JOIN `metrics` M USING(`user_id`)
        JOIN `course_item` CI USING(`item_id`)
        JOIN `enrolment` E USING(`user_id`,`course_id`)
      WHERE
        M.`item_id`=:item_id
      GROUP BY
        U.`puid`
      ORDER BY
        U.`lastname`,U.`firstname` ASC
      ";
        $res = $this->db->queryRows($sql, array('item_id' => $item_id));
        return $res;
    }

    function course_reads_enrolled($course_id)
    {
        $sql = "
        SELECT
          M.`item_id`
          , COUNT(M.`item_id`) AS `reads`
        FROM
          `metrics` M
          JOIN `course_item` CI USING(`item_id`)
          JOIN `enrolment` E USING(`course_id`,`user_id`)
        WHERE
          CI.`course_id`=:course_id
        GROUP BY M.`item_id`
        ORDER BY M.`item_id`
        ";
        $res = $this->db->queryAssoc($sql, 'item_id', compact('course_id'));
        return $res;
    }

    function course_reads_all($course_id)
    {
        $sql = "
        SELECT 
          M.`item_id`
          , COUNT(M.`item_id`) AS `reads`
        FROM 
          `metrics` M
          JOIN `course_item` CI USING(`item_id`)
        WHERE
          CI.`course_id`=:course_id
        GROUP BY M.`item_id`
        ORDER BY M.`item_id`  
        ";
        $res = $this->db->queryAssoc($sql, 'item_id', compact('course_id'));
        return $res;
    }

    function user_read_item($user_id, $item_id)
    {
        $sql = "SELECT COUNT(`time`) FROM `metrics`
        WHERE
        `user_id`=:user_id
        AND `item_id`=:item_id
         ";
        return $this->db->queryOneVal($sql, compact('user_id', 'item_id'));
    }

    function user_read_course($user_id, $course_id)
    {
        $sql = "
        SELECT 
          M.`item_id` 
        FROM 
          `metrics` M
          JOIN `course_item` CI USING(`item_id`)
        WHERE
          M.`user_id`=:user_id
          AND CI.`course_id`=:course_id
         ";
        return $this->db->queryOneColumn($sql, compact('user_id', 'course_id'));
    }
}

class LICRMetricsException extends Exception
{

}
