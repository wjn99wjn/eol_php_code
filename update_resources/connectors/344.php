<?php
namespace php_active_record;
/* connector for National Museum of Natural History Image Collection
estimated execution time: 7 secs.
Connector reads the XML provided by partner and 
- sets the image rating.
- If needed ingests TypeInformation text dataObjects
*/
include_once(dirname(__FILE__) . "/../../config/environment.php");
require_library('ResourceDataObjectElementsSetting');

$timestart = time_elapsed();
$resource_id = 344; 
$resource_path = "http://collections.mnh.si.edu/services/eol/nmnh-mammals-response-20120423.xml.gz"; //Mammals Resource
$nmnh = new ResourceDataObjectElementsSetting($resource_id, $resource_path, 'http://purl.org/dc/dcmitype/StillImage', 2);
$xml = $nmnh->set_data_object_rating_on_xml_document();

require_library('connectors/INBioAPI');
$xml = INBioAPI::assign_eol_subjects($xml);

$nmnh->save_resource_document($xml);
Functions::set_resource_status_to_force_harvest($resource_id);
$elapsed_time_sec = time_elapsed() - $timestart;
echo "\n";
echo "elapsed time = $elapsed_time_sec seconds             \n";
echo "elapsed time = " . $elapsed_time_sec/60 . " minutes  \n";
echo "elapsed time = " . $elapsed_time_sec/60/60 . " hours \n";
echo "\n\n Done processing.";
?>