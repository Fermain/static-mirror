<?php

namespace Static_Mirror;

class Mirrorer {

	/**
	 * Create a static mirror of the site by given urls
	 * 
	 * @param  Array  $urls
	 * @param  String $destination
	 * @return WP_Error|null
	 */
	public static function create( Array $urls, $destination ) {

		if ( ! static::check_dependancies() ) {
			return new WP_Error( 'dependancies-not-met', 'You do not have the necessary dependancies to run a mirror.' );
		}

		$temp_destination = sys_get_temp_dir() . '/' . 'static-mirror-' . rand( 0,99999 );

		wp_mkdir_p( $destination );

		foreach ( $urls as $url ) {

			$cmd = sprintf( 
				'wget --user-agent="%s" -nc -p -k -r -erobots=off --restrict-file-names=windows --html-extension -P %s %s',
				'WordPress/Static-Mirror; ' . get_bloginfo( 'url' ),
				escapeshellarg( $temp_destination ),
				escapeshellarg( esc_url_raw( $url ) )
			);

			shell_exec( $cmd );
		}

		static::move_directory( untrailingslashit( $temp_destination ), untrailingslashit( $destination ) );
		
	}

	/**
	 * Copies contents from $source to $dest, optionally ignoring SVN meta-data
	 * folders (default).
	 * @param string $source
	 * @param string $dest
	 * @return boolean true on success false otherwise
	 */
	public static function move_directory($source, $dest ) {

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
				if ( ! @copy( $source . '/' . $file, $dest . '/' . $file ) ) {

				}
				unlink( $source . '/' . $file );
			}

		}
	   
		return true;
	}

	/**
	 * Check if we have all the needed dependancies for the mirroring
	 * @return bool
	 */
	public static function check_dependancies() {

		if ( ! static::is_shell_exec_available() ) {
			return false;
		}

		if ( ! is_null( shell_exec( 'hash wget 2>&1' ) ) ) {
			return false; 
		}

		return true;
	}

	/**
	 * Check whether shell_exec has been disabled.
	 *
	 * @return bool
	 */
	private static function is_shell_exec_available() {

		// Are we in Safe Mode
		if ( self::is_safe_mode_active() )
			return false;

		// Is shell_exec or escapeshellcmd or escapeshellarg disabled?
		if ( array_intersect( array( 'shell_exec', 'escapeshellarg', 'escapeshellcmd' ), array_map( 'trim', explode( ',', @ini_get( 'disable_functions' ) ) ) ) )
			return false;

		// Functions can also be disabled via suhosin
		if ( array_intersect( array( 'shell_exec', 'escapeshellarg', 'escapeshellcmd' ), array_map( 'trim', explode( ',', @ini_get( 'suhosin.executor.func.blacklist' ) ) ) ) )
			return false;

		// Can we issue a simple echo command?
		if ( ! @shell_exec( 'echo backupwordpress' ) )
			return false;

		return true;

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
}