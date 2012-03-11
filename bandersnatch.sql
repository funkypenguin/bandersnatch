# Create the bandersnatch database

CREATE DATABASE `bandersnatch`; 

# Create the bandersnatch user
USE mysql;

INSERT INTO `user` ( `Host` , `User` , `Password` , `Select_priv` , `Insert_priv` , `Update_priv` , `Delete_priv` , `Create_priv` , `Drop_priv` , `Reload_priv` , `Shutdown_priv` , `Process_priv` , `File_priv` , `Grant_priv` , `References_priv` , `Index_priv` , `Alter_priv` )
VALUES (
'localhost', 'bandersnatch', PASSWORD( 'bandersnatch' ) , 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'N', 'N'
);


# Give the bandersnatch user access to the bandersnatch database:

INSERT INTO `db` ( `Host` , `Db` , `User` , `Select_priv` , `Insert_priv` , `Update_priv` , `Delete_priv` , `Create_priv` , `Drop_priv` , `Grant_priv` , `References_priv` , `Index_priv` , `Alter_priv` )
VALUES (
'localhost', 'bandersnatch', 'bandersnatch', 'Y', 'Y', 'Y', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N'
);


# Now create the bandersnatch database structure

USE bandersnatch;
#
# Table structure for table `auth`
#

DROP TABLE IF EXISTS `auth`;
CREATE TABLE `auth` (
  `username` varchar(50) NOT NULL default '',
  `PASSWORD` varchar(32) NOT NULL default '',
  PRIMARY KEY  (`username`),
  KEY `PASSWORD` (`PASSWORD`)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Dumping data for table `auth`
#

INSERT INTO `auth` (`username`, `PASSWORD`) VALUES
('admin', 'd6d60d51e2392ba9e902a192470991e8');

#
# Table structure for table `message`
#

DROP TABLE IF EXISTS `message`;
CREATE TABLE `message` (
  `message_to` varchar(255) NOT NULL default '',
  `message_from` varchar(255) NOT NULL default '',
  `message_id` varchar(255) default NULL,
  `message_type` varchar(255) default NULL,
  `message_body` text,
  `message_subject` varchar(255) default NULL,
  `message_thread` varchar(255) default NULL,
  `message_error` varchar(255) default NULL,
  `message_errorcode` int(5) default NULL,
  `message_timestamp` timestamp(14) NOT NULL,
  KEY `message_to` (`message_to`),
  KEY `message_from` (`message_from`),
  KEY `message_timestamp` (`message_timestamp`)
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Table structure for table `presence`
#

DROP TABLE IF EXISTS `presence`;
CREATE TABLE `presence` (
  `presence_from` varchar(255) NOT NULL default '',
  `presence_type` varchar(20) NOT NULL default 'available',
  `presence_status` varchar(255) default NULL,
  `presence_priority` int(11) default NULL,
  `presence_show` varchar(20) default NULL,
  `presence_timestamp` timestamp(14) NOT NULL
) TYPE=MyISAM;
# --------------------------------------------------------

#
# Table structure for table `user`
#

DROP TABLE IF EXISTS `user`;
CREATE TABLE `user` (
  `user_jid` varchar(255) NOT NULL default '',
  `user_status` varchar(15) NOT NULL default '',
  `user_subscribed` enum('Y','N') NOT NULL default 'N',
  `user_lastactive` timestamp(14) NOT NULL,
  PRIMARY KEY  (`user_jid`)
) TYPE=MyISAM;

