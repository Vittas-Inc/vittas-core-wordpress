<?php
/**
 * Self-hosted plugin updates from GitHub releases.
 *
 * @package Vittas_Payments
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Wires the plugin into WordPress's normal update machinery.
 *
 * The plugin is distributed from GitHub rather than the WordPress.org
 * directory, so nothing tells a merchant's site that a new version exists.
 * plugin-update-checker fills that gap: it polls the releases API and hands
 * WordPress an update record, so "Update available" appears on the Plugins
 * screen and one-click updates work exactly as they do for a .org plugin.
 *
 * That matters more than convenience — this plugin handles payments, and a
 * security fix is worthless if merchants never learn it shipped.
 */
class Vittas_Updater {

	/** Public GitHub repository that publishes the releases. */
	const REPOSITORY = 'https://github.com/Vittas-Inc/vittas-core-wordpress/';

	/**
	 * Branch treated as stable when no release asset is present.
	 *
	 * @var string
	 */
	const BRANCH = 'main';

	/**
	 * Boots the update checker.
	 */
	public static function init() {
		$loader = VITTAS_PAYMENTS_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

		// The library is committed alongside the plugin, but a merchant who
		// copies only part of the folder (or a security scanner that strips
		// bundled libraries) would fatal the whole site here. Updates are a
		// convenience; taking a store offline over them is not acceptable.
		if ( ! is_readable( $loader ) ) {
			return;
		}

		require_once $loader;

		if ( ! class_exists( PucFactory::class ) ) {
			return;
		}

		$checker = PucFactory::buildUpdateChecker(
			self::REPOSITORY,
			VITTAS_PAYMENTS_FILE,
			'vittas-payments'
		);

		$api = $checker->getVcsApi();

		if ( $api ) {
			// Ship built release assets (the zip attached to a GitHub release)
			// rather than the repo tarball. A raw tarball would include the
			// example store and CI config, and would unpack under a directory
			// named for the tag instead of `vittas-payments`.
			$api->enableReleaseAssets( '/vittas-payments-.*\.zip/i' );
		}

		/**
		 * Filters the update checker instance.
		 *
		 * Lets a site pin to a branch, add a private-repo token, or disable
		 * update checks entirely.
		 *
		 * @param object $checker plugin-update-checker instance.
		 */
		do_action( 'vittas_update_checker', $checker );
	}
}
