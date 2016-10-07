<?php
ssv('nonce',md5(date('U')/17.0));
?>
<form id="login" method="post">
  <input type="hidden" name="login" value="<?php echo sv('nonce'); ?>" />
  <table>
    <tr>
      <th>
        <label for="xpid">
          XP login:
        </label>
      </th>
      <td>
        <input name="xpid" id="xpid" type="text" tabindex="1" />
      </td>
      <td rowspan="2">
        <input type="submit" value="Login" tabindex="3" />
      </td>
    </tr>
    <tr>
      <th>
        <label for="xppass">
          Password:
        </label>
      </th>
      <td>
        <input name="xppass" id="xppass" type="password" tabindex="2" />
      </td>
    </tr>
  </table>
</form>
