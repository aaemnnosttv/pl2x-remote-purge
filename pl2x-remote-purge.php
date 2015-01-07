<?php
/**
 * Plugin Name: PL2X Remote Purge
 * Author: Evan Mattson
 * Version: 0.1
 * Description: A utility to allow for PL2X cache to be purged remotely using a dynamic url.
 */

namespace PL2X;

use OptEngine;

class RemotePurge
{
	// pl settings option key
	const PL_OPT_KEY = 'remote_purge_key';

	// purge query var
	const PURGE_QUERY_VAR = 'pl2x_purge';

	// regenerate purge key query var
	const PURGE_REGEN_KEY = 'pl2x_purge_regen';

	// purge key regenerated status query var
	const PURGE_REGEN_KEY_STATUS = 'purge_key_regenerated';

	public static function init()
	{
		self::add_filter('pagelines_options_array', 'add_panel');
		self::add_action('pagelines_options_pl2x_purge_field', 'render_purge_key_field', 10, 2);
		self::add_action('admin_menu', 'admin_menu', 20);
		self::add_action('admin_notices', 'regenerated_notice');

		// set initial value if there isn't one
		if ( ! self::get_purge_key() )
			self::regenerate_purge_key();

		self::listen();
	}

	public static function admin_menu()
	{
		global $_pagelines_options_page_hook; // yuck
		self::add_action("load-{$_pagelines_options_page_hook}", 'maybe_regenerate_key');
	}

	private static function listen()
	{
		if ( $key = filter_input(INPUT_GET, self::PURGE_QUERY_VAR) )
		{
			$response = new \stdClass;

			if ( $key === self::get_purge_key() )
			{
				do_action('extend_flush');
				$response->status = 'success';
				$response->message = 'Cache purged.';
				$status_code = 200;
			}
			else
			{
				$response->status = 'fail';
				$response->message = 'Invalid key.';
				$status_code = 422;
			}

			if ( ! headers_sent() )
			{
				nocache_headers();
				@header("Content-type: application/json");
				status_header( $status_code );

				echo json_encode( $response );
				exit;
			}
			else
			{
				wp_die( $response->message, $response->status, array('response' => $status_code) );
			}
			// silence
		}
	}

	public static function maybe_regenerate_key()
	{
		if ( $nonce = filter_input(INPUT_GET, self::PURGE_REGEN_KEY) )
		{
			if ( wp_verify_nonce( $nonce, self::PURGE_REGEN_KEY ) )
			{
				self::regenerate_purge_key();

				wp_redirect( add_query_arg(array(
					self::PURGE_REGEN_KEY        => false,
					self::PURGE_REGEN_KEY_STATUS => true
				)) );
				exit;
			}
			else
			{
				wp_redirect( add_query_arg(array(
					self::PURGE_REGEN_KEY        => false,
					self::PURGE_REGEN_KEY_STATUS => 0,
					'error'                      => 'invalid_nonce'
				)) );
				exit;
			}
		}
	}

	public static function regenerated_notice()
	{
		if ( filter_input(INPUT_GET, self::PURGE_REGEN_KEY_STATUS) )
		{
			?>
<div class="updated">
	<p>PL2X purge key regenerated successfully!</p>
</div>
			<?php
		}
	}

	public static function add_panel( $opts )
	{
		if ( current_user_can( 'administrator' ) )
		{
			$opts['remote_purge'] = (array) self::get_panel_config();
		}

		return $opts;
	}

	public static function get_panel_config()
	{
		$config = new \stdClass;
		$config->remote_purge_key = array(
			'title'    => 'Remote Purge Key',
			'shortexp' => 'For purging theme cache remotely. Especially useful for regenerating styles after deploying new changes.',
			'type'     => 'pl2x_purge_field',
			'exp'      => sprintf('<div><a href="%s" style="text-decoration:none;" onclick="return false;" disabled><span class="dashicons dashicons-admin-links"></span> Copy URL</a></div>
				<div><em>%s</em></div>',
				self::get_purge_url(),
				'Right-click and choose Copy Link Address'
				)
		);

		return $config;
	}

	public static function render_purge_key_field( $oid, $o )
	{
		?>
		<pre style="max-width: 100%; overflow-x: auto;"><?php echo self::get_purge_url() ?></pre>
		<div><a href="<?php echo self::get_regen_url() ?>" class="button">Regenerate key</a></div>
		<em>This will invalidate the current key and replace it with a new one.</em>
		
		<?php echo OptEngine::input_hidden('pl2x_purge_key', $o['input_name'], $o['val']) ?>
		<?php
	}

	private static function get_purge_url()
	{
		return add_query_arg(array(self::PURGE_QUERY_VAR => self::get_purge_key()), home_url());
	}

	private static function get_regen_url()
	{
		return add_query_arg(array(self::PURGE_REGEN_KEY => wp_create_nonce(self::PURGE_REGEN_KEY)));
	}

	private static function get_purge_key()
	{
		return ploption(self::PL_OPT_KEY);
	}

	private static function regenerate_purge_key()
	{
		plupop(self::PL_OPT_KEY, self::generate_purge_key());
	}

	public static function generate_purge_key()
	{
		return wp_hash( uniqid() );
	}

	public static function add_action($handle, $method, $priority = 10, $args = 1)
	{
		add_action( $handle, array(__CLASS__, $method), $priority, $args);
	}

	public static function add_filter($handle, $method, $priority = 10, $args = 1)
	{
		add_filter( $handle, array(__CLASS__, $method), $priority, $args);
	}
}
add_action('pagelines_hook_init', 'PL2X\\RemotePurge::init');
