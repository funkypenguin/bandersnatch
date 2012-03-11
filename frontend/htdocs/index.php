<?php
function getmicrotime()
{ 
  list($usec, $sec) = explode(" ",microtime()); 
  return ((float)$usec + (float)$sec); 
} 
$time_start = getmicrotime();

// pull in configuration
require_once( '../includes/config.inc.php' );

// Start Authentication
$a->start();

// perform logout, and put func back in place. Must be done before switch structure
if ($func == 'logout')
{
  $a->logout();
  $a->start();
  $func = $oldfunc;
}

switch ($func)
{
  case "log": 

    if (($jid) && ($a->getAuth()))
    {
      echo create_user_log_page($jid);
    }

    elseif ($jid)
    {
      echo create_user_page($jid);
    }

    else
    {
      echo create_admin_home_page();
    }
    break; 

  case "user":

    if ($jid)
    {
      echo create_user_page($jid);
    }

    else
    {
      echo create_home_page();
    }
    break;

  case "login":

    echo create_login_page();
    break;						 
    default:
    echo create_home_page();
    break;			
}

$time_end = getmicrotime();
$execution_time = $time_end - $time_start;

$tpl->setCurrentBlock("footer");
$tpl->setVariable
  (array
    (
      "APP_NAME" => $config['app_name'],
      "APP_VERSION" => $config['app_version'],
      "EXECUTION_TIME" => $execution_time
    )
  );

$tpl->parse("footer");
return $tpl->show("footer");

/*__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|
# Function   : create_home_page()
# Purpose    : Returns the completely parsed "home" page. 
##########################################*/
function create_home_page()
{
  $config = $GLOBALS['config'];
  $db = $GLOBALS['db'];
  $tpl = $GLOBALS['tpl'];
  $date	= $GLOBALS['date'];	
	
  $pretty_date = date('j M Y');
  $pretty_date_past = date('j M Y',strtotime($date));
  $time = date('H:i');
	
  $title = "Jabber server statistics for \"". $config['local_server']. "\" on $pretty_date_past";
  $message_stats = generate_total_message_stats($date);	
  $transport_stats = generate_transport_stats("",$date);
  $user_list = generate_user_list($date);

  $center_block = $message_stats. $transport_stats. $user_list;

  $tpl->setCurrentBlock("main_page");
  $tpl->setVariable
    (array
      (
        "PAGE_TITLE" => $title,
        "LINK_HOME" => $_SERVER['PHP_SELF']. "?date=$date",
        "ADMIN" => generate_admin_options(),
        "PAGE_TIMESTAMP" => "Statistics generated on $pretty_date at $time",
        "DATE_SELECT" => generate_date_selectbox(),
        "CENTER_BLOCK" => $center_block
      )
    );
  $tpl->parse("main_page");
  return $tpl->get("main_page");
}

/*__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|
# Function   : create_user_page($jid)
# Purpose    : Returns the completely parsed basic "user stats" page. 
# Parameters : string $jid -> The JID whose stats to display
##########################################*/
function create_user_page($jid)
{
  $config = $GLOBALS['config'];
  $db = $GLOBALS['db'];
  $tpl = $GLOBALS['tpl'];
  $date	= $GLOBALS['date'];	
	
  $pretty_date = date('j M Y');
  $pretty_date_past = date('j M Y',strtotime($date));
  $time = date('H:i');	
	
  foreach($config['local_domains'] as $subdomain)
  {
    if (strstr($jid,"@$subdomain")) { $local_ok = true; }
  }

  if ($local_ok)
  {
    $title = "Jabber user statistics for: \"$jid\" on $pretty_date_past";
    $subtitle = "Statistics generated on $pretty_date at $time";
    $local_message_stats  = generate_local_message_stats($jid,$date);
    $remote_message_stats = generate_remote_message_stats($jid,$date);	
    $presence = generate_presence_history($jid,$date);
  }

  else
  {
    $title = "Invalid JID specified ($jid)";
    $subtitle = "Jabberwocky only generates statistics for local users.";
  }

  $center_block = $local_message_stats. $remote_message_stats. $presence;

  $tpl->setCurrentBlock("main_page");
  $tpl->setVariable
    (array
      (
        "PAGE_TITLE" => $title,
        "LINK_HOME" => $_SERVER['PHP_SELF']. "?date=$date",
        "ADMIN"	=> generate_admin_options(),				
        "PAGE_TIMESTAMP" => $subtitle,
        "DATE_SELECT" => generate_date_selectbox(),
        "CENTER_BLOCK" => $center_block
      )
    );
  $tpl->parse("main_page");
  return $tpl->get("main_page");
}

/*__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|
# Function   : create_user_log_page($jid)
# Purpose    : Returns the completely parsed "user message log" page. 
# Parameters : string $jid -> The JID whose logs to display
##########################################*/
function create_user_log_page($jid)
{
  global $orderby; # because the generate_sort_by function can modify it
  $config = $GLOBALS['config'];
  $db = $GLOBALS['db'];
  $tpl = $GLOBALS['tpl'];
  $date = $GLOBALS['date'];
  $search = $GLOBALS['search'];	
	
  if (!$orderby) { $orderby = "message_timestamp"; }

  $sort_options = array
    (
      "message_timestamp" => "Ascending",
      "message_timestamp DESC" => "Descending"
    );
  $orderby_html = generate_sort_by($sort_options);
	
  $pretty_date = date('j M Y');
  $pretty_date_past = date('j M Y',strtotime($date));
  $time = date('H:i');
	
  foreach($config['local_domains'] as $subdomain)
  {
    if (strstr($jid,"@$subdomain")) { $local_ok = true; }
  }

  if ($local_ok)
  {
    $title = "Jabber message log for \"$jid\" on $pretty_date_past";
    $timestamp = "Log generated on $pretty_date at $time";
    $messagelog = generate_message_log($jid,$date);

    if ($search)
    {
      $title .= " (searching for \"$search\")";
    }
  }

  else
  {
    $title = "Invalid JID specified ($jid)";
    $subtitle = "Jabberwocky only logs messages for local users.";
  }

  $center_block = $messagelog;

  $tpl->setCurrentBlock("main_page");
  $tpl->setVariable
    (array
      (
        "PAGE_TITLE" => $title,
        "NAVIGATION" => generate_page_nums(total_messages($jid,$date)),
        "SORT_BY" => $orderby_html,
        "SEARCH" => generate_search_bar(),
        "LINK_HOME" => $_SERVER['PHP_SELF']."?date=$date",
        "ADMIN"	=> generate_admin_options(),				
        "PAGE_TIMESTAMP" => $timestamp,
        "DATE_SELECT" => generate_date_selectbox(),
        "CENTER_BLOCK" => $center_block
      )
    );
  $tpl->parse("main_page");
  return $tpl->get("main_page");
}

/*__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|
# Function   : create_login_page()
# Purpose    : Returns the completely parsed "administrator login" page. 
##########################################*/
function create_login_page()
{
  $a = $GLOBALS['a'];
  $tpl = $GLOBALS['tpl'];
  $oldfunc = $GLOBALS['oldfunc'];
  $limit = $GLOBALS["limit"];
  $page = $GLOBALS["page"];
  $orderby = $GLOBALS["orderby"];
  $jid = $GLOBALS["jid"];
  $date = $GLOBALS["date"];
	
	
  if (!$a->getAuth())
  {
    $title = "Administrator login";
    $subtitle = "Please enter your administrator username and password below";
    $tpl->setCurrentBlock("login_form");
    $tpl->setVariable("FORM_ACTION" , $_SERVER['PHP_SELF']."?func=$oldfunc&jid=$jid&page=$page&limit=$limit&orderby=$orderby&date=$date");
    $tpl->parseCurrentBlock("login_form");		
    $login_form = $tpl->get("login_form");
  }

  else
  {
    $title = "You are already logged in as \"". $a->getUsername(). "\"!";
  }

  $tpl->setCurrentBlock("main_page");
  $tpl->setVariable
    (array
      (
        "PAGE_TITLE" => $title,
        "LINK_HOME" => $_SERVER['PHP_SELF']. "?date=$date",
        "PAGE_SUBTITLE" => $subtitle,
        "CENTER_BLOCK" => $login_form
      )
    );
  $tpl->parse("main_page");
  return $tpl->get("main_page");
}

?>
