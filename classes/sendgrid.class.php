<?php
/**
 * SendGrid delivery integration for Mailster.
 *
 * @package Mailster_SendGrid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers SendGrid as a Mailster delivery method and handles send and bounce flows.
 */
class MailsterSendGrid {

	/**
	 * Absolute filesystem path to the plugin directory.
	 *
	 * @var string
	 */
	private $plugin_path;

	/**
	 * URL to the plugin directory.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Temporary API key override used during verification.
	 *
	 * @var string|null
	 */
	private $apikey;

	/**
	 * Hooks activation, loads translations, and registers the init callback.
	 */
	public function __construct() {

		$this->plugin_path = plugin_dir_path( MAILSTER_SENDGRID_FILE );
		$this->plugin_url  = plugin_dir_url( MAILSTER_SENDGRID_FILE );

		register_activation_hook( MAILSTER_SENDGRID_FILE, array( &$this, 'activate' ) );
		register_deactivation_hook( MAILSTER_SENDGRID_FILE, array( &$this, 'deactivate' ) );

		load_plugin_textdomain( 'mailster-sendgrid' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}

	/**
	 * Registers Mailster delivery hooks or shows the missing-Mailster notice.
	 *
	 * @return void
	 */
	public function init() {

		if ( ! function_exists( 'mailster' ) ) {

			add_action( 'admin_notices', array( &$this, 'notice' ) );

		} else {

			add_filter( 'mailster_delivery_methods', array( &$this, 'delivery_method' ) );
			add_action( 'mailster_deliverymethod_tab_sendgrid', array( &$this, 'deliverytab' ) );

			add_filter( 'mailster_verify_options', array( &$this, 'verify_options' ) );

			if ( 'sendgrid' === mailster_option( 'deliverymethod' ) ) {
				add_action( 'mailster_initsend', array( &$this, 'initsend' ) );
				add_action( 'mailster_presend', array( &$this, 'presend' ) );
				add_action( 'mailster_dosend', array( &$this, 'dosend' ) );
				add_action( 'mailster_sendgrid_cron', array( &$this, 'reset' ) );
				add_action( 'mailster_cron_worker', array( &$this, 'check_bounces' ), -1 );
				add_action( 'mailster_check_bounces', array( &$this, 'check_bounces' ) );
				add_action( 'mailster_section_tab_bounce', array( &$this, 'section_tab_bounce' ) );

				if ( mailster_option( 'sendgrid_user' ) ) {
					/* translators: %s: HTML link to the Mailster delivery settings page */
					mailster_notice( sprintf( __( 'SendGrid now requires an API key. Please visit the %s and update your information!', 'mailster-sendgrid' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=sendgrid_update_required#delivery">Settings Page</a>' ), 'info', 7200, 'sendgrid_update_required' );
				}
			}
		}
	}

	/**
	 * Applies SendGrid transport settings before a send starts.
	 *
	 * Uses the mailster_initsend hook to set initial settings.
	 *
	 * @param object $mailobject Mailster mail object.
	 * @return void
	 */
	public function initsend( $mailobject ) {

		$method = mailster_option( 'sendgrid_api' );

		if ( 'smtp' === $method ) {

			$secure = mailster_option( 'sendgrid_secure' );

			$mailobject->mailer->Mailer        = 'smtp';
			$mailobject->mailer->SMTPSecure    = $secure ? 'ssl' : 'none';
			$mailobject->mailer->Host          = 'smtp.sendgrid.net';
			$mailobject->mailer->Port          = $secure ? 465 : 587;
			$mailobject->mailer->SMTPAuth      = true;
			$mailobject->mailer->Username      = 'apikey';
			$mailobject->mailer->Password      = mailster_option( 'sendgrid_apikey' );
			$mailobject->mailer->SMTPKeepAlive = true;
		}

		// SendGrid will handle DKIM integration.
		$mailobject->dkim = false;
	}

	/**
	 * Builds the SendGrid payload before each message is sent.
	 *
	 * Uses the mailster_presend hook to apply settings before each mail.
	 *
	 * @param object $mailobject Mailster mail object.
	 * @return void
	 */
	public function presend( $mailobject ) {

		$method = mailster_option( 'sendgrid_api' );

		if ( 'smtp' === $method ) {

			if ( ! empty( $xsmtpapi ) ) {
				$mailobject->add_header( 'X-SMTPAPI', wp_json_encode( $xsmtpapi ) );
			}

			// Use pre_send from the main class.
			$mailobject->pre_send();

		} elseif ( 'web' === $method ) {

			$mailobject->pre_send();

			$recipients = array();

			if ( ! is_array( $mailobject->to ) ) {
				$mailobject->to = array( $mailobject->to );
			}

			foreach ( $mailobject->to as $i => $to ) {
				$recipients[] = (object) array(
					'name'  => $mailobject->to_name[ $i ] ? $mailobject->to_name[ $i ] : null,
					'email' => $mailobject->to[ $i ] ? $mailobject->to[ $i ] : null,
				);
			}

			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Mailster mail object properties.
			$mailobject->sendgrid_object = array(
				'personalizations' => array(
					array(
						'to' => $recipients,
					),
				),
				'from'             => array(
					'email' => $mailobject->from,
					'name'  => $mailobject->from_name,
				),
				'subject'          => $mailobject->subject,
				'content'          => array(
					array(
						'type'  => 'text/plain',
						'value' => $mailobject->mailer->AltBody,
					),
					array(
						'type'  => 'text/html',
						'value' => $mailobject->mailer->Body,
					),
				),
				'custom_args'      => (object) array(
					'mailster_id'   => (string) mailster_option( 'ID' ),
					'campaign_id'   => (string) $mailobject->campaignID,
					'index'         => (string) $mailobject->index,
					'subscriber_id' => (string) $mailobject->subscriberID,
					'message_id'    => (string) $mailobject->messageID,
				),

			);
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			$reply_to = is_array( $mailobject->reply_to ) ? reset( $mailobject->reply_to ) : $mailobject->reply_to;

			if ( $reply_to ) {
				$mailobject->sendgrid_object['reply_to'] = array(
					'email' => $reply_to,
				);
			}

			$categories = mailster_option( 'sendgrid_categories' );
			if ( ! empty( $categories ) ) {
				$mailobject->sendgrid_object['categories'] = array_slice( array_map( 'trim', explode( ',', $categories ) ), 0, 10 );
			}

			if ( ! empty( $mailobject->headers ) ) {
				if ( isset( $mailobject->headers['X-Mailster-Campaign'] ) ) {
					$mailobject->headers['X-Mailster-Campaign'] = (string) $mailobject->headers['X-Mailster-Campaign'];
				}
				$mailobject->sendgrid_object['headers'] = $mailobject->headers;
			}

			$attachments = $mailobject->mailer->getAttachments();

			if ( ! empty( $attachments ) ) {
				$attachments_holder = array();

				foreach ( $attachments as $attachment ) {
					if ( file_exists( $attachment[0] ) ) {
						$object = (object) array(
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Local attachment path; SendGrid API requires a base64 payload.
							'content'     => base64_encode( file_get_contents( $attachment[0] ) ),
							'filename'    => $attachment[1],
							'type'        => $attachment[4],
							'disposition' => $attachment[6],
						);

						if ( 'inline' === $attachment[6] ) {
							$object->content_id = $attachment[7];
						}

						$attachments_holder[] = $object;
					}
				}

				if ( ! empty( $attachments_holder ) ) {
					$mailobject->sendgrid_object['attachments'] = $attachments_holder;
				}
			}
		}
	}

	/**
	 * Sends the current message through SMTP or the SendGrid Web API.
	 *
	 * Uses the mailster_dosend hook and triggers the send.
	 *
	 * @param object $mailobject Mailster mail object.
	 * @return void
	 */
	public function dosend( $mailobject ) {

		$method = mailster_option( 'sendgrid_api' );

		if ( 'smtp' === $method ) {

			// Use send from the main class.
			$mailobject->do_send();

		} elseif ( 'web' === $method ) {

			if ( ! isset( $mailobject->sendgrid_object ) ) {
				$mailobject->set_error( __( 'SendGrid options not defined', 'mailster-sendgrid' ) );
				return;
			}
			if ( empty( $mailobject->sendgrid_object['subject'] ) ) {
				$mailobject->set_error( __( 'SendGrid requires a subject', 'mailster-sendgrid' ) );
				return;
			}

			$response = $this->do_post( 'mail/send', $mailobject->sendgrid_object );

			if ( is_wp_error( $response ) ) {
				$mailobject->set_error( $response->get_error_message() );
				$mailobject->sent = false;
			} else {
				$mailobject->sent = true;
			}
		}
	}

	/**
	 * Clears Mailster's send-period counters.
	 *
	 * @return void
	 */
	public function reset() {
		update_option( '_transient__mailster_send_period_timeout', false );
		update_option( '_transient__mailster_send_period', 0 );
	}

	/**
	 * Prepares embedded images for a message body.
	 *
	 * @param string $message Message HTML.
	 * @return array{files: array<string, string>, content: array<string, string>, html: string}
	 */
	public function embedd_images( $message ) {

		$return = array(
			'files'   => array(),
			'content' => array(),
			'html'    => $message,
		);

		$upload_folder = wp_upload_dir();
		$folder        = $upload_folder['basedir'];

		preg_match_all( "/(src|background)=[\"']([^\"']+)[\"']/Ui", $message, $images );

		if ( isset( $images[2] ) ) {

			foreach ( $images[2] as $i => $url ) {
				if ( empty( $url ) ) {
					continue;
				}
				if ( 'http://' === substr( $url, 0, 7 ) ) {
					continue;
				}
				if ( 'https://' === substr( $url, 0, 8 ) ) {
					continue;
				}
				if ( ! file_exists( $folder . '/' . $url ) ) {
					continue;
				}
				$filename  = basename( $url );
				$directory = dirname( $url );
				if ( '.' === $directory ) {
					$directory = '';
				}
				$cid            = md5( $folder . '/' . $url . time() );
				$return['html'] = str_replace( $url, 'cid:' . $cid, $return['html'] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload path.
				$return['files'][ $filename ]   = file_get_contents( $folder . '/' . $url );
				$return['content'][ $filename ] = $cid;
			}
		}
		return $return;
	}

	/**
	 * Adds SendGrid to Mailster's delivery method list.
	 *
	 * @param array<string, string> $delivery_methods Delivery method labels keyed by slug.
	 * @return array<string, string>
	 */
	public function delivery_method( $delivery_methods ) {
		$delivery_methods['sendgrid'] = 'SendGrid';
		return $delivery_methods;
	}

	/**
	 * Renders the SendGrid delivery settings tab.
	 *
	 * @return void
	 */
	public function deliverytab() {

		$verified = mailster_option( 'sendgrid_verified' );

		include $this->plugin_path . '/views/settings.php';
	}

	/**
	 * Checks whether an API key can read SendGrid scopes.
	 *
	 * @param string|null $apikey API key to verify. Null uses the stored key.
	 * @return bool
	 */
	public function verify( $apikey = null ) {

		$this->apikey = $apikey;

		$response = $this->do_get( 'scopes' );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Verifies saved options and schedules the daily SendGrid reset.
	 *
	 * @param array<string, mixed> $options Mailster options being saved.
	 * @return array<string, mixed>
	 */
	public function verify_options( $options ) {

		$timestamp = wp_next_scheduled( 'mailster_sendgrid_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mailster_sendgrid_cron' );
		}

		if ( 'sendgrid' === $options['deliverymethod'] ) {

			$old_apikey = mailster_option( 'sendgrid_apikey' );

			if ( $old_apikey !== $options['sendgrid_apikey']
				|| ! mailster_option( 'sendgrid_verified' ) || ! $options['sendgrid_apikey'] ) {

				$options['sendgrid_verified'] = $this->verify( $options['sendgrid_apikey'] );

				if ( $options['sendgrid_verified'] ) {
					/* translators: %s: HTML link to the SendGrid billing page */
					add_settings_error( 'mailster_options', 'mailster_options', sprintf( __( 'Please update your sending limits! %s', 'mailster-sendgrid' ), '<a href="https://app.sendgrid.com/settings/billing" class="external">SendGrid Dashboard</a>' ) );

				}
			}

			if ( ! wp_next_scheduled( 'mailster_sendgrid_cron' ) ) {
				// Reset on 00:00 PST ( GMT -8 ) == GMT +16.
				$timeoffset = strtotime( 'midnight' ) + ( ( 24 - 8 ) * HOUR_IN_SECONDS );
				if ( $timeoffset < time() ) {
					$timeoffset + ( 24 * HOUR_IN_SECONDS );
				}
				wp_schedule_event( $timeoffset, 'daily', 'mailster_sendgrid_cron' );
			}

			if ( 'smtp' === $options['sendgrid_api'] ) {
				if ( function_exists( 'fsockopen' ) ) {
					$host = 'smtp.sendgrid.net';
					$port = $options['sendgrid_secure'] ? 465 : 587;
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen -- SMTP port reachability probe.
					$conn = fsockopen( $host, $port, $errno, $errstr, 15 );

					if ( is_resource( $conn ) ) {

						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with the fsockopen probe above.
						fclose( $conn );

					} else {

						/* translators: %s: blocked SMTP port number */
						add_settings_error( 'mailster_options', 'mailster_options', sprintf( __( 'Not able to use SendGrid with SMTP API cause of the blocked port %s! Please send with the WEB API or choose a different delivery method!', 'mailster-sendgrid' ), $port ) );

					}
				}
			} elseif ( 'sendgrid' !== $options['sendgrid_bouncehandling'] ) {

				add_settings_error( 'mailster_options', 'mailster_options', __( 'It is currently not possible to handle bounces with Mailster when using the WEB API', 'mailster-sendgrid' ) );
				$options['sendgrid_bouncehandling'] = 'sendgrid';
			}

			if ( 'sendgrid' !== $options['sendgrid_bouncehandling'] ) {
				add_settings_error( 'mailster_options', 'mailster_options', sprintf( __( 'Please make sure your SendGrid Account "preserve headers" otherwise Mailster is not able to handle bounces', 'mailster-sendgrid' ), $port ) );
			}
		}

		return $options;
	}

	/**
	 * Imports SendGrid suppressions and applies them as Mailster bounces.
	 *
	 * @return bool
	 */
	public function check_bounces() {

		if ( get_transient( 'mailster_check_bounces_lock' ) || ! mailster_option( 'sendgrid_verified' ) ) {
			return false;
		}

		$now = time();

		$last_bounce_check = get_transient( '_mailster_sendgrid_last_bounce_check' );
		if ( ! $last_bounce_check ) {
			set_transient( '_mailster_sendgrid_last_bounce_check', $now );
			$last_bounce_check = $now;
		}

		$collection    = array();
		$errormessages = array();

		$response = $this->do_get( 'suppression/bounces', array( 'start_time' => $last_bounce_check ) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: SendGrid API error message */
			$errormessages[]       = sprintf( __( 'Cannot read Bounces: %s', 'mailster-sendgrid' ), $response->get_error_message() );
			$collection['bounces'] = array();
		} else {
			$collection['bounces'] = (array) $response;
		}

		$response = $this->do_get( 'suppression/blocks', array( 'start_time' => $last_bounce_check ) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: SendGrid API error message */
			$errormessages[]      = sprintf( __( 'Cannot read Blocks: %s', 'mailster-sendgrid' ), $response->get_error_message() );
			$collection['blocks'] = array();
		} else {
			$collection['blocks'] = (array) $response;
		}

		$response = $this->do_get( 'suppression/spam_reports', array( 'start_time' => $last_bounce_check ) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: SendGrid API error message */
			$errormessages[]            = sprintf( __( 'Cannot read Spam reports: %s', 'mailster-sendgrid' ), $response->get_error_message() );
			$collection['spam_reports'] = array();
		} else {
			$collection['spam_reports'] = (array) $response;
		}

		$response = $this->do_get( 'suppression/unsubscribes', array( 'start_time' => $last_bounce_check ) );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: SendGrid API error message */
			$errormessages[]            = sprintf( __( 'Cannot read Unsubscribes: %s', 'mailster-sendgrid' ), $response->get_error_message() );
			$collection['unsubscribes'] = array();
		} else {
			$collection['unsubscribes'] = (array) $response;
		}

		foreach ( $collection as $type => $messages ) {

			foreach ( $messages as $message ) {

				$subscriber = mailster( 'subscribers' )->get_by_mail( $message->email );

				// Only if the subscriber exists and is active.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Mailster subscriber object.
				if ( $subscriber && 1 === (int) $subscriber->status ) {

					$campaigns    = mailster( 'subscribers' )->get_sent_campaigns( $subscriber->ID );
					$campaigns    = array_reverse( $campaigns );
					$campaign_ids = wp_list_pluck( $campaigns, 'campaign_id' );

					if ( 'unsubscribes' === $type ) {

						$campaign_id = isset( $campaigns[0] ) ? $campaigns[0]->campaign_id : null;

						mailster( 'subscribers' )->unsubscribe( $subscriber->ID, $campaign_id );

					} else {

						// Any code with 5, for example 5.x.x, or a spam report or block.
						$is_hard_bounce = in_array( $type, array( 'spam_reports', 'blocks' ), true ) || '5' === substr( (string) $message->status, 0, 1 );

						if ( ! empty( $campaigns ) ) {
							foreach ( $campaigns as $i => $campaign ) {

								// Only the last 20 campaigns.
								if ( $i >= 20 ) {
									break;
								}

								mailster( 'subscribers' )->bounce( $subscriber->ID, $campaign->campaign_id, $is_hard_bounce, $message->reason );

							}
						} else {
							mailster( 'subscribers' )->bounce( $subscriber->ID, null, $is_hard_bounce, $message->reason );
						}
					}
				}
			}
		}

		if ( ! empty( $errormessages ) ) {
			mailster_notice( __( 'There is a problem while requesting suppressions from SendGrid: ', 'mailster-sendgrid' ) . '<br>' . implode( '<br>', $errormessages ), 'error', false, 'sendgrid_suppressions_error' );
		} else {
			mailster_remove_notice( 'sendgrid_suppressions_error' );
		}

		set_transient( '_mailster_sendgrid_last_bounce_check', $now );

		return true;
	}

	/**
	 * Sends a GET request to the SendGrid API.
	 *
	 * @param string               $endpoint API path relative to /v3/.
	 * @param array<string, mixed> $args     Query arguments.
	 * @param int                  $timeout  Request timeout in seconds.
	 * @return mixed|WP_Error
	 */
	public function do_get( $endpoint, $args = array(), $timeout = 15 ) {
		return $this->do_call( 'GET', $endpoint, $args, $timeout );
	}

	/**
	 * Sends a POST request to the SendGrid API.
	 *
	 * @param string               $endpoint API path relative to /v3/.
	 * @param array<string, mixed> $args     JSON body arguments.
	 * @param int                  $timeout  Request timeout in seconds.
	 * @return mixed|WP_Error
	 */
	public function do_post( $endpoint, $args = array(), $timeout = 15 ) {
		return $this->do_call( 'POST', $endpoint, $args, $timeout );
	}

	/**
	 * Sends a DELETE request to the SendGrid API.
	 *
	 * @param string               $endpoint API path relative to /v3/.
	 * @param array<string, mixed> $args     Request arguments.
	 * @param int                  $timeout  Request timeout in seconds.
	 * @return mixed|WP_Error
	 */
	public function do_delete( $endpoint, $args = array(), $timeout = 15 ) {
		return $this->do_call( 'DELETE', $endpoint, $args, $timeout );
	}

	/**
	 * Performs an authenticated request against the SendGrid v3 API.
	 *
	 * @param string               $method   HTTP method. GET and POST are supported.
	 * @param string               $endpoint API path relative to /v3/.
	 * @param array<string, mixed> $args     Query or body arguments.
	 * @param int                  $timeout  Request timeout in seconds.
	 * @return mixed|WP_Error
	 */
	private function do_call( $method, $endpoint, $args = array(), $timeout = 15 ) {

		$url = 'https://api.sendgrid.com/v3/' . $endpoint;

		$args   = wp_parse_args( $args, array() );
		$body   = null;
		$apikey = isset( $this->apikey ) ? $this->apikey : mailster_option( 'sendgrid_apikey' );

		if ( 'GET' === $method ) {
			$url = add_query_arg( $args, $url );
		} elseif ( 'POST' === $method ) {
			$body = wp_json_encode( $args );
		} else {
			return new WP_Error( 'method_not_allowed', 'This method is not allowed' );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $apikey,
			'Content-Type'  => 'application/json',
		);

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'headers' => $headers,
				'timeout' => $timeout,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! in_array( $code, array( 200, 202, 204 ), true ) ) {
			$errors = array_unique( wp_list_pluck( $body->errors, 'message' ) );
			return new WP_Error( $code, implode( ', ', $errors ) );
		}

		return $body;
	}

	/**
	 * Shows a note when SendGrid handles bounces.
	 *
	 * @return void
	 */
	public function section_tab_bounce() {

		if ( 'sendgrid' !== mailster_option( 'sendgrid_bouncehandling' ) ) {
			return;
		}

		?>
		<div class="error inline"><p><strong><?php esc_html_e( 'Bouncing is handled by SendGrid so all your settings will be ignored', 'mailster-sendgrid' ); ?></strong></p></div>

		<?php
	}

	/**
	 * Notice shown when Mailster is not available.
	 *
	 * @return void
	 */
	public function notice() {
		?>
	<div id="message" class="error">
		<p>
		<strong>SendGrid integration for Mailster</strong> requires the <a href="https://mailster.co/?utm_campaign=wporg&utm_source=wordpress.org&utm_medium=plugin&utm_term=SendGrid">Mailster Newsletter Plugin</a>, at least version <strong><?php echo esc_html( MAILSTER_SENDGRID_REQUIRED_VERSION ); ?></strong>.
		</p>
	</div>
		<?php
	}

	/**
	 * Sets default options and prompts the user to pick SendGrid.
	 *
	 * @return void
	 */
	public function activate() {

		if ( function_exists( 'mailster' ) ) {

			/* translators: %s: HTML link to the Mailster delivery settings page */
			mailster_notice( sprintf( __( 'Change the delivery method on the %s!', 'mailster-sendgrid' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', 360, 'delivery_method' );

			$defaults = array(
				'sendgrid_apikey'         => null,
				'sendgrid_api'            => 'web',
				'sendgrid_bouncehandling' => 'sendgrid',
				'sendgrid_verified'       => false,
			);

			$mailster_options = mailster_options();

			foreach ( $defaults as $key => $value ) {
				if ( ! isset( $mailster_options[ $key ] ) ) {
					mailster_update_option( $key, $value );
				}
			}

			$this->reset();
		}
	}

	/**
	 * Falls back to the simple delivery method when SendGrid was active.
	 *
	 * @return void
	 */
	public function deactivate() {

		if ( function_exists( 'mailster' ) ) {
			if ( 'sendgrid' === mailster_option( 'deliverymethod' ) ) {
				mailster_update_option( 'deliverymethod', 'simple' );
				/* translators: %s: HTML link to the Mailster delivery settings page */
				mailster_notice( sprintf( __( 'Change the delivery method on the %s!', 'mailster-sendgrid' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', 360, 'delivery_method' );
			}
		}
	}
}
