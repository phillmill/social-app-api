<?php
/**
 * Generates a random password, intended for the forgot password functionality
 *
 * @param int $length
 * @return string
 */
function generatePassword($length = 8) {
  $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $count = mb_strlen($chars);
  for ($i = 0, $result = ''; $i < $length; $i++) {
    $index = rand(0, $count - 1);
    $result .= mb_substr($chars, $index, 1);
  }
  return $result;
}

/**
 * Validates if a user token is valid
 * Returns user ID if success, false on fail
 *
 * @param string $token
 * @return bool
 */
function validateUserToken($token) {
	global $f3, $db;

	if($token) {
		$check_sql = "SELECT id FROM user WHERE (md5(CONCAT(email, ?)) = ?) OR (md5(CONCAT(facebook_id, ?)) = ?)";
		$sql_params = array($f3->get('md5_salt'), $token, $f3->get('md5_salt'), $token);
		$check_query = $db->prepare($check_sql);
	    $check_query->execute($sql_params);
	    $check_result = $check_query->fetchAll(PDO::FETCH_ASSOC);
	    if(!empty($check_result)) {
	    	return @$check_result[0]['id'];
	    } else {
	    	return false;
	    }
	} else {
		return false;
	}
}

/**
 * Validates if a user token is valid / they are validated to run functions that require authentication
 * Handles errors and such
 *
 * @param void
 * @return bool/int
 */
function authenticated() {
	global $f3, $db;
	$headers = getallheaders();
	$token = @$headers['Token'];

	// Return true if okay
	if($token && ($user_id = validateUserToken($token))) {
  		return $user_id;
	} else {
  		// Send user an error if they're not authenticated
		$result = (object) array(
	        'status' => -2,
	        'status_explanation' => 'Invalid token...'.$token
	    );
  		header('Content-Type: application/json');
  		echo json_encode($result);
  		exit;
  	}
}

/**
 * Gets token, if there is one
 *
 * @param void
 * @return string
 */
function getToken() {
	$headers = getallheaders();
	$token = @$headers['Token'];
	return $token;
}

/**
 * Gets facebook access token, if there is one
 *
 * @param void
 * @return string
 */
function getFacebookAccessToken() {
	$headers = getallheaders();
	$fb_access_token = @$headers['FacebookAccessToken'];
	return $fb_access_token;
}

/**
 * Gets registration ID
 *
 * @param void
 * @return string
 */
function getRegistrationID() {
	$headers = getallheaders();
	$registration_id = @$headers['RegistrationID'];
	return $registration_id;
}

/**
 * Gets user image (goes out to gravitar / facebook)
 *
 * @param string email
 * @return object
 */
function getUserImage($email) {
	global $f3;
	if($email) {
		return sprintf('http://www.gravatar.com/avatar/%s?d=%s&v=2', md5($email), urlencode($f3->get('default_user_photo')));
	} else {
		return false;
	}
}

/**
 * Gets time ago text based on minutes
 *
 * @param string $token
 * @return bool/int
 */
function getTimeAgo($mins_ago) {
	// If its 0, we don't need to do anymore processing
	if($mins_ago == 0)
		return 'just now';

	// Figure out the wording...
	if($mins_ago) {
		$extra = '';
		switch(true) {
			case $mins_ago < 60:
				$unit = 'minute';
				$amount = $mins_ago;
				break;
			case $mins_ago >= 1440: // 24 hours
				$unit = 'day';
				$amount = floor($mins_ago / 60 / 24);
				break;
			case $mins_ago >= 60:
				$unit = 'hour';
				$amount = floor($mins_ago / 60);
				break;
			default:
				$unit = 'some';
				$amount = 'time';
				break;
		}

		$extra = $amount != 1 ? 's' : ''; // plural
		return sprintf('%s %s%s ago', $amount, $unit, $extra);
	} else {
		return false;
	}
}

/**
 * Sends a push notification
 *
 * @param string $message
 * @param string $register_id
 * @param string $hash_redirect - the hash url that the notification will redirect to
 * @return array
 */
function sendPushNotification($message, $register_id, $hash_redirect = false) {
	global $f3;

	if(!$register_id)
		throw new Exception("Yeah.. You'll need a register_id to send a notification.", 1);

	if(!$message)
		throw new Exception("Yeah.. You'll need a message to send a notification.", 1);
	
	$notification = (object) array(
		'to' => $register_id,
		'data' => array(
			'title' => $f3->app_name,
			'message' => $message,
			'hash_redirect' => $hash_redirect
		)
	);

	$ch = curl_init();
	curl_setopt_array($ch, array(
	    CURLOPT_RETURNTRANSFER => 1,
	    CURLOPT_URL => 'https://gcm-http.googleapis.com/gcm/send',
	    CURLOPT_SSL_VERIFYPEER => false,
	    CURLOPT_POST => true,
	    CURLOPT_POSTFIELDS => json_encode($notification),
	    CURLOPT_HTTPHEADER => array(
	    	'Authorization: key='.$f3->get('google_places_api_key'),
	    	'Content-Type: application/json'
	    )
	));

	if($result = curl_exec($ch)) {
		return true;
	} else {
		return false;
	}
}

/**
 * Genreric curl request wrapper
 *
 * @param string $url
 * @param string $cache_key
 * @return array
 */
function curlJSONRequest( $url, $cache_key ) {
	global $f3, $db, $cache, $logger;

	// Before we do anything, check the cache for data
	if($result_obj = $cache->get($cache_key)) {
		$logger->write('From Cache: ' . $cache_key);
		return $result_obj;
	}

	unset($result_obj);

	if($url) {
		$ch = curl_init();
		curl_setopt_array($ch, array(
		    CURLOPT_RETURNTRANSFER => 1,
		    CURLOPT_URL => $url,
		    CURLOPT_SSL_VERIFYPEER => false,
		    CURLOPT_REFERER => $f3->get('curl_referer')
		));
		$logger->write($url);
		$result = curl_exec($ch);
		if($result = curl_exec($ch)) {
			$result_obj = json_decode($result);
			$cache->set($cache_key, $result_obj, $f3->get('cache_time'));
			curl_close($ch);
			return $result_obj;
		}
		else {
			curl_close($ch);
			return false;
		}
	} else {
		return false;
	}
}

/**
 * Gets business/places near by lat lon
 *
 * @param double $lat
 * @param double $lon
 * @param int $limit
 * @return array
 */
function getNearbyLocations($lat, $lon) {
	global $f3, $db, $logger;
	if($lat && $lon) {
		$rankby = 'distance';
		$types = 'accounting|airport|amusement_park|aquarium|art_gallery|atm|bakery|bank|bar|beauty_salon|bicycle_store|book_store|bowling_alley|bus_station|cafe|campground|car_dealer|car_rental|car_repair|car_wash|casino|cemetery|church|city_hall|clothing_store|convenience_store|courthouse|dentist|department_store|doctor|electrician|electronics_store|embassy|establishment|finance|fire_station|florist|food|funeral_home|furniture_store|gas_station|general_contractor|grocery_or_supermarket|gym|hair_care|hardware_store|health|hindu_temple|home_goods_store|hospital|insurance_agency|jewelry_store|laundry|lawyer|library|liquor_store|local_government_office|locksmith|lodging|meal_delivery|meal_takeaway|mosque|movie_rental|movie_theater|moving_company|museum|night_club|painter|park|parking|pet_store|pharmacy|physiotherapist|place_of_worship|plumber|police|post_office|real_estate_agency|restaurant|roofing_contractor|rv_park|school|shoe_store|shopping_mall|spa|stadium|storage|store|subway_station|synagogue|taxi_stand|train_station|travel_agency|university|veterinary_care|zoo';
		$search_url = sprintf(
			'https://maps.googleapis.com/maps/api/place/nearbysearch/json?location=%s,%s&types=%s&rankby=%s&key=%s',
			$lat,
			$lon,
			// $radius,
			$types,
			$rankby,
			$f3->get('google_places_api_key')
		);
		return curlJSONRequest($search_url, md5(sprintf('place_search_%s_%s', $lat, $lon)));
	} else {
		return false;
	}
}

/**
 * Gets place details
 *
 * @param int $place_id
 * @return object
 */
function getPlace($place_id) {
	global $f3, $db;
	if($place_id) {
		// Set up the search url
		$search_url = sprintf(
			'https://maps.googleapis.com/maps/api/place/details/json?placeid=%s&key=%s',
			$place_id,
			$f3->get('google_places_api_key')
		);

		// Do curl request get the place (or retrieve it from cache, the curlJSONRequest takes care of that)
		if($response = curlJSONRequest($search_url, ('place'.$place_id))) {
			$place = getStrippedPlace($response);
			return $place;
		} else {
			return false;
		}
	} else {
		return false;
	}
}

/**
 * Gets needed fields from a place oject
 *
 * @param object $place
 * @return object
 */
function getStrippedPlace($place) {
	global $f3, $db;
	if($place) {
		$place = $place->result ? $place->result : $place;
		return (object) array(
			'id' => $place->id,
			'vicinity' => $place->vicinity,
			'name' => $place->name,
			'latitude' => $place->geometry->lat,
			'longitude' => $place->geometry->lng
		);
	} else {
		return false;
	}
}