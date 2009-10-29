<?php

system("clear");
$attr = @$argv[1];
$id = @$argv[2];
$self = @$argv[3];

if($attr != "-id" || !$id || !is_numeric($id) || ($self && $self!="-self"))
{
    echo "\n\n\tcompare_hierarchy.php -id [hierarchy_id] [-self]\n\n";
    exit;
}


define("ENVIRONMENT", "integration");
// define('DEBUG', true);
// define('MYSQL_DEBUG', true);
//define('DEBUG_TO_FILE', true);
include_once(dirname(__FILE__)."/../config/start.php");

$mysqli =& $GLOBALS['mysqli_connection'];


ob_start();

$hierarchy = new Hierarchy($id);
if($hierarchy)
{
    $hierarchy2 = null;
    if($self) $hierarchy2 = $hierarchy;
    CompareHierarchies::process_hierarchy($hierarchy, $hierarchy2, true);
}else echo "\nNo hierarchy with id $id\n\n";


?>