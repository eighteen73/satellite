<?php
/**
 * Sync from a remote website
 *
 * @package         Satellite
 */

namespace Eighteen73\Satellite\Sync;

use Eighteen73\Satellite\Environment;
use Roots\WPConfig\Config;
use Roots\WPConfig\Exceptions\UndefinedConfigKeyException;
use WP_CLI;

/**
 * Sync from a remote website
 */
class Sync {
	use Environment;

	/**
	 * Which features should be run
	 *
	 * @var array
	 */
	private array $options = [
		'database'         => false,
		'uploads'          => false,
		'active_plugins'   => true,
		'inactive_plugins' => true,
	];

	/**
	 * Various command settings
	 *
	 * @var array
	 */
	private array $settings = [
		'ssh_host' => null,
		'ssh_port' => '22',
		'ssh_user' => null,
		'ssh_path' => null,
		'plugins'  => [
			'activate'   => null,
			'deactivate' => null,
		],
	];

	/**
	 * Is the pv command available
	 *
	 * @var bool
	 */
	private bool $has_pv = false;

	/**
	 * Path to local wp
	 *
	 * @var string
	 */
	private string $local_wp = '';

	/**
	 * Path to remote wp
	 *
	 * @var string
	 */
	private string $remote_wp = '';

	/**
	 * The command run by CLI
	 *
	 * @param array $args User arguments
	 * @param array $assoc_args User arguments
	 *
	 * @return void
	 */
	public function run( array $args, array $assoc_args ) {
		if ( ! $this->is_safe_environment() ) {
			WP_CLI::error( 'This can only be run in a development and staging environments. Check your wp_get_environment_type() setting.' );
		}

		if ( ! $this->has_all_settings() ) {
			WP_CLI::error( 'You are missing some config settings in your environment. Please refer to the plugin\'s README.md.' );
		}

		$this->check_for_pv();
		$this->find_local_wp();

		if ( ! $this->has_remote_access() ) {
			WP_CLI::error( 'Cannot access remote website. Please check your connection settings.' );
		}

		$this->find_remote_wp();

		$this->get_options( $assoc_args );

		if ( $this->options['database'] ) {
			$this->fetch_database();
			$this->enable_stripe_test_mode();
		}

		if ( $this->options['uploads'] ) {
			$this->fetch_uploads();
		}

		if ( $this->options['active_plugins'] ) {
			$this->activate_plugins();
		}

		if ( $this->options['inactive_plugins'] ) {
			$this->deactivate_plugins();
		}

		WP_CLI::line();
		WP_CLI::success( 'All done!' );
	}

	/**
	 * Development and staging use only
	 */
	private function is_safe_environment(): bool {
		return in_array( $this->environment(), [ 'development', 'local', 'staging' ], true );
	}

	/**
	 * Load settings from .env or config (.env takes precedence)
	 */
	private function has_all_settings(): bool {
		try {
			$this->settings['ssh_host'] = getenv( 'SATELLITE_SSH_HOST' ) ?: Config::get( 'SATELLITE_SSH_HOST' );
			$this->settings['ssh_user'] = getenv( 'SATELLITE_SSH_USER' ) ?: Config::get( 'SATELLITE_SSH_USER' );
			$this->settings['ssh_path'] = getenv( 'SATELLITE_SSH_PATH' ) ?: Config::get( 'SATELLITE_SSH_PATH' );
		} catch ( UndefinedConfigKeyException $e ) {
			return false;
		}

		// Special case for SSH port
		try {
			$ssh_port                   = getenv( 'SATELLITE_SSH_PORT' ) ?: Config::get( 'SATELLITE_SSH_PORT' );
			$this->settings['ssh_port'] = strval( $ssh_port );
			if ( ! preg_match( '/^[0-9]+$/', $this->settings['ssh_port'] ) ) {
				$this->settings['ssh_port'] = null;
			}
		} catch ( UndefinedConfigKeyException $e ) {
			// Do nothing
		}

		foreach ( $this->settings as $setting ) {
			if ( empty( $setting ) ) {
				return false;
			}
		}

		// Plugin (de)activations
		$activated_plugins   = null;
		$deactivated_plugins = null;

		if ( getenv( 'SATELLITE_SYNC_ACTIVATE_PLUGINS' ) ) {
			$activated_plugins = getenv( 'SATELLITE_SYNC_ACTIVATE_PLUGINS' );
		} else {
			try {
				$activated_plugins = Config::get( 'SATELLITE_SYNC_ACTIVATE_PLUGINS' );
			} catch ( UndefinedConfigKeyException $e ) {
				// Do nothing
			}
		}
		if ( $activated_plugins !== null ) {
			$activated_plugins = preg_split( '/[\s,]+/', $activated_plugins );
			$activated_plugins = array_filter( $activated_plugins );
		}
		$this->settings['plugins']['activate'] = $activated_plugins;

		if ( getenv( 'SATELLITE_SYNC_DEACTIVATE_PLUGINS' ) ) {
			$deactivated_plugins = getenv( 'SATELLITE_SYNC_DEACTIVATE_PLUGINS' );
		} else {
			try {
				$deactivated_plugins = Config::get( 'SATELLITE_SYNC_DEACTIVATE_PLUGINS' );
			} catch ( UndefinedConfigKeyException $e ) {
				// Do nothing
			}
		}
		if ( $deactivated_plugins !== null ) {
			$deactivated_plugins = preg_split( '/[\s,]+/', $deactivated_plugins );
			$deactivated_plugins = array_filter( $deactivated_plugins );
		}
		$this->settings['plugins']['deactivate'] = $deactivated_plugins;

		return true;
	}

	/**
	 * Check it the pv command is available
	 *
	 * @return void
	 */
	private function check_for_pv() {
		$this->has_pv = ! empty( exec( 'which pv' ) );
		if ( ! $this->has_pv ) {
			WP_CLI::warning( "You may wish to install 'pv' to see progress when running this command." );
		}
	}

	/**
	 * Check it the local wp command is available and where it's located
	 *
	 * @return void
	 */
	private function find_local_wp() {
		// Possible `wp` locations, with the most preferable ones first
		$possible_paths = [
			'./vendor/bin/wp',
			'/usr/local/bin/wp',
			'wp',
		];
		foreach ( $possible_paths as $path ) {
			if ( exec( "which '{$path}'" ) !== null ) {
				$this->local_wp = $path;

				return;
			}
		}
	}

	/**
	 * Check it the remote wp command is available and where it's located
	 *
	 * @return void
	 */
	private function find_remote_wp() {
		// Possible `wp` locations, with the most preferable ones first
		$possible_paths = [
			"{$this->settings['ssh_path']}/vendor/bin/wp",
			'/usr/local/bin/wp',
			'wp',
		];
		foreach ( $possible_paths as $path ) {
			// Try remote WP-CLI
			$command            = "{$this->settings['ssh_command']} \"bash -c \\\"test -f {$path} && echo true || echo false\\\"\"";
			$live_server_status = exec( $command );
			if ( $live_server_status === 'true' ) {
				$this->remote_wp = $path;
				break;
			}
		}

		if ( ! $this->remote_wp ) {
			WP_CLI::error( "Cannot find WP-CLI at {$this->settings['ssh_user']}@{$this->settings['ssh_host']}" );
		}
	}

	/**
	 * Get the user's command arguments
	 *
	 * @param array $assoc_args User arguments
	 *
	 * @return void
	 */
	private function get_options( array $assoc_args ) {
		$true_values = [ true, 'true', 1, '1', 'yes' ];
		if ( isset( $assoc_args['database'] ) ) {
			$this->options['database'] = in_array( $assoc_args['database'], $true_values, true );
		}
		if ( isset( $assoc_args['uploads'] ) ) {
			$this->options['uploads'] = in_array( $assoc_args['uploads'], $true_values, true );
		}
	}

	/**
	 * Can the remote website be accessed
	 *
	 * @return bool
	 */
	private function has_remote_access(): bool {
		$this->settings['ssh_command'] = "ssh -q -p {$this->settings['ssh_port']} {$this->settings['ssh_user']}@{$this->settings['ssh_host']}";

		// Try SSH
		$command            = "{$this->settings['ssh_command']} exit; echo $?";
		$live_server_status = exec( $command );
		if ( $live_server_status === '255' ) {
			WP_CLI::error( "Cannot connect to {$this->settings['ssh_user']}@{$this->settings['ssh_host']} over SSH" );
		}

		return true;
	}

	/**
	 * Reusable title output for CLI feedback
	 *
	 * @param string $title Title text
	 *
	 * @return void
	 */
	private function print_action_title( string $title ) {
		WP_CLI::line( WP_CLI::colorize( '%b' ) );
		WP_CLI::line( strtoupper( $title ) );
		WP_CLI::line( WP_CLI::colorize( str_pad( '', strlen( $title ), '~' ) . '%n' ) );
	}

	/**
	 * Overwrite the local database using the remote one
	 *
	 * @return void
	 */
	private function fetch_database() {
		$this->print_action_title( 'Fetching database' );

		$pipe    = $this->has_pv ? ' | pv | ' : ' | ';
		$command = "{$this->settings['ssh_command']} \"bash -c \\\"cd {$this->settings['ssh_path']} && {$this->remote_wp} db export --quiet --single-transaction - | gzip -cf\\\"\" {$pipe} gunzip -c | {$this->local_wp} db import --quiet -";
		system( $command );
	}

	/**
	 * Put Stripe in test mode if applicable
	 *
	 * @return void
	 */
	private function enable_stripe_test_mode() {
		if ( $this->is_plugin_installed_and_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) ) {
			WP_CLI::line( 'Enabling Stripe test mode' );
			$option             = get_option( 'woocommerce_stripe_settings' );
			$option['testmode'] = 'yes';
			update_option( 'woocommerce_stripe_settings', $option );
		}
	}

	/**
	 * Download remote uploaded files
	 *
	 * @return void
	 */
	private function fetch_uploads() {
		$this->print_action_title( 'Fetching uploads' );
		WP_CLI::line( WP_CLI::colorize( '%y// todo%n' ) );
	}

	/**
	 * Activate named plugins
	 *
	 * @return void
	 */
	private function activate_plugins() {
		if ( empty( $this->settings['plugins']['activate'] ) ) {
			return;
		}

		$this->print_action_title( 'Activating Plugins' );

		foreach ( $this->settings['plugins']['activate'] as $plugin ) {
			if ( $this->is_plugin_installed( $plugin ) ) {
				if ( ! $this->is_plugin_active( $plugin ) ) {
					$command = "{$this->local_wp} plugin activate {$plugin}";
					system( $command );
				} else {
					WP_CLI::warning( "Plugin {$plugin} is already active" );
				}
			} else {
				WP_CLI::warning( "Plugin {$plugin} is not available to activate" );
			}
		}
	}

	/**
	 * Deactivate named plugins
	 *
	 * @return void
	 */
	private function deactivate_plugins() {
		if ( empty( $this->settings['plugins']['deactivate'] ) ) {
			return;
		}

		$this->print_action_title( 'Deactivating Plugins' );

		foreach ( $this->settings['plugins']['deactivate'] as $plugin ) {
			if ( $this->is_plugin_installed( $plugin ) ) {
				if ( $this->is_plugin_active( $plugin ) ) {
					$command = "{$this->local_wp} plugin deactivate {$plugin}";
					system( $command );
				} else {
					WP_CLI::warning( "Plugin {$plugin} is already inactive" );
				}
			} else {
				WP_CLI::warning( "Plugin {$plugin} is not available to deactivate" );
			}
		}
	}

	/**
	 * Check if a plugin is installed
	 *
	 * @param string $plugin_slug Plugin name
	 *
	 * @return bool
	 */
	private function is_plugin_installed( string $plugin_slug ): bool {
		$installed_plugins = get_plugins();

		return array_key_exists( $plugin_slug, $installed_plugins ) || in_array( $plugin_slug, $installed_plugins, true );
	}

	/**
	 * Check if a plugin is enabled
	 *
	 * @param string $plugin_slug Plugin name
	 *
	 * @return bool
	 */
	private function is_plugin_active( string $plugin_slug ): bool {
		if ( is_plugin_active( $plugin_slug ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if a plugin is installed and enabled
	 *
	 * @param string $plugin_slug Plugin name
	 *
	 * @return bool
	 */
	private function is_plugin_installed_and_active( string $plugin_slug ): bool {
		return $this->is_plugin_installed( $plugin_slug ) && $this->is_plugin_installed( $plugin_slug );
	}
}
