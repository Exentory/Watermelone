<?php
error_reporting(-1);
ini_set('display_errors', 'On');
sec_session_start();
// Require the config file. this file reads the database info from a JSON file
require_once('config/config.php');
// The message if it needs to popup
$error = "none";
$version = $s_CoreVersion;

$conn = connect($s_Server, $s_Database, $s_Username, $s_Password);

function connect($s_Server, $s_Database, $s_Username, $s_Password) {
	try {
  $conn = new PDO("mysql:host=$s_Server;dbname=$s_Database", "$s_Username", "$s_Password");
  $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}
	catch (PDOException $e) {
		echo "An error has occurred: " . $e->getMessage();
	}
	return $conn;
}

function sec_session_start() {
    $session_name = 'note_session_id';   // Set a custom session name
    $usinghttps = false;
    // This stops JavaScript being able to access the session id.
    $httponly = true;
    // Forces sessions to only use cookies.
    if (ini_set('session.use_only_cookies', 1) === FALSE) {
        header("Location: redirect.php");
        exit();
    }
    // Gets current cookies params.
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params($cookieParams["lifetime"],
        $cookieParams["path"],
        $cookieParams["domain"],
        $usinghttps,
        $httponly);
    // Sets the session name to the one set above.
    session_name($session_name);
    session_start();            // Start the PHP session
    session_regenerate_id(true);    // regenerated the session, delete the old one.
}

function redirect() {
	$redirecturl = 'index.php';
	header("Location: $redirecturl");
	header("HTTP/1.1 303 See Other");
	die("redirecting");
}

// If a edit is reffered, store it when the user needs to login first
// We do this to check if the user already edited the post
// This way we prevent loops
if(isset($_SESSION['editID'])) {
  $_GET['edit'] = $_SESSION['editID'];
  }

// If the user posts to logout, exit the admin session, and set the message
if(isset($_GET['logout'])) {
    $_SESSION["admin"] = false;
    $_SESSION['error'] = 'You have been logged out.';
    redirect();
  }

// If the user wants to login, check the database
if (isset($_POST['user']) && isset($_POST['pass'])) {
  $_SESSION["user"] = strip_tags($_POST['user']);
  $username = $_SESSION["user"];
  // Set the sql to get data from the server
  try {
    $sql = $conn->prepare("SELECT `password` FROM `user` WHERE `username` = :user");
    $sql->bindParam(":user", $username);
    $sql->execute();
    // Get the password
    foreach ($sql->fetchAll() as $row) {
      $hashedpassword = $row["password"];
    }
  }
  catch (PDOException $e) {
    echo "An error has occurred: " . $e->getMessage();
  }
  // Verify the password, and if it's correct, set the admin session
  if (password_verify($_POST['pass'], $hashedpassword))
  {
    $_SESSION["admin"] = true;
  }
  else {
    $_SESSION["admin"] = false;
    $_SESSION["error"] = 'Invalid Credentials, please try again!';
  }
  $sql->closeCursor();
  redirect();
}

// If a new user is submitted
else if (isset($_POST['usersubmit'])) {
  $username = filter_input(INPUT_POST, 'newuser', FILTER_SANITIZE_STRING); //DUE TO CHANGE
  $password = filter_input(INPUT_POST, 'newpass', FILTER_SANITIZE_SPECIAL_CHARS); //DUE TO CHANGE
  $email = filter_input(INPUT_POST, 'newemail', FILTER_SANITIZE_EMAIL); //DUE TO CHANGE
  // Hash the new password
  $hashedpassword = password_hash($password, PASSWORD_BCRYPT);

  $query = "INSERT INTO `user` (
  `username` ,
  `email` ,
  `password`
  )
  VALUES (
  :user,  :email,  :password
  );
  ";

  try {
    $sql = $conn->prepare($query);
    $sql->bindParam(":user", $username);
    $sql->bindParam(":email", $email);
    $sql->bindParam(":password", $hashedpassword);
    $sql->execute();
  }
  catch (PDOException $e) {
    echo "An error has occurred: " . $e->getMessage();
  }
  $sql->closeCursor();
  // Redirect
  redirect();
  header("HTTP/1.1 303 See Other");
  die("redirecting");
}

// Submit a normal post to the database
else if(isset($_POST['post-submit'])) {
  // Check if there is data, get it and format it
  if ( (isset($_POST['post-title'])) && (!empty($_POST['post-title'])) ) {$post_title = $_POST['post-title'];}
  if ( (isset($_POST['post-data'])) && (!empty($_POST['post-data'])) ) {$post_data = $_POST['post-data'];}
  if ( (isset($post_title)) && (isset($post_data)) ) {
    // Set the time the post was created
    // And a unique ID for the post
    $created = time();
    $uniqid = uniqid();
    try {
      $sql = $conn->prepare("INSERT INTO cmsData (postID, postTitle, postData, postTime) VALUES( :id , :title , :data , :time )");
      $sql->bindParam(":id", $uniqid);
      $sql->bindParam(":title", $post_title);
      $sql->bindParam(":data", $post_data);
      $sql->bindParam(":time", $created);
      $sql->execute();
          // Set the message to succes!
      $_SESSION["error"] = 'postsubmitted';
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
      $_SESSION["error"] = 'postsubmitfailed';
    }
    $sql->closeCursor();

    if (!empty($_POST['metatags'])) {
      $metatags = explode(',',$_POST['metatags']);
      foreach($metatags as $tag) {
        try {
          $sql = $conn->prepare("INSERT INTO cmstags (postID, tag) VALUES( :id , :tag)");
          $sql->bindParam(":id", $uniqid);
          $sql->bindParam(":tag", $tag);
          $sql->execute();
        }
        catch (PDOException $e) {
          echo "An error has occurred: " . $e->getMessage();
        }
      }
    }
  }
  else {
    // Set the error to fail!
    $_SESSION["error"] = 'postsubmitfailed';
  }
  // Redirect
  redirect();
}

// If the user wants to edit a post
else if(isset($_POST['edit-submit'])) {
  // Check if there is data, get it and format it
  if ( (isset($_POST['post-title'])) && (!empty($_POST['post-title'])) ) {$post_title = $_POST['post-title'];}
  if ( (isset($_POST['post-data'])) && (!empty($_POST['post-data'])) ) {$post_data = $_POST['post-data'];}
  if ( (isset($post_title)) && (isset($post_data)) ) {
    // Get the ID that was provided with the post
    // and reset the session to prevent loops (see top of page)
    $editID = $_SESSION['edit-ID'];
    unset($_SESSION['edit-ID']);

    try {
      $sql = $conn->prepare("UPDATE `cmsData` SET `postTitle` = :title, `postData` = :data WHERE  `postID` =  :id");
      $sql->bindParam(":id", $editID);
      $sql->bindParam(":title", $post_title);
      $sql->bindParam(":data", $post_data);
      $sql->execute();
          // Set the message to succes!
      $_SESSION["error"] = 'postsubmitted';
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
      $_SESSION["error"] = 'postsubmitfailed';
    }
    $sql->closeCursor();
  }
  else {
    // Set error
    $_SESSION["error"] = 'postsubmitfailed';
  }
  redirect();
}

// If the user did not enter all the login fields
else if (isset($_POST['user']) || isset($_POST['pass']))
{
  // Set the error
  $_SESSION["error"] = 'Please fill in all fields';
  // Set the admin session to false for safety
  $_SESSION['admin'] = false;
  redirect();
}

// The main code for editing
// If the code gets throught the edit ID's without loops, this code runs
if (isset($_GET['edit'])) {
  $editID = $_GET['edit'];
  $_SESSION['edit-ID'] = $editID;
  // If the user is logged in, continue editing
  if ($_SESSION['admin']) {
    // Get the post
    try {
      $sql = $conn->prepare("SELECT `postTitle`, `postData` FROM `cmsData` WHERE `postID` = :id ");
      $sql->bindParam(":id", $editID);
      $sql->execute();
      if ($sql->rowCount() == 0) {
        $_SESSION['error'] = 'postnonexist';
        redirect();
      }
      foreach ($sql->fetchAll() as $row) {
        $edittitle = $row["postTitle"];
        $editbody = $row["postData"];
        $editbody = str_replace('"', "'", $editbody);
      }
      $sql->closeCursor();
      $sql = $conn->prepare("SELECT `tag` FROM `cmstags` WHERE `postID` = :id ");
      $sql->bindParam(":id", $editID);
      $sql->execute();
      $tagsarray = array();
      $rowcount = 0;
      foreach ($sql->fetchAll() as $row) {
        $tagsarray[$rowcount] = $row["tag"];
        $rowcount++;
      }
      $sql->closeCursor();
      $edittags = implode(',', $tagsarray);
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
    }
  }
  else {
    $_SESSION['editID'] = $editID;
  }
}

// If the user wants to delete a post
if(isset($_GET['delete'])) {
  $deleteID=$_GET['delete'];
  // Check if he is logged in
  if($_SESSION['admin'])
  {
    try {
      $sql = $conn->prepare("DELETE FROM `cmsData` WHERE `postID` = :id");
      $sql->bindParam(":id", $deleteID);
      $sql->execute();
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
    }
    $sql->closeCursor();
  }
  // Redirect if not logged in
  redirect();
}

// get statistics from the database, just for bants
try {
      $sql = $conn->prepare("SELECT `postId` FROM `cmsData`");
      $sql->execute();
      $post_count = $sql->rowCount();
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
    }
if (isset($_SESSION['error'])) {
  $error = $_SESSION['error'];
}

// The main function for displaying posts
// This function can be called from anywhere in the code as long as
// watermelone.php is included in the file
// The function needs a connection, which can just be the standard
// $conn, and can have a postID
// If a postID is specified, it will only display that post
function display_public($connection, $post = NULL, $tag = NULL) {
  if (($post == NULL) && ($tag == NULL)) {
    // Set sql for all posts
    try {
      $sql = $connection->prepare("SELECT * FROM cmsData ORDER BY postTime");
      $sql->execute();
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
    }
  }
  else if ($post != NULL) {
    // Set sql for single post
    try {
      $sql = $connection->prepare("SELECT * FROM cmsData WHERE `postID` = :id ORDER BY postTime");
      $sql->bindParam(":id", $post);
      $sql->execute();
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
    }
  }
  else if ($tag != NULL) {
    // Set sql for single tag
    try {
      $sql = $connection->prepare("SELECT * FROM cmstags WHERE `tag` = :tag ");
      $sql->bindParam(":tag", $tag);
      $sql->execute();
      if ($sql->rowCount() > 0) {
        $postarray = array();
        $rowcount = 0;
        foreach ($sql->fetchAll() as $row) {
          $postarray[$rowcount] = "'" . $row['postID'] . "'";
          $rowcount++;
        }
        $sql->closeCursor();
        $posts = (string)implode(', ', $postarray);
        $sql = $connection->prepare("SELECT * FROM cmsData WHERE `postID` IN ( $posts )");
        $sql->execute();
      }
    }
    catch (PDOException $e) {
      echo "An error has occurred: " . $e->getMessage();
    }
	}
	$entry_display;
    if ($sql->rowCount() > 0) {
      // While there are posts, format them
      date_default_timezone_set('Europe/Amsterdam');
      // Get results
      try {
        foreach ($sql->fetchAll() as $row) {
          $title = stripslashes($row["postTitle"]);
          $bodytext = stripslashes($row["postData"]);
          $bodytext = str_replace(array('<div>', '</div>'), array('<span>', '</span>'), $bodytext);
          $postID = $row['postID'];
          $nUpdated = date("l F jS Y \@ g:i a",$row['postTime']);
          $entry_display = <<<ENTRY_DISPLAY

    <article class="note_article">
			<h2>
				$title
			</h2>
				$bodytext
    </article>

ENTRY_DISPLAY;
					//<div class="post-date"><div class="noted"></div> on $nUpdated - <a href="note/index.php?edit=$postID">edit</a></div>
        	// Return the posts, which are all saves in the variable
  echo $entry_display;
        }
      }
      catch (PDOException $e) {
          echo "An error has occurred: " . $e->getMessage();
      }
      $sql->closeCursor();
    }
  else {
    echo "There are no posts to show!";
  }
}

?>
