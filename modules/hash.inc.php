<?php

function purl_hash($table, $string) {
  global $licrdb;
  $chars = 'bcdfghjkmnpqrstvwxzBCDFGHJKLMNPQRSTVWXZ23456789';
  $sql = "SELECT `hash` FROM `$table` WHERE `hash`=?";
  $clear = $table . $string;
  $count = 0;
  $done = false;
  while (!$done) {
    $tmp = md5($clear . $count, true);
    $tmp = substr($tmp, 0, 6);
    $hash = '';
    for ($i = 0; $i < 6; $i++) {
      $hash.=$chars{ord($tmp{$i}) % strlen($chars)};
    }
    $done = !$licrdb->queryOneVal($sql, $hash);
    $count++;
  }
  return $hash;
}
