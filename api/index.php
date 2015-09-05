<?php

// Kickstart the framework
$f3=require('lib/base.php');

// Facebook SDK (v4)
define('FACEBOOK_SDK_V4_SRC_DIR', 'lib/facebook_sdk/src/Facebook/');
require_once('lib/facebook_sdk/autoload.php');

// Include Classes
require_once('classes/user.class.php');
require_once('classes/achievement.class.php');

// Include Helpers
require_once('helpers.php');

// PHP Mailer
require_once('lib/PHPMailerAutoload.php');

$f3->set('DEBUG',1);
if ((float)PCRE_VERSION<7.9)
	trigger_error('PCRE version is out of date');

// Load configuration
$f3->config('config.ini');

// Set up the logger
$logger = new Log('error.log');

// Set up the cacher'
$cache = \Cache::instance();
$cache->load( true );

// Open database connection.
$db = new DB\SQL(
    'mysql:host=localhost;dbname='. $f3->get('dbname'),
    $f3->get('dbuser'),
    $f3->get('dbpass')
);
// 
// This should be removed in @production
header('Access-Control-Allow-Origin: *');
// header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
// header('Access-Control-Allow-Methods: GET, POST, PUT');

/**
 * Route: Sign up user
 *
 * @example /user/sign-up
 */
$f3->route(
  array(
    'POST /user/sign-up'
  ), function($f3, $params) use ($db) {

  $facebook_id = $f3->get('POST.facebook_id');
  $email = $f3->get('POST.email');
  $password = $f3->get('POST.password');
  $registration_id = $f3->get('POST.registration_id');

  if($email && filter_var($email, FILTER_VALIDATE_EMAIL) && $password) {
    $user = new User($email);

    if( !$user->existsAlready() ) {
      if($user->signUp($registration_id, $password)) {
        $result = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.',
          'token' => $user->getToken()
        );
      } else {
        $result = (object) array(
            'status' => -1,
            'status_explanation' => 'Database error, couldn\'t insert user record.'
        );
      }
    // User with email already exists
    } else {
      $result = (object) array(
          'status' => -2,
          'status_explanation' => 'A user with provided email already exists.'
      );
    }
  } else {
    $result = (object) array(
        'status' => -3,
        'status_explanation' => 'Missing data.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($result);  
  
 }, $f3->get('route_ttl'));

/**
 * Route: FB "ping", becuase we don't know if they're signin in or signing up, this route will take care of figuring that out
 *
 * @example /user/fb-ping
 */
$f3->route(
  array(
    'POST /user/fb-ping'
  ), function($f3, $params) use ($db) {

  $facebook_id = $f3->get('POST.facebook_id');
  $registration_id = getRegistrationID();

  if($facebook_id) {
    $user = new User(null, null, $facebook_id);
    if( !$user->existsAlready() ) {
      if($user->signUp($registration_id)) {
        $result = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.',
          'user_info' => $user->getInfo(),
          'token' => $user->getToken()
        );
      } else {
        $result = (object) array(
            'status' => -1,
            'status_explanation' => 'Database error, couldn\'t insert facebook user record.'
        );
      }
    } else {
      // Generates a token based on facebook_id and tries to sign in with that.
      if( $user->signIn(null, $user->getToken(), $registration_id) ) {
        $result = (object) array(
            'status' => 1,
            'status_explanation' => 'Success.',
            'user_info' => $user->getInfo(false, true),
            'token' => $user->getToken()
        );
      } else {
        $result = (object) array(
            'status' => -2,
            'status_explanation' => 'A user with provided facebook ID already exists. But that shouldnt matter...'
        );
      }
    }
  } else {
    $result = (object) array(
        'status' => -3,
        'status_explanation' => 'Missing/Invalid data.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($result);  
  
 }, $f3->get('route_ttl'));

/**
 * Route: Sign in user
 *
 * @example /user/sign-in
 */
$f3->route(
  array(
    'POST /user/sign-in'
  ), function($f3, $params) use ($db) {

  $email = $f3->get('POST.email');
  $password = $f3->get('POST.password');
  $token = getToken();

  if(($email && filter_var($email, FILTER_VALIDATE_EMAIL) && $password) || $token) {
    // Attempt to sign in
    $user = new User($email);
    if( $user->signIn($password, $token, getRegistrationID()) ) {
      $result = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.',
          'user_info' => $user->getInfo(),
          'token' => $user->getToken()
      );
    } else {
      $result = (object) array(
          'status' => -1,
          'status_explanation' => 'Mismatching email and password.'
      );
    }
  } else {
    $result = (object) array(
        'status' => -3,
        'status_explanation' => 'Missing/Invalid data.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($result);  
  
 }, $f3->get('route_ttl'));

/**
 * Route: Get user info
 *
 * @example /user/get-info
 */
$f3->route(
  array(
    'POST /user/get-info'
  ), function($f3, $params) use ($db) {
  // Attempt to sign in
  if($user_id = authenticated()) {
    $user = new User(null, $user_id);
    if( $info = $user->getInfo() ) {
      $result = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.',
          'user_info' => $info
      );
      unset($info);
    } else {
      $result = (object) array(
          'status' => -1,
          'status_explanation' => 'Database error.'
      );
    }
  } else {
    $result = (object) array(
        'status' => -3,
        'status_explanation' => 'Invalid token.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($result);  
  
}, $f3->get('route_ttl'));

/**
 * Route: Edit user info
 *
 * @example /user/edit-information
 */
$f3->route(
  array(
    'POST /user/edit-information'
  ), function($f3, $params) use ($db) {
  // Attempt to sign in
  if($user_id = authenticated()) {
    $user = new User(null, $user_id);
    $args = array(
      'first_name' => $f3->get('POST.first_name'),
      'last_name' => $f3->get('POST.last_name')
    );
    if( $user->editInformation($args) ) {
      $result = (object) array(
          'status' => 1,
          'user_info' => $user->getInfo(),
          'status_explanation' => 'Success.'
      );
      unset($info);
    } else {
      $result = (object) array(
          'status' => -1,
          'status_explanation' => 'Database error.'
      );
    }
  } else {
    $result = (object) array(
        'status' => -3,
        'status_explanation' => 'Invalid token.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($result);  
  
}, $f3->get('route_ttl'));

/**
 * Route: Returns all data about user (friends, info etc.)
 *
 * @example /user/data-update
 */
$f3->route(
  array(
    'GET /user/data-update'
  ), function($f3, $params) use ($db) {

  if($sender_id = authenticated()) {
    $user = new User(null, $sender_id);
    $response = (object) array(
        'status' => 1,
        'status_explanation' => 'Success.',
        'user_info' => $user->getInfo(),
        'user_friends' => $user->getFriends()
    );
  } else {
    $response = (object) array(
        'status' => -3,
        'status_explanation' => 'Invalid token.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($response);  
  
 }, $f3->get('route_ttl'));

/**
 * Route: Add friend
 *
 * @example /user/add-friend
 */
$f3->route(
  array(
    'POST /user/add-friend'
  ), function($f3, $params) use ($db) {

  // Attempt to sign in
  if($sender_id = authenticated()) {
    $friend_id = $f3->get('POST.friend_id');
    $user = new User(null, $sender_id);
    if($user->addFriend($friend_id)) {
      $user->getInfo(true);
      $potential_friend = new User(null, $friend_id);
      $potential_friend->getInfo(true);
      sendPushNotification(
        sprintf('%s wants to be your friend', $user->getFullName()),
        $potential_friend->registration_id,
        '#/friends'
      );
      unset($potential_friend);
      $response = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.'
      );
    } else {
      $response = (object) array(
          'status' => -1,
          'status_explanation' => 'Could not add friend for unknown reason.'
      );
    }
  } else {
    $response = (object) array(
        'status' => -3,
        'status_explanation' => 'Invalid token.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($response);  
}, $f3->get('route_ttl'));

/**
 * Route: Accept Friend Request
 *
 * @example /user/accept-friend
 */
$f3->route(
  array(
    'POST /user/accept-friend'
  ), function($f3, $params) use ($db) {

  // Attempt to sign in
  if($sender_id = authenticated()) {
    $fs_id = $f3->get('POST.friendship_id');
    $user = new User(null, $sender_id);
    if($user->acceptFriend($fs_id)) {
      $response = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.',
          'user_info' => $user->getInfo(),
          'user_friends' => $user->getFriends()
      );
    } else {
      $response = (object) array(
          'status' => -1,
          'status_explanation' => 'Could not accept friend request for unknown reason.'
      );
    }
  } else {
    $response = (object) array(
        'status' => -3,
        'status_explanation' => 'Invalid token.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($response);  
}, $f3->get('route_ttl'));

/**
 * Route: Ignore Friend Request
 *
 * @example /user/ignore-friend
 */
$f3->route(
  array(
    'POST /user/ignore-friend'
  ), function($f3, $params) use ($db) {

  // Attempt to sign in
  if($sender_id = authenticated()) {
    $fs_id = $f3->get('POST.friendship_id');
    $user = new User(null, $sender_id);
    if($user->ignoreFriendRequest($fs_id)) {
      $response = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.',
          'user_info' => $user->getInfo(),
          'user_friends' => $user->getFriends()
      );
    } else {
      $response = (object) array(
          'status' => -1,
          'status_explanation' => 'Could not ignore request for unknown reason.'
      );
    }
  } else {
    $response = (object) array(
        'status' => -3,
        'status_explanation' => 'Invalid token.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($response);  
}, $f3->get('route_ttl'));

/**
 * Route: Gets user's friends
 *
 * @example /user/get-friends
 */
$f3->route(
  array(
    'POST /user/get-friends'
  ), function($f3, $params) use ($db) {

  // Attempt to sign in
  if($sender_id = authenticated()) {
    $user = new User(null, $sender_id);
    if($friends = $user->getFriends()) {
      $response = (object) array(
          'status' => 1,
          'status_explanation' => 'Success.',
          'friends' => $friends
      );
    } else {
      $response = (object) array(
          'status' => -1,
          'status_explanation' => 'Could not retrieve friends for unknown reason.'
      );
    }
  } else {
    $response = (object) array(
        'status' => -3,
        'status_explanation' => 'Invalid token.'
    );
  }

  header('Content-Type: application/json');
  echo json_encode($response);  
}, $f3->get('route_ttl'));

/**
 * Route: User Reset Pass
 *
 * @example /user/reset-pass
 */
$f3->route('POST /user/reset-pass', function($f3, $params) use ($db) {
  $email = $f3->get('POST.email');
  $user = new User($email);

  // If user exists
  if( $user->existsAlready() ) {
    if( $user->resetPassword() ) {
      $response = (object) array(
        'status' => 1,
        'status_explanation' => 'Success'
      );
    } else {
      $response = (object) array(
        'status' => -2,
        'status_explanation' => 'Could not send email.'
      );
    }
  } else {
    $response = (object) array(
        'status' => -1,
        'status_explanation' => 'A user with provided email doesn\'t exist.'
    );
  }
    
  header('Content-Type: application/json');
  echo json_encode($response, JSON_PRETTY_PRINT);
}, $f3->get('route_ttl'));

/**
 * Route: Find users by searching
 *
 * @example /user/reset-pass
 */
$f3->route('POST /find-users', function($f3, $params) use ($db) {
  $search = $f3->get('POST.search');
  // If user exists
  if($user_id = authenticated()) {
    $response = User::getUsersByName($search, $user_id);
  } else {
    $result = (object) array(
      'status' => -1,
      'status_explanation' => 'Insufficient data provided.',
    );
  }
    
  header('Content-Type: application/json');
  echo json_encode($response, JSON_PRETTY_PRINT);
}, $f3->get('route_ttl'));

/**
 * Route: Get user information by ID
 *
 * @example /user/@id
 */
$f3->route('POST /user/@id', function($f3, $params) use ($db) {
  $id = $f3->get('PARAMS.id');

  // If user sending request is authenticated
  if($sender_id = authenticated()) {
    $user = new User(null, $id);
    if($user_info = $user->getInfo(false)) {
      $response = (object) array(
        'status' => 1,
        'status_explanation' => 'Success.',
        'handle' => $user_info->handle,
        'first_name' => $user_info->first_name,
        'last_name' => $user_info->last_name,
        'image' => $user_info->image
      );
    } else {
      $response = (object) array(
        'status' => -2,
        'status_explanation' => 'Couldn\'t fetch user information from database.',
      );
    }
} else {
    $response = (object) array(
      'status' => -1,
      'status_explanation' => 'Insufficient data provided.',
    );
  }
    
  header('Content-Type: application/json');
  echo json_encode($response, JSON_PRETTY_PRINT);
}, $f3->get('route_ttl'));

/**
 * Route: Retrieve all achievements
 * @todo the achievement system needs to be finished. Right now it just returns a list of available achievements. Nothing more.
 *
 * @example /achievements/
 */
$f3->route(
  array(
    'GET /achievements'
  ), function($f3, $params) use ($db) {

  if( $achievements = Achievement::getAll() ) {
    $result = (object) array(
     'status' => 1,
     'achievements' => $achievements
    );
  } else {
    $result = (object) array(
     'status' => -1,
     'status_explanation' => 'Could not retrieve achievements.',
    );
  }

  header('Content-Type: application/json');
  echo json_encode($result); 

}, $f3->get('route_ttl'));

$f3->run();
