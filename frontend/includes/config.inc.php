<?php

$config['template'] = 'default.tpl.htm';
$config['limit'] = '50';

$config['database_type'] = 'mysql';
$config['database_host'] = 'localhost';
$config['database_table'] = 'bandersnatch';
$config['database_user'] = 'bandersnatch';
$config['database_password'] = 'bandersnatch';

$config['local_server'] = "jabber.yourdomain.com";
$config['local_domains'] = array
  (
    'jabber.yourdomain.com',
    'conference.jabber.yourdomain.com'
  );
									
$config['local_transports'] = array
  (
    'msn' => 'msn.jabber.yourdomain.com',
    'icq' => 'icq.jabber.yourdomain.com',
    'aim' => 'aim.jabber.yourdomain.com',
    'yahoo' => 'yahoo.jabber.yourdomain.com',
    'rss' => 'headlines.jabber.yourdomain.com',
    'groupchat' => 'conference.jabber.yourdomain.com'
  );

#################### End of user-configurable options #######################

$config['app_version'] = '0.2';
$config['app_name'] = 'Bandersnatch PHP Frontend';

// Setup database DSN
$dsn = $config['database_type']. "://". $config['database_user']. ":". $config['database_password'];
$dsn .= "@". $config['database_host']. "/". $config['database_table'];

// Load PEAR Integrated Template libraries
require_once "HTML/Template/IT.php"; 
$tpl = new HTML_Template_IT("../templates");  
$tpl->loadTemplatefile($config['template'], true, true);

// Load PEAR DB Libraries
require_once( 'DB.php' );
require_once( '../includes/functions.inc.php' );
$db = DB::connect($dsn);
if (DB::isError($db)) { generate_error('Database connection error - '. $dsn); }

// Load PEAR Auth libraries
require_once "Auth/Auth.php";
$a = new Auth("DB", $dsn);
$a->setShowLogin( false ); // Don't automatically show login page, we'll do it ourselves

// Note. 2003-02-13: Noticed today that if the Auth connection fails (I had a bad DSN), no decent error
// message is given. I got a "non-existant function query()" error :(

// use variable variables to import alternating row colors from template
$messagetypes = array('normal','chat','groupchat','row');

foreach ($messagetypes as $type)
{
  for ($i = 1; $i <= 2; $i++)
  {
    $block_name = "color_" . $type . "_" . $i;
    $tpl->touchBlock($block_name);
    $tpl->parse($block_name);
    $$block_name = $tpl->get($block_name);
  }
}			

// Define global variables that the functions will use
$func = $HTTP_GET_VARS['func'];
$jid = $HTTP_GET_VARS['jid'];
$page = $HTTP_GET_VARS['page'];
$limit = $HTTP_GET_VARS['limit'];
$orderby = $HTTP_GET_VARS['orderby'];
$oldfunc = $HTTP_GET_VARS['oldfunc'];
$date = $HTTP_GET_VARS['date'];
$search = $HTTP_GET_VARS['search'];


// Check above global variables, and set some defaults
if (!$limit) { $limit = $config['limit']; }
if ((!$page) || ($page < 1)) { $page = 1; }
/*if (($orderby <> "sent DESC") && ($orderby <> "message_from")) {
	$orderby = "sent DESC";
} - Do we need this, now that generate_sort_by() checks valid values? */
if (!$search) {  $search = $HTTP_POST_VARS['search']; }
if (!$date) {  	 $date = $HTTP_POST_VARS['date']; }
if (!$date) {  	 $date = date('Ymd'); }

if (!$config['local_server']) generate_error('No local server set','You do not have a \'local server\' set in <b>config.inc.php</b>');

#FIXME:  Set local_domains to equal server at least
				
?>
