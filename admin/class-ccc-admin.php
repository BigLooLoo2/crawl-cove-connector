<?php
/**
 * Admin page (Tools → Crawl Cove): connection status, setup instructions,
 * and the change log with one-click revert.
 *
 * @package crawl-cove-connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller: Tools → Crawl Cove.
 */
class CCC_Admin {

	/**
	 * Hook the admin menu and the revert form handler.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ccc_revert', array( __CLASS__, 'handle_revert' ) );
	}

	/**
	 * Register the Tools → Crawl Cove submenu page.
	 */
	public static function menu() {
		add_management_page(
			__( 'Crawl Cove Connector', 'crawl-cove-connector' ),
			__( 'Crawl Cove', 'crawl-cove-connector' ),
			'manage_options',
			'crawl-cove-connector',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Admin-post.php handler for the per-row "Revert" button.
	 */
	public static function handle_revert() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'crawl-cove-connector' ) );
		}
		$change_id = isset( $_POST['change_id'] ) ? (int) $_POST['change_id'] : 0;
		check_admin_referer( 'ccc_revert_' . $change_id );

		$adapter = CCC_Adapter::detect();
		$notice  = 'error';
		if ( $adapter && ! is_wp_error( CCC_Change_Log::revert( $change_id, $adapter ) ) ) {
			$notice = 'reverted';
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'crawl-cove-connector',
					'ccc_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Tools → Crawl Cove page: status, setup steps, change log.
	 */
	public static function render() {
		$adapter = CCC_Adapter::detect();
		$log     = CCC_Change_Log::all();
		// Read-only display flag from our own redirect (handle_revert() already
		// nonce-checked the action that set it); nothing here changes state.
		$notice = isset( $_GET['ccc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ccc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Crawl Cove Connector', 'crawl-cove-connector' ); ?></h1>

			<?php if ( 'reverted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Change reverted.', 'crawl-cove-connector' ); ?></p></div>
			<?php elseif ( 'error' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not revert that change.', 'crawl-cove-connector' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'crawl-cove-connector' ); ?></h2>
			<table class="widefat striped" style="max-width:700px">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'SEO plugin detected', 'crawl-cove-connector' ); ?></td>
						<td>
							<?php if ( $adapter ) : ?>
								<strong><?php echo esc_html( $adapter->label() ); ?></strong>
								<?php echo esc_html( $adapter->plugin_version ? ' v' . $adapter->plugin_version : '' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'None', 'crawl-cove-connector' ); ?></strong>
								— <?php esc_html_e( 'install Yoast SEO, Rank Math, SEOPress or AIOSEO; fixes cannot be applied without one.', 'crawl-cove-connector' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'REST endpoint', 'crawl-cove-connector' ); ?></td>
						<td><code><?php echo esc_html( rest_url( CCC_Rest::NS ) ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Connect the Crawl Cove desktop app', 'crawl-cove-connector' ); ?></h2>
			<ol style="max-width:700px">
				<li>
				<?php
					printf(
						/* translators: %s: link to the current user's profile page */
						wp_kses( __( 'Create an <a href="%s">Application Password</a> for your user (Users → Profile → Application Passwords). Name it "Crawl Cove".', 'crawl-cove-connector' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( admin_url( 'profile.php#application-passwords-section' ) )
					);
				?>
				</li>
				<li><?php esc_html_e( 'In Crawl Cove, open the site profile → WordPress → paste the site URL, your username and the application password.', 'crawl-cove-connector' ); ?></li>
				<li><?php esc_html_e( 'Crawl, review the suggested title/description fixes, and push the approved ones. Every push lands in the log below and can be reverted.', 'crawl-cove-connector' ); ?></li>
			</ol>

			<h2><?php esc_html_e( 'Change log', 'crawl-cove-connector' ); ?></h2>
			<?php if ( ! $log ) : ?>
				<p><?php esc_html_e( 'No changes pushed yet.', 'crawl-cove-connector' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'Post', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'Field', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'Before', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'After', 'crawl-cove-connector' ); ?></th>
							<th><?php esc_html_e( 'By', 'crawl-cove-connector' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $log as $e ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $e['time'] ) ); ?></td>
							<td>
								<?php $edit = get_edit_post_link( $e['post_id'] ); ?>
								<?php if ( $edit ) : ?>
									<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( get_the_title( $e['post_id'] ) ); ?></a>
								<?php else : ?>
									#<?php echo (int) $e['post_id']; ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $e['field'] ); ?></td>
							<td><?php echo esc_html( '' === $e['from'] ? '—' : $e['from'] ); ?></td>
							<td><?php echo esc_html( '' === $e['to'] ? '—' : $e['to'] ); ?></td>
							<td><?php echo esc_html( $e['source'] ); ?></td>
							<td>
								<?php if ( empty( $e['reverted'] ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="ccc_revert" />
										<input type="hidden" name="change_id" value="<?php echo (int) $e['id']; ?>" />
										<?php wp_nonce_field( 'ccc_revert_' . (int) $e['id'] ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Revert', 'crawl-cove-connector' ); ?></button>
									</form>
								<?php else : ?>
									<em><?php esc_html_e( 'reverted', 'crawl-cove-connector' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
