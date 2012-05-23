<?php
namespace php_active_record;
/* connector: 323 */

define("DEVELOPER_KEY", "AI39si4JyuxT-aemiIm9JxeiFbr4F3hphhrhR1n3qPkvbCrrLRohUbBSA7ngDqku8mUGEAhYZpKDTfq2tu_mDPImDAggk8At5Q");
define("YOUTUBE_EOL_USER", "EncyclopediaOfLife");
define("YOUTUBE_API", "http://gdata.youtube.com/feeds/api");

class YouTubeAPI
{
    public static function get_all_taxa()
    {
        $all_taxa = array();
        $used_collection_ids = array();
        $usernames_of_subscribers = self::get_subscriber_usernames();
        $user_video_ids = self::get_upload_videos_from_usernames($usernames_of_subscribers);
        print_r($user_video_ids);
        $total_users = count($usernames_of_subscribers);
        $user_index = 0;
        foreach($usernames_of_subscribers as $username)
        {
            $user_index++;
            if(@!$user_video_ids[$username]) continue;
            $number_of_user_videos = count($user_video_ids[$username]);
            $video_index = 0;
            foreach($user_video_ids[$username] as $video_id)
            {
                $video_index++;
                if($GLOBALS['ENV_DEBUG']) echo "[user $user_index of $total_users] [video $video_index of $number_of_user_videos]\n";
                if($record = self::build_data($video_id))
                {
                    $arr = self::get_youtube_taxa($record, $used_collection_ids);
                    $page_taxa              = $arr[0];
                    $used_collection_ids    = $arr[1];
                    if($page_taxa) $all_taxa = array_merge($all_taxa, $page_taxa);
                }
            }
        }
        return $all_taxa;
    }

    public static function get_youtube_taxa($record, $used_collection_ids)
    {
        //this will output the raw (but structured) array
        $response = self::parse_xml($record);
        $page_taxa = array();
        foreach($response as $rec)
        {
            if(@$used_collection_ids[$rec["taxon_id"]]) continue;
            $taxon = self::get_taxa_for_photo($rec);
            if($taxon) $page_taxa[] = $taxon;
            @$used_collection_ids[$rec["taxon_id"]] = true;
        }
        return array($page_taxa, $used_collection_ids);
    }

    public static function build_data($video_id)
    {
        $url = YOUTUBE_API  . '/videos/' . $video_id . '?v=2&alt=json';
        $raw_json = Functions::get_remote_file($url);
        $json_object = json_decode($raw_json);
        if(!@$json_object->entry->id)
        {
            if($GLOBALS['ENV_DEBUG']) echo "$url -- invalid response\n";
            return;
        }
        $license = @$json_object->entry->{'media$group'}->{'media$license'}->href;
        if(!$license || !preg_match("/^http:\/\/creativecommons.org\/licenses\//", $license))
        {
            if($GLOBALS['ENV_DEBUG']) echo "$url -- invalid license\n";
            return;
        }
        
        
        $thumbnailURL = @$json_object->entry->{'media$group'}->{'media$thumbnail'}[1]->url;
        $mediaURL = @$json_object->entry->{'media$group'}->{'media$content'}[0]->url;
        
        // For a while we used the API URL for the identifier (not sure why). Just 
        // trying to preserve that so I don't lose all curation/rating information
        // for the existing objects. Really we just need to use the video ID: -ravHVw8K4U
        // video IDs starting with '-' must have the - tuened into /
        // eg: -ravHVw8K4U becomes /ravHVw8K4U
        $identifier_video_id = $video_id;
        if(substr($identifier_video_id, 0, 1) == "-") $identifier_video_id = "/" . trim(substr($identifier_video_id, 1));
        return array("id"            => YOUTUBE_API  . '/videos?q=' . $identifier_video_id . '&license=cc&v=2',
                     "author"        => $json_object->entry->author[0]->name->{'$t'},
                     "author_uri"    => $json_object->entry->author[0]->uri->{'$t'},
                     "author_detail" => $json_object->entry->author[0]->uri->{'$t'},
                     "author_url"    => "http://www.youtube.com/user/" . $json_object->entry->author[0]->name->{'$t'},
                     "media_title"   => $json_object->entry->title->{'$t'},
                     "description"   => str_replace("\r\n", "<br/>", trim($json_object->entry->{'media$group'}->{'media$description'}->{'$t'})),
                     "thumbnail"     => $json_object->entry->{'media$group'}->{'media$thumbnail'}[1]->url,
                     "sourceURL"     => 'http://youtu.be/' . $video_id,
                     "mediaURL"      => $json_object->entry->{'media$group'}->{'media$content'}[0]->url,
                     "video_id"      => $video_id );
    }

    private static function parse_xml($rec)
    {
        $arr_data = array();
        $description = Functions::import_decode($rec['description']);
        $description = str_ireplace("<br />", "", $description);
        $license = "";
        $arr_sciname = array();
        if(preg_match_all("/\[(.*?)\]/ims", $description, $matches))//gets everything between brackets []
        {
            $smallest_taxa = self::get_smallest_rank($matches[1]);
            $smallest_rank = $smallest_taxa['rank'];
            $sciname       = $smallest_taxa['name'];
            //smallest rank sciname: [$smallest_rank][$sciname]
            $multiple_taxa_YN = self::is_multiple_taxa_video($matches[1]);
            if(!$multiple_taxa_YN) $arr_sciname = self::initialize($sciname);
            foreach($matches[1] as $tag)
            {
                $tag=trim($tag);
                if($multiple_taxa_YN)
                {
                    if(is_numeric(stripos($tag,$smallest_rank)))
                    {
                        if(preg_match("/^taxonomy:" . $smallest_rank . "=(.*)$/i", $tag, $arr))$sciname = ucfirst(trim($arr[1]));
                        $arr_sciname = self::initialize($sciname,$arr_sciname);
                    }
                }
                if(preg_match("/^taxonomy:binomial=(.*)$/i", $tag, $arr))       $arr_sciname[$sciname]['binomial']  = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:trinomial=(.*)$/i", $tag, $arr))  $arr_sciname[$sciname]['trinomial'] = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:genus=(.*)$/i", $tag, $arr))      $arr_sciname[$sciname]['genus']     = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:family=(.*)$/i", $tag, $arr))     $arr_sciname[$sciname]['family']    = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:order=(.*)$/i", $tag, $arr))      $arr_sciname[$sciname]['order']     = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:class=(.*)$/i", $tag, $arr))      $arr_sciname[$sciname]['class']     = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:phylum=(.*)$/i", $tag, $arr))     $arr_sciname[$sciname]['phylum']    = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:kingdom=(.*)$/i", $tag, $arr))    $arr_sciname[$sciname]['kingdom']   = ucfirst(trim($arr[1]));
                elseif(preg_match("/^taxonomy:common=(.*)$/i", $tag, $arr))     $arr_sciname[$sciname]['commonNames'][]  = trim($arr[1]);
            }
            foreach($matches[0] as $str) $description = str_ireplace($str, "", trim($description));
        }

        $license = 'http://creativecommons.org/licenses/by/3.0/';

        foreach($arr_sciname as $sciname => $temp)
        {
            if(!$sciname && @$arr_sciname[$sciname]['trinomial']) $sciname = @$arr_sciname[$sciname]['trinomial'];
            if(!$sciname && @$arr_sciname[$sciname]['genus'] && @$arr_sciname[$sciname]['species'] && !preg_match("/ /", @$arr_sciname[$sciname]['genus']) && !preg_match("/ /", @$arr_sciname[$sciname]['species'])) $sciname = @$arr_sciname[$sciname]['genus']." ".@$arr_sciname[$sciname]['species'];                        
            if(!$sciname && !@$arr_sciname[$sciname]['genus'] && !@$arr_sciname[$sciname]['family'] && !@$arr_sciname[$sciname]['order'] && !@$arr_sciname[$sciname]['class'] && !@$arr_sciname[$sciname]['phylum'] && !@$arr_sciname[$sciname]['kingdom']) return array();
                        
            //start data objects //----------------------------------------------------------------------------------------
            $arr_objects = array();
            $identifier  = $rec['id'];
            $dataType    = "http://purl.org/dc/dcmitype/MovingImage";
            $mimeType    = "video/x-flv";
            if(trim($rec['media_title'])) $title = $rec['media_title'];
            else                          $title = "YouTube video";
            $source       = $rec['sourceURL'];
            $mediaURL     = $rec['mediaURL'];
            $thumbnailURL = $rec['thumbnail'];
            $agent = array();
            if($rec['author']) $agent[] = array("role" => "author" , "homepage" => $rec['author_url'] , $rec['author']);
            if(stripos($description, "<br>Author: ") == "")
            {
                $description .= "<br><br>Author: <a href='$rec[author_url]'>$rec[author]</a>";
                $description .= "<br>Source: <a href='$rec[sourceURL]'>YouTube</a>";
            }
            $arr_objects = self::add_objects($identifier, $dataType, $mimeType, $title, $source, $description, $mediaURL, $agent, $license, $thumbnailURL, $arr_objects);
            //end data objects //----------------------------------------------------------------------------------------

            $taxon_id   = str_ireplace(" ", "_", $sciname) . '_' . $rec['video_id'];
            $arr_data[]=array(  "identifier"   => "",
                                "source"       => "",
                                "kingdom"      => $arr_sciname[$sciname]['kingdom'],
                                "phylum"       => $arr_sciname[$sciname]['phylum'],
                                "class"        => $arr_sciname[$sciname]['class'],
                                "order"        => $arr_sciname[$sciname]['order'],
                                "family"       => $arr_sciname[$sciname]['family'],
                                "genus"        => $arr_sciname[$sciname]['genus'],
                                "sciname"      => $sciname,
                                "taxon_id"     => $taxon_id,
                                "commonNames"  => @$arr_sciname[$sciname]['commonNames'],
                                "arr_objects"  => $arr_objects
                             );
        }
        return $arr_data;
    }

    private static function initialize($sciname, $arr_sciname=NULL)
    {
        $arr_sciname[$sciname]['binomial']    = "";
        $arr_sciname[$sciname]['trinomial']   = "";
        $arr_sciname[$sciname]['subspecies']  = "";
        $arr_sciname[$sciname]['species']     = "";
        $arr_sciname[$sciname]['genus']       = "";
        $arr_sciname[$sciname]['family']      = "";
        $arr_sciname[$sciname]['order']       = "";
        $arr_sciname[$sciname]['class']       = "";
        $arr_sciname[$sciname]['phylum']      = "";
        $arr_sciname[$sciname]['kingdom']     = "";
        $arr_sciname[$sciname]['commonNames'] = array();
        return $arr_sciname;
    }

    private static function is_multiple_taxa_video($arr)
    {
        $taxa=array();
        foreach($arr as $tag)
        {
            if(preg_match("/^taxonomy:(.*)\=/i", $tag, $arr))
            {
                $rank = trim($arr[1]);
                if(in_array($rank,$taxa)) return 1;
                $taxa[] = $rank;
            }
        }
        return 0;
    }

    private static function get_smallest_rank($match)
    {
        $rank_id = array("trinomial" => 1, "binomial" => 2, "genus" => 3, "family" => 4, "order" => 5, "class" => 6, "phylum" => 7, "kingdom" => 8);
        $smallest_rank_id = 9;
        $smallest_rank = "";
        foreach($match as $tag)
        {
            if(preg_match("/^taxonomy:(.*)\=/i", $tag, $arr))
            {
                $rank = trim($arr[1]);
                if(in_array($rank, array_keys($rank_id)))
                {
                    if($rank_id[$rank] < $smallest_rank_id)
                    {
                        $smallest_rank_id = $rank_id[$rank];
                        $smallest_rank = $rank;
                    }
                }
            }
        }
        foreach($match as $tag) if(preg_match("/^taxonomy:" . $smallest_rank . "=(.*)$/i", $tag, $arr)) $sciname = ucfirst(trim($arr[1]));
        if(!isset($sciname))
        {
            print "\n This needs checking...";
            print "<pre>"; print_r($match); print "</pre>";
        }
        return array("rank" => $smallest_rank, "name" => $sciname);
    }

    private static function add_objects($identifier, $dataType, $mimeType, $title, $source, $description, $mediaURL, $agent, $license, $thumbnailURL, $arr_objects)
    {
        $arr_objects[] = array( "identifier"   => $identifier,
                                "dataType"     => $dataType,
                                "mimeType"     => $mimeType,
                                "title"        => $title,
                                "source"       => $source,
                                "description"  => $description,
                                "mediaURL"     => $mediaURL,
                                "agent"        => $agent,
                                "license"      => $license,
                                "thumbnailURL" => $thumbnailURL
                              );
        return $arr_objects;
    }

    private static function get_taxa_for_photo($rec)
    {
        $taxon = array();
        $taxon["source"] = $rec["source"];
        $taxon["identifier"] = trim($rec["identifier"]);
        $taxon["scientificName"] = ucfirst(trim($rec["sciname"]));
        if($rec["sciname"] != @$rec["family"]) $taxon["family"] = ucfirst(trim(@$rec["family"]));
        if($rec["sciname"] != @$rec["genus"]) $taxon["genus"] = ucfirst(trim(@$rec["genus"]));
        if($rec["sciname"] != @$rec["order"]) $taxon["order"] = ucfirst(trim(@$rec["order"]));
        if($rec["sciname"] != @$rec["class"]) $taxon["class"] = ucfirst(trim(@$rec["class"]));
        if($rec["sciname"] != @$rec["phylum"]) $taxon["phylum"] = ucfirst(trim(@$rec["phylum"]));
        if($rec["sciname"] != @$rec["kingdom"]) $taxon["kingdom"] = ucfirst(trim(@$rec["kingdom"]));
        foreach($rec["commonNames"] as $comname) $taxon["commonNames"][] = new \SchemaCommonName(array("name" => $comname, "language" => ""));
        if($rec["arr_objects"])
        {
            foreach($rec["arr_objects"] as $object)
            {
                $data_object = self::get_data_object($object);
                if(!$data_object) return false;
                $taxon["dataObjects"][] = new \SchemaDataObject($data_object);
            }
        }
        $taxon_object = new \SchemaTaxon($taxon);
        return $taxon_object;
    }

    private static function get_data_object($rec)
    {
        $data_object_parameters = array();
        $data_object_parameters["identifier"]   = trim(@$rec["identifier"]);
        $data_object_parameters["source"]       = $rec["source"];
        $data_object_parameters["dataType"]     = trim($rec["dataType"]);
        $data_object_parameters["mimeType"]     = trim($rec["mimeType"]);
        $data_object_parameters["mediaURL"]     = trim(@$rec["mediaURL"]);
        $data_object_parameters["thumbnailURL"] = trim(@$rec["thumbnailURL"]);
        $data_object_parameters["created"]      = trim(@$rec["created"]);
        $data_object_parameters["description"]  = Functions::import_decode(@$rec["description"]);
        $data_object_parameters["source"]       = @$rec["source"];
        $data_object_parameters["license"]      = @$rec["license"];
        $data_object_parameters["rightsHolder"] = @trim($rec["rightsHolder"]);
        $data_object_parameters["title"]        = @trim($rec["title"]);
        $data_object_parameters["language"]     = "en";
        $agents = array();
        foreach(@$rec["agent"] as $agent)
        {
            $agentParameters = array();
            $agentParameters["role"]     = $agent["role"];
            $agentParameters["homepage"] = $agent["homepage"];
            $agentParameters["logoURL"]  = "";
            $agentParameters["fullName"] = $agent[0];
            $agents[] = new \SchemaAgent($agentParameters);
        }
        $data_object_parameters["agents"] = $agents;
        return $data_object_parameters;
    }

    private static function get_subscriber_usernames()
    {
        $usernames_of_subscribers = array();
        $usernames_of_subscribers['EncyclopediaOfLife'] = 1;
        
        /* We need to excluded a number of YouTube users because they have many videos and none of which is for EOL and each of those videos is checked by the connector. */
        $usernames_of_people_to_ignore = array('PRI');
        
        // /* as of 3-14-12: This is the same list that is taken from the API below. 
        // This is just a safeguard that when the API suddenly changes that EOL won't lose all their YouTube contributors */
        // $usernames_of_subscribers['jenhammock1']        = 1;
        // $usernames_of_subscribers['PRI']                = 1;
        // $usernames_of_subscribers['treegrow']           = 1;
        // $usernames_of_subscribers['soapberrybug']       = 1;
        // $usernames_of_subscribers['heliam']             = 1;
        // $usernames_of_subscribers['smithsonianNMNH']    = 1;
        // $usernames_of_subscribers['robmutch1']          = 1;
        // $usernames_of_subscribers['NESCentMedia']       = 1;
        // $usernames_of_subscribers['TuftsEnvStudies']    = 1;
        // $usernames_of_subscribers['censusofmarinelife'] = 1;
        // $usernames_of_subscribers['lubaro1977']         = 1;

        /* or you can get them by getting all the subscriptions of the YouTube user 'EncyclopediaOfLife' */
        $url = YOUTUBE_API . '/users/' . YOUTUBE_EOL_USER . '/subscriptions?v=2';
        $xml = Functions::get_hashed_response($url);
        foreach($xml->entry as $entry)
        {
            foreach($entry->title as $title)
            {
                if(preg_match("/^Activity of: (.*)$/", $title, $arr))
                {
                    if(!in_array($arr[1], $usernames_of_people_to_ignore))
                    {
                        $usernames_of_subscribers[$arr[1]] = 1;
                    }
                }
            }
        }
        return array_keys($usernames_of_subscribers);
    }

    public static function get_upload_videos_from_usernames($usernames)
    {
        $max_results = 50;
        $user_video_ids = array();
        foreach($usernames as $username)
        {
            if($GLOBALS['ENV_DEBUG']) echo "Getting video list for $username...\n";
            $start_index = 1;
            while(true)
            {
                $url = YOUTUBE_API . '/users/' . $username . '/uploads?';
                $url .= "start-index=$start_index&max-results=$max_results";
                $xml = Functions::get_hashed_response($url);
                if($xml->entry)
                {
                    foreach($xml->entry as $entry) 
                    {
                        $user_video_pathinfo = pathinfo($entry->id);
                        $user_video_ids[$username][] = $user_video_pathinfo['basename'];
                    }
                }
                else break;
                $start_index += $max_results;
            }
        }
        return $user_video_ids;
    }
}
?>