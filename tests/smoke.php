<?php

namespace MailPoet\Subscribers {
	class SubscribersRepository {
		public $trashed = array();
		public $deleted = array();

		public function bulkTrash( array $ids ) {
			$this->trashed[] = $ids;
			return count( $ids );
		}

		public function bulkDelete( array $ids ) {
			$this->deleted[] = $ids;
			return count( $ids );
		}
	}
}

namespace MailPoet\DI {
	class ContainerWrapper {
		private static $instance;
		public $repository;

		public static function getInstance() {
			if ( ! self::$instance ) {
				self::$instance             = new self();
				self::$instance->repository = new \MailPoet\Subscribers\SubscribersRepository();
			}
			return self::$instance;
		}

		public function get( $class ) {
			return $this->repository;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'WEEK_IN_SECONDS', 604800 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	class WP_Error {
		public function __construct( $code = '', $message = '' ) {}
	}

	class FakeWpdb {
		public $prefix = 'wp_';
		public $last_query = '';
		public $next_ids = array();

		public function prepare( $query, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			foreach ( $args as $value ) {
				if ( false !== strpos( $query, '%s' ) ) {
					$query = preg_replace( '/%s/', "'" . addslashes( $value ) . "'", $query, 1 );
				} else {
					$query = preg_replace( '/%d/', (string) (int) $value, $query, 1 );
				}
			}
			return $query;
		}

		public function get_col( $query ) {
			$this->last_query = $query;
			$ids              = $this->next_ids;
			$this->next_ids   = array();
			return $ids;
		}

		public function get_var( $query ) {
			$this->last_query = $query;
			return 0;
		}
	}

	function add_action() {}
	function register_activation_hook() {}
	function register_deactivation_hook() {}
	function register_uninstall_hook() {}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function get_option( $name, $default = false ) { return $default; }
	function update_option() {}

	$wpdb = new FakeWpdb();
	require dirname( __DIR__ ) . '/cleanup-mailpoet-subscribers.php';

	function assert_true( $condition, $message ) {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	$statuses = ncm_mailpoet_cleanup_statuses();
	assert_true( 4 === count( $statuses ), 'All four cleanup statuses are registered.' );
	assert_true( WEEK_IN_SECONDS === $statuses['unconfirmed']['age'], 'Unconfirmed retention remains one week.' );

	$wpdb->next_ids = array( '51', '52' );
	$ids = ncm_mailpoet_get_eligible_ids( 'active', 'unconfirmed', '2026-09-14 12:00:00' );
	assert_true( array( 51, 52 ) === $ids, 'Subscriber IDs are normalized to integers.' );
	assert_true( false !== strpos( $wpdb->last_query, 'wp_user_id IS NULL' ), 'WordPress users are excluded.' );
	assert_true( false !== strpos( $wpdb->last_query, 'is_woocommerce_user = 0' ), 'WooCommerce users are excluded.' );
	assert_true( false !== strpos( $wpdb->last_query, "status = 'unconfirmed'" ), 'Status is included in the query.' );
	assert_true( false !== strpos( $wpdb->last_query, 'LIMIT 1000' ), 'The new batch size replaces the API default of 50.' );

	$wpdb->next_ids = array( '1001', '1002', '1003' );
	$processed = ncm_mailpoet_process_batches( 'trash', 'unconfirmed' );
	$repository = \MailPoet\DI\ContainerWrapper::getInstance()->repository;
	assert_true( 3 === $processed, 'Trash batches report the processed count.' );
	assert_true( array( 1001, 1002, 1003 ) === $repository->trashed[0], 'Eligible IDs are passed to MailPoet bulkTrash.' );

	$wpdb->next_ids = array( '2001', '2002' );
	$processed = ncm_mailpoet_process_batches( 'delete' );
	assert_true( 2 === $processed, 'Delete batches report the processed count.' );
	assert_true( array( 2001, 2002 ) === $repository->deleted[0], 'Trash IDs are passed to MailPoet bulkDelete.' );

	echo "Cleanup MailPoet subscribers smoke tests passed.\n";
}
