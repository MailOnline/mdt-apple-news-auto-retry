<?php
/**
 * Plugin Name: Apple News Auto Retry
 * Plugin URI:  https://github.com/MailOnline/mdt-apple-news-auto-retry/
 * Description: Auto retry pending Apple News articles
 * Version:     0.0.1
 * Author:      Metro.co.uk
 * Author URI:  https://github.com/MailOnline/mdt-apple-news-auto-retry/graphs/contributors
 */

namespace MDT\Apple_News_Auto_Retry;

/**
 * Class Main
 * @package MDT\Apple_News_Auto_Retry
 */
class Main {
	/**
	 * Cron event name
	 *
	 * @var string
	 */
	const CRON_EVENT = 'mdt_an_auto_retry_publish';

	/**
	 * Should schedule filter name
	 *
	 * @var string
	 */
	const FILTER_NAME_SHOULD_SCHEDULE = 'mdt_an_auto_retry_should_schedule';


	/**
	 * Should schedule filter name
	 *
	 * @var string
	 */
	const FILTER_NAME_SCHEDULE_DELAY = 'mdt_an_auto_retry_schedule_delay';

	/**
	 * Retry success action name
	 *
	 * @var string
	 */
	const ACTION_NAME_RETRY_SUCCESS = 'mdt_an_auto_retry_push_success';

	/**
	 * Retry failure action name
	 *
	 * @var string
	 */
	const ACTION_NAME_RETRY_FAILURE = 'mdt_an_auto_retry_push_failure';

	/**
	 * Attempts meta key name
	 *
	 * @var string
	 */
	const META_KEY_ATTEMPTS = 'mdt_an_auto_retry_attempts';

	/**
	 * Scheduled retry time meta key name
	 *
	 * @var string
	 */
	const META_KEY_SCHEDULED = 'mdt_an_auto_retry_next_scheduled';

	/**
	 * Maximum retry attempts
	 *
	 * @var integer
	 */
	const MAX_ATTEMPTS = 3;


	/**
	 * Init
	 */
	public static function init(){
		add_action('init', [__CLASS__, 'add_actions']);
	}

	/**
	 * Binds the actions if the publish-to-apple-news plugin is
	 * also present
	 */
	public static function add_actions(){
		if ( class_exists('\Apple_News') && class_exists('\Admin_Apple_Settings')){
			add_action( self::CRON_EVENT, array( __CLASS__, 'retry_publish' ), 10 );

			add_action( 'wp_after_insert_post', array( __CLASS__, 'schedule_auto_retry' ), 100, 2 );

			//On successful AN push delete remaining retry events + meta
			add_action( 'apple_news_after_push', array(__CLASS__, 'clear_existing_retry'), 10 );

			//@todo add api for sync publishing
			//@todo load gutenberg assets
		}
	}


	/**
	 * Attempt retry
	 *
	 * @param int $post_id Post ID
	 */
	public static function retry_publish($post_id){
		$an_id = get_post_meta( $post_id, 'apple_news_api_id', true );
		$pending = get_post_meta( $post_id, 'apple_news_api_pending', true );

		//Do not perform sync push if the article already has an apple-news ID and isn't in a pending state
		if($an_id && !$pending){
			self::clear_existing_retry($post_id);
			return;
		}

		$attempt = get_post_meta($post_id, self::META_KEY_ATTEMPTS, true) ?: 1;
		$result = self::do_sync_push($post_id);

		if($result['success']){
			$share_url = get_post_meta( $post_id, 'apple_news_api_share_url', true);
			do_action(self::ACTION_NAME_RETRY_SUCCESS, $post_id, $share_url, $attempt);
		} else {
			do_action(self::ACTION_NAME_RETRY_FAILURE, $post_id, $result['error'], $attempt);
			//if failure, schedule for a further retry if below MAX_ATTEMPTS
			if($attempt < self::MAX_ATTEMPTS){
				self::schedule_single_event($post_id);
				$attempt++;
				update_post_meta($post_id, self::META_KEY_ATTEMPTS, $attempt);
			}
		}
	}

	/**
	 * Perform a synchronous push to Apple News for the given $post_id
	 *
	 * @param int $post_id Post ID
	 * @return array Success or Error information
	 */
	public static function do_sync_push($post_id){
		$admin_settings = new \Admin_Apple_Settings();
		$settings       = $admin_settings->fetch_settings();

		$action = new \Apple_Actions\Index\Push( $settings, $post_id );
		$error = false;

		try {
			$action->perform( true );
		} catch ( \Apple_Actions\Action_Exception $e ) {
			$error = $e->getMessage();
		}

		//if no error from push then cleanup
		if(!$error){
			self::clear_existing_retry($post_id);
		}

		return ['success' => !$error, 'error' => $error];
	}


	/**
	 * See if a published post's content was updated to include a new video
	 *
	 * @param int      $post_id Post ID.
	 * @param WP_Post  $post    Updated post object.
	 */
	public static function schedule_auto_retry($post_id, $post){
		// Do not schedule on autosaves or revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only schedule for published posts
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Only handle default posts
		if ( 'post' !== $post->post_type ) {
			return;
		}

		//clear previous scheduled retry and attempt count
		self::clear_existing_retry($post_id);

		//Allow clients to prevent retry scheduling e.g when an article isn't suitable
		$should_schedule = apply_filters(self::FILTER_NAME_SHOULD_SCHEDULE, true, $post_id);

		if($should_schedule){
			self::schedule_single_event($post_id);
		}
	}

	/**
	 * Schedule the retry attempt 2 minutes (default) into the future
	 *
	 * @param int $post_id Post ID
	 */
	public static function schedule_single_event($post_id){
		$delay = (int) apply_filters(self::FILTER_NAME_SCHEDULE_DELAY, 120);
		$time = time() + $delay;
		wp_schedule_single_event( $time, self::CRON_EVENT, self::get_cron_arguments($post_id) );
		update_post_meta($post_id, self::META_KEY_SCHEDULED, $time);
	}

	/**
	 * Clear the scheduled retry even for the given post and delete
	 * related meta values.
	 *
	 * @param int $post_id Post ID
	 */
	public static function clear_existing_retry($post_id){
		if($post_id){
			wp_clear_scheduled_hook( self::CRON_EVENT, self::get_cron_arguments($post_id));
			delete_post_meta($post_id, self::META_KEY_ATTEMPTS);
			delete_post_meta($post_id, self::META_KEY_SCHEDULED);
		}
	}

	/**
	 * Returns the arguments to pass along in the scheduled retry events
	 *
	 * @param int $post_id Post ID
	 * @return array Arguments for scheduled event
	 */
	public static function get_cron_arguments($post_id){
		$cron_args = [$post_id];

		return $cron_args;
	}
}

Main::init();



