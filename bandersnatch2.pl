#!/usr/bin/perl -w

#__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|

# Bandersnatch - A jabber logger and statistics reporter
#
# Bandersnatch is an external Jabber (www.jabber.org) component that logs
# all messages sent to it into a DBI-compatible database. 
#
# Bandersnatch's real usefulness is in it's PHP-based web frontend. From that
# interface it's possible to view remote vs. local usage, individual tranport
# usage, individual message logs, etc.

###############################################################################
#               Bandersnatch - Jabber logger and statistics reporter          #
#          Copyright (C) 2003, David Young <davidy@funkypenguin.co.za>        #
#                                                                             #
#  This program is free software; you can redistribute it and/or modify it    #
#  under the terms of the GNU General Public License as published by the Free #
#  Software Foundation; either version 2 of the License, or (at your option)  #
#  any later version.                                                         #
#                                                                             #
#  This program is distributed in the hope that it will be useful, but        #
#  WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY #
#  or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License   #
#  for more details.                                                          #
#                                                                             #
#  You should have received a copy of the GNU General Public License along    #
#  with this program; if not, write to the Free Software Foundation, Inc.,    #
# 	59 Temple Place, Suite 330, Boston, MA 02111-1307 USA                 #
#                                                                             #
###############################################################################

my $VERSION = "0.0.3";

# +----------------------------------------------------------------------------+
# | Declare Global Variables                                                   |
# +----------------------------------------------------------------------------+

my %config;
my %scsm_sessions;
my @routes;
my $dbh;


# Clean path whenever you use taint checks (Make %ENV safer)
$ENV{'PATH'} = "";
delete @ENV{qw(IFS CDPATH ENV BASH_ENV)};

# Set up vars
my $config_file = $ARGV[0];
my $configdir = ".";
my $config;

# Check user input
if(defined $config_file)
{
  # Untaint by stripping any bad characters, see "perlsec" man page.
  $config_file =~ /^([-\w.\/]+)$/ or die "Bad characters found\n\n";
  $config_file = $1;
}
else
{
  $config_file = "$configdir/config.xml";
}


# +----------------------------------------------------------------------------+
# | Load Modules                                                               |
# +----------------------------------------------------------------------------+

# Other dependancies. In a later version, we will remove the need for XML::Stream
use DBI;
use Getopt::Long;
use XML::Stream qw(Tree);
use warnings;
use strict;

# POE stuff
use POE::Preprocessor;
const XNode POE::Filter::XML::Node
use POE qw/ Component::Jabber::Client::J2 Component::Jabber::Error/;
use POE::Filter::XML::Node;
use POE::Filter::XML::NS qw/ :JABBER :IQ /;



# The POE session itself - this runs everything else!
POE::Session->create
  (
#   options => { debug => 1, trace => 1},
    inline_states =>
    {
      _start => \&start_event,
      _stop =>
      sub
      {
        my $kernel = $_[KERNEL];
        $kernel->alias_remove('Tester');
      },
      input_event => \&input_event,
      error_event => \&error_event,
      init_finished => \&init_finished,
      }
);

sub start_event()
{
# Load the config file, using old XML::Stream method
  &loadConfig();
  my $kernel = $_[KERNEL];
  $kernel->alias_set('Tester');
  POE::Component::Jabber::Client::J2->new
  (
    IP => $config{server}->{hostname},
    PORT => $config{server}->{port},
    HOSTNAME => $config{server}->{hostname},
    USERNAME => $config{component}->{name}, # The current bandersnatch config.xml file has no option for this. Until we replace the config file, it's hardcoded.
    PASSWORD => $config{server}->{secret},
    BIND_DOMAIN => $config{component}->{name},
    BIND_OPTION => 'log',
    ALIAS => 'COMPONENT',
    #DEBUG => '1',
    STATE_PARENT => 'Tester',
    STATES =>
      {
        InitFinish => 'init_finished',
        InputEvent => 'input_event',
        ErrorEvent => 'error_event',
      }
  );
}

# Startup n' stuff
sub init_finished()
{
  my ($kernel, $heap, $jid) = @_[KERNEL, HEAP, ARG0];
  $heap->{'jid'} = $jid;
  print "\n\nBandersnatch is listening! ;)\n";
}

# this is what handles an incoming event... could this be our link to the data?
sub input_event()
{
  my ($to, $from, $body);
  my ($kernel, $heap, $node) = @_[KERNEL, HEAP, ARG0];

  if($node->name() eq 'route')
  {
    my $child = $node->get_children();
    $child = $child->[0];
    if (!$child->attr('from')) { return; }

    if($child->name() eq 'message' and $child->attr('from') ne $heap->{'jid'})
    {
      log_message($node);
    }
    
    elsif($child->name() eq "presence") 
    {
      log_presence($node);
    }

#    else
#    {
#     print "\n===PACKET RECEIVED===\n";
#     print $node->to_str() . "\n";
#     print "=====================\n\n";
#    }
  }

  elsif($node->name() eq 'comp:route') #to log component i.e. AIM-t messages
  {
    my $child = $node->get_children();
    $child = $child->[0];
    if (!$child->attr('from'))  { return; }

    if($child->name() eq 'message' and $child->attr('from') ne $heap->{'jid'})
    {
      log_message($node);
    }
  }
}

sub error_event()
{
  my $error = $_[ARG0];

  if($error == +PCJ_SOCKFAIL)
  {
    my ($call, $code, $err) = @_[ARG1..ARG3];
    print "Socket error: $call, $code, $err\n";
  }

  elsif($error == +PCJ_SOCKDISC)
  {
    print "We got disconneted\n";
  }

  elsif ($error == +PCJ_AUTHFAIL)
  {
    print "Failed to authenticate\n";
  }

  elsif ($error == +PCJ_BINDFAIL)
  {
    print "Failed to bind a resource\n";
  }

  elsif ($error == +PCJ_SESSFAIL)
  {
    print "Failed to establish a session\n";
  }
}


# +----------------------------------------------------------------------------+
# | Connect to Database Server                                                 |
# +----------------------------------------------------------------------------+

if (!connectDatabase())
{
  print("(ERROR) Unable to connect to MySQL database (".$config{mysql}->{server}."@".$config{mysql}->{server}.")");
  exit(0);
}

print("Connected to MySQL database (".$config{mysql}->{dbname}."@".$config{mysql}->{server}.") ...");

# These two subs are not to be used yet... for a later stage when we change the layout of the config file
#sub get_config()
#{
#  my $path = shift;
#
#  if(defined($path))
#  {
#    $file = IO::File->new($path);
#  }
#
#  else
#  {
#    $file = IO::File->new('./config.xml');
#  }
#
#  my $filter = POE::Filter::XML->new();
#  my @lines = $file->getlines();
#  my $nodes = $filter->get(\@lines);
#  splice(@$nodes,0,1);
#  my $hash = {};
#
#  foreach my $node (@$nodes)
#  {
#    print "\n\n". $node->name(). "\n---------\n"; 				
#    $hash->{$node->name()} = &get_hash_from_node($node);
#  }
#				
#  return $hash;
#}

#sub get_hash_from_node()
#{
#  my $node = shift;
#  my $hash = {};
#  return $node->data() unless keys %{$node->[3]} > 0;
#
#  foreach my $kid (keys %{$node->[3]})
#  {
#    print $node->[3]->{$kid}->name() . "\n";
#    $hash->{$node->[3]->{$kid}->name()} = &get_hash_from_node($node->[3]->{$kid});
#  }
#  return $hash;
#
#}

# +----------------------------------------------------------------------------+
# | Load Configuration Settings                                                |
# +----------------------------------------------------------------------------+
sub loadConfig
{
  my $parser = new XML::Stream::Parser(style=>"Tree");
  my @tree = $parser->parsefile($config_file);

# +------------------------------------------------------------------------+
# | Jabber Server Settings                                                 |
# +------------------------------------------------------------------------+
  my @serverTree = &XML::Stream::GetXMLData("tree", $tree[0], "server", "", "");
  $config{server}->{hostname} = &XML::Stream::GetXMLData("value", \@serverTree, "hostname", "", "");
  $config{server}->{port} = &XML::Stream::GetXMLData("value", \@serverTree, "port", "", "");
  $config{server}->{secret} = &XML::Stream::GetXMLData("value", \@serverTree, "secret", "", "");
  $config{server}->{connectiontype} = &XML::Stream::GetXMLData("value", \@serverTree, "connectiontype", "", "");
  $config{server}->{connectiontype} = "tcpip" if ($config{server}->{connectiontype} eq "");

# +------------------------------------------------------------------------+
# | Component Settings                                                     |
# +------------------------------------------------------------------------+
  my @componentTree = &XML::Stream::GetXMLData("tree", $tree[0], "component", "", "");
  $config{component}->{name} = &XML::Stream::GetXMLData("value", \@componentTree, "name", "", "");

# +------------------------------------------------------------------------+
# | Database Settings                                                      |
# +------------------------------------------------------------------------+
  my @mysqlTree = &XML::Stream::GetXMLData("tree", $tree[0], "mysql", "", "");
  $config{mysql}->{server} = &XML::Stream::GetXMLData("value", \@mysqlTree, "server", "", "");
  $config{mysql}->{dbname} = &XML::Stream::GetXMLData("value", \@mysqlTree, "dbname", "", "");
  $config{mysql}->{username} = &XML::Stream::GetXMLData("value", \@mysqlTree, "username", "", "");
  $config{mysql}->{password} = &XML::Stream::GetXMLData("value", \@mysqlTree, "password", "", "");

# +------------------------------------------------------------------------+
# | Debug Settings                                                         |
# +------------------------------------------------------------------------+
  my @debugTree = &XML::Stream::GetXMLData("tree", $tree[0], "debug", "", "");
  $config{debug}->{level} = &XML::Stream::GetXMLData("value", \@debugTree, "level", "", "");
  $config{debug}->{file} = &XML::Stream::GetXMLData("value", \@debugTree, "file", "", "");

# +------------------------------------------------------------------------+
# | Site Settings                                                         |
# +------------------------------------------------------------------------+
  my @siteTree = &XML::Stream::GetXMLData("tree", $tree[0], "site", "", "");
  $config{site}->{local_server} = &XML::Stream::GetXMLData("value", \@siteTree, "local_server", "", "");
  $config{site}->{privacy} = &XML::Stream::GetXMLData("value", \@siteTree, "privacy", "", "");
  $config{site}->{aggressive_presence} = &XML::Stream::GetXMLData("value", \@siteTree, "aggressive_presence", "", "");
  my @admin_jids = &XML::Stream::GetXMLData("value array", \@siteTree, "admin_jids", "", "");
  $config{site}->{admin_jids} = \@admin_jids;
  my @confidential_jids = &XML::Stream::GetXMLData("value array", \@siteTree, "confidential_jids", "", "");
  $config{site}->{confidential_jids} = \@confidential_jids;
  my @ignore_jids = &XML::Stream::GetXMLData("value array", \@siteTree, "ignore_jids", "", "");
  my @local_domains = &XML::Stream::GetXMLData("value array", \@siteTree, "local_domains", "", "");
  
##################################
# check that local_domains contains the value of local_server. If not, put it in :)
################################
  my $local_server = $config{site}->{local_server};
  my $found_in_array;

  foreach my $domain (@local_domains)
  {
    if ($domain =~ /^$local_server/)
    {
      $found_in_array = 1;
    }
  }

  if (!$found_in_array)
  {
    push(@local_domains,$local_server);
  }
  $config{site}{local_domains} = \@local_domains;

##################################
# check that ignore_jids contains the name of component. If not, put it in :)
################################
  my $component_name = $config{component}->{name};
  $found_in_array = 0;

  foreach my $jid (@ignore_jids)
  {
    if ($jid =~ /^$component_name/)
    {
      $found_in_array = 1;
    }
  }

  if (!$found_in_array)
  {
    push(@ignore_jids,$component_name);
  }
  $config{site}{ignore_jids} = \@ignore_jids;
  $parser->{HANDLER}->{startDocument} = undef;
  $parser->{HANDLER}->{endDocument} = undef;
  $parser->{HANDLER}->{startElement} = undef;
  $parser->{HANDLER}->{endElement} = undef;
  $parser->{HANDLER}->{characters} = undef;
}

#__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|
# Function   : log_presence
# Purpose    : Receives a "presence" node, breaks it down, and logs it
##########################################*/
sub log_presence
{
  my $node = shift;	
  my $header = $node->get_children()->[0];
  my $show_node = $header->get_children()->[0];		
  my $status_node = $header->get_children()->[1];
  my $sqlquery;
  my $show;
  my $status;

  foreach my $kid (keys %{$header->[3]})
  {
  return if ($kid eq 'x' ); # We don't want this data - dirty hack though, because I don't really know how to do it properly ;) - David
  if ($kid eq "show") 
    {
      $show = $header->[3]->{$kid}->data;
    }

    elsif ($header->[3]->{$kid}->name eq "status") 
    {
      $status = $header->[3]->{$kid}->data;
    }
  }		
  my $type = $header->attr('type');
  my $from = $header->attr('from');
  my $priority = $header->attr('priority');

# Either add this entry to the scsm array, or lookup the JID using it
  my $scsm = $header->attr('scsm');		

  if ($from)
  {
    $scsm_sessions{'$scsm'} = $from;
  }

  else
  {
  $from = $scsm_sessions{'$scsm'};
  }
	
# If user is signing off, remove entry in SC:SM "memory"
  if ((defined($type)) && ($type eq 'unavailable'))
  {
    delete($scsm_sessions{'$scsm'});
  }
  $type = "not_implemented" if (!$type); # This seems to be a compatibility thing with jabberd1.4 protocol
  return if (!$from); # We don't know which user sent this, so we may as well just return.
  return if ($type eq "probe"); # We don't want to log probes
	
# Ignorable JIDs. (Certain "noisy" chatbot services come to mind!)
  foreach my $ignoreable_jid (@{$config{site}{ignore_jids}})
  {
    if ($from =~ /$ignoreable_jid/)
    {
#     print("presenceCB: ignoring ($ignoreable_jid)");
      return;
    }
  }

# Log this occurance into the database
  $sqlquery = "INSERT INTO presence (presence_from,presence_type, presence_priority,";
  $sqlquery .= "presence_status,presence_show) VALUES (";
  $sqlquery .= $dbh->quote($from). ",";
  $sqlquery .= $dbh->quote($type). ",";
  $sqlquery .= $dbh->quote($priority). ",";
  $sqlquery .= $dbh->quote($status). ",";
  $sqlquery .= $dbh->quote($show). ")";
  my $sth = $dbh->prepare($sqlquery);
  $sth->execute;		
}
	
#__________________________________________
#                                          |
#   |~|_        -- Funky Penguin --        |
#   o-o    Corporate GNU/Linux Solutions   |
#   /V\                                    |
#  // \\                                   |
# /(   )\  ..Work smarter, not harder..    |
#  ^-~-^     [www.funkypenguin.co.za]      |
###########################################|
# Function   : log_message
# Purpose    : Log "message" type nodes
##########################################*/
sub log_message
{
  my $node = shift;
  my %message;
  my $sqlquery;
  my $is_local = 0;	
  my $header = $node->get_children()->[0];
  my $body = $header->get_children()->[0];

  return if (!$body->data()); # Don't log empty messages, or "non-<message>" messages :)
  return if ($header->attr('sc:sm')); # Avoid duplicating messages.
  return if ($header->attr('sm:sm')); # Don't duplicate component messages. i.e. AIM-t		
  $message{'to'} = $header->attr('to');
  $message{'from'} = $header->attr('from');
  $message{'type'} = $header->attr('type');
  $message{'subject'} = $header->attr('subject');
  $message{'thread'} = $header->attr('thread');
  $message{'error'} = $header->attr('error');
  $message{'errorcode'} = $header->attr('errorcode');
  $message{'body'} = $body->data();				

# Manupilate SCSM "memory"
  my $scsm = $header->attr('scsm');
  $scsm_sessions{'$scsm'} = $message{'from'};

# Ignorable JIDs. (Certain "noisy" chatbot services come to mind!)
  foreach my $ignoreable_jid (@{$config{site}{ignore_jids}})
  {
    if (($message{'to'} =~ /$ignoreable_jid/) || ($message{'from'} =~ /$ignoreable_jid/))
    {
#      print("receiveCB: ignoring ($ignoreable_jid)");
      return;
    }
  }

############# Mask confidential messages ########################
  my $to_local;
  my $from_local;

  foreach my $confidential_jid (@{$config{site}{confidential_jids}})
  {
    $to_local = $confidential_jid if ($message{'to'} =~ /$confidential_jid/);
    $from_local = $confidential_jid if ($message{'from'} =~ /$confidential_jid/);
  }

  if (($to_local) && ($from_local))
  {
    $message{'body'} = "Confidential ($from_local --> $to_local)";
  }
    
############# Mask depending on privacy level ########################
  $to_local = "";
  $from_local = "";

  if ($config{site}{privacy} == 3)
  {
    foreach my $local_domain (@{$config{site}{local_domains}})
    {
      $to_local = $1 if ($message{'to'} =~ /([^@]+)\@$local_domain/);
      $from_local = $1 if ($message{'from'} =~ /([^@]+)\@$local_domain/);
    }
    $message{'to'} =~ s/([^@]+)(\@.*)/privacy-level-3$2/ if (!$to_local);
    $message{'from'} =~ s/([^@]+)(\@.*)/privacy-level-3$2/ if (!$from_local);
    $message{'body'} = "privacy-level-3";
  } 

  elsif ($config{site}{privacy} == 2)
  {
    foreach my $local_domain (@{$config{site}{local_domains}})
    {
      $to_local = $1 if ($message{'to'} =~ /([^@]+)\@$local_domain/);
      $from_local = $1 if ($message{'from'} =~ /([^@]+)\@$local_domain/);
    }
    $message{'to'} =~ s/([^@]+)(\@.*)/privacy-level-2$2/ if (!$to_local);
    $message{'from'} =~ s/([^@]+)(\@.*)/privacy-level-2$2/ if (!$from_local);
    $message{'body'} = "privacy-level-2" if ((!$from_local) || (!$to_local));
  }

  elsif ($config{site}{privacy} == 1)
  {
    foreach my $local_domain (@{$config{site}{local_domains}})
    {
      $to_local = $1 if ($message{'to'} =~ /([^@]+)\@$local_domain/);
      $from_local = $1 if ($message{'from'} =~ /([^@]+)\@$local_domain/);
    }
    $message{'to'} =~ s/([^@]+)(\@.*)/privacy-level-1$2/ if (!$to_local);
    $message{'from'} =~ s/([^@]+)(\@.*)/privacy-level-1$2/ if (!$from_local);
  }
############# End Mask depending on privacy level ########################

# Quote-ify all the variables we're going to be sticking into the database
  $message{'to'} = $dbh->quote($message{'to'});
  $message{'from'} = $dbh->quote($message{'from'});
  $message{'id'} = $dbh->quote($message{'id'});
  $message{'type'} = $dbh->quote($message{'type'});
  $message{'body'} = $dbh->quote($message{'body'});
  $message{'subject'} = $dbh->quote($message{'subject'});
  $message{'thread'} = $dbh->quote($message{'thread'});
  $message{'error'} = $dbh->quote($message{'error'});
  $message{'errorcode'}	= $dbh->quote($message{'errorcode'});		
  $sqlquery = "INSERT INTO message (message_to,message_from,message_id,";
  $sqlquery .= "message_type,message_body,message_subject,message_thread,";
  $sqlquery .= "message_error,message_errorcode) VALUES (";
  $sqlquery .= $message{'to'}. ",";
  $sqlquery .= $message{'from'}. ",";
  $sqlquery .= $message{'id'}. ",";
  $sqlquery .= $message{'type'}. ",";
  $sqlquery .= $message{'body'}. ",";
  $sqlquery .= $message{'subject'}. ",";
  $sqlquery .= $message{'thread'}. ",";
  $sqlquery .= $message{'error'}. ",";
  $sqlquery .= $message{'errorcode'}. ")";
  my $sth = $dbh->prepare($sqlquery);
  $sth->execute;
}		

# +----------------------------------------------------------------------------+
# | Connect to Database Server                                                 |
# +----------------------------------------------------------------------------+
sub connectDatabase
{
  $dbh = DBI->connect("DBI:mysql:database=$config{mysql}->{dbname}:$config{mysql}->{server}",
  $config{mysql}->{username}, $config{mysql}->{password});

  if (!defined($dbh))
  {
    return 0;
  }

  $dbh->trace(2) if (($config{debug}->{level} > 0) && defined($dbh));
  return 1;
}

# Run the POE kernel!
POE::Kernel->run();
