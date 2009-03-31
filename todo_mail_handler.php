<?php
###############################################################################
#
# Copyright 2009 Evan McLean
# http://evanmclean.com/
#
# This program is free software: you can redistribute it and/or modify it under
# the terms of the GNU General Public License as published by the Free Software
# Foundation, either version 3 of the License, or (at your option) any later
# version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
# FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
# details.
#
# You should have received a copy of the GNU General Public License along with
# this program.  If not, see <http://www.gnu.org/licenses/>.
#
###############################################################################
#
# A script to use nearlyfreespeech.net's feature of being able to redirect
# an email alias to a URL in order to create new actions in a tracks
# installation.
#
# This uses the REST API of a Tracks installation.
#
# References:
#   http://www.getontracks.org/
#   https://www.nearlyfreespeech.net/
#   https://members.nearlyfreespeech.net/wiki/HowTo/EmailURL
#
# Read parts 1, 2 and 3 below.  Set your key in part 1, and tracks
# configuration(s) in part 3.  Then set your email alias(es) and you're ready
# to go.
#
# The subject of the email becomes the description of the new Action item, and
# the body (if any) will be the note.  Any file attachments in the email will
# be ignored.
#
# Use plain text for the email unless you enjoy reading the raw HTML markup
# generated by your email client.
#
# Signatures: Anything after a single line containing two hyphens ("--") in the
# body of the email will be ignored.  This is an effort to chop of email
# signatures.
#
# Advanced Subject lines: The subject line can contain a Context, and/or a
# Project.
#
# Examples:
#	do laundry @ Home
#	Write spec > Project X
#	Call Bill @ Phone > Project X
#
# If you really want to put an "@" or an ">" in your Action's description, then
# proceed it with a backslash ("\").  To put a backslash in, use two ("\\").
# Basically, a backslash will be eaten, and the next character will be taken
# as a literal.
#
# Order is not important, neither is case or white space around the operators.
# So all the following examples are functionally equivalent:
#
#	Call Bill @ Phone > Project X
#	Call Bill@phone>project x
#	Call Bill>project x@phone
#
# If the Context does not already exist, the default Context is used instead*
# (see part 2.)  If you want it to create the specified Context if it does not
# already exist, preceed it with an exclamation point.
#
# Example:
#	do laundry @ !Home
#
# Likewise, if the Project does not already exists, no project is assigned to
# the Action be default*.  If you want to create the specified Project if it
# does not already exists, preceed it with an exclamation point.
#
# Example:
#	Write spec > !Project X
#
# * See the $finiky configuration option in part 2 to make it bounce the emails
# instead if the desired Context or Project does not exist and is not being
# created.
#
###############################################################################
ini_set('log_errors',1);
error_reporting(E_ALL | E_STRICT);

###############################################################################
# PART 1 - Set your key
#
# Change the key below to some value that only you know.  This key should be
# passed in by the email alias to prove it is coming from something you
# set up.  You set your email alias to a URL such as:
#
#   http://mysite.com/path/todo_mail_handler.php?key=mysecret
#
# If the key is not valid, this script will silently exit, doing nothing.
#
define('KEY', 'mysecret');

###############################################################################
# PART 2 - The configuration class.
#
# This class documents the values you need to define in order to fully
# configure this script.  DO NOT CHANGE THIS CODE.  Use it as a reference for
# setting up your configuration in PART 3.
#
class TracksConfig
{
  /**
  * The base URL for your tracks installation to add the Action items to.
  * Make sure you include the trailing slash.
  *
  * e.g. http://tracks.mydomain.com/
  */
  public $baseUrl;

  /**
  * The user name and password for the tracks user to add Action items to.
  */
  public $user;
  public $pass;

  /**
  * This is the NUMBER of the default context to use for adding items.
  * You can find the number by going to the page for a particular context and
  * looking at the URL.
  *
  * This context must already exist.
  */
  public $defaultContext;

  /**
  * Either a string (for one address), or an array of strings (for multiple
  * addresses) that we scan the To header for to tell if we should process the
  * email for this tracks configuration.
  *
  * WARNING: A simple sub-string search is performed, so pick your addresses
  * wisely.  Addresses such as "mytracks@mydomain.com" and
  * "yourtracks@mydomain.com" would both match an address of
  * "tracks@mydomain.com".
  */
  public $emails;

  /**
  * Optional filter on who is allowed to send emails to this tracks.
  * Can be one of:
  *   null: Anybody can send email.
  *   string: Only the specified email address can send email.
  *   array of strings: Only the specified email addresses can send email.
  *
  * Filtering is done by simply doing a sub-string search on the From header.
  * Any non-matches are silently dropped.
  */
  public $froms;

  /**
  * An email address to forward anything we couldn't figure out how to handle.
  * If null, then anything we couldn't handle is silently dropped.
  */
  public $bounce;

  /**
  * By default, if a Context that doesn't already exist is specified on the
  * subject line, without the proceeding exclamation point to indicate
  * creation, then the Action item is dropped into the default context instead.
  * If $finiky is true, the email is forwarded to the $bounce address instead.
  *
  * Likewise, if a Project that doesn't already exist is specified on the
  * subject line, without the proceeding exclamation point to indicate
  * creation, then the Action item not assigned to any Project.  If $finiky is
  * true, the email is forward to the $bounce address instead.
  */
  public $finiky;

  /**
  * Constructor.  Use this in part 3.
  */
  public function __construct(
    $baseUrl
  , $user
  , $pass
  , $defaultContext
  , $emails
  , $froms
  , $bounce
  , $finiky = false
  )
  {
    $this->baseUrl = $baseUrl;
    $this->user = $user;
    $this->pass = $pass;
    $this->defaultContext = $defaultContext;
    $this->emails = $emails;
    $this->froms = $froms;
    $this->bounce = $bounce;
    $this->finiky = $finiky;
  }
}

###############################################################################
# PART 3 - Tracks Configurations
#
# One or more tracks configurations to process. A configuration is used if
# one of the $emails matches a sub-string search on the To header.
# (So yes, you could configure incoming emails to go to two or more tracks
# installs if you wanted.)
#
$TRACKS_CONFIG = array(
  new TracksConfig('http://tracks.mydomain.com/', 'user', 'pass', 1
  , 'todo@mydomain.com', null, 'me@mydomain.com')
);

###############################################################################
# PART 4 - The email processor.  YOU SHOULD NOT NEED TO MODIFY ANYTHING BELOW.
#
# We don't use any attachments, so delete any files.
#
foreach ( $_FILES as $file )
  @unlink($file['tmp_name']);

#
# Ensure our secret key is specified.
#
if ( strcmp(KEY, @$_GET['key']) != 0 )
  exit;

#
# Declare various classes and functions we will use below.
#

/**
* Encapsulate all the data about the email that we care about.
*/
define('READING_DESCRIPTION', 1);
define('READING_CONTEXT', 2);
define('READING_PROJECT', 3);

class Action
{
  public $description = null;
  public $note = null;
  public $context = null;
  public $project = null;
  public $createContext = false;
  public $createProject = false;
  public $err = null;
  public $valid = false;

  public function __construct()
  {
    $this->valid = true;
    if ( ! $this->processSubject() )
      $this->valid = false;
    if ( ! $this->processBody() )
      $this->valid = false;
  }

  private function processSubject()
  {
    $description = '';
    $context = '';
    $project = '';
    $state = READING_DESCRIPTION;

    $subject = $_POST['Subject'];
    $len = strlen($subject);
    for ( $xi = 0; $xi < $len; ++$xi )
    {
      $literal = false;
      $ch = $subject[$xi];
      if ( $ch == '\\' )
      {
	++$xi;
	if ( $xi >= $len )
	  break;
	$ch = $subject[$xi];
	$literal = true;
      }
      if ( ! $literal )
	switch ( $ch )
	{
	  case '@':
	    if ( ! empty($context) )
	    {
	      $this->err = 'Two or more contexts in subject (@).';
	      return false;
	    }
	    $state = READING_CONTEXT;
	    break;

	  case '>':
	    if ( ! empty($project) )
	    {
	      $this->err = 'Two or more projects in subject (>).';
	      return false;
	    }
	    $state = READING_PROJECT;
	    break;

	  default:
	    $literal = true;
	    break;
	}

      if ( $literal )
	switch ( $state )
	{
	  case READING_DESCRIPTION:
	    $description .= $ch;
	    break;

	  case READING_CONTEXT:
	    $context .= $ch;
	    break;

	  case READING_PROJECT:
	    $project .= $ch;
	    break;

	  default:
	    $this->err = "Unknown subject processing state $state";
	    return false;
	}
    }

    $description = trim($description);
    if ( empty($description) )
    {
      $this->err = 'No description for the action given.';
      return false;
    }
    $this->description = $description;

    $context = trim($context);
    if ( ! empty($context) )
    {
      if ( strcmp('!', $context[0]) == 0 )
      {
	$this->createContext = true;
	$context = substr($context, 1);
      }
      if ( ! empty($context) )
	$this->context = $context;
    }

    $project = trim($project);
    if ( ! empty($project) )
    {
      if ( strcmp('!', $project[0]) == 0 )
      {
	$this->createProject = true;
	$project = substr($project, 1);
      }
      if ( ! empty($project) )
	$this->project = $project;
    }

    return true;
  }

  private function processBody()
  {
    $lines = explode("\n", $_POST['Body']);
    $num_lines = count($lines);

    // Scan, right-trimming lines until we reach the end or the signature
    // marker.
    $last_line = 0;
    while ( $last_line < $num_lines )
    {
      $lines[$last_line] = rtrim($lines[$last_line]);
      if ( strcmp('--', $lines[$last_line]) == 0 )
	break;
      ++$last_line;
    }

    // Now back up to the last non-empty line.
    while (( $last_line > 0 ) && empty($lines[$last_line-1]) )
      --$last_line;

    // Now find the first non-empty line.
    $first_line = 0;
    while (( $first_line < $last_line ) && empty($lines[$first_line]) )
      ++$first_line;

    // Put the range of lines back together as a single string.
    if ( $first_line > $last_line ) // No note.
    {
      $this->note = null;
    }
    else
    {
      $this->note = $lines[$first_line++];
      while ( $first_line < $last_line )
	$this->note .= "<br/>\n" . htmlentities($lines[$first_line++]);
    }

    return true;
  }
}

/**
* Escapes characters for XML just like htmlentities does for HTML.
*/
function xmlentities( $string )
{
  static $trans;
  if ( ! isset($trans) )
  {
    $trans = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES);
    foreach ($trans as $key => $value)
      $trans[$key] = '&#'.ord($key).';';
    $trans[chr(38)] = '&amp;';
    $trans['<'] = '&lt;';
    $trans['>'] = '&gt;';
    $trans['"'] = '&quot;';
    $trans["'"] = '&apos;';
  }
  return strtr($string, $trans);
}

/**
* Check if the email address is in the To header.
*/
function gotAddress( $header, $address )
{
  return stripos($header, $address) !== false;
}

/**
* See if the header contains one of the specified email addresses.
* @param $header The header to test.
* @param $emails Either a string, or an array of strings.
*/
function gotAddressString( $header, $emails )
{
  if ( ! is_array($emails) )
    return gotAddress($header, $emails);
  foreach ( $emails as $email )
    if ( gotAddress($header, $email) )
      return true;
  return false;
}

/**
* Check if the incoming email is suitable for this install of tracks.
*/
function useTracks( &$tracks )
{
  if ( ! gotAddressString($_POST['To'], $tracks->emails) )
    return false;
  if ( ! is_null($tracks->froms) )
    if ( ! gotAddressString($_POST['From'], $tracks->froms) )
    {
      error_log("No match on From header.\n" . $_POST['From']);
      return false;
    }
  return true;
}

/**
* Could not process the request for whatever reason.
*/
function bounce( &$tracks, &$action, $err )
{
  if ( ! is_null($tracks->bounce) )
  {
    $body = "Could not process incoming action.\n\n";
    if ( ! empty($err) )
      $body .= "$err\n\n";

    $subject = $_POST['Subject'];
    $body .= "Subject: $subject\n\n";
    $body .= $_POST['Body'];

    $to = $tracks->bounce;
    mail($to, "Could not process $subject", $body, "From: $to\r\n");
  }

  if ( empty($err) )
    error_log('Bouncing unspecified error.');
  else
    error_log("Bouncing $err");
}

/**
* Perform a HTTP get to the tracks system.
* @param $tracks The tracks system to call.
* @param $url The url to call (relative to $tracks->baseUrl).
* @return String of data returned, or false on error (bounce called.)
*/
function getData( $tracks, $url )
{
  $url = $tracks->baseUrl . $url;
  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_USERPWD, $tracks->user . ':' . $tracks->pass);
  curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));

  $ret = curl_exec($curl);
  if ( $ret === false )
  {
    $err = 'Error ' . curl_errno($curl) . ': ' . curl_error($curl)
    . "\nURL: $url";
    curl_close($curl);
    global $action; // Okay - a bit of a kludge, but like, whatever.
    bounce($tracks, $action, $err);
    return false;
  }

  curl_close($curl);
  return $ret;
}

/**
* Perform a HTTP post to the tracks system.
* @param $tracks The tracks system to call.
* @param $url The url to call (relative to $tracks->baseUrl).
* @param $data The data to post.
* @return The ID of the object returned, or false on error (bounce called.)
*/
function postData( $tracks, $url, $data )
{
  $url = $tracks->baseUrl . $url;
  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($curl, CURLOPT_HEADER, true);
  curl_setopt($curl, CURLOPT_POST, true);
  curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
  curl_setopt($curl, CURLOPT_USERPWD, $tracks->user . ':' . $tracks->pass);
  curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));

  $ret = curl_exec($curl);
  if ( $ret === false )
  {
    $err = 'Error ' . curl_errno($curl) . ': ' . curl_error($curl)
    . "\nURL: $url";
    curl_close($curl);
    global $action; // Okay - a bit of a kludge, but like, whatever.
    bounce($tracks, $action, $err);
    return false;
  }

  $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  if (( strcmp('201', $code) != 0 ) && ( strcmp('200', $code) != 0 ))
  {
    $err = "Error HTTP returned $code\n\nHeaders:\n$ret\n\nURL: $url";
    curl_close($curl);
    global $action; // Okay - a bit of a kludge, but like, whatever.
    bounce($tracks, $action, $err);
    return false;
  }

  curl_close($curl);

  $lines = explode("\n", $ret, 20);
  foreach ( $lines as $line )
    if ( strncmp($line, 'Location: ', 10) == 0 )
    {
      $line = trim($line);
      $xi = strlen($line) - 1;
      while ( $xi >= 0 )
      {
	$ch = $line[$xi];
	if ( strcmp($ch, '/') == 0 )
	{
	  global $action; // Okay - a bit of a kludge, but like, whatever.
	  bounce($tracks, $action, "Bad location header.\n$line");
	  return false;
	}
	if ( ctype_digit($ch) )
	  break;
	--$xi;
      }

      $id = '';
      while ( $xi >= 0 )
      {
	$ch = $line[$xi--];
	if ( ctype_digit($ch) )
	{
	  $id = $ch . $id;
	}
	else if ( strcmp($ch, '/') == 0 )
	{
	  return (int) $id;
	}
	else
	{
	  global $action; // Okay - a bit of a kludge, but like, whatever.
	  bounce($tracks, $action, "Bad location header.\n$line");
	  return false;
	}
      }

      global $action; // Okay - a bit of a kludge, but like, whatever.
      bounce($tracks, $action, "Bad location header.\n$line");
      return false;
    }

  bounce($tracks, $action, "Could not find location header.\n$line");
  return false;
}

/**
* Get the ID of the context to use, creating it if appropriate.
* Will call bounce if it experiences an error.
* @return Context ID to use, or false if there was an error.
*/
function getContextId( &$tracks, &$action )
{
  if ( empty($action->context) )
    return $tracks->defaultContext;

  $rawxml = getData($tracks, 'contexts.xml');
  if ( $rawxml === false )
    return false;

  $xml = new SimpleXMLElement($rawxml);
  foreach ( $xml->context as $context )
  {
    $name = $context->name;
    $name = "$name"; // Just to be sure.
    if ( strcasecmp($name, $action->context) == 0 )
      return (int) $context->id;
  }

  if ( $action->createContext )
  {
    return postData($tracks, 'contexts.xml'
    , '<context><name>' . xmlentities($action->context) . '</name></context>');
  }

  if ( $tracks->finiky )
  {
    bounce($tracks, $action, 'Unknown context: ' . $action->context);
    return false;
  }
  return $tracks->defaultContext;
}

/**
* Get the ID of the project to use, creating it if appropriate.
* Will call bounce if it experiences an error.
* @return Project ID to use, or null if no project, or false if there was an
* error.
*/
function getProjectId( &$tracks, &$action )
{
  if ( empty($action->project) )
    return null;

  $rawxml = getData($tracks, 'projects.xml');
  if ( $rawxml === false )
    return false;

  $xml = new SimpleXMLElement($rawxml);
  foreach ( $xml->project as $project )
  {
    $name = $project->name;
    $name = "$name"; // Just to be sure.
    if ( strcasecmp($name, $action->project) == 0 )
      return (int) $project->id;
  }

  if ( $action->createProject )
  {
    return postData($tracks, 'projects.xml'
    , '<project><name>' . xmlentities($action->project) . '</name></project>');
  }

  if ( $tracks->finiky )
  {
    bounce($tracks, $action, 'Unknown project: ' . $action->project);
    return false;
  }
  return null;
}

error_log("Processing subject: " . $_POST['Subject']);

$action = new Action();

foreach ( $TRACKS_CONFIG as $tracks )
  if ( useTracks($tracks) )
  {

    if ( ! $action->valid )
    {
      bounce($tracks, $action, $action->err);
      continue;
    }

    $context_id = getContextId($tracks, $action);
    if ( $context_id === false )
      continue;

    $project_id = getProjectId($tracks, $action);
    if ( $project_id === false )
      continue;

    $rawxml = '<todo><description>' . xmlentities($action->description)
    . '</description><context_id>' . $context_id . '</context_id>';

    if ( ! is_null($project_id) )
      $rawxml .= '<project_id>' . $project_id . '</project_id>';

    if ( ! is_null($action->note) )
    {
      $rawxml .= '<notes>' . xmlentities($action->note) . '</notes>';
    }

    $rawxml .= '</todo>';

    if ( postData($tracks, 'todos.xml', $rawxml) !== false )
      error_log('Written new todo!');
  }
