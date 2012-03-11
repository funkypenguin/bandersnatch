<?php
/*__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|
# Function   : sql_one_result($sqlquery)
# Purpose    : Returns a single result from an SQL query ($sqlquery)
# Parameters : string $sqlquery -> SQL query to run
##########################################*/
function sql_one_result ($sqlquery)
{
  $db = $GLOBALS["db"];

  $queryresult = $db->query($sqlquery); 

  if (DB::isError($queryresult))
  {
    generate_error('Database query error',$queryresult->getMessage(). " [$sqlquery]"); 
  }

  else
  { 
    while ($queryrow = $queryresult->fetchRow())
    {
      $result = $queryrow[0]; 
    }
  }
  return $result;
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
# Function   : generate_presence_history($jid,$date = "now()")
# Purpose    : Returns a "time-table" of presence history. (Online at this 
#              time, offline at this time, etc.
# Parameters : string $jid -> JID whose presence history we're examining
#              string $date -> Optional. Specify a day to examine. Not currently
#                              used.
##########################################*/
function generate_presence_history($jid,$date = "now()")
{
  $tpl = $GLOBALS["tpl"];
  $db = $GLOBALS["db"];

  // Determine JID's most recent status, BEFORE today
  $date_condition = "(TO_DAYS($date) - TO_DAYS(presence_timestamp) > 0)";
  $sqlquery = "SELECT presence_type, presence_status, presence_show FROM presence WHERE presence_from LIKE '%$jid%' AND presence_type NOT LIKE 'probe' AND presence_type NOT LIKE '%subscribe' AND $date_condition ORDER BY presence_timestamp DESC LIMIT 0,1";
  $queryresult = $db->query($sqlquery); 

  if (DB::isError($queryresult))
  {  
    generate_error('Database query error',$queryresult->getMessage(). " [$sqlquery]"); 
  }

  else
  { 
    while ($queryrow = $queryresult->fetchRow())
    {
      $type = $queryrow[0]; 
      $status = $queryrow[1]; 
      $show = $queryrow[2]; 
      $yesterday_presence = determine_presence($type,$status,$show);
    }
  }

  if (!$yesterday_presence) { $yesterday_presence = "offline"; } // If no results were returned, he's never logged in

  // "Fake" the first entry in the AA. We must have an entry for midnight, to start the day
  $time_array["00:00"] = array
    (
      "presence" => $yesterday_presence,
      "status" => $status
    );

  // Get presence data
  $date_condition = "DATE_FORMAT( presence_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";
  $sqlquery = "SELECT presence_type, presence_status, presence_show, DATE_FORMAT(presence_timestamp, '%H:%i') as presence_timestamp_formatted FROM presence WHERE presence_from LIKE '%$jid%' AND $date_condition ORDER BY presence_timestamp";
  $queryresult = $db->query($sqlquery); 

  if (DB::isError($queryresult))
  {
    generate_error('Database query error',$queryresult->getMessage(). " [$sqlquery]");
  }

  while ($queryrow = $queryresult->fetchRow())
  {
    $type = $queryrow[0]; 
    $status = $queryrow[1]; 
    $show = $queryrow[2];
    $timestamp = $queryrow[3];
		
    if (($type <> "probe") && (!(stristr($type, "subscribe")))) 
    { // We're only interested in "normal" presence updates
      $presence = determine_presence($type,$status,$show);
      $time_array[$timestamp] = array
        (
          "presence" => $presence,
          "status" => $status, 
          "elapsed" => $elapsed);
        }								
      // note: by using the time in minutes as the key in the AA, we can never have more than one
     //       entry per minute. This eliminates "presence" flooding. (Users playing silly buggers!)
    }

    # Time-travel. Insert the future date into the past array :)
    foreach($time_array as $timestamp => $presence_array)
    {
      $presence = $presence_array["presence"];
		
      // filter out duplicate entries. Because of the one-minute flood limit,
      // we might have two "online" presences next to each other.
      $duplicate = false;
      if ($presence == $oldpresence) { $duplicate = true; }
      $oldpresence = $presence;
	
      if (!$duplicate)
      {
	foreach ($time_array[$timestamp] as $key => $value)
        {  // transfer values from old AA into clean one
          $clean_time_array[$timestamp][$key] = $value; 
        }

      if ($old_timestamp)
      {
        $clean_time_array[$old_timestamp]["future_timestamp"] = $timestamp;
      }
      $old_timestamp = $timestamp;
    }
  }

  // Initialize the template, and set default values
  $tpl->setCurrentBlock('presence_table_inside');
  $tpl->setVariable('TABLE_TITLE', 'Presence History') ;

  foreach($clean_time_array as $timestamp => $presence_array)
  { 
    $presence = $presence_array['presence'];
    $status = $presence_array['status'];
    $elapsed = $presence_array['elapsed'];
    $future_timestamp = $presence_array['future_timestamp'];
		
    // The most recent presence won't have a future timestamp
    if ((!$future_timestamp) && ($date == date('Ymd')))
    { 
      $future_timestamp = 'Now'; 
    }

    elseif (!$future_timestamp)
    {
      $future_timestamp = '24:00'; 
    }
			
    // Make the status value look nice, if we're going to use it
    unset($pretty_status);

    if (!(strtolower($status) == strtolower($presence)) && (!(stristr($status, 'subscri'))) && ($status))
    {
      $pretty_status = "( $status )";
    }
	
    $tpl->setCurrentBlock("$presence");
    $tpl->setVariable
      (array
        (
          "TIME" => $timestamp." - ".$future_timestamp,
          "PRESENCE" => $presence,
          "STATUS" => $pretty_status
        )
      );
    $tpl->parseCurrentBlock("$presence");
    $tpl->parse("presence_table_inside");
  }

  $tpl->parse("presence_table");
  return $tpl->get("presence_table"); // return the parsed result
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
# Function   : generate_transport_stats($jid, $date = "now()")
# Purpose    : Returns a usage summary of our transports. Gets
#              the transports from global variable $local_transports
# Parameters : string $jid  -> Optional. Default is to examine transport
#                              usage for the whole server, although we could
#                              examine it for one JID
#              string $date -> Optional. Specify a day to examine. Not currently
#                              used.
##########################################*/
function generate_transport_stats($jid = "", $date = "now()")
{
  $tpl = $GLOBALS['tpl'];
  $db = $GLOBALS['db'];
  $config = $GLOBALS['config'];

  $title = "Transport Summary";	
	
  // Determine total messages (local and remote) today
  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";
  $sqlquery = "SELECT count( * ) FROM message WHERE $date_condition";
  $total_today = sql_one_result($sqlquery);
	
  if (count($config['local_transports']) > 0)
  {
    // Setup table headings
    $tpl->setCurrentBlock("transport_table_inside");
    $tpl->setVariable
      (array
        (
          "TOTAL_TODAY" => $total_today,
          "TABLE_TITLE" => $title
        )
      );
							
    $row_num = 0; // used for alternating colors, but we'll use it to determine whether there's been any activity
    foreach($config['local_transports'] as $transport => $transjid)
    {
      $tpl->setCurrentBlock("$transport");
		
      $sqlquery = "SELECT count( * ) FROM message WHERE (message_to LIKE '%$jid%' OR message_from LIKE '%$jid%') AND message_from LIKE '%$transjid%' AND $date_condition";
      $from = sql_one_result($sqlquery);
		
      $sqlquery = "SELECT count( * ) FROM message WHERE (message_to LIKE '%$jid%' OR message_from LIKE '%$jid%') AND message_to LIKE '%$transjid%' AND $date_condition";
      $to = sql_one_result($sqlquery);
		
      $sqlquery = "SELECT count( * ) FROM message WHERE ((message_to LIKE '%$transjid%' AND message_from LIKE '%$jid%') OR (message_from LIKE '%$transjid%' AND message_to LIKE '%$jid%')) AND $date_condition";
      $total = $from + $to;
		
      if ($total > 0)
      { // only parse this transport if at least one message was delivered
        $row_num++;

        if($row_num%2)
        { 
          $color = $GLOBALS["color_row_1"]; 
        }

        else
        {
          $color = $GLOBALS["color_row_2"]; 				
        }							

        $tpl->setVariable
          (array
            (
              "BGCOLOR" => $color,
              "SENT" => $to,
              "RECEIVED" => $from,
              "TOTAL" => $total,
              "PERCENTAGE" => round(($total / $total_today) * 100)."%"
            )
          );
        $tpl->parseCurrentBlock("$transport");
        $tpl->parse("transport_table_inside");
      }
    }
  }

  if ($row_num > 0)
  {
    $tpl->parse("transport_table");
    $result = $tpl->get("transport_table");
  }

  return $result;
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
# Function   : generate_user_list($date = "now()")
# Purpose    : Returns a list of users, sortable via activity (messages sent)
#              or JID (alphabetically). Navigatable via $page & $limit variables
# Parameters : string $date -> Optional. Specify a day to examine. Not currently
#                              used.
# Note       : Although outside users would show up on a "most active users" list,
#              we deliberately exclude them. We're watching local users, nobody else.
##########################################*/
function generate_user_list($date = "now()")
{
  global $orderby; # make it global because generate_sort_by function might change it
  $tpl = $GLOBALS["tpl"];
  $db = $GLOBALS["db"];
  $limit = $GLOBALS["limit"];
  $page = $GLOBALS["page"];
  $config = $GLOBALS["config"];
		
  $start = ($page - 1) * $limit;
	
  // These are the options available to sort the user list. Very extendable
  $sort_options = array
    (
      "sent DESC" => "Activity",
      "message_from" => "JID"
    );
  $sort_by_html = generate_sort_by($sort_options); # before the SQL query, because it might change the value of $orderby		
					
  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition .= " message_from LIKE '%@$local_domain%' OR";
  }
  $local_domain_condition = substr($local_domain_condition,0,-3); // trim the last OR
  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";
  $sqlquery = "SELECT count(message_from) as sent, message_from FROM message WHERE ($local_domain_condition) AND $date_condition GROUP BY message_from ORDER BY $orderby LIMIT $start,$limit";
  $queryresult = $db->query($sqlquery); 

  if (DB::isError($queryresult))
  {
    generate_error('Database query error',$queryresult->getMessage(). " [$sqlquery]");
  }

  $row_num = 0;

  while ($queryrow = $queryresult->fetchRow())
  {
    $row_num++;
    $sent = $queryrow[0]; 
    $from = $queryrow[1];
		
    // alternate row colors
    if($row_num%2)
    { 
      $color = $GLOBALS["color_row_1"]; 
    }

    else
    {
      $color = $GLOBALS["color_row_2"]; 				
    }
		
    // if this is the first row, insert an icon
    // Note: this will change once I get the "status notification" icons working
    if ($row_num == 1)
    {
      $tpl->setCurrentBlock("icon");
      $tpl->setVariable
        (array
          (
            "ICON_IMAGE" => "images/online.gif",
            "ICON_ALT" => "User List"
          )
        );
    }
		
    $tpl->setCurrentBlock("row");
    $tpl->setVariable
      (array
        (
          "BGCOLOR" => $color,	
          "ROW_ATTRIBUTE" => create_jid_link($from),
          "ROW_VALUE" => $sent
        )
      );
    $tpl->parseCurrentBlock("row");
  }

  // only parse if the while loop above executed at least once. Else we have no messages today.
  if ($row_num > 0)
  {
    $tpl->setCurrentBlock("generic_table");
    $tpl->setVariable
      (array
        (
          "TABLE_TITLE" => "User List",
          "HEADING_ATTRIBUTE" => "Jabber ID",
          "HEADING_VALUE" => "Sent",
          "SORTED_BY" => $sort_by_html,
          "PAGE_NUMBERS" => generate_page_nums(total_users($date))
        )
      ); // generate "navigation"								
    $tpl->parse("generic_table") ;
    $result = $tpl->get("generic_table");
    unset($tpl->blockdata["generic_table"]);
  }

  return $result;
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
# Function   : generate_message_log($jid,$date = "now()")
# Purpose    : Returns a log of every message the JID sent or received on $date (today)
#              Navigatable via $page & $limit variables
# Parameters : string $jid  -> The JID we're examining. 
#              string $date -> Optional. Specify a day to examine. Not currently
#                              used.
##########################################*/
function generate_message_log($jid,$date = "now()")
{
  $limit = $GLOBALS["limit"];
  $tpl = $GLOBALS["tpl"];
  $db = $GLOBALS["db"];
  $page = $GLOBALS["page"];
  $orderby = $GLOBALS["orderby"];
  $search = $GLOBALS["search"];

  $start = ($page - 1) * $limit;

  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";

  if ($search)
  {
     $search_condition = "AND ( (message_to LIKE '%$search%') OR (message_from LIKE '%$search%') OR (message_body LIKE '%$search%') OR (message_subject LIKE '%$search%'))";
  }
  $sqlquery = "SELECT message_from, message_to, message_type, message_subject, message_body, DATE_FORMAT(message_timestamp, '%e %b %Y, %l:%i %p') as date FROM message WHERE (message_from LIKE '%$jid%' OR message_to LIKE '%$jid%') AND $date_condition $search_condition ORDER BY $orderby LIMIT $start,$limit";
  $queryresult = $db->query($sqlquery); 

  if (DB::isError($queryresult))
  {
    generate_error('Database query error',$queryresult->getMessage(). " [$sqlquery]");
  }

  while ($queryrow = $queryresult->fetchRow())
  {
    $from = $queryrow[0]; 
    $to = $queryrow[1];
    $type = $queryrow[2];
    $subject = $queryrow[3];
    $body = $queryrow[4];
    $timestamp = $queryrow[5];
		
    if (!$subject) $subject = "[None]";
		
    // for our purposes, only 3 types of messages. Blank "type" values indicate "normal" (default) messages
    if(($type <> "chat") && ($type <> "groupchat"))
    {
      $type = "normal";
    }

    switch ($type)
    {
      case "chat":
        $color_send = $GLOBALS["color_chat_1"];
        $color_receive = $GLOBALS["color_chat_2"];
        break;

      case "groupchat":
        $color_send = $GLOBALS["color_groupchat_1"];
        $color_receive = $GLOBALS["color_groupchat_2"];
        break;

      case "normal":
        $color_send = $GLOBALS["color_normal_1"];
        $color_receive = $GLOBALS["color_normal_2"];
        break;
    }
		
		
    $tpl->setCurrentBlock("message_log_table");

    if (stristr($from,$jid))
    {
      $tpl->setVariable
        (array
          (
            "OTHERPARTY" => "To: ". create_jid_link($to),
            "ICON_IMAGE" => "images/message_$type.gif",
            "ICON_IMAGE_ALT" => "$type message",
            "DIRECTION_IMAGE" => 'images/direction_to.gif',
            "DIRECTION_IMAGE_ALT" => "FROM: $jid TO: $to",
            "BGCOLOR" => $color_send
          )
        );
    }

    else
    {
      $tpl->setVariable
        (array
          (
            "OTHERPARTY" => 'From: '. create_jid_link($from),
            "ICON_IMAGE" => "images/message_$type.gif",
            "ICON_IMAGE_ALT" => "$type message",
            "DIRECTION_IMAGE" => 'images/direction_from.gif',
            "DIRECTION_IMAGE_ALT" => "FROM: $from TO: $jid",
            "BGCOLOR" => $color_receive
          )
        );
    }
    $tpl->setVariable
      (array
        (
          "BODY" => $body,
          "SUBJECT" => $subject,
          "DATE" => $timestamp
        )
      );

    $tpl->parse("message_log_table");
    $result .= $tpl->get("message_log_table");
    unset($tpl->blockdata["message_log_table"]); // Wipe the template block clean so we can reuse it
  }

  return $result;
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
# Function   : determine_presence ($type, $status, $show)
# Purpose    : Analyses three attributes of a presence message, to determine the actual presence
# Parameters : string $type   -> "type" attribute of the presence message. Default (online) is often blank
#              string $status -> "status" attribute of the presence message. Optional in jabber protocol, 
#                                 set by client. No standard.
#              string $show   -> "show" attribute of the presence message. Optional in jabber protocol,
#                                 set by client. Often descriptive, like "Out to lunch".
##########################################*/
function determine_presence ($type, $status, $show)
{
	
  // Mark subscribe / unsub requests as "online"
  if ((stristr($type, "subscribe")) || (stristr($type, "probe")))
  {
     return "online";
  } 

  if ((($type == "unavailable") && (!$status)) || ($status == "Invisible"))
  {
    return "invisible"; 
  }

  elseif ((!$type) && (!$show))
  {
    return "online";
  }

  elseif (($type == "unavailable") && ($status))
  {
    return "offline"; 			
  }

  else
  {
    return $show;
  }
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
# Function   : generate_remote_message_stats($jid="",$date = "now()")
# Purpose    : Generates send / receive totals for remote messages.
# Parameters : string $jid    -> Generate stats specific to this individual JID.
#              string $date   -> Optional. Specify a day to examine.
#                                Not currently used.
##########################################*/
function generate_remote_message_stats($jid,$date = "now()")
{
  $tpl = $GLOBALS['tpl'];
  $db = $GLOBALS['db'];
  $config = $GLOBALS["config"];
	
  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";

  // Setup template
  $tpl->setCurrentBlock("generic_table");
  $tpl->setVariable("TABLE_TITLE", "Remote Messages") ;        
  $tpl->setCurrentBlock("row");

  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition_to .= " message_to NOT LIKE '%@$local_domain%' AND";
    $local_domain_condition_from .= " message_from NOT LIKE '%@$local_domain%' AND";
  }
  $local_domain_condition_to = substr($local_domain_condition_to,0,-4); // trim final AND
	$local_domain_condition_from = substr($local_domain_condition_from,0,-4);	
	
  $sqlquery = "SELECT count( * ) FROM message WHERE (($local_domain_condition_to) OR ($local_domain_condition_from)) AND $date_condition ";
  $total_remote = sql_one_result($sqlquery);

  // messages sent today
  unset($local_domain_condition);

  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition .= " AND message_to NOT LIKE '%\@$local_domain%'";
  }	

  $sqlquery = "SELECT count( * ) FROM message WHERE message_from LIKE '$jid%' $local_domain_condition AND $date_condition";
  $sent_remote = sql_one_result($sqlquery);
  $tpl->setVariable
    (array
      (
        "ICON_IMAGE" => "images/icon_remote.gif",
        "ICON_ALT" => "Remote Messages",
        "BGCOLOR" => $GLOBALS["color_row_1"],
        "ROW_ATTRIBUTE" => "Sent",
        "ROW_VALUE" => $sent_remote
      )
    );
  $tpl->parseCurrentBlock("row");

  // messages received today
  unset($local_domain_condition);

  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition .= " AND message_from NOT LIKE '%@$local_domain%'";
  }	
  $sqlquery = "SELECT count( * ) FROM message WHERE message_to LIKE '$jid%' $local_domain_condition AND $date_condition";
  $received_remote = sql_one_result($sqlquery);
  $tpl->setVariable
    (array
      (
        "BGCOLOR" => $GLOBALS["color_row_2"],		
        "ROW_ATTRIBUTE" => "Received",
        "ROW_VALUE" => $received_remote
      )
    );
  $tpl->parseCurrentBlock("row");
	
  if ($total_remote > 0 )
  {
    $percentage_remote = round((($received_remote + $sent_remote) / $total_remote ) * 100);
  }

  else
  {
    $percentage_remote = 0; // avoid division by zero
  }
	
  $tpl->setVariable
    (array
      (
        "BGCOLOR" => $GLOBALS["color_row_1"],	
        "ROW_ATTRIBUTE" => "Percentage of total ($total_remote)",
        "ROW_VALUE" => $percentage_remote. "%"
      )
    );
  $tpl->parseCurrentBlock("row");

  $tpl->parse("generic_table");
  return $tpl->get("generic_table");	
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
# Function   : generate_local_message_stats($jid="",$date = "now()")
# Purpose    : Generates send / receive totals for local messages.
# Parameters : string $jid    -> Generate stats specific to this individual JID.
#              string $date   -> Optional. Specify a day to examine.
#                                Not currently used.
##########################################*/
function generate_local_message_stats($jid,$date = "now()")
{
  $tpl = $GLOBALS['tpl'];
  $db = $GLOBALS['db'];
  $config = $GLOBALS["config"];
	
  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";

  // setup table
  $tpl->setCurrentBlock("generic_table");
  $tpl->setVariable("TABLE_TITLE", "Local Messages") ;        
  $tpl->setCurrentBlock("row");
	
  // calculate total now, so we don't duplicate work in the if statement below
  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition_to .= " message_to LIKE '%@$local_domain%' OR";
    $local_domain_condition_from .= " message_from LIKE '%@$local_domain%' OR";
  }
  $local_domain_condition_to = substr($local_domain_condition_to,0,-3); // trim final OR
  $local_domain_condition_from = substr($local_domain_condition_from,0,-3);	

  $sqlquery = "SELECT count( * ) FROM message WHERE (($local_domain_condition_to) AND ($local_domain_condition_from)) AND $date_condition ";
  $total_local = sql_one_result($sqlquery);

  // messages sent today
  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition .= " message_to LIKE '%@$local_domain%' OR";
  }
  $local_domain_condition = substr($local_domain_condition,0,-3);
  $sqlquery = "SELECT count( * ) FROM message WHERE message_from LIKE '$jid%' AND ($local_domain_condition) AND $date_condition";
  $sent_local = sql_one_result($sqlquery);
  $tpl->setVariable
    (array
      (
        "ICON_IMAGE" => "images/icon_local.gif",
        "ICON_ALT" => "Local Messages",		
        "BGCOLOR" => $GLOBALS["color_row_1"],					
        "ROW_ATTRIBUTE" => "Sent",
        "ROW_VALUE" => $sent_local
      )
    );
  $tpl->parseCurrentBlock("row");

  // messages received today
  unset($local_domain_condition);

  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition .= " message_from LIKE '%@$local_domain%' OR";
  }
  $local_domain_condition = substr($local_domain_condition,0,-3);
  $sqlquery = "SELECT count( * ) FROM message WHERE message_to LIKE '$jid%' AND ($local_domain_condition) AND $date_condition";
  $received_local = sql_one_result($sqlquery);
  $tpl->setVariable
    (array
      (
        "BGCOLOR" => $GLOBALS["color_row_2"],	
        "ROW_ATTRIBUTE" => "Received",
        "ROW_VALUE" => $received_local
      )
    );
  $tpl->parseCurrentBlock("row");
	
  // percentage of total
  if ($total_local > 0 )
  {
    $percentage_local = round((($received_local + $sent_local) / $total_local ) * 100);
  }

  else
  { 
    $percentage_local = 0; // avoid division by zeno
  }
  $tpl->setVariable
    (array
      (
        "BGCOLOR" => $GLOBALS["color_row_1"],	
        "ROW_ATTRIBUTE" => "Percentage of total ($total_local)",
        "ROW_VALUE" => $percentage_local. "%"
      )
    );
  $tpl->parseCurrentBlock("row");

  $tpl->parse("generic_table");
  $result = $tpl->get("generic_table");
  return $result;
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
# Function   : generate_total_message_stats($date = "now()")
# Purpose    : Generates send / receive totals for main page messages.
# Parameters : string $date   -> Optional. Specify a day to examine. Not currently
#                              used.
##########################################*/
function generate_total_message_stats($date = "now()")
{
  $tpl = $GLOBALS['tpl'];
  $db = $GLOBALS['db'];
  $config = $GLOBALS["config"];
	
  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";

  // setup table
  $tpl->setCurrentBlock("generic_table");
  $tpl->setVariable("TABLE_TITLE", "Message Summary") ;        
  $tpl->setCurrentBlock("row");
	
  // calculate total local
  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition_to .= " message_to LIKE '%@$local_domain%' OR";
    $local_domain_condition_from .= " message_from LIKE '%@$local_domain%' OR";
  }
  $local_domain_condition_to = substr($local_domain_condition_to,0,-3); // trim final AND
  $local_domain_condition_from = substr($local_domain_condition_from,0,-3);	

  $sqlquery = "SELECT count( * ) FROM message WHERE (($local_domain_condition_to) AND ($local_domain_condition_from)) AND $date_condition ";
  $total_local = sql_one_result($sqlquery);

  $tpl->setVariable
    (array
      (
        "ICON_IMAGE" => "images/icon_local.gif",
        "ICON_ALT" => "Local Messages",		
        "BGCOLOR" => $GLOBALS["color_row_1"],					
        "ROW_ATTRIBUTE" => "Local",
        "ROW_VALUE" => $total_local
      )
    );
  $tpl->parseCurrentBlock("row");
	
  unset($local_domain_condition_to);
  unset($local_domain_condition_from);
  // calculate total remote
  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition_to .= " message_to NOT LIKE '%@$local_domain%' AND";
    $local_domain_condition_from .= " message_from NOT LIKE '%@$local_domain%' AND";
  }
  $local_domain_condition_to = substr($local_domain_condition_to,0,-4); // trim final AND
  $local_domain_condition_from = substr($local_domain_condition_from,0,-4);	
	
  $sqlquery = "SELECT count( * ) FROM message WHERE (($local_domain_condition_to) OR ($local_domain_condition_from)) AND $date_condition ";
  $total_remote = sql_one_result($sqlquery);

  $tpl->setVariable
    (array
      (
        "ICON_IMAGE" => "images/icon_remote.gif",
        "ICON_ALT" => "Remote Messages",		
        "BGCOLOR" => $GLOBALS["color_row_2"],					
        "ROW_ATTRIBUTE" => "Remote",
        "ROW_VALUE" => $total_remote
      )
    );
  $tpl->parseCurrentBlock("row");

  // generate total
  $tpl->setVariable
    (array
      (
        "BGCOLOR" => $GLOBALS["color_row_1"],					
        "ROW_ATTRIBUTE" => "Total",
        "ROW_VALUE" => ($total_remote + $total_local)
      )
    );
  $tpl->parseCurrentBlock("row");

  $tpl->parse("generic_table") ;
  $result = $tpl->get("generic_table");
  return $result;
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
# Function   : create_jid_link($jid)
# Purpose    : Takes a JID and outputs it as a clickable link.
# Parameters : string $jid    -> The JID to use
# Note       : Since we only examine local JIDs, no HREF will be created for
#              remote JIDs. It would be easier just to construct the <A HREF>
#              statement ourselves, instead of using templates, but we want a
#              strict code/design seperation. We seperate the JID from the
#              resounce, so that each can be clicked seperately and give
#              different results.
##########################################*/
function create_jid_link($jid)
{
  $config = $GLOBALS["config"];
  $tpl = $GLOBALS["tpl"];
  $date = $GLOBALS["date"];

  foreach($config['local_domains'] as $local_domain)
  {
  if (strstr($jid,"@$local_domain")) { $create_link = true; }
  }

  if ($create_link)
  { # Only make local links clickable
    $tpl->setCurrentBlock("link");

    if (strstr($jid,"/"))
    {
      $jid_plain = substr($jid, 0, strpos($jid,"/"));
      $resource = substr($jid, strpos($jid,"/")+1);
      $tpl->setVariable
        (array
          (
            'LINK_URL' => $_SERVER['PHP_SELF']."?func=user&jid=$jid_plain&date=$date",
            'LINK_TEXT' =>  $jid_plain,
            'LINK_SEPERATOR' => '/'
          )
        );       
      $tpl->parseCurrentBlock("link");
      $tpl->setVariable
        (array
          (
            'LINK_URL' => $_SERVER['PHP_SELF']."?func=user&jid=$jid&date=$date",
            'LINK_TEXT' =>  $resource
          )
        ); 
      $tpl->parseCurrentBlock("link");
    }

    else
    {
      $tpl->setVariable
        (array
          (
            'LINK_URL' => $_SERVER['PHP_SELF']."?func=user&jid=$jid&date=$date",
            'LINK_TEXT' =>  $jid
          )
        );       
      $tpl->parseCurrentBlock("link");
    }

    $tpl->parse('link');
    return $tpl->get('link');
  }

  else
  {
    return $jid;
  }
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
# Function   : total_messages($jid = "",$date = "now()")
# Purpose    : Returns the total amount of messages sent by $jid on given date
# Parameters : string $jid    -> Optional. The JID to use. If empty, the total
#                                amount of messages for the entire site will be
#                                returned.
#              string $date   -> Optional. The specific date to examine. Not
#                                currently implemented.
##########################################*/
function total_messages($jid = "",$date = "now()")
{
  $db = $GLOBALS["db"];
  $search = $GLOBALS["search"];
	
  if ($search)
  {
    $search_condition = "AND ( (message_to LIKE '%$search%') OR (message_from LIKE '%$search%') OR (message_body LIKE '%$search%'))";
  }
  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";
  $sqlquery = "SELECT count( * ) FROM message WHERE (message_to LIKE '%$jid%' OR message_from LIKE '%$jid%') AND $date_condition $search_condition";
  return sql_one_result($sqlquery);	
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
# Function   : total_users($date = "now()")
# Purpose    : Returns the total amount of users who sent messages on given date
# Parameters : string $date   -> Optional. The specific date to examine. Not
#                                currently implemented.
##########################################*/
function total_users($date = "now()")
{
  $db = $GLOBALS["db"];
  $config = $GLOBALS["config"];

  foreach($config['local_domains'] as $local_domain)
  {
    $local_domain_condition .= " message_from LIKE '%@$local_domain%' OR";
  }	
  $local_domain_condition = substr($local_domain_condition,0,-3);
	
  $date_condition = "DATE_FORMAT( message_timestamp, '%Y%m%d' ) = DATE_FORMAT( $date , '%Y%m%d' ) ";
  $sqlquery = "SELECT message_from FROM message WHERE ($local_domain_condition) AND $date_condition GROUP BY message_from";

  $result = $db->query ($sqlquery);
  return $result->numRows();
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
# Function   : generate_page_nums($total)
# Purpose    : Generates the "navigation panel", based on given total, and
#              global page & limit variables
# Parameters : int $total   -> The pre-determined total amount of records
##########################################*/
function generate_page_nums($total)
{
  $jid = $GLOBALS["jid"];
  $limit = $GLOBALS["limit"];
  $page = $GLOBALS["page"];
  $func  = $GLOBALS["func"];
  $orderby = $GLOBALS["orderby"];
  $tpl = $GLOBALS["tpl"];
  $date = $GLOBALS["date"];
  $search = $GLOBALS["search"];
	
  $pages = ceil($total / $limit);
	
  if ($pages > 1)
  {
    $tpl->setCurrentBlock("navigation");
    if ($page <> 1)
    { // If we're not on page 1, we can create [first] and [prev] links
      $tpl->setCurrentBlock("nav_first");
      $tpl->setVariable("LINK_FIRST",$_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=1&limit=$limit&orderby=$orderby&date=$date&search=$search");
      $tpl->parseCurrentBlock("nav_first");			
      $tpl->setCurrentBlock("nav_prev");
      $tpl->setVariable("LINK_PREV",$_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=". ($page-1) . "&limit=$limit&orderby=$orderby&date=$date&search=$search");
      $tpl->parseCurrentBlock("nav_prev");
    }

    else
    { // If we're on page 1, [first] and [prev] links must be un-clickable
      $tpl->touchBlock("nav_first_nolink");
      $tpl->parseCurrentBlock("nav_first_nolink");		
      $tpl->touchBlock("nav_prev_nolink");
      $tpl->parseCurrentBlock("nav_prev_nolink");
    }
    for ($i = 1; $i <= $pages; $i++)
    {
      $tpl->setCurrentBlock("nav_pagenumber");
      if ($i == $page)
      {
        $tpl->setVariable("PAGENUMBER_NOLINK",$i);
      }

      else
      {
        $tpl->setVariable
          (array
            (
              "PAGENUMBER" => $i,	
              "LINK_PAGENUMBER" => $_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=$i&limit=$limit&orderby=$orderby&date=$date&search=$search"
            )
          );
      }

      $tpl->parseCurrentBlock("nav_pagenumber");
    }
    if ($page <> $pages)
    {
      $tpl->setCurrentBlock("nav_last");
      $tpl->setVariable("LINK_LAST",$_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=$pages&limit=$limit&orderby=$orderby&date=$date&search=$search");
      $tpl->parseCurrentBlock("nav_last");			
      $tpl->setCurrentBlock("nav_next");
      $tpl->setVariable("LINK_NEXT",$_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=". ($page+1) . "&limit=$limit&orderby=$orderby&date=$date&search=$search");
      $tpl->parseCurrentBlock("nav_next");			
    }

    else
    {
      $tpl->touchBlock("nav_next_nolink");
      $tpl->parseCurrentBlock("nav_next_nolink");		
      $tpl->touchBlock("nav_last_nolink");
      $tpl->parseCurrentBlock("nav_last_nolink");
    }		
				
    $tpl->setCurrentBlock("navigation");			
    $tpl->parseCurrentBlock("navigation");			
    $result = $tpl->get("navigation");					
  }

  return $result;
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
# Function   : generate_sort_by($sort_options)
# Purpose    : Generates a "sort by:
# Parameters : array $sort_options -> An associative array of
#                                     "field_in_table" => "nice formatted name"
#                                     The sort options are determined from this
#                                     array
##########################################*/
function generate_sort_by($sort_options)
{
  global $orderby; # We use the global variable, because we may need to change it
  $limit = $GLOBALS["limit"];
  $page = $GLOBALS["page"];
  $func = $GLOBALS["func"];
  $jid = $GLOBALS["jid"];	
  $tpl = $GLOBALS["tpl"];
  $date = $GLOBALS["date"];
  $search = $GLOBALS["search"];
		
  $tpl->setCurrentBlock("sorted_by");

  $valid_orderby = false; # Check to see if global orderby value is one of the sortitems

  foreach ($sort_options as $value => $nice_name)
  {
    if (!$first_value) { $first_value = $value; } # If orderby is not valid, it will be set to this value
    if ($value == $orderby)
    { // if it's currently sorted by this value, don't make the text clickable
      $tpl->setCurrentBlock("sorted_by_value_nolink");
      $tpl->setVariable("SORTED_BY_VALUE_NOLINK",$nice_name);
      $tpl->parseCurrentBlock("sorted_by_value_nolink");
      $valid_orderby = true;
    }

    else
    {
      $tpl->setCurrentBlock("sorted_by_value");
      $tpl->setVariable
        (array
          (
            "SORTED_BY_VALUE" => $nice_name,
            "SORTED_BY_VALUE_LINK" => $_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=$page&limit=$limit&orderby=$value&date=$date&search=$search"
          )
        );
      $tpl->parseCurrentBlock("sorted_by_value");
    }
  }	
	
  if (!$valid_orderby) { $orderby = $first_value; }
	
  $tpl->setCurrentBlock("sorted_by");			
  $tpl->parseCurrentBlock("sorted_by");			
  $result = $tpl->get("sorted_by");					
  return $result;
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
# Function   : generate_admin_options()
# Purpose    : Generates the "admin toolbar" (login, logout, message log...)
##########################################*/
function generate_admin_options()
{
  $limit = $GLOBALS["limit"];
  $date = $GLOBALS["date"];
  $page = $GLOBALS["page"];
  $tpl = $GLOBALS["tpl"];
  $orderby = $GLOBALS["orderby"];
  $jid = $GLOBALS["jid"];
  $func = $GLOBALS["func"];
  $a = $GLOBALS["a"]; // auth variable			
		
  $tpl->setCurrentBlock("admin_bar");
  if ($a->getAuth())
  { // if user is logged in
    $tpl->setCurrentBlock("admin_logged_in_as");
    $tpl->setVariable("LOGGED_IN_AS",$a->getUsername());
    $tpl->parseCurrentBlock("logged_in_as");

    if (($jid) && ($func == "user"))
    { // if we're browsing user stats, show link to message log
      $tpl->setCurrentBlock("admin_message_log");
      $tpl->setVariable("MESSAGE_LOG_LINK",$_SERVER['PHP_SELF']."?func=log&jid=$jid&page=$page&limit=$limit&date=$date");
      $tpl->parseCurrentBlock("admin_message_log");
    }

    elseif (($jid) && ($func == "log"))
    { // if we're message log, show link to user stats
      $tpl->setCurrentBlock("admin_user_stats");
      $tpl->setVariable("USER_STATS_LINK",$_SERVER['PHP_SELF']."?func=user&jid=$jid&page=$page&limit=$limit&date=$date");
      $tpl->parseCurrentBlock("admin_user_stats");			
    }
			
    $tpl->setCurrentBlock("admin_logout");
    $tpl->setVariable("ADMIN_LOGOUT_LINK",$_SERVER['PHP_SELF']."?func=logout&oldfunc=$func&jid=$jid&page=$page&limit=$limit&orderby=$orderby&date=$date");
    $tpl->parseCurrentBlock("admin_logout");			
  }

  else
  {
    $tpl->setCurrentBlock("admin_login");
    $tpl->setVariable("ADMIN_LOGIN_LINK",$_SERVER['PHP_SELF']."?func=login&oldfunc=$func&jid=$jid&page=$page&limit=$limit&orderby=$orderby&date=$date");
    $tpl->parseCurrentBlock("admin_");
  }
	
  $tpl->setCurrentBlock("admin_bar");			
  $tpl->parseCurrentBlock("admin_bar");			
  $result = $tpl->get("admin_bar");					
  return $result;
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
# Function   : generate_error($title,$subtitle)
# Purpose    : Generates an error page, and dies
# Parameters : string $title    -> The text to fill the {TITLE} variable on the
#                                  template.
#              string $subtitle -> Optional. The text to fill the {SUBTITLE}
#                                  variable on the template.
##########################################*/
function generate_error($title,$subtitle = "")
{
  $tpl = $GLOBALS['tpl'];
  $date = $GLOBALS['date'];

  $tpl->setCurrentBlock("main_page");
  $tpl->setVariable
    (array
      (
        "PAGE_TITLE" => "Error: $title",
        "LINK_HOME" => $_SERVER['PHP_SELF']. "?date=$date",	
        "PAGE_SUBTITLE" => $subtitle
      )
    );
  $tpl->parse("main_page");
  echo $tpl->get("main_page");
  die();
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
# Function   : generate_date_selectbox()
# Purpose    : Generates a list of available dates in the database, and
#              "selects" the currently selected one
##########################################*/
function generate_date_selectbox()
{
  $tpl = $GLOBALS["tpl"];
  $db = $GLOBALS["db"];
  $current_date = $GLOBALS["date"];
  $jid = $GLOBALS["jid"];
  $limit = $GLOBALS["limit"];
  $page = $GLOBALS["page"];
  $func = $GLOBALS["func"];
  $orderby = $GLOBALS["orderby"];
  $search = $GLOBALS["search"];
		
  $tpl->setCurrentBlock("date_bar");
  $tpl->setVariable("DATE_BAR_ACTION",$_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=$page&limit=$limit&orderby=$orderby&search=$search");
		
  $sqlquery =  "SELECT COUNT(*) as count, DATE_FORMAT( message_timestamp, '%Y%m%d' ) as date,"; 
  $sqlquery .= "DATE_FORMAT( message_timestamp, '%e %b %Y' ) as pretty_date from message group by date";
  $queryresult = $db->query($sqlquery); 

  if (DB::isError($queryresult))
  {
    generate_error('Database query error',$queryresult->getMessage(). " [$sqlquery]");
  }

  while ($queryrow = $queryresult->fetchRow())
  {
    $count = $queryrow[0]; 
    $date = $queryrow[1];
    $date_pretty = $queryrow[2];
		
    if ($date == date('Ymd')) $date_pretty .= " (Today)";

    if ($date == $current_date)
    {
      $tpl->setCurrentBlock("date_bar_option_selected");
    }

    else
    {
      $tpl->setCurrentBlock("date_bar_option");
    }
    $tpl->setVariable
      (array
        (
          "OPTION" => $date_pretty,
          "VALUE" => $date
        )
      );
    $tpl->parseCurrentBlock("date_bar_option");
    $tpl->parseCurrentBlock("date_bar_option_selected");								
    $tpl->parse("date_bar_row");			
  }

  $tpl->parse("date_bar");
  return $tpl->get("date_bar");
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
# Function   : generate_date_selectbox()
# Purpose    : Generates a list of available dates in the database, and "selects" the currently selected one
##########################################*/
function generate_search_bar()
{
  $tpl = $GLOBALS["tpl"];
  $db = $GLOBALS["db"];
  $current_date = $GLOBALS["date"];
  $jid = $GLOBALS["jid"];
  $limit = $GLOBALS["limit"];
  $page = $GLOBALS["page"];
  $func = $GLOBALS["func"];
  $orderby = $GLOBALS["orderby"];
  $search = $GLOBALS["search"];
  $date = $GLOBALS["date"];
	
  $tpl->setCurrentBlock("search_bar");
  $tpl->setVariable("SEARCH_BAR_ACTION",$_SERVER['PHP_SELF']."?func=$func&jid=$jid&limit=$limit&orderby=$orderby&date=$date");
	
  if ($search)
  {
    $tpl->setCurrentBlock("search_bar_results");
    $tpl->setVariable
      (array
        (
          "SEARCH_RESULTS_TEXT" => $search,
          "SEARCH_CLEAR_LINK" => $_SERVER['PHP_SELF']."?func=$func&jid=$jid&page=$page&limit=$limit&orderby=$orderby&date=$date"
        )
      );
    $tpl->parseCurrentBlock("search_bar_results");
  }

  $tpl->setCurrentBlock("search_bar");
  $tpl->setVariable("SEARCH_TEXT",$search);

  $tpl->parse("search_bar");
  return $tpl->get("search_bar");			
}
?>
