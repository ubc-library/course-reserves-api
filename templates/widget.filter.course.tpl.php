<form>
  <fieldset>
    <legend>Filter</legend>
    Search Title: <input type="search" size="30" id="filter_course_title" name="filter_course_title" />
    Search Instructor: <input type="search" size="30" id="filter_course_instructor" name="filter_course_instructor" />
    <br />
    <label for="filter_course_active">Active:</label> 
    <input type="checkbox" id="filter_course_active" name="filter_course_active" value="1" />
    <label for="filter_course_semester">Semester:</label> 
    <select name="filter_course_semester" id="select_semester"></select>
    <label for="course_branch_select">Branch:</label>
    <select class="branch_select" id="course_branch_select" name="branch_select"></select>
    <br />
    <input id="filter_course_save" type="button" value="Save" />
    Use: <select id="filter_course_select" name="filter_course_select"></select>
    <input id="filter_course_clear" type="reset" value="Clear" />
  </fieldset>
  <fieldset id="course_filter_results">
    <legend>Results</legend>
    No results.
  </fieldset>  
  <fieldset>
    <legend>Batch</legend>
    <input id="batch_course_dates" type="button" value="Set Start/End Dates" />
    <input id="batch_course_print" type="button" value="Print Selected courses" />
  </fieldset>
</form>
