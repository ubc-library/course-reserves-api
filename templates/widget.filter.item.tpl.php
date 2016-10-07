<form>
  <fieldset>
    <legend>Filter</legend>
    Search: <input type="search" size="60" id="filter_item_search" name="filter_item_search" />
    <br />
    Branch: <select id="branch_select" name="branch_select"></select>
    Status: <select id="status_select" name="status_select"></select>
    Type: <select id="type_select" name="type_select"></select>
    <br />
    <input id="filter_course_use" type="checkbox" name="filter_course_use" /> Use current courses filter
    <br />
    <input id="filter_item_save" type="button" value="Save" />
    Use: <select id="filter_item_select" name="filter_item_select"></select>
    <input id="filter_item_clear" type="reset" value="Clear" />
  </fieldset>
  <fieldset id="item_filter_results">
    <legend>Results</legend>
    No results.
  </fieldset>  
  <fieldset>
    <legend>Batch</legend>
    <input id="batch_item_status" type="button" value="Set Status" />
    <input id="batch_item_dates" type="button" value="Set Start/End Dates" />
    <input id="batch_item_print" type="button" value="Print Selected Items" />
    <input id="batch_item_pickslips" type="button" value="Print Pick Slips" />
  </fieldset>
</form>
