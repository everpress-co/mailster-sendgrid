<?php
/*
Plugin Name: Mailster SendGrid Integration
Plugin URI: http://rxa.li/mailster?utm_campaign=wporg&utm_source=Mailster+SendGrid+Integration
Description: Uses SendGrid to deliver emails for the Mailster Newsletter Plugin for WordPress.
Version: 1.0.1
Author: revaxarts.com
Author URI: https://mailster.co
Text Domain: mailster-sendgrid
License: GPLv2 or later
*/


define( 'MAILSTER_SENDGRID_VERSION', '1.0' );
define( 'MAILSTER_SENDGRID_REQUIRED_VERSION', '2.2' );
define( 'MAILSTER_SENDGRID_ID', 'sendgrid' );


class MailsterSendGird {

	private $plugin_path;
	private $plugin_url;

	public function __construct() {

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );

		load_plugin_textdomain( 'mailster-sendgrid' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}


	/*
	 * init the plugin
	 *
	 * @access public
	 * @return void
	 */
	public function init() {

		if ( ! function_exists( 'mailster' ) ) {

			add_action( 'admin_notices', array( &$this, 'notice' ) );

		} else {

			add_filter( 'mailster_delivery_methods', array( &$this, 'delivery_method' ) );
			add_action( 'mailster_deliverymethod_tab_sendgrid', array( &$this, 'deliverytab' ) );

			add_filter( 'mailster_verify_options', array( &$this, 'verify_options' ) );

			if ( mailster_option( 'deliverymethod' ) == MAILSTER_SENDGRID_ID ) {
				add_action( 'mailster_initsend', array( &$this, 'initsend' ) );
				add_action( 'mailster_presend', array( &$this, 'presend' ) );
				add_action( 'mailster_dosend', array( &$this, 'dosend' ) );
				add_action( 'mailster_sendgrid_cron', array( &$this, 'reset' ) );
				add_action( 'mailster_cron_worker', array( &$this, 'check_bounces' ), -1 );
				add_action( 'mailster_check_bounces', array( &$this, 'check_bounces' ) );
				add_action( 'mailster_section_tab_bounce', array( &$this, 'section_tab_bounce' ) );
			}
		}

	}


	/**
	 * initsend function.
	 *
	 * uses mailster_initsend hook to set initial settings
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function initsend( $mailobject ) {

		$method = mailster_option( MAILSTER_SENDGRID_ID . '_api' );

		if ( $method == 'smtp' ) {

			$secure = mailster_option( MAILSTER_SENDGRID_ID . '_secure' );

			$mailobject->mailer->Mailer = 'smtp';
			$mailobject->mailer->SMTPSecure = $secure ? 'ssl' : 'none';
			$mailobject->mailer->Host = 'smtp.sendgrid.net';
			$mailobject->mailer->Port = $secure ? 465 : 587;
			$mailobject->mailer->SMTPAuth = true;
			$mailobject->mailer->Username = mailster_option( MAILSTER_SENDGRID_ID . '_user' );
			$mailobject->mailer->Password = mailster_option( MAILSTER_SENDGRID_ID . '_pwd' );
			$mailobject->mailer->SMTPKeepAlive = true;

		} elseif ( $method == 'web' ) {

		}

		// sendgrid will handle DKIM integration
		$mailobject->dkim = false;

	}


	/**
	 * presend function.
	 *
	 * uses the mailster_presend hook to apply settings before each mail
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function presend( $mailobject ) {

		$method = mailster_option( MAILSTER_SENDGRID_ID . '_api' );

		$xsmtpapi['unique_args'] = array(
			'subject' => $mailobject->subject,
			'subscriberID' => $mailobject->subscriberID,
			'campaignID' => $mailobject->campaignID,
		);
		$categories = mailster_option( MAILSTER_SENDGRID_ID . '_categories' );
		if ( ! empty( $categories ) ) {
			$xsmtpapi['category'] = array_slice( array_map( 'trim', explode( ',', $categories ) ), 0, 10 );
		}

		if ( $method == 'smtp' ) {

			if ( ! empty( $xsmtpapi ) ) {
				$mailobject->add_header( 'X-SMTPAPI', json_encode( $xsmtpapi ) );
			}

			// use pre_send from the main class
			$mailobject->pre_send();

		} elseif ( $method == 'web' ) {

			// embedding images doesn't work
			$mailobject->embed_images = false;

			$mailobject->pre_send();

			$mailobject->sendgrid_object = array(
				'from' => $mailobject->from,
				'fromname' => stripslashes($mailobject->from_name),
				'replyto' => $mailobject->reply_to,
				// doesn't work right now
				// 'returnpath' => $mailobject->bouncemail,
				'to' => $mailobject->to,
				'subject' => stripslashes($mailobject->subject),
				'text' => stripslashes($mailobject->mailer->AltBody),
				'html' => stripslashes($mailobject->mailer->Body),
				'api_user' => mailster_option( MAILSTER_SENDGRID_ID . '_user' ),
				'api_key' => mailster_option( MAILSTER_SENDGRID_ID . '_pwd' ),
				'files' => array(),
				'content' => array(),
				'x-smtpapi' => json_encode( $xsmtpapi ),
			);

			if ( ! empty( $mailobject->headers ) ) {
				$mailobject->sendgrid_object['headers'] = json_encode( $mailobject->headers );
			}

			// currently not working on some clients
			if ( false ) {

				$images = $this->embedd_images( $mailobject->make_img_relative( $mailobject->content ) );

				$mailobject->sendgrid_object['files'] = wp_parse_args( $images['files'] , $mailobject->sendgrid_object['files'] );
				$mailobject->sendgrid_object['content'] = wp_parse_args( $images['content'] , $mailobject->sendgrid_object['content'] );

			}

			if ( ! empty( $mailobject->attachments ) ) {

				$attachments = $this->attachments( $mailobject->attachments );

				$mailobject->sendgrid_object['files'] = wp_parse_args( $attachments['files'] , $mailobject->sendgrid_object['files'] );
				$mailobject->sendgrid_object['content'] = wp_parse_args( $attachments['content'] , $mailobject->sendgrid_object['content'] );

			}
		}

	}


	/**
	 * dosend function.
	 *
	 * uses the ymail_dosend hook and triggers the send
	 *
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function dosend( $mailobject ) {

		$method = mailster_option( MAILSTER_SENDGRID_ID . '_api' );

		if ( $method == 'smtp' ) {

			// use send from the main class
			$mailobject->do_send();

		} elseif ( $method == 'web' ) {

			if ( ! isset( $mailobject->sendgrid_object ) ) {
				$mailobject->set_error( __( 'SendGrid options not defined', 'mailster-sendgrid' ) );
				return false;
			}

			$response = $this->do_call( 'mail.send', $mailobject->sendgrid_object, true );

			// set errors if exists
			if ( isset( $response->errors ) ) {
				$mailobject->set_error( $response->errors );
			}

			if ( isset( $response->message ) && $response->message == 'success' ) {
				$mailobject->sent = true;
			} else {
				$mailobject->sent = false;
			}
		}
	}




	/**
	 * reset function.
	 *
	 * resets the current time
	 *
	 * @access public
	 * @param mixed $message
	 * @return array
	 */
	public function reset() {
		update_option( '_transient__mailster_send_period_timeout', false );
		update_option( '_transient__mailster_send_period', 0 );

	}



	/**
	 * attachments function.
	 *
	 * prepares the array for attachemnts
	 *
	 * @access public
	 * @param unknown $attachments
	 * @return array
	 */
	public function attachments( $attachments ) {

		$return = array(
			'files' => array(),
			'content' => array(),
		);

		foreach ( $attachments as $attachment ) {
			if ( ! file_exists( $attachment ) ) {
				continue;
			}
			$filename = basename( $attachment );

			$return['files'][ $filename ] = file_get_contents( $attachment );
			$return['content'][ $filename ] = $filename;

		}

		return $return;
	}


	/**
	 * embedd_images function.
	 *
	 * prepares the array for embedded images
	 *
	 * @access public
	 * @param mixed $message
	 * @return array
	 */
	public function embedd_images( $message ) {

		$return = array(
			'files' => array(),
			'content' => array(),
			'html' => $message,
		);

		$upload_folder = wp_upload_dir();
		$folder = $upload_folder['basedir'];

		preg_match_all( "/(src|background)=[\"']([^\"']+)[\"']/Ui", $message, $images );

		if ( isset( $images[2] ) ) {

			foreach ( $images[2] as $i => $url ) {
				if ( empty( $url ) ) {
					continue;
				}
				if ( substr( $url, 0, 7 ) == 'http://' ) {
					continue;
				}
				if ( substr( $url, 0, 8 ) == 'https://' ) {
					continue;
				}
				if ( ! file_exists( $folder . '/' . $url ) ) {
					continue;
				}
				$filename = basename( $url );
				$directory = dirname( $url );
				if ( $directory == '.' ) {
					$directory = '';
				}
				$cid = md5( $folder . '/' . $url . time() );
				$return['html'] = str_replace( $url, 'cid:' . $cid, $return['html'] );
				$return['files'][ $filename ] = file_get_contents( $folder . '/' . $url );
				$return['content'][ $filename ] = $cid;
			}
		}
		return $return;
	}




	/**
	 * delivery_method function.
	 *
	 * add the delivery method to the options
	 *
	 * @access public
	 * @param mixed $delivery_methods
	 * @return void
	 */
	public function delivery_method( $delivery_methods ) {
		$delivery_methods[ MAILSTER_SENDGRID_ID ] = 'SendGrid';
		return $delivery_methods;
	}


	/**
	 * deliverytab function.
	 *
	 * the content of the tab for the options
	 *
	 * @access public
	 * @return void
	 */
	public function deliverytab() {

		$verified = mailster_option( MAILSTER_SENDGRID_ID . '_verified' );

?>
		<table class="form-table">
			<?php if ( ! $verified ) { ?>
			<tr valign="top">
				<th scope="row">&nbsp;</th>
				<td><p class="description"><?php echo sprintf( __( 'You need a %s account to use this service!', 'mailster-sendgrid' ), '<a href="http://mbsy.co/sendgrid/63320" class="external">SendGrid</a>' ); ?></p>
				</td>
			</tr>
			<?php }?>
			<tr valign="top">
				<th scope="row"><?php _e( 'SendGrid Username' , 'mailster-sendgrid' ) ?></th>
				<td><input type="text" name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_user]" value="<?php echo esc_attr( mailster_option( MAILSTER_SENDGRID_ID . '_user' ) ); ?>" class="regular-text"></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'SendGrid Password' , 'mailster-sendgrid' ) ?></th>
				<td><input type="password" name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_pwd]" value="<?php echo esc_attr( mailster_option( MAILSTER_SENDGRID_ID . '_pwd' ) ); ?>" class="regular-text" autocomplete="new-password"></td>
			</tr>
			<tr valign="top">
				<th scope="row">&nbsp;</th>
				<td>
					<?php if ( $verified ) : ?>
					<span style="color:#3AB61B">&#10004;</span> <?php esc_html_e( 'Your credentials are ok!', 'mailster-sendgrid' ) ?>
					<?php else : ?>
					<span style="color:#D54E21">&#10006;</span> <?php esc_html_e( 'Your credentials are WRONG!', 'mailster-sendgrid' ) ?>
					<?php endif; ?>

					<input type="hidden" name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_verified]" value="<?php echo $verified ?>">
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Send Emails with' , 'mailster-sendgrid' ) ?></th>
				<td>
				<select name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_api]">
					<option value="web" <?php selected( mailster_option( MAILSTER_SENDGRID_ID . '_api' ), 'web' )?>>WEB API</option>
					<option value="smtp" <?php selected( mailster_option( MAILSTER_SENDGRID_ID . '_api' ), 'smtp' )?>>SMTP API</option>
				</select>
				<?php if ( mailster_option( MAILSTER_SENDGRID_ID . '_api' ) == 'web' ) : ?>
				<span class="description"><?php _e( 'embedded images are not working with the web API!', 'mailster-sendgrid' ); ?></span>
				<?php endif; ?>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Categories' , 'mailster-sendgrid' ) ?></th>
				<td><input type="text" name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_categories]" value="<?php echo esc_attr( mailster_option( MAILSTER_SENDGRID_ID . '_categories' ) ); ?>" class="large-text">
				<p class="howto"><?php echo sprintf( __( 'Define up to 10 %s, separated with commas which get send via SendGrid X-SMTPAPI' , 'mailster-sendgrid' ), '<a href="https://sendgrid.com/docs/API_Reference/SMTP_API/categories.html" class="external">' . __( 'Categories', 'mailster-sendgrid' ) . '</a>' ) ?></p>
			</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Secure Connection' , 'mailster-sendgrid' ) ?></th>
				<td><label><input type="hidden" name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_secure]" value=""><input type="checkbox" name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_secure]" value="1" <?php checked( mailster_option( MAILSTER_SENDGRID_ID . '_secure' ), true )?>> <?php _e( 'use secure connection', 'mailster-sendgrid' ); ?></label></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Bounce Handling via' , 'mailster-sendgrid' ) ?></th>
				<td>
				<select name="mailster_options[<?php echo MAILSTER_SENDGRID_ID ?>_bouncehandling]">
					<option value="sendgrid" <?php selected( mailster_option( MAILSTER_SENDGRID_ID . '_bouncehandling' ), 'sendgrid' )?>>SendGrid</option>
					<option value="mailster" <?php selected( mailster_option( MAILSTER_SENDGRID_ID . '_bouncehandling' ), 'mailster' )?>>Mailster</option>
				</select> <span class="description"><?php _e( 'Mailster cannot handle bounces when the WEB API is used' , 'mailster-sendgrid' ) ?></span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'DKIM' , 'mailster-sendgrid' ) ?></th>
				<td><p class="howto"><?php _e( 'Set the domain to "sendgrid.me" on the "Apps" page at SendGrid.com (default)' , 'mailster-sendgrid' ) ?></p></td>
			</tr>
		</table>

	<?php

	}


	/**
	 * verify_options function.
	 *
	 * some verification if options are saved
	 *
	 * @access public
	 * @param unknown $user (optional)
	 * @param unknown $pwd  (optional)
	 * @return void
	 */
	public function verify( $user = '', $pwd = '' ) {

		$url = ( mailster_option( MAILSTER_SENDGRID_ID . '_secure' ) ? 'https' : 'http' ) . '://api.sendgrid.com/api/profile.get.json';

		if ( ! $user ) {
			$user = mailster_option( MAILSTER_SENDGRID_ID . '_user' );
		}
		if ( ! $pwd ) {
			$pwd = mailster_option( MAILSTER_SENDGRID_ID . '_pwd' );
		}

		$data = array(
			'api_user' => $user,
			'api_key' => $pwd,
		);

		$response = wp_remote_get( add_query_arg( $data, $url ), array(
			'timeout' => 5,
		) );

		$response = wp_remote_retrieve_body( $response );
		$response = json_decode( $response );

		if ( isset( $response->error ) ) {
			return false;
		} elseif ( isset( $response[0]->username ) ) {
			return true;
		}

		return false;
	}



	/**
	 * verify_options function.
	 *
	 * some verification if options are saved
	 *
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function verify_options( $options ) {

		if ( $timestamp = wp_next_scheduled( 'mailster_sendgrid_cron' ) ) {
			wp_unschedule_event( $timestamp, 'mailster_sendgrid_cron' );
		}

		if ( $options['deliverymethod'] == MAILSTER_SENDGRID_ID ) {

			$old_user = mailster_option( MAILSTER_SENDGRID_ID . '_user' );
			$old_pwd = mailster_option( MAILSTER_SENDGRID_ID . '_pwd' );

			if ( $old_user != $options[ MAILSTER_SENDGRID_ID . '_user' ]
				|| $old_pwd != $options[ MAILSTER_SENDGRID_ID . '_pwd' ]
				|| ! mailster_option( MAILSTER_SENDGRID_ID . '_verified' ) ) {

				$options[ MAILSTER_SENDGRID_ID . '_verified' ] = $this->verify( $options[ MAILSTER_SENDGRID_ID . '_user' ], $options[ MAILSTER_SENDGRID_ID . '_pwd' ] );

				if ( $options[ MAILSTER_SENDGRID_ID . '_verified' ] ) {
					add_settings_error( 'mailster_options', 'mailster_options', sprintf( __( 'Please update your sending limits! %s', 'mailster-sendgrid' ), '<a href="http://sendgrid.com/account/overview" class="external">SendGrid Dashboard</a>' ) );

				}
			}

			if ( ! wp_next_scheduled( 'mailster_sendgrid_cron' ) ) {
				// reset on 00:00 PST ( GMT -8 ) == GMT +16
				$timeoffset = strtotime( 'midnight' ) + ( ( 24 -8 ) * HOUR_IN_SECONDS );
				if ( $timeoffset < time() ) {
					$timeoffset + ( 24 * HOUR_IN_SECONDS );
				}
				wp_schedule_event( $timeoffset, 'daily', 'mailster_sendgrid_cron' );
			}

			if ( $options[ MAILSTER_SENDGRID_ID . '_api' ] == 'smtp' ) {
				if ( function_exists( 'fsockopen' ) ) {
					$host = 'smtp.sendgrid.net';
					$port = $options[ MAILSTER_SENDGRID_ID . '_secure' ] ? 465 : 587;
					$conn = fsockopen( $host, $port, $errno, $errstr, 15 );

					if ( is_resource( $conn ) ) {

						fclose( $conn );

					} else {

						add_settings_error( 'mailster_options', 'mailster_options', sprintf( __( 'Not able to use SendGrid with SMTP API cause of the blocked port %s! Please send with the WEB API or choose a different delivery method!', 'mailster-sendgrid' ), $port ) );

					}
				}
			} else {

				if ( $options[ MAILSTER_SENDGRID_ID . '_bouncehandling' ] != 'sendgrid' ) {
					add_settings_error( 'mailster_options', 'mailster_options', __( 'It is currently not possible to handle bounces with Mailster when using the WEB API', 'mailster-sendgrid' ) );
					$options[ MAILSTER_SENDGRID_ID . '_bouncehandling' ] = 'sendgird';
				}
			}

			if ( $options[ MAILSTER_SENDGRID_ID . '_bouncehandling' ] != 'sendgrid' ) {
				add_settings_error( 'mailster_options', 'mailster_options', sprintf( __( 'Please make sure your SendGrid Account "preserve headers" otherwise Mailster is not able to handle bounces', 'mailster-sendgrid' ), $port ) );
			}
		}

		return $options;
	}


	/**
	 * check_bounces function.
	 *
	 * checks for bounces and reset them if needed
	 *
	 * @access public
	 * @return void
	 */
	public function check_bounces() {

		if ( get_transient( 'mailster_check_bounces_lock' ) || mailster_option( MAILSTER_SENDGRID_ID . '_bouncehandling' ) != 'sendgrid' ) {
			return false;
		}

		// check bounces only every five minutes
		set_transient( 'mailster_check_bounces_lock', true, mailster_option( 'bounce_check', 5 ) * 60 );

		$collection = array();

		$response = $this->do_call( 'bounces.get', array( 'date' => 1, 'limit' => 200 ) );

		if ( is_wp_error( $response ) ) {
			$collection['bounces'] = array();
		} else {
			$collection['bounces'] = (array) $response->body;
		}

		$response = $this->do_call( 'blocks.get', array( 'date' => 1, 'limit' => 200 ) );

		if ( is_wp_error( $response ) ) {
			$collection['blocks'] = array();
		} else {
			$collection['blocks'] = (array) $response->body;
		}

		$response = $this->do_call( 'spamreports.get', array( 'date' => 1, 'limit' => 200 ) );

		if ( is_wp_error( $response ) ) {
			$collection['spamreports'] = array();
		} else {
			$collection['spamreports'] = (array) $response->body;
		}

		$response = $this->do_call( 'unsubscribes.get', array( 'date' => 1, 'limit' => 200 ) );

		if ( is_wp_error( $response ) ) {
			$collection['unsubscribes'] = array();
		} else {
			$collection['unsubscribes'] = (array) $response->body;
		}

		foreach ( $collection as $type => $messages ) {

			foreach ( $messages as $message ) {

				$subscriber = mailster( 'subscribers' )->get_by_mail( $message->email );

				// only if user exists
				if ( $subscriber ) {

					$reseted = false;
					$campaigns = mailster( 'subscribers' )->get_sent_campaigns( $subscriber->ID );

					if ( $type == 'unsubscribes' ) {

						if ( mailster( 'subscribers' )->unsubscribe( $subscriber->ID, isset( $campaigns[0] ) ? $campaigns[0]->campaign_id : null ) ) {
							$response = $this->do_call( $type . '.delete', array( 'email' => $message->email ), true );
							$reseted = isset( $response->message ) && $response->message == 'success';
						}
					} else {

						// any code with 5 eg 5.x.x or a spamreport
						$is_hard_bounce = $type == 'spamreports' || substr( $message->status, 0, 1 ) == 5;

						foreach ( $campaigns as $i => $campaign ) {

							// only the last 20 campaigns
							if ( $i >= 20 ) {
								break;
							}

							if ( mailster( 'subscribers' )->bounce( $subscriber->ID, $campaign->campaign_id, $is_hard_bounce ) ) {
								$response = $this->do_call( $type . '.delete', array( 'email' => $message->email ), true );
								$reseted = isset( $response->message ) && $response->message == 'success';
							}
						}
					}

					if ( ! $reseted ) {
						$response = $this->do_call( $type . '.delete', array( 'email' => $message->email ), true );
						$reseted = isset( $response->message ) && $response->message == 'success';
					}
				} else {
					// remove user from the list
					$response = $this->do_call( $type . '.delete', array( 'email' => $message->email ), true );
					$count++;
				}
			}
		}

	}


	/**
	 * do_call function.
	 *
	 * makes a request to the sendgrid endpoint and returns the result
	 *
	 * @access public
	 * @param mixed $path
	 * @param array $data     (optional) (default: array())
	 * @param bool  $bodyonly (optional) (default: false)
	 * @param int   $timeout  (optional) (default: 5)
	 * @return void
	 */
	public function do_call( $path, $data = array(), $bodyonly = false, $timeout = 5 ) {

		$url = ( ! mailster_option( MAILSTER_SENDGRID_ID . '_secure' ) ? 'http' : 'https' ) . '://api.sendgrid.com/api/' . $path . '.json';
		if ( is_bool( $data ) ) {
			$bodyonly = $data;
			$data = array();
		}

		$user = mailster_option( MAILSTER_SENDGRID_ID . '_user' );
		$pwd = mailster_option( MAILSTER_SENDGRID_ID . '_pwd' );

		if ( $path == 'mail.send' ) {

			$url = add_query_arg( array(
				'api_user' => $user,
				'api_key' => $pwd,
			), $url );

			$response = wp_remote_post( $url, array(
				'timeout' => $timeout,
				'body' => $data,
			) );

		} else {

			$data = wp_parse_args( $data, array(
				'api_user' => $user,
				'api_key' => $pwd,
			) );

			$response = wp_remote_get( add_query_arg( $data, $url ), array(
				'timeout' => $timeout,
			) );

		}

		if ( is_wp_error( $response ) ) {

			return $response;

		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $code != 200 ) {
			return new WP_Error( $code, $body->message );
		}

		if ( $bodyonly ) {
			return $body;
		}

		return (object) array(
			'code' => $code,
			'headers' => wp_remote_retrieve_headers( $response ),
			'body' => $body,
		);

	}


	/**
	 * section_tab_bounce function.
	 *
	 * displays a note on the bounce tab (Mailster >= 1.6.2)
	 *
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function section_tab_bounce() {

		if ( mailster_option( MAILSTER_SENDGRID_ID . '_bouncehandling' ) != 'sendgrid' ) {
			return;
		}

	?>
		<div class="error inline"><p><strong><?php _e( 'Bouncing is handled by SendGrid so all your settings will be ignored', 'mailster-sendgrid' ); ?></strong></p></div>

	<?php
	}



	/**
	 * Notice if Mailster is not available
	 *
	 * @access public
	 * @return void
	 */
	public function notice() {
	?>
	<div id="message" class="error">
	  <p>
	   <strong>SendGrid integration for Mailster</strong> requires the <a href="http://rxa.li/mailster?utm_campaign=wporg&utm_source=SendGrid+integration+for+Mailster">Mailster Newsletter Plugin</a>, at least version <strong><?php echo MAILSTER_SENDGRID_REQUIRED_VERSION ?></strong>.
	  </p>
	</div>
	<?php
	}



	/**
	 * activate function
	 *
	 * @access public
	 * @return void
	 */
	public function activate() {

		if ( function_exists( 'mailster' ) ) {

			mailster_notice( sprintf( __( 'Change the delivery method on the %s!', 'mailster-sendgrid' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', false, 'delivery_method' );

			$this->reset();
		}
	}


	/**
	 * deactivate function
	 *
	 * @access public
	 * @return void
	 */
	public function deactivate() {

		if ( function_exists( 'mailster' ) ) {
			if ( mailster_option( 'deliverymethod' ) == MAILSTER_SENDGRID_ID ) {
				mailster_update_option( 'deliverymethod', 'simple' );
				mailster_notice( sprintf( __( 'Change the delivery method on the %s!', 'mailster-sendgrid' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', false, 'delivery_method' );
			}
		}
	}


}


new MailsterSendGird();
