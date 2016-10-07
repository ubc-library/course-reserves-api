<h1>
  <a href="/"><em>LiCR</em></a>
</h1>
  <div id="licr_tabs">
  <div id="user"><?php echo sv('user')['firstname'].' '.sv('user')['lastname']; ?> logged in.  <a href="/?logout=1">Log out</a></div>
    <ul>
      <li><a href="#item-tab">Items</a></li>
      <li><a href="#course-tab">Courses</a></li>
      <li><a href="#user-tab">Users</a></li>
    </ul>
    <div id="item-tab">
      <?php include '../templates/widget.filter.item.tpl.php'; ?>
    </div>
    <div id="course-tab">
      <?php include '../templates/widget.filter.course.tpl.php'; ?>
    </div>
    <div id="user-tab">
      <?php include '../templates/widget.filter.user.tpl.php'; ?>
    </div>
<?php
//echo licrCall('GetCourseInfo',array('course'=>'679'),'html');
?>
  </div>