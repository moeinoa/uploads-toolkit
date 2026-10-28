<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ذخیره‌ی وضعیت اسکن، نتایج، تنظیمات، لاگ و سطل بازیافت.
 * همه چیز در wp-content/utk-data-<hash>/ (خارج از uploads) نگه‌داری می‌شود؛ فایل‌های داده با
 * هدر "<?php exit; ?>" شروع می‌شوند تا حتی در صورت دسترسی مستقیم، محتوایی نمایش داده نشود.
 */
final class UTK_Store {

	const HDR = "<?php exit; ?>\n";

	public static function dir() {
		static $d = null;
		if ( null === $d ) {
			$d = wp_normalize_path( WP_CONTENT_DIR ) . '/utk-data-' . substr( md5( wp_salt( 'auth' ) ), 0, 10 );
			if ( ! is_dir( $d ) ) {
				wp_mkdir_p( $d );
				file_put_contents( $d . '/index.php', "<?php // silence\n" );
				file_put_contents( $d . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			}
		}
		return $d;
	}

	public static function trash_dir() {
		$d = self::dir() . '/trash';
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
		return $d;
	}

	public static function path( $name ) {
		return self::dir() . '/' . $name . '.php';
	}

	public static function write_file( $file, $data ) {
		$tmp = $file . '.tmp' . getmypid();
		if ( false === file_put_contents( $tmp, self::HDR . serialize( $data ), LOCK_EX ) ) {
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $file );
			if ( ! @rename( $tmp, $file ) ) {
				@unlink( $tmp );
				return false;
			}
		}
		return true;
	}

	public static function read_file( $file ) {
		if ( ! is_file( $file ) ) {
			return null;
		}
		$s = file_get_contents( $file );
		if ( false === $s || 0 !== strpos( $s, self::HDR ) ) {
			return null;
		}
		$v = @unserialize( substr( $s, strlen( self::HDR ) ), array( 'allowed_classes' => false ) );
		return false === $v ? null : $v;
	}

	public static function write( $name, $data ) {
		return self::write_file( self::path( $name ), $data );
	}

	public static function read( $name ) {
		return self::read_file( self::path( $name ) );
	}

	public static function delete( $name ) {
		@unlink( self::path( $name ) );
	}

	public static function lock( $name ) {
		$h = @fopen( self::dir() . '/' . $name . '.lock', 'c' );
		if ( ! $h ) {
			return false;
		}
		if ( ! flock( $h, LOCK_EX | LOCK_NB ) ) {
			fclose( $h );
			return false;
		}
		return $h;
	}

	public static function unlock( $h ) {
		if ( $h ) {
			flock( $h, LOCK_UN );
			fclose( $h );
		}
	}

	/* ------------------------------------------------------------ settings */

	public static function defaults() {
		return array(
			'min_age'       => 3,   // فایل‌های جدیدتر از N روز دست‌نخورده می‌مانند
			'trash_days'    => 14,  // نگه‌داری سطل بازیافت
			'max_age_h'     => 24,  // حداکثر عمر نتیجه‌ی اسکن برای اجازه‌ی حذف (ساعت)
			'algo'          => 'md5',
			'hash'          => 1,   // محاسبه‌ی هش برای یافتن تکراری‌ها
			'inactive_used' => 0,   // ارجاع از پیش‌نویس/زباله‌دان هم «استفاده» حساب شود
			'protect'       => '',  // الگوهای محافظت‌شده (هر خط یک الگو؛ مثال: 2019/*)
			'budget'        => 20,  // ثانیه‌ی کار در هر درخواست اسکن
		);
	}

	public static function settings() {
		$s = get_option( 'utk_settings', array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	public static function save_settings( $in ) {
		$d    = self::defaults();
		$algo = isset( $in['algo'] ) ? (string) $in['algo'] : 'md5';
		$s    = array(
			'min_age'       => max( 0, min( 3650, (int) ( isset( $in['min_age'] ) ? $in['min_age'] : $d['min_age'] ) ) ),
			'trash_days'    => max( 0, min( 3650, (int) ( isset( $in['trash_days'] ) ? $in['trash_days'] : $d['trash_days'] ) ) ),
			'max_age_h'     => max( 1, min( 720, (int) ( isset( $in['max_age_h'] ) ? $in['max_age_h'] : $d['max_age_h'] ) ) ),
			'algo'          => in_array( $algo, hash_algos(), true ) && in_array( $algo, self::algos(), true ) ? $algo : 'md5',
			'hash'          => empty( $in['hash'] ) ? 0 : 1,
			'inactive_used' => empty( $in['inactive_used'] ) ? 0 : 1,
			'protect'       => isset( $in['protect'] ) ? sanitize_textarea_field( (string) $in['protect'] ) : '',
			'budget'        => max( 5, min( 120, (int) ( isset( $in['budget'] ) ? $in['budget'] : $d['budget'] ) ) ),
		);
		update_option( 'utk_settings', $s, false );
		return $s;
	}

	public static function algos() {
		return array_values( array_intersect( array( 'md5', 'sha1', 'sha256', 'crc32b', 'xxh3', 'xxh128' ), hash_algos() ) );
	}

	/* ----------------------------------------------------------------- log */

	public static function log( array $row ) {
		$f   = self::dir() . '/log.php';
		$row = array( 't' => time(), 'u' => wp_get_current_user()->user_login ) + $row;
		if ( ! is_file( $f ) ) {
			file_put_contents( $f, self::HDR );
		}
		file_put_contents( $f, wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX );
	}

	public static function read_log( $n = 200 ) {
		$f = self::dir() . '/log.php';
		if ( ! is_file( $f ) ) {
			return array();
		}
		$lines = array_slice( (array) file( $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), 1 );
		$out   = array();
		foreach ( array_reverse( array_slice( $lines, -$n ) ) as $l ) {
			$r = json_decode( $l, true );
			if ( is_array( $r ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}
}
