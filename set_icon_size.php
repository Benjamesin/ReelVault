<?php
// Start the session
session_start();
// Check if the slider value is set in the query string
if (isset($_GET["value"])) {
  // Get the slider value and cast it to an integer
  $value = (int) $_GET["value"];
  // Set the $iconSize variable in the session
  $_SESSION["iconSize"] = $value;
  // Echo back the value for confirmation
  echo $value;
}
?>
