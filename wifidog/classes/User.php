<?php

/********************************************************************\
 * This program is free software; you can redistribute it and/or    *
 * modify it under the terms of the GNU General Public License as   *
 * published by the Free Software Foundation; either version 2 of   *
 * the License, or (at your option) any later version.              *
 *                                                                  *
 * This program is distributed in the hope that it will be useful,  *
 * but WITHOUT ANY WARRANTY; without even the implied warranty of   *
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the    *
 * GNU General Public License for more details.                     *
 *                                                                  *
 * You should have received a copy of the GNU General Public License*
 * along with this program; if not, contact:                        *
 *                                                                  *
 * Free Software Foundation           Voice:  +1-617-542-5942       *
 * 59 Temple Place - Suite 330        Fax:    +1-617-542-2652       *
 * Boston, MA  02111-1307,  USA       gnu@gnu.org                   *
 *                                                                  *
 \********************************************************************/
/**@file User.php
 * @author Copyright (C) 2005 Benoit Gr�goire <bock@step.polymtl.ca>
 */

require_once BASEPATH.'include/common.php';

/** Abstract a User. */
class User {
	private $mRow;
	private $mId;

	/** Instantiate a user object 
	 * @param $id The id of the requested user 
	 * @return a User object, or null if there was an error
	 */
	static function getUserByID($id) {
		$object = null;
		$object = new self($id);
		return $object;
	}
	
	/** Instantiate a user object 
	 * @param $username The username of the user
	 * @param $account_origin The account origin
	 * @return a User object, or null if there was an error
	 */
	static function getUserByUsernameAndOrigin($username, $account_origin) {
		global $db;
		$object = null;
		
		$username_str = $db->EscapeString($username);
		$account_origin_str = $db->EscapeString($account_origin);
		$db->ExecSqlUniqueRes("SELECT user_id FROM users WHERE username = '$username_str' AND account_origin = '$account_origin_str'", $user_info, false);
		
		$object = new self($user_info['user_id']);
		return $object;
	}

	/** Returns the hash of the password suitable for storing or comparing in the database.  This hash is the same one as used in NoCat
	 * @return The 32 character hash.
	 */
	public static function passwordHash($password) {
		return base64_encode(pack("H*", md5($password)));
	}

	/** Create a new User in the database 
	 * @param $id The id to be given to the new user
	 * @return the newly created User object, or null if there was an error
	 */
	static function createUser($id, $username, $account_origin, $email, $password) {
		global $db;

		$object = null;
		$id_str = $db->EscapeString($id);
		$username_str = $db->EscapeString($username);
		$account_origin_str = $db->EscapeString($account_origin);
		$email_str = $db->EscapeString($email);
		$password_hash = $db->EscapeString(User :: passwordHash($password));
		$status = ACCOUNT_STATUS_VALIDATION;
		$token = User :: generateToken();

		$db->ExecSqlUpdate("INSERT INTO users (user_id,username, account_origin,email,pass,account_status,validation_token,reg_date) VALUES ('$id_str','$username_str','$account_origin_str','$email_str','$password_hash','$status','$token',NOW())");

		$object = new self($id);
		return $object;
	}

	/** @param $object_id The id of the user */
	function __construct($object_id) {
		global $db;
		$object_id_str = $db->EscapeString($object_id);
		$sql = "SELECT * FROM users WHERE user_id='{$object_id_str}'";
		$db->ExecSqlUniqueRes($sql, $row, false);
		if ($row == null) {
			throw new Exception(_("user_id '{$object_id_str}' could not be found in the database"));
		}
		$this->mRow = $row;
		$this->mId = $row['user_id'];
	} //End class

	function getId() {
		return $this->mId;
	}

	function getUsername() {
		return $this->mRow['username'];
	}

	private function getEmail() {
		return $this->mRow['email'];
	}

	private function getPasswordHash() {
		return $this->mRow['pass'];
	}

	/** Get the account status.  
	 * @return Possible values are listed in common.php
	*/
	function getAccountStatus() {
		return $this->mRow['account_status'];
	}

	function setAccountStatus($status) {
		global $db;

		$status_str = $db->EscapeString($status);
		if (!($update = $db->ExecSqlUpdate("UPDATE users SET account_status='{$status_str}' WHERE user_id='{$this->mId}'"))) {
			throw new Exception(_("Could not update status."));
		}
		$this->mRow['account_status'] = $status;
	}

	/** Is the user valid?  Valid means that the account is validated or hasn't exhausted it's validation period. 
	 $errmsg: Returs the reason why the account is or isn't valid */
	function isUserValid(& $errmsg = null) {
		$retval = false;
		$account_status = $this->getAccountStatus();
		if ($account_status == ACCOUNT_STATUS_ALLOWED) {
			$retval = true;
		} else
			if ($account_status == ACCOUNT_STATUS_VALIDATION) {
				$sql = "SELECT CASE WHEN ((NOW() - reg_date) > interval '".VALIDATION_GRACE_TIME." minutes') THEN true ELSE false END AS validation_grace_time_expired FROM users WHERE (user_id='{$this->mId}'";
				$db->ExecSqlUniqueRes($sql, $user_info, false);

				if ($user_info['validation_grace_time_expired'] == 't') {
					$errmsg = _("Sorry, your ").$validation_grace_time._(" minutes grace period to retrieve your email and validate your account has now expired. You will have to connect to the internet and validate your account from another location or create a new account. For help, please ").'<a href="'.BASEPATH.'faq.php'.'">'._("click here.").'</a>';
					$retval = false;
				} else {
					$errmsg = _("Your account is currently valid.");
					$retval = true;
				}
			} else {
				$errmsg = _("Sorry, your account is not valid: ").$account_status_to_text[$account_status];
				$retval = false;
			}
		return $retval;
	}

	function getValidationToken() {
		return $this->mRow['validation_token'];
	}

	function getInfoArray() {
		return $this->mRow;
	}

	/** Generate a token in the connection table so the user can actually use the internet 
	@return true on success, false on failure 
	*/
	function generateConnectionToken() {
		if ($this->isUserValid()) {
			global $db;
			$token = self :: generateToken();
			if ($_SERVER['REMOTE_ADDR']) {
				$node_ip = $db->EscapeString($_SERVER['REMOTE_ADDR']);
			}
			if (isset ($_REQUEST['gw_id']) && $_REQUEST['gw_id']) {
				$node_id = $db->EscapeString($_REQUEST['gw_id']);
				$db->ExecSqlUpdate("INSERT INTO connections (user_id, token, token_status, timestamp_in, node_id, node_ip, last_updated) VALUES ('".$this->getId()."', '$token', '".TOKEN_UNUSED."', NOW(), '$node_id', '$node_ip', NOW())", false);
				$retval = $token;
			}
			else
				$retval = false;
		} else {
			$retval = false;
		}
		return $retval;
	}

	function setPassword($password) {
		global $db;

		$new_password_hash = $this->passwordHash($password);
		if (!($update = $db->ExecSqlUpdate("UPDATE users SET pass='$new_password_hash' WHERE user_id='{$this->mId}'"))) {
			throw new Exception(_("Could not change user's password."));
		}
		$this->mRow['pass'] = $password;
	}

	function getConnections() {
		global $db;
		$db->ExecSql("SELECT * FROM connections,nodes WHERE user_id='{$this->mId}' AND nodes.node_id=connections.node_id ORDER BY timestamp_in", $connections, false);
		return $connections;
	}

	/** Return all the users
	 */
	static function getAllUsers() {
		global $db;

		$db->ExecSql("SELECT * FROM users", $objects, false);
		if ($objects == null) {
			throw new Exception(_("No users could not be found in the database"));
		}
		return $objects;
	}

	function sendLostUsername() {
		$username = $this->getUsername();
		$subject = LOST_USERNAME_EMAIL_SUBJECT;
		$from = "From: ".VALIDATION_EMAIL_FROM_ADDRESS;
		$body = "Hello,
		
		You have requested that the authentication server send you your username:
		
		Username: $username
		
		Have a nice day,
		
		The Team";

		mail($this->getEmail(), $subject, $body, $from);
	}

	function sendValidationEmail() {
		if ($this->getAccountStatus() != ACCOUNT_STATUS_VALIDATION) {
			throw new Exception(_("The user is not in validation period."));
		} else {
			if ($this->getValidationToken() == "") {
				throw new Exception(_("The validation token is empty."));
			} else {
				$subject = VALIDATION_EMAIL_SUBJECT;
				$url = "http://".$_SERVER["SERVER_NAME"]."/validate.php?username=".$this->getUsername()."&token=".$this->getValidationToken();
				$body = "Hello!
				
				Please follow the link below to validate your account.
				
				$url
				
				Thank you,
				
				The Team";
				$from = "From: ".VALIDATION_EMAIL_FROM_ADDRESS;

				mail($this->getEmail(), $subject, $body, $from);
			}
		}
	}

	function sendLostPasswordEmail() {
		global $db;

		$new_password = $this->randomPass();
		$this->setPassword($new_password);

		$username = $this->getUsername();

		$subject = LOST_PASSWORD_EMAIL_SUBJECT;
		$body = "Hello,
		
		You have requested that the authentication server send you a new password:
		
		Username: $username
		Password: $new_password
		
		Have a nice day,
		
		The Team";
		$from = "From: ".VALIDATION_EMAIL_FROM_ADDRESS;

		mail($this->getEmail(), $subject, $body, $from);
	}

	static function userExists($id) {
		global $db;
		$id_str = $db->EscapeString($id);
		$sql = "SELECT * FROM users WHERE user_id='{$id_str}'";
		$db->ExecSqlUniqueRes($sql, $row, false);
		return $row;
	}

	function emailExists($id) {
		global $db;
		$id_str = $db->EscapeString($id);
		$sql = "SELECT * FROM users WHERE email='{$id_str}'";
		$db->ExecSqlUniqueRes($sql, $row, false);
		return $row;
	}

	public static function randomPass() {
		$rand_pass = ''; // makes sure the $pass var is empty.
		for ($j = 0; $j < 3; $j ++) {
			$startnend = array ('b', 'c', 'd', 'f', 'g', 'h', 'j', 'k', 'l', 'm', 'n', 'p', 'q', 'r', 's', 't', 'v', 'w', 'x', 'y', 'z',);
			$mid = array ('a', 'e', 'i', 'o', 'u', 'y',);
			$count1 = count($startnend) - 1;
			$count2 = count($mid) - 1;

			for ($i = 0; $i < 3; $i ++) {
				if ($i != 1) {
					$rand_pass .= $startnend[rand(0, $count1)];
				} else {
					$rand_pass .= $mid[rand(0, $count2)];
				}
			}
		}
		return $rand_pass;
	}

	public static function generateToken() {
		return md5(uniqid(rand(), 1));
	}

} // End class
?>

