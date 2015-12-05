<?php

/* This file serves as the core file for receiving and parsing song requests
At some point we need to make a general request class...need to do this when we implement our API (if we go that direction)

The main code that is executed is found at the bottom of the file. The rest are methods that help with parsing. 

-Guy
*/

header("content-type: text/xml");
echo "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>";
include("include/SMS.php");


$From = '';
$To = '';
$Body = '';
$FromCity = '';
$FromState = '';
$FromZip = '';
$FromCountry = '';
$ToCity = '';
$ToState = '';
$ToZip = '';
$ToCountry = '';

//Populate variables(if they exist)

isset($_POST['From'])?$From = $_POST['From']:$From = '';
isset($_POST['To'])?$To = $_POST['To']:$To = '';
isset($_POST['Body'])?$Body = $_POST['Body']:$Body = '';
isset($_POST['FromCity'])?$FromCity = $_POST['FromCity']:$FromCity = '';
isset($_POST['FromState'])?$FromState = $_POST['FromState']:$FromState = '';
isset($_POST['FromZip'])?$FromZip = $_POST['FromZip']:$FromZip = '';
isset($_POST['FromCountry'])?$FromCountry = $_POST['FromCountry']:$FromCountry = '';
isset($_POST['ToCity'])?$ToCity = $_POST['ToCity']:$ToCity = '';
isset($_POST['ToState'])?$ToState = $_POST['ToState']:$ToState = '';
isset($_POST['ToZip'])?$ToZip = $_POST['ToZip']:$ToZip = '';
isset($_POST['ToCountry'])?$ToCountry = $_POST['ToCountry']:$ToCountry = '';

/* here we look at the start of the users text to determine how to handle it */ 
function requesttype($msg) {
	$substr10 = substr($msg, 0, 10);
	$substr5 = substr($msg, 0, 5);
	if (strpos($substr10, "command") !== false) { 
		sendCommands();
	} else if (strpos($substr5, "song") !== false) { //start song identifiers - need to rewrite this to be much more general sometime soon..
		handleRequest($msg);
	} else if(strpos($substr5, "play") !== false) {
		handleRequest($msg, "play");
	} else if (strpos($msg, " by ") !== false) {
		handleRequest($msg, " by ");
	} else if (strpos($msg, "-") !== false) { //end song identifiers
		handleRequest($msg, "-");
	} else if (strpos($substr10, "shoutout") !== false || strpos($substr10, "shout out") !== false) {
		handleShoutout($msg);
	} else {
		$result = exactMatch($msg); //user just sent us a text with no keywords - test if it's a song with much stricter conditions than above
		if (!$result) { 
			handleMsg($msg); //must not be a song, handle it as a message and give back song request instructions
		} else {
			sendConfirmation($result['song'], $result['artist']); //song confirmation
		}
	}
}

//primary song identification function
function request($song, $artist) {
    $request = findSong($song, $artist); //check our database first
    if (!isset($request)) {
        $request = echoNest($song, $artist); //song must not be in our database, so we check echonest
    }
    if (!isset($request)) {
         $request = spotify($song, $artist); //then spotify
    }
    if (!isset($request)) {
        return null; //then fail
    }
    return $request;
}


function sendCommands() { 
	global $info, $sms;
    $sms->sendToCell("To request a song format your text as follows: 'play Calle Ocho by Pitbull' or 'play Calle Ocho.", $info['toNumber'], $info['clientNumber']);
    $sms->sendToCell("To do a shoutout, text 'shoutout [your_shoutout]' and to send a message simply text the message\n".$info['custom_msg'], $info['toNumber'], $info['clientNumber']); 
}


//try to parse text for keywords and return the results of the request
function handleRequest($msg, $divide) { //function receives request plus divide between song and artist
    $song;
    $artist;
    //retrieve song and artist given divide!
    if ($divide == "play") {
    	$substr = substr($msg, 0, 5);
    	if (strpos($substr, " play") !== false) { 
    		$request = explode(" play", $msg);
    	} else if (strpos($substr, "play ") !== false) {
    		$request = explode("play ", $msg);
    	} else if (strpos($substr, "play") !== false) {
    		$request = explode("play", $msg);
    	}
    	if (strpos($request[1], " by ") !== false) {
    		$temp = explode(" by ", $request[1]);
    		$song = $temp[0];
    		$artist = $temp[1];
    	} else if (strpos($request[1], "-") !== false) {
    		$temp = explode("-", $request[1]);
    		$song = $temp[0];
    		$artist = $temp[1];
    	} else if (strpos($request[1], "artist") !== false) {
    		$temp = explode("artist", $request[1]);
    		$song = $temp[0];
    		$artist = $temp[1];
    	} else {
    		$song = $request[1];
    	}
    } else if ($divide == " by ") {
    	$temp = explode(" by ", $msg);
    	$song = $temp[0];
    	$artist = $temp[1];
    } else if ($divide == "-") {
    	$temp = explode("-", $msg);
    	$song = $temp[0];
    	$artist = $temp[1];
    }
    $song_parse = explode(" ", $song);
    $artist_parse = explode(" ", $artist);
    $song;
    $artist;
    for($i = 0; $i < count($song_parse); $i++) { //make sure we don't have double white space (could mess up query of APIs)
    	$new = rtrim(ltrim($song_parse[$i]));
    	if ($i == 0)
    		$song = $new;
    	else
    		$song .= " ".$new;
    }
    for ($i = 0; $i < count($artist_parse); $i++) {
    	$new = rtrim(ltrim($artist_parse[$i]));
    	if ($i == 0)
    		$artist = $new;
    	else
    		$artist .= " ".$new;
    }
 	$result = request($song, $artist); //get request given parsed song and artist
 	if ($result == null) {
 		handleMsg("Could not find your song. We have sent a message to the DJ."); //no result found =/, fail with a message
 	} else if (count($result) == 1) {
		sendConfirmation($result[0]["song"], $result[0]["artist"]);
		print_r($result);
	} else { //we SHOULD handle these cases differently - but do we want to? We should do something to determine tie breaks, perhaps when we have more data...
			//we don't want to text them back asking to pick one from the options simply cause of cost...
		sendConfirmation($result[0]["song"], $result[0]["artist"]);
		print_r($result);
	}
}


/* check if the users text EXACTLY matches any of the songs in our database. Perhaps we should allow some flexibility here?
Also maybe need to add more robust artist checking, but no reason to now */
function exactMatch($query) {
	global $database;
	$allwhite = str_replace(' ', '', $query);
	if (strlen($allwhite) < 2)
		return false;
	$q = $database->connection->query("SELECT * FROM `songs` WHERE `keyword1` = '".$query."' OR `keyword2` = '".$query."' OR `keyword3` = '".$query."' OR LOWER(`song`) = '".$query."' OR CONCAT(LOWER(`song`), ' ', LOWER(`artist`)) = '".$query."'");
	if (mysqli_num_rows($q) > 0) { //we have a match!
		while($row = $q->fetch_assoc()) 
			return array("song" => $row['song'], "artist" => $row['artist']); //redo this so that it actually goes through all possible matches and try to determine the right one
	} else {
		return false;
	}
}


//sometimes the API calls give us covers and/or totally random songs, make sure we DONT return those so filter them out
function removeNoise($song, $artist, $input, $artist_input) {
    if (strpos($input, $song) === false && strpos($song, $input) === false) //neither is a subset of the other
        return false;
    if (strlen($artist_input) > 2) { //if an artist exists, check the same
        if (strpos($artist_input, $artist) === false && strpos($artist, $artist_input) === false)
            return false;
    }
    if (!(strpos($song, "cover") === false)) //get rid of covers and tributers
        return false;
    if (!(strpos($song, "tribute") === false))
        return false;
    if (!(strpos($artist, "tribute") === false))
        return false;
    if (!(strpos($song, "originally") === false))
        return false;
    if (!strpos($song, "in the style of") === false)
        return false;
    return true;
    
}


//query spotify and return the most popular result
function spotify($song_input, $artist_input) {
    $song;
    $artist;
    $min = 100;
    if (strlen($artist_input) > 2)
    	$query = $song_input." ".$artist_input;
    else
    	$query = $song_input;
    $spotify_url = "http://ws.spotify.com/search/1/track.json?q=".urlencode($query); //API call to spotify
    $json = file_get_contents($spotify_url);
    $spotify = json_decode($json, TRUE);
    $ret = array();
    for ($i = 0; $i < count($spotify["tracks"]); $i++) {
        $song1 = $spotify["tracks"][$i]["name"];
        $artist1 = $spotify["tracks"][$i]["artists"][0]["name"];
        if ($spotify["tracks"][$i]["popularity"] != 0) { //valid song so continue. Note that popularity is distributed between 0 and 1
	        if (removeNoise(strtolower($song1), strtolower($artist1), $song_input, $artist_input)) { //if the track is not a cover and could be a valid return value 
   		 		if (strlen($artist_input) > 2) { //break based on whether input has artist or not
        			$distance = distance($song_input." ".$artist_input, $song1." ".$artist1, 1, 1)/$spotify["tracks"][$i]["popularity"]; //this seems to work well - distance over popularity to the best song to return - note this favors more popular songs
        			if ($distance < $min) { //we have found a better result, so replace it
        				$ret = array();
        				array_push($ret, array("song" => $song1, "artist" => $artist1, "distance" => $distance));
            			$min = $distance;
            		} else if ($distance == $min) {
            			$ret = array_push($ret, array("song" => $song1, "artist" => $artist1, "distance" => $distance));
            		}
    			} else {
    				$distance = distance($song_input, $song1, 1, 1)/$spotify["tracks"][$i]["popularity"];
    				if ($distance < $min) {
    					$ret = array();
        				array_push($ret, array("song" => $song1, "artist" => $artist1, "distance" => $distance));
            			$min = $distance;
            		} else if ($distance == $min) {
            			array_push($ret, array("song" => $song1, "artist" => $artist1, "distance" =>$distance));
            		}
        		}
    		}
    	}
	}
    
    if ($min == 100) //this must mean we found NO matches so fail
    	return null;
    else {
   		return $ret;
   	}
}


//query our database to find closest match
function findSong($song, $artist) {
    global $database;
	if (strlen($song) < 2) //basically nothing inputted, no way this could be a song
		return null;
    $query = $database->connection->query("SELECT * FROM `songs`"); // get all the songs we have in our database
    $min = 1000;
    $arr = array();
    $max_song_distance = floor(sqrt(strlen($song))); //this is our threshold cost. If a song is within this distance it could be the song
    $max_artist_distance = floor(sqrt(strlen($artist)));
    while ($row = $query->fetch_assoc()) {
    	//find the distance between the song and ALL of the song keywords and then take the minimum one to represent the distance - remember that the keywords are indicative of potential variations of the song name
        $cur = min(distance(strtolower($row['keyword1']), $song, $max_song_distance, 1, 1), distance(strtolower($row['song']), $song, $max_song_distance, 1, 1), distance(strtolower($row['keyword2']), $song, $max_song_distance, 1, 1), distance(strtolower($row['keyword3']), $song, $max_song_distance, 1, 1));      
        if ($cur > $max_song_distance) //not close enough
        	continue;
        if (strlen($artist) > 2) { //do the same tests on artist - we may need more robust artist checking but if song + artist are specified anyways the APIs usually handle it pretty well - need this more for songs
        	$next = distance(strtolower($row['artist']), $artist, $max_artist_distance, 1, 1);
        	if ($next > $max_artist_distance)
        		continue;
        } else {
        	$next = 0;
        }
        if ($cur + $next < $min) {
        	$arr = array();
        	array_push($arr, array(array("song" => $row['song'], "artist" => $row['artist'], "distance" => $cur+$next)));
            $min = $cur + $next;
        } else if ($cur + $next == $min) {
        	array_push($arr, array("song" =>$row['song'], "artist" =>$row["artist"], "distance" => $min));
         }	
    }
    if ($min == 1000) //didn't find anything close enough
    	return null;
    else
    	return $arr;
}


//query itunes and return top result 
//itunes results are horrible, never use this. 
function itunes($query, $input, $artist_input) {
    $url = "https://itunes.apple.com/search?term=".$query."&entity=musicTrack";
    $html = file_get_contents($url);   
    $data = json_decode($html, TRUE);
    $song;
    $artist;
    if (isset($data["results"][0]["trackName"])) {     
        for ($i = 0; $i < 10; $i++) {
            if (removeNoise(strtolower($data["results"][$i]["trackName"]), strtolower($data["results"][$i]["artistName"]), $input, $artist_input)) { //makin sure we're not returning the wrong song or some bullshit
                $song = $data["results"][$i]["trackName"];
                $artist = $data["results"][$i]["artistName"];
                break;
            }
        }
        
        if (isset($song) && isset($artist)) {
            $arr = array();
            $arr['song'] = $song;
            $arr['artist'] = $artist;
            return $arr;
        } else {
            return null;
        }
    } else {
        return null;
    }
}


//store song request in database and send user confirmation
function sendConfirmation($song, $artist) {
    global $info, $database, $content, $sms;
    $response = "Thanks for requesting ".$song." by ".$artist;
    if (strlen($response) > 160) { //twilio will fail if message is over 160 chars - UPDATE TO NEW API VERSION AND THIS DOESN'T HAPPEN
    	$response = "Thanks for requesting ".$song;
    	 if (strlen($response) > 160) {
    	 	$response = "Thanks for your request!";
    	 }
    }
    $msg = $response."\n\n".$info['custom_msg']; //append custom message for marketing 
    if(strlen($msg) > 160) {
    	$sms->sendToCell($response, $info['toNumber'], $info['clientNumber']);
    	$custom_msg .= "\n\nThanks for using RequestNow (http://goo.gl/Of6Vn)"; //because why not promote ourselves if there's room
    	$sms->sendToCell($info['custom_msg'], $info['toNumber'], $info['clientNumber']);
    } else {
    	$sms->sendToCell($msg, $info['toNumber'], $info['clientNumber']);
    }
    $request = $song." by ".$artist;
    if (!!$info['android_id'] && strlen($info['android_id']) > 1)
	    androidNotification($request, "New request");
    
    
}

//levenshtein distance with transpositions
function distance($str1, $str2, $insertion, $substitution) {
    // Return trivial case - where they are equal
    if ($str1 == $str2)
        return 0;
        
    $len1 = strlen($str1);
    $len2 = strlen($str2);

    // Return trivial case - where one is empty
    if ($len1 == 0 || $len2 == 0)
        return $len1 + $len2;
	$M = array();
	for ($i = 0; $i <= $len1; $i++)
		$M[$i][0] = $i * $insertion;
	for ($i = 0; $i <= $len2; $i++)
		$M[0][$i] = $i * $insertion;
	for ($i = 1; $i <= $len1; $i++) {
		for ($j = 1; $j <= $len2; $j++) {
			if ($str1[$i-1] == $str2[$j-1]) {
				$cost = 0;
			} else {
				$cost = $substitution;
			}
			$M[$i][$j] = min($M[$i-1][$j] + $insertion, $M[$i][$j-1] + $insertion, $M[$i-1][$j-1] + $cost);
			if ($i > 1  && $j > 1 && $str1[$i-1] == $str2[$j-2] && $str1[$i-2] == $str2[$j-1]) {
				$M[$i][$j] = min($M[$i][$j], $M[$i-2][$j-2]+$substitution); //transposition
			}
		}
	}
	return $M[$len1][$len2];
}
    
//query with song or artist
function echoNest($song, $artist) {
    if (strlen($artist) > 2)
        $url = "http://developer.echonest.com/api/v4/song/search?api_key=".urlencode(ECHONEST_KEY)."&format=json&results=3&sort=song_hotttnesss-desc&title=".urlencode($song)."&artist=".urlencode($artist); 
    else
        $url = "http://developer.echonest.com/api/v4/song/search?api_key=".urlencode(ECHONEST_KEY)."&format=json&results=3&sort=song_hotttnesss-desc&title=".urlencode($song);
    $json = file_get_contents($url); //note we sort by popularity here as well
    $echo = json_decode($json, TRUE);
    $ret = array();
    $min = 1000;
    $max_song_distance = floor(sqrt(strlen($song))); //max allowable range
    $max_artist_distance = floor(sqrt(strlen($artist)));
    for ($i = 0; $i < count($echo['response']['songs']); $i++) {
        $cur = distance(strtolower($echo['response']['songs'][$i]['title']), $song, 1, 1);
        if ($cur > $max_song_distance)
        	continue;
        if (strlen($artist) > 2) { //artist exists so let's check it
        	$next = distance(strtolower($echo['response']['songs'][$i]['artist_name']), $artist, 1, 1);
        	if ($next > $max_artist_distance)
        		continue;
        } else 
        	$next = 0;
        $arr = array("song" => $echo['response']['songs'][$i]['title'], "artist" => $echo['response']['songs'][$i]['artist_name'], "distance" => $cur+$next);
        if ($cur + $next < $min) {
        	$ret = array();
        	array_push($ret, $arr);
        	$min = $cur+$next;
        } else if ($cur + $next == $min) {
        	 array_push($ret, $arr);
        }	
    }
   if ($min == 1000) //same as before nothing close enough was found
   	return null;
   else
   	 return $ret; 
}
   
   
//generate Spotify link for liveview
function spotifyLink($song, $artist) {
   $query = $song." ".$artist;
   $spotify_url = "http://ws.spotify.com/search/1/track.json?q=".urlencode($query);
   $json = file_get_contents($spotify_url);
   $spotify = json_decode($json, TRUE);
   $href = $spotify["tracks"][0]["href"];
   $components = explode(":", $href); //seems to work effectively but should be made cleaner
   $url = "http://open.spotify.com/track/".$components[2];
   return $url;
}

//send android notification 
function androidNotification($response, $subject) {
	global $info;
	//set the post data
	$data = array();
	$data['registration_ids'] = array();
	array_push($data['registration_ids'], $info['android_id']);
	$second = array();
	$second['message'] = $response;
	$second['title'] = $subject;
	$data['data'] = $second;
	//post data is set.
	//make the post request with the valid auth key
	 $params = array('http' => array(
              'method' => "POST",
              'content' => json_encode($data),
              'header' => "Content-Type: application/json\nAuthorization:key=".ANDROID_API_KEY
            ));
  $ctx = stream_context_create($params);
  $fp = @fopen("https://android.googleapis.com/gcm/send", 'rb', false, $ctx);
  if (!$fp) {
    throw new Exception("Problem with $url, $php_errormsg");
  }
  $response = @stream_get_contents($fp);
  if ($response === false) {
    throw new Exception("Problem reading data from $url, $php_errormsg");
  }
  return $response;
}



/* Essentially our main function. Executed code goes down here */


$info = array();
$info['FromZip'] = $FromZip;
$info['FromState'] = $FromState;
$info['FromCity'] = $FromCity;

$content = htmlspecialchars(strip_tags($database->connection->real_escape_string(strtolower($_POST['Body'])))); //make sure XSS and SQL vulnerabilities can't be done.
$info['toNumber'] = substr($_POST['To'], 1); 
$info['clientNumber'] = substr($_POST['From'], 1);
if (strlen($content) > 0) //empty message so just discard
	requesttype($content);