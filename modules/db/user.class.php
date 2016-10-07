<?php

class User
{
    const EXCEPTION_PARAMETER_NOT_ARRAY = 1601;
    const EXCEPTION_MISSING_FIELD = 1602;
    const EXCEPTION_USER_EXISTS = 1603;
    const EXCEPTION_NO_CHANGE_SPECIFIED = 1604;
    const EXCEPTION_USER_DOES_NOT_EXIST = 1605;
    private $fields = array(
        'firstname',
        'lastname',
        'email',
        'puid',
        'libraryid'
    );

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

    public function exists($user_id)
    {
        $sql = "SELECT COUNT(*) FROM `user` WHERE `user_id`=? LIMIT 1";
        $num = $this->db->queryOneVal($sql, $user_id);
        return $num;
    }

    /**
     * Create user, return user_id
     * $data is an associative array containing
     * firstname, lastname, email, puid
     *
     * @param array $data
     *          associative array
     * @return boolean integer
     * @throws LICRUserException
     */
    function create($data)
    {
        if (!is_array($data)) {
            throw new LICRUserException ('User::create parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        $data ['libraryid'] = $data ['libraryid'] ? $data ['libraryid'] : '';
        $data ['email'] = $data ['email'] ? $data ['email'] : '';
        $bind = array();
        foreach ($this->fields as $field) {
            if (isset ($data [$field])) {
                $bind [$field] = $data [$field];
            } else {
                throw new LICRUserException ("User::create Missing field $field", self::EXCEPTION_MISSING_FIELD);
            }
        }
        // if user exists, take this as an update
        if ($existing_info = $this->info_by_puid($data ['puid'])) {
            return $this->modify($existing_info ['user_id'], $data);
        }
        if (!empty ($data ['firstname']) && !empty ($data ['lastname']) && !empty ($data ['libraryid'])) {
            list ($data ['firstname'], $data ['lastname']) = $this->_fixname($data ['firstname'], $data ['lastname'], $data ['libraryid']);
        }
        foreach (array_keys($bind) as $col) {
            $fields [] = "
			`$col`=:$col
			";
        }
        $sql = "
				INSERT INTO `user`
				SET
				";
        $sql .= implode(',', $fields);
        $res = $this->db->execute($sql, $bind, false);
        if (!$res) {
            return false;
        }
        $user_id = $this->db->lastInsertId();
        history_add('user', $user_id, "Created user " . $data ['firstname'] . " " . $data ['lastname'] . " (" . $data ['puid'] . ")");
        return $user_id;
    }

    /**
     * Modify user
     *
     * @param integer $user_id
     * @param $data array
     * @return string boolean
     * @throws LICRUserException
     */
    function modify($user_id, $data)
    {
        if (!is_array($data)) {
            throw new LICRUserException ('User::modify second parameter must be array', self::EXCEPTION_PARAMETER_NOT_ARRAY);
        }
        $uinfo = $this->info_by_id($user_id);
        if (empty ($data ['libarryid']))
            $data ['libraryid'] = $uinfo ['libraryid'];
        if (!empty ($data ['firstname']) && !empty ($data ['lastname']) && !empty ($data ['libraryid'])) {
            list ($data ['firstname'], $data ['lastname']) = $this->_fixname($data ['firstname'], $data ['lastname'], $data ['libraryid']);
        }
        $bind = array();
        $changes = 0;
        foreach ($this->fields as $field) {
            if (!empty ($data [$field])) {
                $bind [$field] = $data [$field];
                if ($uinfo [$field] != $data [$field]) {
                    $changes++;
                }
            } else {
                $bind [$field] = $uinfo [$field];
            }
        }
        if ($changes == 0) {
            throw new LICRUserException ('User::modify No changes specified', self::EXCEPTION_NO_CHANGE_SPECIFIED);
        }
        foreach (array_keys($bind) as $col) {
            $fields [] = "
			`$col`=:$col
			";
        }
        $sql = "
				UPDATE `user`
				SET
				";
        $sql .= implode(',', $fields);
        $sql .= "
				WHERE `user_id`=:user_id
				";
        $bind ['user_id'] = $user_id;
        $res = $this->db->execute($sql, $bind, false);
        if (!$res) {
            return false;
        }
        $changes = '';
        foreach ($bind as $key => $val) {
            $changes .= "$key: $val,";
        }
        $affected = $res->rowCount();
        if ($affected) {
            history_add('user', $user_id, "Modified user $user_id: " . trim($changes, ','));
        }
        return ($affected ? 'OK' : 'No Change');
    }

    function refreshnames()
    {
        $sql = "SELECT `firstname`,`lastname`,`libraryid`,`puid` from `user`
				WHERE `libraryid`!=''
				AND (
				`firstname` NOT REGEXP('^[0-9A-Za-z \/,\-\.''\(\)]+$')
				OR
				`lastname`  NOT REGEXP('^[0-9A-Za-z \/,\-\.''\(\)]+$')
				)
				";
        $res = $this->db->execute($sql);
        $sql = "UPDATE `user` SET `firstname`=:newfirstname, `lastname`=:newlastname WHERE `puid`=:puid";
        $ret = '';
        while ($row = $res->fetch()) {
            extract($row);
            $ret .= "<p>$firstname $lastname";
            list ($newfirstname, $newlastname) = $this->_fixname($firstname, $lastname, $libraryid);
            if ($newfirstname !== $firstname || $newlastname !== $lastname) {
                $this->db->execute($sql, compact('newfirstname', 'newlastname', 'puid'));
                $ret .= " to $newfirstname $newlastname</p>";
            } else {
                $ret .= " No change</p>";
            }
        }
        return $ret;
    }

    private function _fixname($first, $last, $libraryid)
    {
        $first = trim(str_replace("\\'", "'", $first));
        $last = trim(str_replace("\\'", "'", $last));
        $tries = array();
        foreach (array(
                     'latin1',
                     'ISO-8859-1',
                     'Windows-1252',
                     'html'
                 ) as $enc) {
            $try = recode("$enc..utf8", $first . ' ' . $last);
            if (mb_strlen($try, $enc) < strlen("$first $last")) {
                $first = recode("$enc..utf8", $first);
                $last = recode("$enc..utf8", $last);
                break;
            }
        }
        if (@json_encode($first . $last) && strpos($first . $last, '?') === FALSE) {
            return array(
                $first,
                $last
            );
        }
        $fl = file_get_contents('http://parsnip.library.ubc.ca/getbarcode/namefromid.php?id=' . $libraryid);
        if ($fl) {
            $fl = json_decode($fl, true);
            if (isset ($fl ['firstname'])) {
                $newfirst = $fl ['firstname'];
                $newlast = $fl ['lastname'];
                return array(
                    trim($newfirst),
                    trim($newlast)
                );
            }
        }
        return array(
            $first,
            $last
        );
    }

    /**
     * Delete a user given user_id
     * return value should be 0 or 1 (number of users deleted)
     *
     * @param integer $user_id
     * @return integer
     * @throws LICRUserException
     */
    function delete_by_id($user_id)
    {
        $info = $this->info_by_id($user_id);
        if ($info) {
            $sql = "
					DELETE FROM `user`
					WHERE
					`user_id`=?
					LIMIT 1
					";
            $res = $this->db->execute($sql, $user_id);
            $affected = $res->rowCount();
            if ($affected) {
                history_add('user', $user_id, "Deleted user " . $info ['firstname'] . ' ' . $info ['lastname'] . ' (' . $info ['puid'] . ')');
            }
            return $affected;
        } else {
            throw new LICRUserException ('User::delete_by_id ' . $user_id . ' does not exist', self::EXCEPTION_USER_DOES_NOT_EXIST);
        }
    }

    /**
     * Delete a user given puid
     * return value should be 0 or 1 (number of users deleted)
     *
     * @param string $puid
     * @throws LICRUserException
     * @return integer
     */
    function delete_by_puid($puid)
    {
        $info = $this->info_by_puid($puid);
        if ($info) {
            $sql = "
					DELETE FROM `user`
					WHERE
					`puid`=?
					LIMIT 1
					";
            $res = $this->db->execute($sql, $puid);
            $affected = $res->rowCount();
            if ($affected) {
                history_add('user', $info ['user_id'], "Deleted user " . $info ['firstname'] . ' ' . $info ['lastname'] . ' (' . $info ['puid'] . ')');
            }
            return $affected;
        } else {
            throw new LICRUserException ('User::delete_by_puid ' . $puid . ' does not exist', self::EXCEPTION_USER_DOES_NOT_EXIST);
        }
    }

    /**
     * Return associative array of user info given user_id
     * return false if user not found
     *
     * @param integer $user_id
     * @return array boolean
     * @return array
     */
    function info_by_id($user_id)
    {
        $cols = array();
        foreach ($this->fields as $field) {
            $cols [] = "`$field`";
        }
        $sql = "
				SELECT
				" . implode(',', $cols) . "
				,`user_id`
				FROM
				`user`
				WHERE
				`user_id`=?
				LIMIT 1
				";
        $res = $this->db->queryOneRow($sql, $user_id);
        return $res;
    }

    /**
     * Return associative array of user info given puid
     * return false if user not found
     *
     * @param string $puid
     * @return array boolean
     */
    function info_by_puid($puid)
    {
        if (!$puid) return false;
        $cols = array();
        foreach ($this->fields as $field) {
            $cols [] = "`$field`";
        }
        $sql = "
				SELECT
				" . implode(',', $cols) . "
				,`user_id`
				FROM
				`user`
				WHERE
				`puid`=?
				LIMIT 1
				";
        $res = $this->db->queryOneRow($sql, $puid);
        return $res;
    }

    function puid_by_libraryid($libraryid)
    {
        $sql = "SELECT `puid` FROM `user` WHERE `libraryid`=? AND `libraryid` != '' AND `libraryid` IS NOT NULL";
        $res = $this->db->queryOneRow($sql, $libraryid);
        if ($res)
            return $res ['puid'];
        else
            return FALSE;
    }

    function search($name_fragment, $course_id = -1, $sis_role = FALSE)
    {
        $like = $this->db->likeQuote($name_fragment);
        $exact = FALSE;
        $searchlibraryid = is_numeric($name_fragment);
        if (strpos($name_fragment, '"') !== FALSE) {
            preg_match_all('/"([^"]*)"/', $name_fragment, $matches);
            if (isset($matches[1][0])) {
                $exact = $matches[1][0];
            }
        }
        if ($course_id == -1) {
            $sql = "
					SELECT DISTINCT
					U.`user_id`
					,U.`firstname`
					,U.`lastname`
					,U.`puid`
					,U.`libraryid`
					,U.`email`
					FROM
					`user` U";
            if ($sis_role) {
                $sql .= "
						JOIN `enrolment` E USING(`user_id`)
						";
            }
            if ($exact) {
                $sql .= "
					WHERE
					((
					  `puid`=?
					  OR `firstname`=?
					  OR `lastname`=?
					  OR `email`=?
				";
                $bind = array(
                    $exact,
                    $exact,
                    $exact,
                    $exact
                );

            } else {
                $sql .= "
					WHERE
					((
			  		`puid` LIKE ?
			      OR CONCAT(`firstname`,' ',`lastname`,' ',`firstname`,' ',`email`) LIKE ?
					";
                $bind = array(
                    $like,
                    $like
                );
            }
            if ($searchlibraryid) {
                $sql .= "
						OR `libraryid` = ?
						";
                $bind[] = $name_fragment;
            }
            $sql .= ")";
            if ($sis_role) {
                $sql .= "
						AND E.`sis_role`=?";
                $bind [] = $sis_role;
            }
            $sql .= "
					)
			    ORDER BY `lastname`,`firstname`
					";
        } else {
            $sql = "
					SELECT DISTINCT
					U.`user_id`
					,U.`firstname`
					,U.`lastname`
					,U.`puid`
					,U.`email`
					,U.`libraryid`
					FROM
					`user` U JOIN `enrolment` E USING(`user_id`)";
            if ($exact) {
                $sql .= "
					WHERE
					((
					  `puid`=?
					  OR `firstname`=?
					  OR `lastname`=?
					  OR `email`=?
				";
                $bind = array(
                    $exact,
                    $exact,
                    $exact,
                    $exact
                );

            } else {
                $sql .= "
				WHERE
					((
			  		U.`puid` LIKE ?
			      OR CONCAT(U.`firstname`,' ',U.`lastname`,' ',U.`firstname`,' ',U.`email`) LIKE ?
					";
                $bind = array(
                    $like,
                    $like
                );
            }
            if ($searchlibraryid) {
                $sql .= "
						OR `libraryid` = ?
						";
                $bind[] = $name_fragment;
            }
            $sql .= ")";
            if ($sis_role) {
                $sql .= "
						AND `enrolment`.`sis_role`=?";
                $bind [] = $sis_role;
            }
            $sql .= "
			     )
					 AND `enrolment`.`course_id`=?
			";
            $bind[] = $course_id;
            $sql .= "
			    ORDER BY `lastname`,`firstname`
			    ";
        }
        $res = $this->db->queryAssoc($sql, 'user_id', $bind);
        return $res;
    }
}

class LICRUserException extends Exception
{
}

