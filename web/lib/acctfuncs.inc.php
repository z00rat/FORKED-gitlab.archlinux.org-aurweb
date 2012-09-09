<?php

# Helper function- retrieve request param if available, "" otherwise
function in_request($name) {
	if (isset($_REQUEST[$name])) {
		return $_REQUEST[$name];
	}
	return "";
}

# Format PGP key fingerprint
function html_format_pgp_fingerprint($fingerprint) {
	if (strlen($fingerprint) != 40 || !ctype_xdigit($fingerprint)) {
		return $fingerprint;
	}

	return htmlspecialchars(substr($fingerprint, 0, 4) . " " .
		substr($fingerprint, 4, 4) . " " .
		substr($fingerprint, 8, 4) . " " .
		substr($fingerprint, 12, 4) . " " .
		substr($fingerprint, 16, 4) . "  " .
		substr($fingerprint, 20, 4) . " " .
		substr($fingerprint, 24, 4) . " " .
		substr($fingerprint, 28, 4) . " " .
		substr($fingerprint, 32, 4) . " " .
		substr($fingerprint, 36, 4) . " ", ENT_QUOTES);
}

# Display the standard Account form, pass in default values if any

function display_account_form($UTYPE,$A,$U="",$T="",$S="",
			$E="",$P="",$C="",$R="",$L="",$I="",$K="",$UID=0) {
	# UTYPE: what user type the form is being displayed for
	# A: what "form" name to use
	# U: value to display for username
	# T: value to display for account type
	# S: value to display for account suspended
	# E: value to display for email address
	# P: password value
	# C: confirm password value
	# R: value to display for RealName
	# L: value to display for Language preference
	# I: value to display for IRC nick
	# N: new package notify value
	# UID: Users.ID value in case form is used for editing

	global $SUPPORTED_LANGS;

	include("account_edit_form.php");
	return;
} # function display_account_form()


# process form input from a new/edit account form
#
function process_account_form($UTYPE,$TYPE,$A,$U="",$T="",$S="",$E="",
			$P="",$C="",$R="",$L="",$I="",$K="",$UID=0,$dbh=NULL) {
	# UTYPE: The user's account type
	# TYPE: either "edit" or "new"
	# A: what parent "form" name to use
	# U: value to display for username
	# T: value to display for account type
	# S: value to display for account suspended
	# E: value to display for email address
	# P: password value
	# C: confirm password value
	# R: value to display for RealName
	# L: value to display for Language preference
	# I: value to display for IRC nick
	# N: new package notify value
	# UID: database Users.ID value

	# error check and process request for a new/modified account
	global $SUPPORTED_LANGS;

	if (!$dbh) {
		$dbh = db_connect();
	}

	if(isset($_COOKIE['AURSID'])) {
		$editor_user = uid_from_sid($_COOKIE['AURSID'], $dbh);
	}
	else {
		$editor_user = null;
	}

	$error = "";
	if (empty($E) || empty($U)) {
		$error = __("Missing a required field.");
	}

	if ($TYPE == "new") {
		# they need password fields for this type of action
		#
		if (empty($P) || empty($C)) {
			$error = __("Missing a required field.");
		}
	} else {
		if (!$UID) {
			$error = __("Missing User ID");
		}
	}

  if (!$error && !valid_username($U) && !user_is_privileged($editor_user, $dbh))
	$error = __("The username is invalid.") . "<ul>\n"
			."<li>" . __("It must be between %s and %s characters long",
			USERNAME_MIN_LEN,  USERNAME_MAX_LEN )
			. "</li>"
			. "<li>" . __("Start and end with a letter or number") . "</li>"
			. "<li>" . __("Can contain only one period, underscore or hyphen.")
			. "</li>\n</ul>";

	if (!$error && $P && $C && ($P != $C)) {
		$error = __("Password fields do not match.");
	}
	if (!$error && $P != '' && !good_passwd($P))
		$error = __("Your password must be at least %s characters.",PASSWD_MIN_LEN);

	if (!$error && !valid_email($E)) {
		$error = __("The email address is invalid.");
	}

	if (!$error && $K != '' && !valid_pgp_fingerprint($K)) {
		$error = __("The PGP key fingerprint is invalid.");
	}

	if ($UTYPE == "Trusted User" && $T == 3) {
		$error = __("A Trusted User cannot assign Developer status.");
	}
	if (!$error && !array_key_exists($L, $SUPPORTED_LANGS)) {
		$error = __("Language is not currently supported.");
	}
	if (!$error) {
		# check to see if this username is available
		# NOTE: a race condition exists here if we care...
		#
		$q = "SELECT COUNT(*) AS CNT FROM Users ";
		$q.= "WHERE Username = " . $dbh->quote($U);
		if ($TYPE == "edit") {
			$q.= " AND ID != ".intval($UID);
		}
		$result = $dbh->query($q);
		$row = $result->fetch(PDO::FETCH_NUM);

		if ($row[0]) {
			$error = __("The username, %s%s%s, is already in use.",
				"<b>", htmlspecialchars($U,ENT_QUOTES), "</b>");
		}
	}
	if (!$error) {
		# check to see if this email address is available
		# NOTE: a race condition exists here if we care...
		#
		$q = "SELECT COUNT(*) AS CNT FROM Users ";
		$q.= "WHERE Email = " . $dbh->quote($E);
		if ($TYPE == "edit") {
			$q.= " AND ID != ".intval($UID);
		}
		$result = $dbh->query($q);
		$row = $result->fetch(PDO::FETCH_NUM);

		if ($row[0]) {
			$error = __("The address, %s%s%s, is already in use.",
					"<b>", htmlspecialchars($E,ENT_QUOTES), "</b>");
		}
	}
	if ($error) {
		print "<span class='error'>".$error."</span><br/>\n";
		display_account_form($UTYPE, $A, $U, $T, $S, $E, "", "",
				$R, $L, $I, $K, $UID);
	} else {
		if ($TYPE == "new") {
			# no errors, go ahead and create the unprivileged user
			$salt = generate_salt();
			$P = salted_hash($P, $salt);
			$U = $dbh->quote($U);
			$E = $dbh->quote($E);
			$P = $dbh->quote($P);
			$salt = $dbh->quote($salt);
			$R = $dbh->quote($R);
			$L = $dbh->quote($L);
			$I = $dbh->quote($I);
			$K = $dbh->quote(str_replace(" ", "", $K));
			$q = "INSERT INTO Users (AccountTypeID, Suspended, ";
			$q.= "Username, Email, Passwd, Salt, RealName, ";
			$q.= "LangPreference, IRCNick, PGPKey) VALUES (1, 0, ";
			$q.= "$U, $E, $P, $salt, $R, $L, $I, $K)";
			$result = $dbh->exec($q);
			if (!$result) {
				print __("Error trying to create account, %s%s%s.",
						"<b>", htmlspecialchars($U,ENT_QUOTES), "</b>");
			} else {
				# account created/modified, tell them so.
				#
				print __("The account, %s%s%s, has been successfully created.",
						"<b>", htmlspecialchars($U,ENT_QUOTES), "</b>");
				print "<p>\n";
				print __("Click on the Login link above to use your account.");
				print "</p>\n";
			}

		} else {
			# no errors, go ahead and modify the user account

			$q = "UPDATE Users SET ";
			$q.= "Username = " . $dbh->quote($U);
			if ($T) {
				$q.= ", AccountTypeID = ".intval($T);
			}
			if ($S) {
				$q.= ", Suspended = 1";
			} else {
				$q.= ", Suspended = 0";
			}
			$q.= ", Email = " . $dbh->quote($E);
			if ($P) {
				$salt = generate_salt();
				$hash = salted_hash($P, $salt);
				$q .= ", Passwd = '$hash', Salt = '$salt'";
			}
			$q.= ", RealName = " . $dbh->quote($R);
			$q.= ", LangPreference = " . $dbh->quote($L);
			$q.= ", IRCNick = " . $dbh->quote($I);
			$q.= ", PGPKey = " . $dbh->quote(str_replace(" ", "", $K));
			$q.= " WHERE ID = ".intval($UID);
			$result = $dbh->exec($q);
			if (!$result) {
				print __("Error trying to modify account, %s%s%s.",
						"<b>", htmlspecialchars($U,ENT_QUOTES), "</b>");
			} else {
				print __("The account, %s%s%s, has been successfully modified.",
						"<b>", htmlspecialchars($U,ENT_QUOTES), "</b>");
			}
		}
	}
	return;
}

# search existing accounts
#
function search_accounts_form() {
	include("search_accounts_form.php");
	return;
}


# search results page
#
function search_results_page($UTYPE,$O=0,$SB="",$U="",$T="",
		$S="",$E="",$R="",$I="",$K="",$dbh=NULL) {
	# UTYPE: what account type the user belongs to
	# O: what row offset we're at
	# SB: how to sort the results
	# U: value to display for username
	# T: value to display for account type
	# S: value to display for account suspended
	# E: value to display for email address
	# R: value to display for RealName
	# I: value to display for IRC nick

	$HITS_PER_PAGE = 50;
	if ($O) {
		$OFFSET = intval($O);
	} else {
		$OFFSET = 0;
	}
	if ($OFFSET < 0) {
		$OFFSET = 0;
	}
	$search_vars = array();

	if (!$dbh) {
		$dbh = db_connect();
	}

	$q = "SELECT Users.*, AccountTypes.AccountType ";
	$q.= "FROM Users, AccountTypes ";
	$q.= "WHERE AccountTypes.ID = Users.AccountTypeID ";
	if ($T == "u") {
		$q.= "AND AccountTypes.ID = 1 ";
		$search_vars[] = "T";
	} elseif ($T == "t") {
		$q.= "AND AccountTypes.ID = 2 ";
		$search_vars[] = "T";
	} elseif ($T == "d") {
		$q.= "AND AccountTypes.ID = 3 ";
		$search_vars[] = "T";
	}
	if ($S) {
		$q.= "AND Users.Suspended = 1 ";
		$search_vars[] = "S";
	}
	if ($U) {
		$U = "%" . addcslashes($U, '%_') . "%";
		$q.= "AND Username LIKE " . $dbh->quote($U) . " ";
		$search_vars[] = "U";
	}
	if ($E) {
		$E = "%" . addcslashes($E, '%_') . "%";
		$q.= "AND Email LIKE " . $dbh->quote($E) . " ";
		$search_vars[] = "E";
	}
	if ($R) {
		$R = "%" . addcslashes($R, '%_') . "%";
		$q.= "AND RealName LIKE " . $dbh->quote($R) . " ";
		$search_vars[] = "R";
	}
	if ($I) {
		$I = "%" . addcslashes($I, '%_') . "%";
		$q.= "AND IRCNick LIKE " . $dbh->quote($I) . " ";
		$search_vars[] = "I";
	}
	if ($K) {
		$K = "%" . addcslashes(str_replace(" ", "", $K), '%_') . "%";
		$q.= "AND PGPKey LIKE " . $dbh->quote($K) . " ";
		$search_vars[] = "K";
	}
	switch ($SB) {
		case 't':
			$q.= "ORDER BY AccountTypeID, Username ";
			break;
		case 'r':
			$q.= "ORDER BY RealName, AccountTypeID ";
			break;
		case 'i':
			$q.= "ORDER BY IRCNick, AccountTypeID ";
			break;
		case 'v':
			$q.= "ORDER BY LastVoted, Username ";
			break;
		default:
			$q.= "ORDER BY Username, AccountTypeID ";
			break;
	}
	$search_vars[] = "SB";
	$q.= "LIMIT " . $HITS_PER_PAGE . " OFFSET " . $OFFSET;

	if (!$dbh) {
		$dbh = db_connect();
	}

	$result = $dbh->query($q);

	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$userinfo[] = $row;
	}

	include("account_search_results.php");
	return;
}

/*
 * Returns SID (Session ID) and error (error message) in an array
 * SID of 0 means login failed.
 */
function try_login($dbh=NULL) {
	global $MAX_SESSIONS_PER_USER, $PERSISTENT_COOKIE_TIMEOUT;

	$login_error = "";
	$new_sid = "";
	$userID = null;

	if ( isset($_REQUEST['user']) || isset($_REQUEST['passwd']) ) {
		if (!$dbh) {
			$dbh = db_connect();
		}
		$userID = valid_user($_REQUEST['user'], $dbh);

		if ( user_suspended($userID, $dbh) ) {
			$login_error = "Account Suspended.";
		}
		elseif ( $userID && isset($_REQUEST['passwd'])
		  && valid_passwd($userID, $_REQUEST['passwd'], $dbh) ) {

			$logged_in = 0;
			$num_tries = 0;

			# Account looks good.  Generate a SID and store it.

			while (!$logged_in && $num_tries < 5) {
				if ($MAX_SESSIONS_PER_USER) {
					# Delete all user sessions except the
					# last ($MAX_SESSIONS_PER_USER - 1).
					$q = "DELETE s.* FROM Sessions s ";
					$q.= "LEFT JOIN (SELECT SessionID FROM Sessions ";
					$q.= "WHERE UsersId = " . $userID . " ";
					$q.= "ORDER BY LastUpdateTS DESC ";
					$q.= "LIMIT " . ($MAX_SESSIONS_PER_USER - 1) . ") q ";
					$q.= "ON s.SessionID = q.SessionID ";
					$q.= "WHERE s.UsersId = " . $userID . " ";
					$q.= "AND q.SessionID IS NULL;";
					$dbh->query($q);
				}

				$new_sid = new_sid();
				$q = "INSERT INTO Sessions (UsersID, SessionID, LastUpdateTS)"
				  ." VALUES (" . $userID . ", '" . $new_sid . "', UNIX_TIMESTAMP())";
				$result = $dbh->exec($q);

				# Query will fail if $new_sid is not unique
				if ($result) {
					$logged_in = 1;
					break;
				}

				$num_tries++;
			}

			if ($logged_in) {
				$q = "UPDATE Users SET LastLogin = UNIX_TIMESTAMP() ";
				$q.= "WHERE ID = '$userID'";
				$dbh->exec($q);

				# set our SID cookie
				if (isset($_POST['remember_me']) &&
					$_POST['remember_me'] == "on") {
					# Set cookies for 30 days.
					$cookie_time = time() + $PERSISTENT_COOKIE_TIMEOUT;

					# Set session for 30 days.
					$q = "UPDATE Sessions SET LastUpdateTS = $cookie_time ";
					$q.= "WHERE SessionID = '$new_sid'";
					$dbh->exec($q);
				}
				else
					$cookie_time = 0;

				setcookie("AURSID", $new_sid, $cookie_time, "/", null, !empty($_SERVER['HTTPS']), true);
				header("Location: " . get_uri('/'));
				$login_error = "";

			}
			else {
				$login_error = "Error trying to generate session id.";
			}
		}
		else {
			$login_error = __("Bad username or password.");
		}
	}
	return array('SID' => $new_sid, 'error' => $login_error);
}

/*
 * Only checks if the name itself is valid
 * Longer or equal to USERNAME_MIN_LEN
 * Shorter or equal to USERNAME_MAX_LEN
 * Starts and ends with a letter or number
 * Contains at most ONE dot, hyphen, or underscore
 * Returns the username if it is valid
 * Returns nothing if it isn't valid
 */
function valid_username($user) {
	if (!empty($user)) {

		#Is username at not too short or too long?
		if ( strlen($user) >= USERNAME_MIN_LEN &&
		  strlen($user) <= USERNAME_MAX_LEN ) {

			$user = strtolower($user);
			# Does username:
			# start and end with a letter or number
			# contain only letters and numbers,
			#  and at most has one dash, period, or underscore
			if ( preg_match("/^[a-z0-9]+[.\-_]?[a-z0-9]+$/", $user) ) {
				#All is good return the username
				return $user;
			}
		}
	}

	return;
}

/*
 * Checks if the username is valid and if it exists in the database
 * Returns the username ID or nothing
 */
function valid_user($user, $dbh=NULL) {
	/*	if ( $user = valid_username($user) ) { */

	if(!$dbh) {
		$dbh = db_connect();
	}

	if ( $user ) {
		$q = "SELECT ID FROM Users ";
		$q.= "WHERE Username = " . $dbh->quote($user);

		$result = $dbh->query($q);
		# Is the username in the database?
		if ($result) {
			$row = $result->fetch(PDO::FETCH_NUM);
			return $row[0];
		}
	}
	return;
}

# Check for any open proposals about a user. Used to prevent multiple proposals.
function open_user_proposals($user, $dbh=NULL) {
	if(!$dbh) {
		$dbh = db_connect();
	}
	$q = "SELECT * FROM TU_VoteInfo WHERE User = " . $dbh->quote($user) . " ";
	$q.= "AND End > UNIX_TIMESTAMP()";
	$result = $dbh->query($q);
	if ($result->fetchColumn()) {
		return true;
	}
	else {
		return false;
	}
}

# Creates a new trusted user proposal from entered agenda.
# Optionally takes proposal about specific user. Length of vote set by submitter.
function add_tu_proposal($agenda, $user, $votelength, $submitteruid, $dbh=NULL) {
	if(!$dbh) {
		$dbh = db_connect();
	}

	$q = "INSERT INTO TU_VoteInfo (Agenda, User, Submitted, End, SubmitterID) VALUES ";
	$q.= "(" . $dbh->quote($agenda) . ", " . $dbh->quote($user) . ", ";
	$q.= "UNIX_TIMESTAMP(), UNIX_TIMESTAMP() + " . $dbh->quote($votelength);
	$q.= ", " . $submitteruid . ")";
	$result = $dbh->exec($q);
}

# Add a reset key for a specific user
function create_resetkey($resetkey, $uid, $dbh=NULL) {
	if(!$dbh) {
		$dbh = db_connect();
	}
	$q = "UPDATE Users ";
	$q.= "SET ResetKey = '" . $resetkey . "' ";
	$q.= "WHERE ID = " . $uid;
	$dbh->exec($q);
}

# Change a password and save the salt only if reset key and email are correct
function password_reset($hash, $salt, $resetkey, $email, $dbh=NULL) {
	if(!$dbh) {
		$dbh = db_connect();
	}
	$q = "UPDATE Users ";
	$q.= "SET Passwd = '$hash', ";
	$q.= "Salt = '$salt', ";
	$q.= "ResetKey = '' ";
	$q.= "WHERE ResetKey != '' ";
	$q.= "AND ResetKey = " . $dbh->quote($resetkey) . " ";
	$q.= "AND Email = " . $dbh->quote($email);
	$result = $dbh->exec($q);

	if (!$result) {
		$error = __('Invalid e-mail and reset key combination.');
		return $error;
	} else {
		header('Location: ' . get_uri('/passreset/') . '?step=complete');
		exit();
	}
}

function good_passwd($passwd) {
	if ( strlen($passwd) >= PASSWD_MIN_LEN ) {
		return true;
	}
	return false;
}

/* Verifies that the password is correct for the userID specified.
 * Returns true or false
 */
function valid_passwd($userID, $passwd, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}
	if ( strlen($passwd) > 0 ) {
		# get salt for this user
		$salt = get_salt($userID);
		if ($salt) {
			# use salt
			$q = "SELECT ID FROM Users ";
			$q.= "WHERE ID = " . $userID . " ";
			$q.= "AND Passwd = " . $dbh->quote(salted_hash($passwd, $salt));
			$result = $dbh->query($q);
			if ($result) {
				$row = $result->fetch(PDO::FETCH_NUM);
				if ($row[0]) {
					return true;
				}
			}
		} else {
			# check without salt
			$q = "SELECT ID FROM Users ";
			$q.= "WHERE ID = " . $userID . " ";
			$q.= "AND Passwd = " . $dbh->quote(md5($passwd));
			$result = $dbh->query($q);
			if ($result) {
				$row = $result->fetch(PDO::FETCH_NUM);
				if ($row[0]) {
					# password correct, but salt it first
					if (!save_salt($userID, $passwd)) {
						trigger_error("Unable to salt user's password;" .
							" ID " . $userID, E_USER_WARNING);
						return false;
					}
					return true;
				}
			}
		}
	}
	return false;
}

/*
 * Checks if the PGP key fingerprint is valid (must be 40 hexadecimal digits).
 */
function valid_pgp_fingerprint($fingerprint) {
	$fingerprint = str_replace(" ", "", $fingerprint);
	return (strlen($fingerprint) == 40 && ctype_xdigit($fingerprint));
}

/*
 * Is the user account suspended?
 */
function user_suspended($id, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}
	if (!$id) {
		return false;
	}
	$q = "SELECT Suspended FROM Users WHERE ID = " . $id;
	$result = $dbh->query($q);
	if ($result) {
		$row = $result->fetch(PDO::FETCH_NUM);
		if ($row[0]) {
			return true;
		}
	}
	return false;
}

/*
 * This should be expanded to return something
 */
function user_delete($id, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}
	$q = "DELETE FROM Users WHERE ID = " . $id;
	$dbh->query($q);
	return;
}

/*
 * A different way of determining a user's privileges
 * rather than account_from_sid()
 */
function user_is_privileged($id, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}
	$q = "SELECT AccountTypeID FROM Users WHERE ID = " . $id;
	$result = $dbh->query($q);
	if ($result) {
		$row = $result->fetch(PDO::FETCH_NUM);
		if($row[0] > 1) {
			return $row[0];
		}
	}
	return 0;

}

# Remove session on logout
function delete_session_id($sid, $dbh=NULL) {
	if(!$dbh) {
		$dbh = db_connect();
	}

	$q = "DELETE FROM Sessions WHERE SessionID = " . $dbh->quote($sid);
	$dbh->query($q);
}

# Clear out old expired sessions.
function clear_expired_sessions($dbh=NULL) {
	global $LOGIN_TIMEOUT;

	if(!$dbh) {
		$dbh = db_connect();
	}

	$q = "DELETE FROM Sessions WHERE LastUpdateTS < (UNIX_TIMESTAMP() - $LOGIN_TIMEOUT)";
	$dbh->query($q);

	return;
}

function account_details($uid, $username, $dbh=NULL) {
	if(!$dbh) {
		$dbh = db_connect();
	}
	$q = "SELECT Users.*, AccountTypes.AccountType ";
	$q.= "FROM Users, AccountTypes ";
	$q.= "WHERE AccountTypes.ID = Users.AccountTypeID ";
	if (!empty($uid)) {
		$q.= "AND Users.ID = ".intval($uid);
	} else {
		$q.= "AND Users.Username = " . $dbh->quote($username);
	}
	$result = $dbh->query($q);

	if ($result) {
		$row = $result->fetch(PDO::FETCH_ASSOC);
	}

	return $row;
}

function own_account_details($sid, $dbh=NULL) {
	if(!$dbh) {
		$dbh = db_connect();
	}
	$q = "SELECT Users.*, AccountTypes.AccountType ";
	$q.= "FROM Users, AccountTypes, Sessions ";
	$q.= "WHERE AccountTypes.ID = Users.AccountTypeID ";
	$q.= "AND Users.ID = Sessions.UsersID ";
	$q.= "AND Sessions.SessionID = " . $dbh->quote($sid);
	$result = $dbh->query($q);

	if ($result) {
		$row = $result->fetch(PDO::FETCH_ASSOC);
	}

	return $row;
}

function tu_voted($voteid, $uid, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}

	$q = "SELECT COUNT(*) FROM TU_Votes ";
	$q.= "WHERE VoteID = " . intval($voteid) . " AND UserID = " . intval($uid);
	$result = $dbh->query($q);
	if ($result->fetchColumn() > 0) {
		return true;
	}
	else {
		return false;
	}
}

function current_proposal_list($order, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}

	$q = "SELECT * FROM TU_VoteInfo WHERE End > " . time() . " ORDER BY Submitted " . $order;
	$result = $dbh->query($q);

	$details = array();
	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$details[] = $row;
	}

	return $details;
}

function past_proposal_list($order, $lim, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}

	$q = "SELECT * FROM TU_VoteInfo WHERE End < " . time() . " ORDER BY Submitted " . $order . $lim;
	$result = $dbh->query($q);

	$details = array();
	while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$details[] = $row;
	}

	return $details;
}

function proposal_count($dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}

	$q = "SELECT COUNT(*) FROM TU_VoteInfo";
	$result = $dbh->query($q);
	$row = $result->fetch(PDO::FETCH_NUM);

	return $row[0];
}

function vote_details($voteid, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}

	$q = "SELECT * FROM TU_VoteInfo ";
	$q.= "WHERE ID = " . intval($voteid);

	$result = $dbh->query($q);
	$row = $result->fetch(PDO::FETCH_ASSOC);

	return $row;
}

function voter_list($voteid, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}

	$whovoted = '';

	$q = "SELECT tv.UserID,U.Username ";
	$q.= "FROM TU_Votes tv, Users U ";
	$q.= "WHERE tv.VoteID = " . intval($voteid);
	$q.= " AND tv.UserID = U.ID ";
	$q.= "ORDER BY Username";

	$result = $dbh->query($q);
	if ($result) {
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$whovoted.= '<a href="' . get_uri('/accounts/') . '?Action=AccountInfo&amp;ID='.$row['UserID'].'">'.$row['Username'].'</a> ';
		}
	}
	return $whovoted;
}

function cast_proposal_vote($voteid, $uid, $vote, $newtotal, $dbh=NULL) {
	if (!$dbh) {
		$dbh = db_connect();
	}

	$q = "UPDATE TU_VoteInfo SET " . $vote . " = (" . $newtotal . ") WHERE ID = " . $voteid;
	$result = $dbh->exec($q);

	$q = "INSERT INTO TU_Votes (VoteID, UserID) VALUES (" . intval($voteid) . ", " . intval($uid) . ")";
	$result = $dbh->exec($q);
}
