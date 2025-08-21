<?php

namespace Static_Mirror;

use Exception;
use WP_Error;

class Mirrorer {

	/**
	 * Create a static mirror of the site by given urls
	 *
	 * @param  Array  $urls
	 * @param  String $destination
	 * @param  bool   Whether to make the mirror recursivly crawl pages
	 * @throws Exception
	 * @return void
	 */
	public function create( Array $urls, $destination, $recursive ) {

		static::check_dependancies();

		$temp_destination = sys_get_temp_dir() . '/' . 'static-mirror-' . rand( 0,99999 );

		wp_mkdir_p( $destination );

		// Load cookies and resource domains from options, then apply filters
		$mirror_cookies = [];
		$raw_cookies = (string) get_option( 'static_mirror_crawler_cookies', '' );
		foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw_cookies ) ) ) as $line ) {
			if ( strpos( $line, '=' ) !== false ) {
				list( $k, $v ) = array_map( 'trim', explode( '=', $line, 2 ) );
				if ( $k !== '' ) { $mirror_cookies[$k] = $v; }
			}
		}
		if ( empty( $mirror_cookies ) ) { $mirror_cookies = array( 'wp_static_mirror' => 1 ); }
		$mirror_cookies = apply_filters( 'static_mirror_crawler_cookies', $mirror_cookies );

		$resource_domains = [];
		$raw_domains = (string) get_option( 'static_mirror_resource_domains', '' );
		foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw_domains ) ) ) as $host ) {
			$host = preg_replace( '/^https?:\/\//', '', $host );
			$host = rtrim( $host, '/' );
			if ( $host !== '' ) { $resource_domains[] = $host; }
		}
		$resource_domains = apply_filters( 'static_mirror_resource_domains', $resource_domains );

		$cookie_string = implode( ';', array_map( function( $v, $k ) {
			return $k . '=' . $v;
		}, $mirror_cookies, array_keys( $mirror_cookies ) ) );

		foreach ( $urls as $url ) {

			$allowed_domains = $resource_domains;
			$allowed_domains[] = parse_url( $url )['host'];

			$reject_arg = self::build_reject_regex_arg();

			// Wget args. Broken into an array for better readability.
			$args = array(
				sprintf( '--user-agent="%s"', 'WordPress/Static-Mirror; ' . get_bloginfo( 'url' ) ),
				'--no-clobber',
				'--page-requisites',
				'--convert-links',
				'--backup-converted',
				sprintf( '%s', $recursive ? '--recursive' : '' ),
				'--restrict-file-names=windows',
				'--html-extension',
				'--content-on-error',
				'--trust-server-names',
				sprintf( '--header %s', escapeshellarg( 'Cookie: ' . $cookie_string ) ),
				'--span-hosts',
				sprintf( '--domains=%s', escapeshellarg( implode( ',', $allowed_domains ) ) ),
				sprintf( '--directory-prefix=%s', escapeshellarg( $temp_destination ) ),
			);

			if ( $reject_arg !== '' ) {
				// Insert reject arg after restrict-file-names for readability
				$pos = array_search( '--restrict-file-names=windows', $args, true );
				if ( $pos !== false ) {
					array_splice( $args, $pos + 1, 0, [ $reject_arg ] );
				} else {
					$args[] = $reject_arg;
				}
			}

			// Allow bypassing cert check for local (option or constant).
			$no_check_opt = (int) get_option( 'static_mirror_no_check_certificate', 0 ) === 1;

			if ( $no_check_opt || ( defined( 'SM_NO_CHECK_CERT' ) && SM_NO_CHECK_CERT ) ) {
				$args[] = '--no-check-certificate';
			}

			$ua = '';
			$sm_settings = get_option( 'static_mirror_settings', array() );
			if ( ! empty( $sm_settings['user_agent'] ) ) {
				$ua = (string) $sm_settings['user_agent'];
			} else {
				$ua = get_option( 'static_mirror_user_agent', '' );
			}
			if ( $ua ) {
				$args[0] = sprintf( '--user-agent=%s', escapeshellarg( $ua ) );
			}

			// Respect robots toggle
			$robots_on_setting = isset( $sm_settings['robots_on'] ) ? (int) $sm_settings['robots_on'] : (int) get_option( 'static_mirror_robots_on', 0 );
			if ( $robots_on_setting === 1 ) {
				$args[] = '--execute robots=on';
			} else {
				$args[] = '--execute robots=off';
			}

			// Add wait/random-wait and level args based on consolidated settings.
			$wait_time = isset( $sm_settings['wait_seconds'] ) ? (int) $sm_settings['wait_seconds'] : (int) get_option( 'static_mirror_wait_time', 0 );
			if ( $wait_time > 0 ) {
				$args[] = sprintf( '--wait=%d', $wait_time );
			}
			$random_wait = isset( $sm_settings['random_wait'] ) ? (int) $sm_settings['random_wait'] : (int) get_option( 'static_mirror_random_wait', 0 );
			if ( $random_wait > 0 ) {
				$args[] = '--random-wait';
			}
			$level = isset( $sm_settings['level'] ) ? (int) $sm_settings['level'] : (int) get_option( 'static_mirror_level', 0 );
			if ( $level > 0 ) {
				$args[] = sprintf( '--level=%d', $level );
			}

			$cmd = sprintf( 'wget %s %s 2>&1', implode( ' ', $args ), escapeshellarg( esc_url_raw( $url ) ) );

			$data = shell_exec( $cmd );

			// we can infer the command failed if the temp dir does not exist.
			if ( ! is_dir( $temp_destination ) ) {
				throw new Exception( 'wget command failed to return any data (cmd: ' . $cmd . ', data: ' . $data . ')' );
			}

		}

		static::move_directory( untrailingslashit( $temp_destination ), untrailingslashit( $destination ) );

	}

	/**
	 * Perform a dry-run crawl using wget --spider. Returns summary info.
	 *
	 * @param array $urls
	 * @param bool $recursive
	 * @return array{cmd:string,codes:array,rejects:int,raw:string}
	 */
	public function dry_run( array $urls, bool $recursive ) : array {
		$settings = get_option( 'static_mirror_settings', [] );
		$ua = ! empty( $settings['user_agent'] ) ? (string) $settings['user_agent'] : ( 'WordPress/Static-Mirror; ' . home_url() );
		$cookie_header = '';
		if ( ! empty( $settings['crawler_cookies'] ) ) {
			$cookies = [];
			foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $settings['crawler_cookies'] ) ) ) as $line ) {
				if ( strpos( $line, '=' ) !== false ) { $cookies[] = $line; }
			}
			if ( ! empty( $cookies ) ) {
				$cookie_header = sprintf( '--header %s', escapeshellarg( 'Cookie: ' . implode( ';', $cookies ) ) );
			}
		}

		$reject_arg = self::build_reject_regex_arg();
		$robots_on = ! empty( $settings['robots_on'] );
		$level = isset( $settings['level'] ) ? (int) $settings['level'] : 0;

		$allowed_domains = [];
		if ( ! empty( $settings['resource_domains'] ) ) {
			foreach ( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $settings['resource_domains'] ) ) ) as $host ) {
				$host = preg_replace( '/^https?:\/\//', '', $host );
				$host = rtrim( $host, '/' );
				if ( $host !== '' ) { $allowed_domains[] = $host; }
			}
		}

		$cmd_parts = [
			'wget',
			sprintf( '--user-agent=%s', escapeshellarg( $ua ) ),
			'--spider',
			$robots_on ? '--execute robots=on' : '--execute robots=off',
			$reject_arg,
			$cookie_header,
			'--span-hosts',
		];
		if ( $recursive ) { $cmd_parts[] = '--recursive'; }
		if ( $level > 0 ) { $cmd_parts[] = sprintf( '--level=%d', $level ); }
		if ( ! empty( $allowed_domains ) ) {
			$cmd_parts[] = sprintf( '--domains=%s', escapeshellarg( implode( ',', $allowed_domains ) ) );
		}

		$cmd_parts = array_values( array_filter( $cmd_parts ) );

		$full_cmds = [];
		foreach ( $urls as $url ) {
			$full_cmds[] = sprintf( '%s %s 2>&1', implode( ' ', $cmd_parts ), escapeshellarg( esc_url_raw( $url ) ) );
		}

		$raw_output = '';
		foreach ( $full_cmds as $cmd ) {
			$raw_output .= shell_exec( $cmd ) ?: '';
			$raw_output .= "\n";
		}

		$codes = [];
		if ( preg_match_all( '/\s(\d{3})\s/', $raw_output, $m ) ) {
			foreach ( $m[1] as $code ) {
				$code = (string) $code;
				$codes[$code] = isset( $codes[$code] ) ? $codes[$code] + 1 : 1;
			}
		}
		$rejects = substr_count( $raw_output, 'Rejecting' );

		return [
			'cmd' => implode( ' && ', $full_cmds ),
			'codes' => $codes,
			'rejects' => $rejects,
			'raw' => $raw_output,
		];
	}

	/**
	 * Copies contents from $source to $dest, optionally ignoring SVN meta-data
	 * folders (default).
	 * @param string $source
	 * @param string $dest
	 * @return boolean true on success false otherwise
	 */
	public static function move_directory( $source, $dest ) {

		$sourceHandle = opendir( $source );

		if ( ! $sourceHandle ) {
			return false;
		}

		while ( $file = readdir( $sourceHandle ) ) {
			if ( $file == '.' || $file == '..' ) {
				continue;
			}

			if ( is_dir( $source . '/' . $file ) ) {

				wp_mkdir_p( $dest . '/' . $file );

				self::move_directory( $source . '/' . $file, $dest . '/' . $file );
			} else {

				// we want to get the mimetype of the file as wget will not use extensions
				// very well.
				$options = stream_context_get_options( stream_context_get_default() );

				if ( pathinfo( $source . '/' . $file, PATHINFO_EXTENSION ) === 'html' && isset( $options['s3'] ) ) {
					$finfo = finfo_open( FILEINFO_MIME_TYPE );
					$mimetype = finfo_file( $finfo, $source . '/' . $file );
					finfo_close($finfo);

					$options = stream_context_get_options( stream_context_get_default() );
					$options['s3']['ContentType'] = $mimetype;
					$context = stream_context_create( $options );

					@copy( $source . '/' . $file, $dest . '/' . $file, $context );
				} else {
					@copy( $source . '/' . $file, $dest . '/' . $file );
				}

				unlink( $source . '/' . $file );
			}

		}

		return true;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Path to directory.
	 * @return bool
	 */
	public static function rrmdir( string $path ) : bool {
		try {
			$iterator = new DirectoryIterator( $path );
			foreach ( $iterator as $fileinfo ) {
				if ( $fileinfo->isDot() ) {
					continue;
				}
				if ( $fileinfo->isDir() && self::rrmdir( $fileinfo->getPathname() ) ) {
					rmdir( $fileinfo->getPathname() );
				}
				if( $fileinfo->isFile() ) {
					unlink( $fileinfo->getPathname() );
				}
			}
		} catch ( Exception $e ){
			trigger_error( $e->getMessage(), E_USER_WARNING );
			return false;
		}

		return true;
	}

	/**
	 * Check if we have all the needed dependancies for the mirroring
	 * @throws Exception
	 * @return void
	 */
	public static function check_dependancies() {

		static::is_shell_exec_available();

		if ( ! is_null( shell_exec( 'hash wget 2>&1' ) ) ) {
			throw new Exception( 'wget is not available.' );
		}

	}

	/**
	 * Check whether shell_exec has been disabled.
	 *
	 * @throws Exception
	 * @return void
	 */
	private static function is_shell_exec_available() {

		// Are we in Safe Mode
		if ( self::is_safe_mode_active() )
			throw new Exception( 'Safe mode is active.' );

		// Is shell_exec or escapeshellcmd or escapeshellarg disabled?
		if ( array_intersect( array( 'shell_exec', 'escapeshellarg', 'escapeshellcmd' ), array_map( 'trim', explode( ',', @ini_get( 'disable_functions' ) ) ) ) )
			throw new Exception( 'Shell exec is disabled via disable_functions.' );

		// Functions can also be disabled via suhosin
		if ( array_intersect( array( 'shell_exec', 'escapeshellarg', 'escapeshellcmd' ), array_map( 'trim', explode( ',', @ini_get( 'suhosin.executor.func.blacklist' ) ) ) ) )
			throw new Exception( 'Shell exec is disabled via Suhosin.' );

		// Can we issue a simple echo command?
		if ( ! @shell_exec( 'echo backupwordpress' ) )
			throw new Exception( 'Shell exec is not functional.' );
	}

	/**
	 * Check whether safe mode is active or not
	 *
	 * @param string $ini_get_callback
	 * @return bool
	 */
	private static function is_safe_mode_active( $ini_get_callback = 'ini_get' ) {

		if ( ( $safe_mode = @call_user_func( $ini_get_callback, 'safe_mode' ) ) && strtolower( $safe_mode ) != 'off' )
			return true;

		return false;

	}

	/**
	 * Build wget --reject-regex argument from defaults and admin-provided patterns.
	 */
	private static function build_reject_regex_arg() {
		$joined = self::build_reject_regex_pattern();
		if ( $joined === '' ) {
			return '';
		}
		return sprintf( '--reject-regex %s', escapeshellarg( $joined ) );
	}

	/**
	 * Build the joined POSIX ERE pattern used for --reject-regex.
	 */
	public static function build_reject_regex_pattern() : string {
		$defaults = [
			'.+\/feed\/?$',
			'.+\/wp-json\/?(.+)?$',
		];

		// Read from consolidated settings only
		$settings = get_option( 'static_mirror_settings', [] );
		$raw = '';
		if ( is_array( $settings ) && array_key_exists( 'reject_patterns', $settings ) ) {
			$raw = (string) $settings['reject_patterns'];
		}

		$user_lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );

		$patterns = [];
		if ( empty( $user_lines ) ) {
			$patterns = $defaults;
		}
		foreach ( $user_lines as $line ) {
			if ( $line === '' ) { continue; }
			// If looks like delimited regex, strip delimiters; else use raw (presets are ERE-safe).
			if ( preg_match( '/^(.).+\1[imsxuADSUXJ]*$/', $line ) ) {
				$delim = substr( $line, 0, 1 );
				$last = strrpos( $line, $delim );
				if ( $last !== false ) {
					$body = substr( $line, 1, $last - 1 );
					$patterns[] = $body;
				}
			} else {
				$patterns[] = $line;
			}
		}

		return implode( '|', $patterns );
	}
}
