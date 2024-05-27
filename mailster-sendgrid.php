<?php
/*
Plugin Name: Mailster SendGrid Integration
Requires Plugins: mailster
Plugin URI: https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=plugin&utm_term=SendGrid
Description: Uses SendGrid to deliver emails for the Mailster Newsletter Plugin for WordPress.
Version: 2.1.1
Author: EverPress
Author URI: https://mailster.co
Text Domain: mailster-sendgrid
License: GPLv2 or later
*/


define( 'MAILSTER_SENDGRID_VERSION', '2.1.1' );
define( 'MAILSTER_SENDGRID_REQUIRED_VERSION', '4.0' );
define( 'MAILSTER_SENDGRID_FILE', __FILE__ );

require_once __DIR__ . '/classes/sendgrid.class.php';
new MailsterSendGrid();
