<?php
/**
 * Plugin Name: Cleanup MailPoet subscribers
 * Description: Automatically cleans up inactive MailPoet subscribers and provides manual cleanup tools in the MailPoet menu.
 * Version: 1.1.0
 * Author: Nordic Custom Made
 * Author URI: https://nordiccustommade.dk
 * Requires Plugins: mailpoet
 * License: GPL3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NCM_MAILPOET_CLEANUP_PAGE_SLUG', 'mailpoet-cleanup-subscribers' );
define( 'NCM_MAILPOET_CLEANUP_CAPABILITY', 'mailpoet_manage_subscribers' );
define( 'NCM_MAILPOET_CLEANUP_BATCH_SIZE', 1000 );
define( 'NCM_MAILPOET_CLEANUP_MAX_BATCHES', 10 );

/**
 * Statuses managed by this plugin.
 *
 * @return array<string, array<string, int|string>>
 */
function ncm_mailpoet_cleanup_statuses() {
	return array(
		'inactive'     => array( 'label' => 'Inaktiv', 'age' => WEEK_IN_SECONDS ),
		'unconfirmed'  => array( 'label' => 'Ubekræftet', 'age' => WEEK_IN_SECONDS ),
		'unsubscribed' => array( 'label' => 'Afmeldt', 'age' => HOUR_IN_SECONDS ),
		'bounced'      => array( 'label' => 'Afvist', 'age' => HOUR_IN_SECONDS ),
	);
}

/**
 * Get MailPoet's public subscriber repository service.
 *
 * @return object|false
 */
function ncm_mailpoet_get_subscribers_repository() {
	if (
		! class_exists( '\MailPoet\DI\ContainerWrapper' ) ||
		! class_exists( '\MailPoet\Subscribers\SubscribersRepository' )
	) {
		return false;
	}

	try {
		return \MailPoet\DI\ContainerWrapper::getInstance()->get(
			\MailPoet\Subscribers\SubscribersRepository::class
		);
	} catch ( Throwable $error ) {
		return false;
	}
}

/**
 * Return eligible subscriber IDs in a bounded batch.
 *
 * WordPress users and WooCommerce customers are always protected.
 *
 * @param string      $location Either active or trash.
 * @param string|null $status   Optional MailPoet status.
 * @param string|null $cutoff   Optional maximum updated_at value.
 * @param int         $limit    Maximum number of IDs.
 * @return int[]
 */
function ncm_mailpoet_get_eligible_ids( $location, $status = null, $cutoff = null, $limit = NCM_MAILPOET_CLEANUP_BATCH_SIZE ) {
	global $wpdb;

	$table = $wpdb->prefix . 'mailpoet_subscribers';
	$where = array( 'wp_user_id IS NULL', 'is_woocommerce_user = 0' );
	$args  = array();

	$where[] = 'trash' === $location ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';

	if ( null !== $status ) {
		$where[] = 'status = %s';
		$args[]  = $status;
	}

	if ( null !== $cutoff ) {
		$where[] = 'updated_at < %s';
		$args[]  = $cutoff;
	}

	$args[] = max( 1, (int) $limit );
	$sql    = "SELECT id FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d';
	$query  = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	return array_map( 'intval', $wpdb->get_col( $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Process subscribers through MailPoet in batches.
 *
 * @param string      $operation Either trash or delete.
 * @param string|null $status    Optional status for trash operations.
 * @param string|null $cutoff    Optional maximum updated_at value.
 * @param int         $max_batches Safety limit for a single request.
 * @return int|WP_Error Number processed or an error.
 */
function ncm_mailpoet_process_batches( $operation, $status = null, $cutoff = null, $max_batches = NCM_MAILPOET_CLEANUP_MAX_BATCHES ) {
	$repository = ncm_mailpoet_get_subscribers_repository();
	if ( ! $repository ) {
		return new WP_Error( 'mailpoet_unavailable', 'MailPoet kunne ikke indlæses.' );
	}

	$processed = 0;
	$location  = 'delete' === $operation ? 'trash' : 'active';

	for ( $batch = 0; $batch < $max_batches; $batch++ ) {
		$ids = ncm_mailpoet_get_eligible_ids( $location, $status, $cutoff );
		if ( empty( $ids ) ) {
			break;
		}

		try {
			$processed += 'delete' === $operation
				? (int) $repository->bulkDelete( $ids )
				: (int) $repository->bulkTrash( $ids );
		} catch ( Throwable $error ) {
			return new WP_Error( 'mailpoet_cleanup_failed', $error->getMessage() );
		}

		if ( count( $ids ) < NCM_MAILPOET_CLEANUP_BATCH_SIZE ) {
			break;
		}
	}

	return $processed;
}

/**
 * Count subscribers for the cleanup screen.
 *
 * @param string      $location Either active or trash.
 * @param string|null $status   Optional status.
 * @param string|null $cutoff   Optional maximum updated_at value.
 * @param bool        $eligible_only Whether to exclude protected users.
 * @return int
 */
function ncm_mailpoet_count_subscribers( $location, $status = null, $cutoff = null, $eligible_only = false ) {
	global $wpdb;

	$table = $wpdb->prefix . 'mailpoet_subscribers';
	$where = array( 'trash' === $location ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL' );
	$args  = array();

	if ( $eligible_only ) {
		$where[] = 'wp_user_id IS NULL';
		$where[] = 'is_woocommerce_user = 0';
	}

	if ( null !== $status ) {
		$where[] = 'status = %s';
		$args[]  = $status;
	}

	if ( null !== $cutoff ) {
		$where[] = 'updated_at < %s';
		$args[]  = $cutoff;
	}

	$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
	if ( ! empty( $args ) ) {
		$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Move old subscribers with a specific status to trash.
 *
 * @param string $status MailPoet subscriber status.
 */
function ncm_mailpoet_move_subscribers_to_trash( $status ) {
	$statuses = ncm_mailpoet_cleanup_statuses();
	if ( ! isset( $statuses[ $status ] ) ) {
		return;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $statuses[ $status ]['age'] );
	$result = ncm_mailpoet_process_batches( 'trash', $status, $cutoff );
	if ( is_wp_error( $result ) || 0 === $result ) {
		return;
	}

	$log            = (array) get_option( 'cleanup_mailpoet_subscribers_log', array() );
	$log[ $status ] = isset( $log[ $status ] ) ? (int) $log[ $status ] + $result : $result;
	update_option( 'cleanup_mailpoet_subscribers_log', $log );
}
add_action( 'ncm_mailpoet_move_subscribers_to_trash', 'ncm_mailpoet_move_subscribers_to_trash', 10, 1 );

/**
 * Permanently delete eligible subscribers that have been in trash for a day.
 */
function ncm_mailpoet_delete_subscribers_from_trash() {
	global $wpdb;

	$table  = $wpdb->prefix . 'mailpoet_subscribers';
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	$ids    = array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE deleted_at IS NOT NULL
				AND deleted_at < %s
				AND wp_user_id IS NULL
				AND is_woocommerce_user = 0
				ORDER BY id ASC
				LIMIT %d",
				$cutoff,
				NCM_MAILPOET_CLEANUP_BATCH_SIZE * NCM_MAILPOET_CLEANUP_MAX_BATCHES
			)
		)
	);

	if ( empty( $ids ) ) {
		return;
	}

	$repository = ncm_mailpoet_get_subscribers_repository();
	if ( ! $repository ) {
		return;
	}

	$deleted = 0;
	foreach ( array_chunk( $ids, NCM_MAILPOET_CLEANUP_BATCH_SIZE ) as $batch ) {
		try {
			$deleted += (int) $repository->bulkDelete( $batch );
		} catch ( Throwable $error ) {
			break;
		}
	}

	if ( $deleted > 0 ) {
		$log            = (array) get_option( 'cleanup_mailpoet_subscribers_log', array() );
		$log['deleted'] = isset( $log['deleted'] ) ? (int) $log['deleted'] + $deleted : $deleted;
		update_option( 'cleanup_mailpoet_subscribers_log', $log );
	}
}
add_action( 'ncm_mailpoet_delete_subscribers_from_trash', 'ncm_mailpoet_delete_subscribers_from_trash' );

/** Register the cleanup page beneath MailPoet. */
function ncm_mailpoet_cleanup_admin_menu() {
	add_submenu_page(
		'mailpoet-homepage',
		'MailPoet-oprydning',
		'Oprydning',
		NCM_MAILPOET_CLEANUP_CAPABILITY,
		NCM_MAILPOET_CLEANUP_PAGE_SLUG,
		'ncm_mailpoet_render_cleanup_page'
	);
}
add_action( 'admin_menu', 'ncm_mailpoet_cleanup_admin_menu', 100 );

/**
 * Render one secure action form.
 *
 * @param string $operation Action name.
 * @param string $label Button label.
 * @param string $confirm Confirmation prompt.
 * @param string $status Optional status.
 * @param bool   $primary Whether to use the primary button style.
 * @param bool   $disabled Whether the button should be disabled.
 */
function ncm_mailpoet_cleanup_action_form( $operation, $label, $confirm, $status = '', $primary = false, $disabled = false ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
		<input type="hidden" name="action" value="ncm_mailpoet_cleanup">
		<input type="hidden" name="cleanup_operation" value="<?php echo esc_attr( $operation ); ?>">
		<?php if ( '' !== $status ) : ?>
			<input type="hidden" name="subscriber_status" value="<?php echo esc_attr( $status ); ?>">
		<?php endif; ?>
		<?php wp_nonce_field( 'ncm_mailpoet_cleanup_action' ); ?>
		<button type="submit" class="button <?php echo $primary ? 'button-primary' : ''; ?>" onclick="return confirm('<?php echo esc_js( $confirm ); ?>');" <?php disabled( $disabled ); ?>><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
}

/** Render the MailPoet cleanup overview. */
function ncm_mailpoet_render_cleanup_page() {
	if ( ! current_user_can( NCM_MAILPOET_CLEANUP_CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), '', array( 'response' => 403 ) );
	}

	$statuses = ncm_mailpoet_cleanup_statuses();
	?>
	<div class="wrap">
		<h1>MailPoet-oprydning</h1>
		<p>WordPress-brugere og WooCommerce-kunder er beskyttet og bliver aldrig behandlet af disse handlinger.</p>

		<?php if ( isset( $_GET['cleanup_result'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<?php
			$result    = sanitize_key( wp_unslash( $_GET['cleanup_result'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$processed = isset( $_GET['processed'] ) ? absint( $_GET['processed'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$remaining = isset( $_GET['remaining'] ) ? absint( $_GET['remaining'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message   = 'success' === $result
				? sprintf(
					'%1$s abonnenter blev behandlet.%2$s',
					number_format_i18n( $processed ),
					$remaining > 0 ? ' Der er stadig ' . number_format_i18n( $remaining ) . ' egnede tilbage; kør handlingen igen.' : ''
				)
				: 'Oprydningen kunne ikke gennemføres. Kontrollér, at MailPoet er aktivt.';
			?>
			<div class="notice notice-<?php echo 'success' === $result ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>

		<h2>Aktive statusser</h2>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th>Status</th><th>Samlet</th><th>Kan flyttes</th><th>Opfylder tidsgrænsen nu</th><th>Automatisk grænse</th><th>Manuel handling</th></tr></thead>
			<tbody>
				<?php foreach ( $statuses as $status => $config ) : ?>
					<?php
					$cutoff   = gmdate( 'Y-m-d H:i:s', time() - (int) $config['age'] );
					$total    = ncm_mailpoet_count_subscribers( 'active', $status );
					$eligible = ncm_mailpoet_count_subscribers( 'active', $status, null, true );
					$due      = ncm_mailpoet_count_subscribers( 'active', $status, $cutoff, true );
					$age      = WEEK_IN_SECONDS === (int) $config['age'] ? '1 uge' : '1 time';
					?>
					<tr>
						<td><strong><?php echo esc_html( $config['label'] ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $eligible ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $due ) ); ?></td>
						<td><?php echo esc_html( $age ); ?> efter seneste opdatering</td>
						<td>
							<?php
							ncm_mailpoet_cleanup_action_form(
								'trash_status',
								'Flyt alle til papirkurven',
								'Dette flytter alle egnede abonnenter med statussen ' . $config['label'] . ' til papirkurven, også dem der endnu ikke har nået tidsgrænsen. Fortsæt?',
								$status,
								false,
								0 === $eligible
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$trash_total    = ncm_mailpoet_count_subscribers( 'trash' );
		$trash_eligible = ncm_mailpoet_count_subscribers( 'trash', null, null, true );
		?>
		<h2>Papirkurv</h2>
		<p>Der er <strong><?php echo esc_html( number_format_i18n( $trash_total ) ); ?></strong> abonnenter i papirkurven. <strong><?php echo esc_html( number_format_i18n( $trash_eligible ) ); ?></strong> kan slettes permanent.</p>
		<?php
		ncm_mailpoet_cleanup_action_form(
			'empty_trash',
			'Tøm papirkurven permanent',
			'Denne handling sletter alle egnede abonnenter i MailPoets papirkurv permanent og kan ikke fortrydes. Fortsæt?',
			'',
			true,
			0 === $trash_eligible
		);
		?>
	</div>
	<?php
}

/** Handle manual cleanup requests. */
function ncm_mailpoet_handle_cleanup_action() {
	if ( ! current_user_can( NCM_MAILPOET_CLEANUP_CAPABILITY ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.' ), '', array( 'response' => 403 ) );
	}

	check_admin_referer( 'ncm_mailpoet_cleanup_action' );

	$operation = isset( $_POST['cleanup_operation'] ) ? sanitize_key( wp_unslash( $_POST['cleanup_operation'] ) ) : '';
	$result    = new WP_Error( 'invalid_operation', 'Ugyldig handling.' );
	$remaining = 0;

	if ( 'trash_status' === $operation ) {
		$status   = isset( $_POST['subscriber_status'] ) ? sanitize_key( wp_unslash( $_POST['subscriber_status'] ) ) : '';
		$statuses = ncm_mailpoet_cleanup_statuses();
		if ( isset( $statuses[ $status ] ) ) {
			$result = ncm_mailpoet_process_batches( 'trash', $status );
			if ( ! is_wp_error( $result ) ) {
				$remaining = ncm_mailpoet_count_subscribers( 'active', $status, null, true );
			}
		}
	} elseif ( 'empty_trash' === $operation ) {
		$result = ncm_mailpoet_process_batches( 'delete' );
		if ( ! is_wp_error( $result ) ) {
			$remaining = ncm_mailpoet_count_subscribers( 'trash', null, null, true );
		}
	}

	$redirect_args = array(
		'page'           => NCM_MAILPOET_CLEANUP_PAGE_SLUG,
		'cleanup_result' => is_wp_error( $result ) ? 'error' : 'success',
		'processed'      => is_wp_error( $result ) ? 0 : (int) $result,
		'remaining'      => $remaining,
	);

	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_ncm_mailpoet_cleanup', 'ncm_mailpoet_handle_cleanup_action' );

/** Schedule hourly cleanup events. */
function ncm_mailpoet_cron_schedule() {
	if ( ! wp_next_scheduled( 'ncm_mailpoet_delete_subscribers_from_trash' ) ) {
		wp_schedule_event( time(), 'hourly', 'ncm_mailpoet_delete_subscribers_from_trash' );
	}

	foreach ( array_keys( ncm_mailpoet_cleanup_statuses() ) as $status ) {
		if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash', array( $status ) ) ) {
			wp_schedule_event( time(), 'hourly', 'ncm_mailpoet_move_subscribers_to_trash', array( $status ) );
		}
	}
}
add_action( 'init', 'ncm_mailpoet_cron_schedule' );

/** Schedule staggered cleanup events on activation. */
function ncm_mailpoet_activate() {
	if ( ! wp_next_scheduled( 'ncm_mailpoet_delete_subscribers_from_trash' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'ncm_mailpoet_delete_subscribers_from_trash' );
	}

	$delay = 1200;
	foreach ( array_keys( ncm_mailpoet_cleanup_statuses() ) as $status ) {
		if ( ! wp_next_scheduled( 'ncm_mailpoet_move_subscribers_to_trash', array( $status ) ) ) {
			wp_schedule_event( time() + $delay, 'hourly', 'ncm_mailpoet_move_subscribers_to_trash', array( $status ) );
		}
		$delay += 600;
	}
}
register_activation_hook( __FILE__, 'ncm_mailpoet_activate' );

/** Clear all scheduled events. */
function ncm_mailpoet_clear_scheduled_events() {
	wp_clear_scheduled_hook( 'ncm_mailpoet_delete_subscribers_from_trash' );
	foreach ( array_keys( ncm_mailpoet_cleanup_statuses() ) as $status ) {
		wp_clear_scheduled_hook( 'ncm_mailpoet_move_subscribers_to_trash', array( $status ) );
	}
}

function ncm_mailpoet_deactivate() {
	ncm_mailpoet_clear_scheduled_events();
}
register_deactivation_hook( __FILE__, 'ncm_mailpoet_deactivate' );

function ncm_mailpoet_uninstall() {
	ncm_mailpoet_clear_scheduled_events();
	delete_option( 'cleanup_mailpoet_subscribers_log' );
}
register_uninstall_hook( __FILE__, 'ncm_mailpoet_uninstall' );
