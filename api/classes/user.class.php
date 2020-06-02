<?php
global $f3;
use Facebook\FacebookSession;
use Facebook\FacebookRequest;
use Facebook\GraphUser;
use Facebook\FacebookRequestException;
FacebookSession::setDefaultApplication('835741643178417', '07f5775aa7ac6979efa40a7c7ae18e9a');
class User {
	public $id;
	public $email;
	public $handle;
	public $first_name;
	public $last_name;

	/**
	 * Constructor
	 *
	 * @param int $email ?
	 * @param int $user_id ?
	 * @return void
	 */
	public function __construct($email = null, $user_id = null, $facebook_id = null) {
		// Set email if it was provided
		if($email) {
			$this->email = $email;
		}
		// Set user id if it was provided
		if($user_id) {
			$this->id = $user_id;
		}
		// Set facebook id if it was provided
		if($facebook_id) {
			$this->facebook_id = $facebook_id;
		}
	}

	/**
	 * Checks if a user already exists
	 * Intended to be called outside of instantiation
	 *
	 * @param string void
	 * @return bool
	 */
	public function existsAlready() {
		global $f3, $db;
		if($this->email) {
			$check_sql = "SELECT id FROM user WHERE email = ?";
			$check_query = $db->prepare($check_sql);
			$sql_params = array($this->email);
			$check_query->execute($sql_params);
			// Set object ID if successful, in case it's needed later
			if($this->id = $check_query->fetchColumn()) {
				return true;
			} else {
				return false;
			}
		} else if($this->facebook_id) {
			$check_sql = "SELECT id FROM user WHERE facebook_id = ?";
			$check_query = $db->prepare($check_sql);
			$sql_params = array($this->facebook_id);
			$check_query->execute($sql_params);
			// Set object ID if successful, in case it's needed later
			if($this->id = $check_query->fetchColumn()) {
				return true;
			} else {
				return false;
			}
		} else {
			throw new Exception('No email has been added to the object.');
		}
	}

	/**
	 * Signs up the user
	 *
	 * @param string $password
	 * @param string $registration_id
	 * @return bool
	 */
	public function signUp($registration_id = false, $password = false) {
		global $f3, $db;
		if($this->email && $password) {
			$sql_params = array();
			$sql = "INSERT INTO user ";
			$sql .= "(email, registration_id, handle, password, date_created) ";
			$sql .= "VALUES (?, ?, ?, ?, NOW());";
			$query = $db->prepare($sql);
			$sql_params = array($this->email, $registration_id, $this->generateHandle(), $this->passwordHash($password));
			$query->execute($sql_params);
			return $db->lastInsertId();
		} else if($this->facebook_id) {
			$session = new FacebookSession(getFacebookAccessToken());
		    try {
		    	// Get information about fb user
				$me = (new FacebookRequest(
					$session, 'GET', '/me?fields=about,email,first_name,last_name,picture.height(200)'
				))->execute()->getGraphObject(GraphUser::className())->asArray();

				// Setup the object with obtained info
				$this->registration_id = $registration_id;
				$this->first_name = $me['first_name'];
				$this->last_name = $me['last_name'];
				if(@$me['email']) $this->email = $me['email']; else $this->email = '';
				if(@$me['picture']) {
					$this->image = $me['picture']->data->url;
				}

				$sql_params = array();
				$sql = "INSERT INTO user ";
				$sql .= "(facebook_id, email, registration_id, first_name, last_name, image, handle, date_created) ";
				$sql .= "VALUES (?, ?, ?, ?, ?, ?, ?, NOW());";
				$query = $db->prepare($sql);
				$sql_params = array($this->facebook_id, $this->email, $this->registration_id, $this->first_name, $this->last_name, $this->image, $this->generateHandle());
				$query->execute($sql_params);
				$this->id = $db->lastInsertId();

				// Get fb user friends
				$friends = (new FacebookRequest(
					$session, 'GET', '/me/friends'
				))->execute()->getGraphObject(GraphUser::className())->asArray();

				// Create records of friendship in our own database
				if(sizeof($friends['data'])) {
					foreach($friends['data'] as $friend) {
						$friend_id = User::getUserIdByFacebookId($friend->id);
						if($friend_request_id = $this->addFriend($friend_id)) {
							$tmp_user = new User(null, $friend_id);
							$tmp_user->acceptFriend($friend_request_id);
						}
						unset($friend, $tmp_user, $friend_id, $friend_request_id);
					}
				}

				return $this->id;
		    } catch (FacebookRequestException $e) {
		      echo $e->getMessage();
		    // The Graph API returned an error
		    } catch (\Exception $e) {
		      echo $e->getMessage();
		    // Some other error occurred
		    }
	    } else {
				throw new Exception('No password/email was provided/in object.');
	    }
	}

	/**
	 * Signs in the user
	 *
	 * @param string $password
	 * @param string $token
	 * @param string $registration_id
	 * @return int
	 */
	public function signIn($password, $token, $registration_id) {
		global $f3, $db, $logger;
		if(($password || $token) && $registration_id) {
			// Check if user with provided credentials is in the DB
		    $check_sql = "SELECT id, email, facebook_id FROM user WHERE ";
		    $sql_params = array();

		    // If email & password was provided, try to login with that
		    if($this->email && $password) {
		    	$check_sql .= "(email = ? AND password = ?)";
		    	$sql_params[] = $this->email;
		    	$sql_params[] = $this->passwordHash($password);

		    // If token was provided, try to login with that
		    } else if($token) {
		  		$check_sql .= "(md5(CONCAT(email, ?)) = ?) OR ";
		  		$check_sql .= "(md5(CONCAT(facebook_id, ?)) = ?)";
		  		$sql_params[] = $f3->get('md5_salt');
		  		$sql_params[] = $token;
		  		$sql_params[] = $f3->get('md5_salt');
		  		$sql_params[] = $token;
		  	}

		    $check_query = $db->prepare($check_sql);
		    $check_query->execute($sql_params);
				$result = $check_query->fetch(PDO::FETCH_OBJ);
				
		    // Set ID / email / facebook_id if they're not already in object, may need them later
		    if(@$result->id) $this->id = $result->id;
		    if(@$result->email) $this->email = $result->email;
		    if(@$result->facebook_id) $this->facebook_id = $result->facebook_id;
	
		    // Update registration ID
		    if($result && $this->id) {
			    $sql = "UPDATE user ";
			    $sql .= "SET registration_id = ? ";
			    $sql .= "WHERE id = ?";
			    $query = $db->prepare($sql);
			    $sql_params = array($registration_id, $this->id);
			    if(!$query->execute($sql_params)) {
			    	$logger->write(sprintf('Couldnt update registration_id for user %d', $this->id));
			    } else {
			    	$logger->write('Updated a registration ID');
			    }
				}
		    // Return result
		    return $result ? true : false;
	    } else {
				throw new Exception('No password/token was provided/in object.');
	    }
	}

	/**
	 * Resets user password
	 *
	 * @param void
	 * @return int
	 */
	public function resetPassword() {
		global $f3, $db;
		if($this->email && $this->id) {
			if(function_exists('generatePassword')) {
				$tmp_pass = generatePassword(6);
				$sql = "UPDATE user ";
			    $sql .= "SET password = ? ";
			    $sql .= "WHERE id = ?";
			    $query = $db->prepare($sql);
			    $sql_params = array($this->passwordHash($tmp_pass), $this->id);
			    if($query->execute($sql_params)) {
			    	if(class_exists('PHPMailer')) {
							$mail = new PHPMailer;
							$mail->isSMTP(); 
							$mail->Host = $f3->get('smtp_host');
							$mail->Port = $f3->get('smtp_port');
							$mail->Subject = '';
							$mail->From = $f3->get('email_from');
							$mail->FromName = $f3->get('email_from_name');
							$mail->addAddress($this->email);
							$mail->isHTML(true);  
							$mail->Body = sprintf(
								'<h1>Password Reset</h1><p>Your new temporary password is %s. Please sign in to ' . $f3->get('app_name') . ' to change it.</p>', 
								$tmp_pass
							);
							return $mail->send();
			    	} else {
							throw new Exception('PHPMailer function is non-existant.');
			    	}
			    } else {
			    	return false;
			    }
			} else {
				throw new Exception('generatePassword function is non-existant.');
			}
	    } else {
			throw new Exception('No email/id was provided/in object.');
	    }
	}

	/**
	 * Gets user information
	 * Intended for refreshing user local cache/storage on the front end
	 *
	 * @param bool $basic
	 * @param bool $hard_refresh
	 * @return object
	 */
	public function getInfo($basic = false, $hard_refresh = false) {
		global $f3, $db;
		if($this->id || $this->email) {
		    $sql = "SELECT u.id, u.handle, u.email, u.first_name, u.last_name, u.image, u.registration_id";

		    // If not a basic request, add queries to fetch friend requests
		    if(!$basic) {
		    	$sql .= ", GROUP_CONCAT(fs.user_id) AS friend_requests, GROUP_CONCAT(fs.id) AS friendship_ids FROM user u ";
		    	$sql .= "LEFT JOIN friendship fs ON(fs.friend_id = u.id AND fs.accepted != 1) ";
		    } else {
		    	$sql .= " FROM user u ";
		    }

		    $sql_params = array();
		    if($this->id) {
		    	$sql .= "WHERE u.id = ?";
		    	$sql_params[] = $this->id;
		    } else {
		    	$sql .= "WHERE u.email = ?";
		    	$sql_params[] = $this->email;
		    }

		    $query = $db->prepare($sql);
		    $query->execute($sql_params);
		    if($user = $query->fetch(PDO::FETCH_OBJ)) {
		    	$this->first_name = $user->first_name;
		    	$this->last_name = $user->last_name;
		    	$this->handle = $user->handle;
		    	$this->email = $user->email;
		    	$this->image = $user->image;
		    	$this->registration_id = $user->registration_id;

		    	// If not a basic request, add on friend requests
		    	if(!$basic) {
			    	// If any friend request, get info about each
			    	$friend_requests = array();
			    	if($user->friend_requests) {
			    		$requesters = explode(',', $user->friend_requests);
			    		$fs_ids = explode(',', $user->friendship_ids);
			    		$i = 0;
			    		// rid = requester id
			    		foreach($requesters as $rid) {
			    			$requester = new User(null, $rid);
			    			$friend_requests[$i] = $requester->getInfo(true);
			    			$friend_requests[$i]->friendship_id = $fs_ids[$i];
			    			unset($requester);
			    			$i++;
			    		}
			    	}
		    	}

		    	// Basic user info array
		    	$user_info = array(
		    		'id' => $user->id,
		    		'handle' => $user->handle,
		    		'first_name' => $user->first_name,
		    		'last_name' => $user->last_name,
		    		'image' => $this->getImage($hard_refresh)
		    	);
		    	
		    	// "Advanced" user info
		    	if(!$basic) {
		    		$user_info['friend_requests'] = $friend_requests;
		    	}

		    	return (object) $user_info;
		    } else {
		    	return false;
		    }
		} else {
			throw new Exception('Need at least email or id to get user info');
		}
	    
	  $sql_params = array();
	}

	/**
	 * Gets a user by facebook ID
	 *
	 * @param array $info
	 * @return bool
	 */
	public function getUserIdByFacebookId($facebook_id) {
		global $f3, $db;
		if($facebook_id) {
			$sql = "SELECT id FROM user WHERE facebook_id = ?";
			$query = $db->prepare($sql);
			$sql_params = array($facebook_id);
			$query->execute($sql_params);
			if($user_id = $query->fetchColumn()) {
				return $user_id;
			} else {
				return false;
			}
		} else {
			throw new Exception('No facebook id was provided');
		}
	}

	/**
	 * Signs up the user
	 *
	 * @param array $info
	 * @return bool
	 */
	public function editInformation($info) {
		global $f3, $db;
		if($this->id) {
			$sql_params = array();
			$sql = "UPDATE user ";
			$sql .= "SET first_name = ?, last_name = ? ";
			$sql .= "WHERE id = ?;";
			$query = $db->prepare($sql);
			$sql_params = array($info['first_name'], $info['last_name'], $this->id);
			$query->execute($sql_params);
			if($query->execute($sql_params)) {
				return true;
			} else {
				return false;
			}
		} else {
			throw new Exception('No id was provided/in object.');
		}
	}

	/**
	 * Gets user's full name
	 *
	 * @param bool basic
	 * @return object
	 */
	public function getFullName() {
		if($this->first_name && $this->last_name) {
			return $this->first_name . ' ' . $this->last_name;
		} else if($this->handle) {
			return sprintf('@%s', $this->handle);
		} else {
			return false;
		}
	}

	/**
	 * Gets user image (goes out to gravitar / facebook)
	 *
	 * @param bool $hard_refresh
	 * @return object
	 */
	public function getImage($hard_refresh = false) {
		global $f3, $db, $logger;

		// If an image is already set then use that
		if(!$hard_refresh) {
			if(isset($this->image) && $this->image) return $this->image;
		}

		// No email or id? Nothing we can do here
		if(!$this->email && !$this->id) return false;

		// If is a facebook account, we'll hard refresh the image
		if($hard_refresh && $this->facebook_id) {
			$session = new FacebookSession(getFacebookAccessToken());
		    try {
		    	// Get information about fb user
				$me = (new FacebookRequest(
					$session, 'GET', '/me?fields=picture.height(200)'
				))->execute()->getGraphObject(GraphUser::className())->asArray();

				if(@$me['picture']) {
					$sql = "UPDATE user ";
					$sql .= "SET image = ? ";
					$sql .= "WHERE id = ?;";
					$query = $db->prepare($sql);
					$sql_params = array($me['picture']->data->url, $this->id);
					$query->execute($sql_params);
					if(!$query->execute($sql_params)) {
						$logger->write(sprintf('Couldnt update facebook photo for user ID %s', $this->id));
					}
				}

			// The Graph API returned an error
			} catch (FacebookRequestException $e) {
				echo $e->getMessage();

			// Some other error occurred
			} catch (\Exception $e) {
				echo $e->getMessage();
			}
		}

		// Next we'll check the database for an image,
		$sql = "SELECT image FROM user WHERE ";
		if($this->email) {
			$sql .= 'email = ?';
			$sql_params = array($this->email);
		} else {
			$sql .= 'id = ?';
			$sql_params = array($this->id);
		}

		$query = $db->prepare($sql);
		$query->execute($sql_params);
		if($image = $query->fetchColumn()) {
			return $image;
		}

		// As a last resort we'll reach out to gravatar
		return getUserImage($this->email);
	}

	/**
	 * Adds a friend by id
	 *
	 * @param int id
	 * @return bool
	 */
	public function addFriend($id) {
		global $f3, $db;
		if(!$this->hasFriend($id)) {
			$sql_params = array();
			$sql = "INSERT INTO friendship ";
			$sql .= "(user_id, friend_id, date_request_sent) ";
			$sql .= "VALUES (?, ?, UNIX_TIMESTAMP());";
			$query = $db->prepare($sql);
			$sql_params = array($this->id, $id);
			$query->execute($sql_params);
			return $db->lastInsertId();
			// return $query->execute($sql_params) ? true : false;
		} else {
			return false;
		}
	}

	/**
	 * Accepts a friend request
	 *
	 * @param int id - The id of the sent friendship record
	 * @return bool
	 */
	public function acceptFriend($id) {
		global $f3, $db;
		if($this->id) {
			$check_sql = "SELECT friend_id, user_id FROM friendship WHERE id = ? AND accepted != 1";
			$check_query = $db->prepare($check_sql);
			$sql_params = array($id);
			$check_query->execute($sql_params);
			if($friendship = $check_query->fetch(PDO::FETCH_OBJ)) {
				// Make sure they're not already friends
				if(!$this->hasFriend($friendship->user_id)) {
					// Make sure the friend_id is this users' ID so we're not accepting some random friend request
					if($friendship->friend_id == $this->id) {
						// Update record to be accepted
						$sql = "UPDATE friendship ";
						$sql .= "SET accepted = 1, date_request_accepted = UNIX_TIMESTAMP() ";
						$sql .= "WHERE id = ?";
						$query = $db->prepare($sql);
						$sql_params = array($id);
						if($query->execute($sql_params)) {
							unset($sql, $query, $sql_params);
							// Insert a record this this user as well
							$sql = "INSERT INTO friendship ";
							$sql .= "(user_id, friend_id, accepted, date_request_accepted) ";
							$sql .= "VALUES (?, ?, 1, UNIX_TIMESTAMP());";
							$query = $db->prepare($sql);
							$sql_params = array($this->id, $friendship->user_id);
							$query->execute($sql_params);
							if($db->lastInsertId()) {
								return true;
							} else {
								return false;
							}
						} else {
							return false;
						}
					} else {
						return false;
					}
				} else {
					return false;
				}
			} else {
				return false;
			}
		} else {
			throw new Exception('The user ID must be provided to use hasFriend');
		}
	}

	/**
	 * Ignores friend request
	 *
	 * @param int id - The id of the sent friendship record
	 * @return bool
	 */
	public function ignoreFriendRequest($id) {
		global $f3, $db;
		if($this->id) {
			$check_sql = "SELECT friend_id, user_id FROM friendship WHERE id = ? AND accepted != 1";
			$check_query = $db->prepare($check_sql);
			$sql_params = array($id);
			$check_query->execute($sql_params);
			if($friendship = $check_query->fetch(PDO::FETCH_OBJ)) {
				if($friendship->friend_id == $this->id) {
					// Delete the record
					$sql = 'DELETE FROM friendship WHERE id = ?';
					$query = $db->prepare($sql);
					$sql_params = array($id);
					if($query->execute($sql_params)) {
						return true;
					} else {
						return false;
					}
				} else {
					return false;
				}
			} else {
				return false;
			}
		} else {
			throw new Exception('The user ID must be provided to use hasFriend');
		}
	}

	/**
	 * Determines whether user has a friend (by id) or not
	 * This function will return true if a friend request was sent. 
	 * It does NOT determine whether they are confirmed friends
	 *
	 * @param int $id
	 * @return bool
	 */
	public function hasFriend($id) {
		global $f3, $db;
		if($this->id) {
			$check_sql = "SELECT user_id FROM friendship WHERE user_id = ? AND friend_id = ?";
			$check_query = $db->prepare($check_sql);
			$sql_params = array($this->id, $id);
			$check_query->execute($sql_params);
			// Set object ID if successful, in case it's needed later
			if($check_query->fetchColumn()) {
				return true;
			} else {
				return false;
			}
		} else {
			throw new Exception('The user ID must be provided to use hasFriend');
		}
	}

	/**
	 * Gets array of all friends
	 *
	 * @param void
	 * @return array
	 */
	public function getFriends() {
		global $f3, $db;
		if($this->id) {
			$sql = "SELECT u.id, u.handle, u.first_name, u.last_name, u.email FROM friendship fs ";
			$sql .= "INNER JOIN user u ON (u.id = fs.friend_id) ";
			$sql .= "WHERE fs.accepted = 1 ";
			$sql .= "AND fs.user_id = ? ";
			$sql .= "ORDER BY u.first_name ASC";
		    $sql_params = array($this->id);
		    $query = $db->prepare($sql);
		    $query->execute($sql_params);
		    if($friends = $query->fetchAll(PDO::FETCH_OBJ)) {
		    	for($i = 0; $i < sizeof($friends); $i++) {
		    		$friend = new User($friends[$i]->email, $friends[$i]->id);
		    		$friends[$i]->image = $friend->getImage();
		    		unset($friend);
		    	}
		    	return $friends;
		    } else {
		    	return false;
		    }
	    } else {
			throw new Exception('The user ID must be provided to use getFriends');
		}
	}

	/**
	 * Generates a handle by the email
	 * Basically everything up until the @ sign. Loops until it finds one that's not taken yet
	 *
	 * @param void
	 * @return string
	 */
	public function generateHandle() {
		global $f3, $db;
		if($this->email) {
			$base_handle = substr($this->email, 0, (strpos($this->email, '@')));
			$unique = false;
			// Keep going through the gears till we find a unique handle
			$num = 1;
			$sql = 'SELECT id FROM user WHERE handle = ?';
			while(!$unique) {
				$candidate = ($base_handle.$num);
				$query = $db->prepare($sql);
			    $sql_params = array($candidate);
			    $query->execute($sql_params);
			    if(!$query->fetchColumn()) {
			    	$winner = $candidate;
			    	$unique = true;
			    	break;
			    }
				$num++;
			}
			return $winner;
	  } else if($this->first_name && $this->last_name) {
	    $base_handle = strtolower(sprintf('%s%s', $this->first_name, $this->last_name));
			$unique = false;
			// Keep going through the gears till we find a unique handle
			$num = 1;
			$sql = 'SELECT id FROM user WHERE handle = ?';
			while(!$unique) {
				$candidate = ($base_handle.$num);
				$query = $db->prepare($sql);
			    $sql_params = array($candidate);
			    $query->execute($sql_params);
			    if(!$query->fetchColumn()) {
			    	$winner = $candidate;
			    	$unique = true;
			    	break;
			    }
				$num++;
			}
			return $winner;
		}
		else {
			throw new Exception('Not enough information was provided/in object to generate a handle.');
		}
	}

	/**
	 * Finds all users by a search string
	 * Intended to be used without instantiating object
	 *
	 * @param string $string
	 * @param int $user_id
	 * @return string
	 */
	public static function getUsersByName($string, $user_id) {
		global $f3, $db;
		if($string) {
			$sql = "SELECT u.id, CONCAT('@', u.handle) AS handle, u.email, u.first_name, u.last_name, fs.id AS request_sent FROM user u ";
			$sql .= "LEFT JOIN friendship fs ON (fs.friend_id = u.id AND fs.user_id = ?)";
			$sql .= "WHERE handle LIKE ?";
		    $sql_params = array($user_id, '%'.$string.'%');
		    $query = $db->prepare($sql);
		    $query->execute($sql_params);
		    if($users = $query->fetchAll(PDO::FETCH_OBJ)) {
		    	for($i = 0; $i < sizeof($users); $i++) {
		    		$found_user = new User(null, $users[$i]->id);
		    		$users[$i]->image = $found_user->getImage();
		    		unset($found_user);
		    	}
		    	return $users;
		    } else {
		    	return false;
		    }
	    } else {
	    	return false;
				// throw new Exception('No email was provided/in object.');
	    }
	}

	/**
	 * Gets the unique token for the user
	 *
	 * @param void
	 * @return string
	 */
	public function getToken() {
		global $f3, $db;
		if($this->email) {
			return md5($this->email.$f3->get('md5_salt'));
    } else if($this->facebook_id) {
    	return md5($this->facebook_id.$f3->get('md5_salt'));
    } else {
			throw new Exception('No email was provided/in object.');
    }
	}

	/**
	 * Hashes up the provided password
	 *
	 * @param void
	 * @return string
	 */
	public function passwordHash($password) {
		global $f3, $db;
		if($password) {
			return md5($password.$f3->get('md5_salt'));
		} else {
			throw new Exception('No password provided.');
		}
	}
}