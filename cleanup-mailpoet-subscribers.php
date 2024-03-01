<?php
/**
 * Plugin Name: Cleanup MailPoet subscribers
 * Description: Subscribers with status Inactive and Unconfirmed is moved to trash after 1 week. | Unsubscribed and Bounced is moved to trash after 1 hour. | Subscribers in trash is deleted after 1 hour.
 * Version: 1.0.0
 * Author: Nordic Custom Made
 * Author URI: https://nordiccustommade.dk
 * Requires Plugins: mailpoet
 * License: GPL3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * https://github.com/mailpoet/mailpoet/tree/trunk/doc
 * Cron job to move inactive subscribers to the trash
 */

add_action( 'ncm_mailpoet_move_subscribers_to_trash_event', 'ncm_mailpoet_move_subscribers_to_trash', 10, 1);
function ncm_mailpoet_move_subscribers_to_trash( $status ) {

    //get log from options
    $log = get_option( 'cleanup_mailpoet_subscribers_log' );
    if ( ! $log ) {
        $log = array();
        $log[$status] = 0;
    } else {
        if ( !key_exists( $status, $log ) ) {
            $log[$status] = 0;
        }
    }

    $mailpoet_api = false;
    if (class_exists(\MailPoet\API\API::class)) {
        $mailpoet_api = \MailPoet\API\API::MP('v1');
    }

    if ( !$mailpoet_api ) {
        return;
    }

    //dateime 1 week ago
    $date = new DateTime();
    $date->modify('-1 week');
    $date = $date->format('Y-m-d H:i:s');

    if ( $status === 'unsubscribed' || $status === 'bounced') {
        //set date to 1 hour ago
        $date = new DateTime();
        $date->modify('-1 hour');
        $date = $date->format('Y-m-d H:i:s');
    }

    //get all subscribers with status $args['status']

    $subscribers = $mailpoet_api->getSubscribers( array( 'status' => $status ) );
    
    if ( empty( $subscribers ) ) {
        return;
    }

    global $wpdb;
    $prefix = $wpdb->prefix;

    foreach ( $subscribers as $subscriber ) {

        if ( $subscriber['wp_user_id'] || $subscriber['is_woocommerce_user']) {
            continue;
        }

        //if $subscriber['updated_at'] is less than $date move to trash
        if ( $subscriber['updated_at'] < $date ) {
            //in $prefix . 'mailpoet_subscribers' column 'deleted_at' set to now
            $wpdb->update( $prefix . 'mailpoet_subscribers', array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $subscriber['id'] ) );

            //add to log
            $log[$status] = $log[$status] + 1;

        }

    }

    //update log
    update_option( 'cleanup_mailpoet_subscribers_log', $log );

}

/**
 * every hour run ncm_mailpoet_delete_subscribers_from_trash
 */
add_action( 'ncm_mailpoet_delete_subscribers_from_trash_event', 'ncm_mailpoet_delete_subscribers_from_trash' );
function ncm_mailpoet_delete_subscribers_from_trash() {

    $mailpoet_api = false;
    if (class_exists(\MailPoet\API\API::class)) {
        $mailpoet_api = \MailPoet\API\API::MP('v1');
    }

    //get log from options
    $log = get_option( 'cleanup_mailpoet_subscribers_log' );
    if ( ! $log ) {
        $log = array();
        $log['deleted'] = 0;
    } else {
        if ( !key_exists( 'deleted', $log ) ) {
            $log['deleted'] = 0;
        }
    }


    global $wpdb;
    $prefix = $wpdb->prefix;

    //get all subscribers from $prefix . 'mailpoet_subscribers' where 'deleted_at' is not null return ID and deleted_at
    $subscribers = $wpdb->get_results( "SELECT id, deleted_at FROM " . $prefix . "mailpoet_subscribers WHERE deleted_at IS NOT NULL", ARRAY_A );

    if ( empty( $subscribers ) ) {
        return;
    }

    foreach ( $subscribers as $subscriber ) {

        $get_subscriber = $mailpoet_api->getSubscriber( $subscriber['id'] );

        if ( empty( $get_subscriber ) ) {
            continue;
        }

        if ( $get_subscriber['wp_user_id'] || $get_subscriber['is_woocommerce_user']) {
            continue;
        }

        //if $subscriber['deleted_at'] is more than 1 week ago delete from mailpoet_subscribers
        $date = new DateTime();
        $date->modify('-1 day');
        $date = $date->format('Y-m-d H:i:s');

        if ( $subscriber['deleted_at'] < $date ) {
            $wpdb->delete( $prefix . 'mailpoet_subscribers', array( 'id' => $subscriber['id'] ) );
            $wpdb->delete( $prefix . 'mailpoet_subscriber_segment', array( 'subscriber_id' => $subscriber['id'] ) );
            $wpdb->delete( $prefix . 'mailpoet_subscriber_tag', array( 'subscriber_id' => $subscriber['id'] ) );
            $wpdb->delete( $prefix . 'mailpoet_subscriber_custom_field', array( 'subscriber_id' => $subscriber['id'] ) );

            //add to log
            $log['deleted'] = $log['deleted'] + 1;

        }

    }

    //update log
    update_option( 'cleanup_mailpoet_subscribers_log', $log );

}


/**
 * every hour schedule events
 */
add_action( 'init', 'ncm_mailpoet_move_subscribers_to_trash_cron' );
function ncm_mailpoet_move_subscribers_to_trash_cron() {

    //scheduled the diffrent events with 10 minute apart to avoid all events running at the same time
    if ( ! wp_next_scheduled( 'ncm_mailpoet_delete_subscribers_from_trash_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'ncm_mailpoet_delete_subscribers_from_trash_event' );
    }

    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'inactive' ) ) ) {
        wp_schedule_event( time(), 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'inactive' ) );
    }
    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unconfirmed' ) ) ) {
        wp_schedule_event( time(), 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unconfirmed' ) );
    }
    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unsubscribed' ) ) ) {
        wp_schedule_event( time(), 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unsubscribed' ) );
    }
    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'bounced' ) ) ) {
        wp_schedule_event( time(), 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'bounced' ) );
    }
}

/**
 * Activate the plugin
 */
register_activation_hook( __FILE__, 'ncm_mailpoet_activate' );
function ncm_mailpoet_activate() {
    
    //scheduled the diffrent events with 10 minute apart to avoid all events running at the same time
    if ( ! wp_next_scheduled( 'ncm_mailpoet_delete_subscribers_from_trash_event' ) ) {
        wp_schedule_event( time() + 600, 'hourly', 'ncm_mailpoet_delete_subscribers_from_trash_event' );
    }

    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'inactive' ) ) ) {
        wp_schedule_event( time() + 1200, 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'inactive' ) );
    }

    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unconfirmed' ) ) ) {
        wp_schedule_event( time() + 1800, 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unconfirmed' ) );
    }

    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unsubscribed' ) ) ) {
        wp_schedule_event( time() + 2400, 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unsubscribed' ) );
    }

    if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'bounced' ) ) ) {
        wp_schedule_event( time() + 3000, 'hourly', 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'bounced' ) );
    }

}

/**
 * Deactivate the plugin
 * remove all scheduled events
 */
register_deactivation_hook( __FILE__, 'ncm_mailpoet_deactivate' );
function ncm_mailpoet_deactivate() {
    wp_clear_scheduled_hook( 'ncm_mailpoet_delete_subscribers_from_trash_event' );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'inactive' ) );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unconfirmed' ) );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unsubscribed' ) );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'bounced' ) );
}

/**
 * Uninstall the plugin
 */
register_uninstall_hook( __FILE__, 'ncm_mailpoet_uninstall' );
function ncm_mailpoet_uninstall() {
    wp_clear_scheduled_hook( 'ncm_mailpoet_delete_subscribers_from_trash_event' );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'inactive' ) );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unconfirmed' ) );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'unsubscribed' ) );
    wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash_event', array( 'bounced' ) );
    delete_option( 'cleanup_mailpoet_subscribers_log' );
}