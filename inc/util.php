<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * توابع کمکی: نرمال‌سازی مسیرها، استخراج ارجاع‌ها از متن/متا، اعتبارسنجی مسیر فایل.
 */
final class UTK_Util {

	// کلید متاهایی که احتمالاً ID پیوست (attachment) نگه می‌دارند
	const KEY_RX = 'thumb|image|img|gallery|logo|photo|picture|banner|icon|cover|poster|avatar|attachment|media|background|slide|(^|_)ids?($|_)';
	const NOISE  = '/width|height|size|count|position|ratio|opacity|repeat/i';
	// جدول‌های افزونه‌ای که فقط کش/لاگ/داده‌ی مشتق‌شده‌اند و «استفاده‌ی واقعی» حساب نمی‌شوند
	const SKIP_TBL = '/(^|_)(logs?|cache|sessions?|stats?|statistics|queue|actionscheduler\w*|e_events\w*|wc_admin\w*|litespeed\w*|wf\w+|ewww\w*|smush\w*|shortpixel\w*|imagify\w*|itsec\w*|aiowps\w*|duplicator\w*|yoast\w*|rank_math\w*|icl_\w+|wpml\w*)($|_)/i';

	public static function base() {
		static $b = null;
		if ( null === $b ) {
			$u = wp_get_upload_dir();
			$b = untrailingslashit( wp_normalize_path( $u['basedir'] ) );
		}
		return $b;
	}

	public static function baseurl() {
		$u = wp_get_upload_dir();
		return untrailingslashit( $u['baseurl'] );
	}

	// نام پوشه‌ی آپلود (معمولاً "uploads") که در URL ها دنبالش می‌گردیم
	public static function mk() {
		static $m = null;
		if ( null === $m ) {
			$m = basename( self::base() );
		}
		return $m;
	}

	public static function fold( $s ) {
		$s = (string) $s;
		if ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_C );
			if ( is_string( $n ) ) {
				$s = $n;
			}
		}
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	public static function split( $p ) {
		$i = strrpos( $p, '/' );
		return false === $i ? array( '', $p ) : array( substr( $p, 0, $i + 1 ), substr( $p, $i + 1 ) );
	}

	// کلید «فایل منطقی»: مسیر بدون پسوند و بدون -300x200 / -scaled / -e1234567890 / @2x / .webp اضافه
	public static function stem_key( $k ) {
		list( $d, $n ) = self::split( $k );
		$n = preg_replace( '/\.[^.]+$/', '', $n );
		$n = preg_replace( '/\.(?:jpe?g|png|gif|webp|avif|bmp|tiff?)$/', '', $n );
		$n = preg_replace( '/(?:-\d+x\d+|-scaled|-rotated|-e\d{10,}|@\dx)+$/', '', $n );
		return $d . $n;
	}

	public static function ext( $rel ) {
		$p = strrpos( $rel, '.' );
		return false === $p ? '' : strtolower( substr( $rel, $p + 1 ) );
	}

	public static function kind( $rel ) {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			$def = array(
				'image'   => 'jpg jpeg jpe png gif webp avif bmp svg ico tif tiff heic',
				'video'   => 'mp4 mov avi mkv webm m4v wmv flv mpg mpeg 3gp',
				'audio'   => 'mp3 wav ogg m4a flac aac',
				'pdf'     => 'pdf',
				'doc'     => 'doc docx xls xlsx ppt pptx odt ods txt csv rtf',
				'archive' => 'zip rar 7z gz tar bz2',
			);
			foreach ( $def as $k => $list ) {
				foreach ( explode( ' ', $list ) as $e ) {
					$map[ $e ] = $k;
				}
			}
		}
		$e = self::ext( $rel );
		return isset( $map[ $e ] ) ? $map[ $e ] : 'other';
	}

	public static function is_thumbable( $rel ) {
		return in_array( self::ext( $rel ), array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp' ), true );
	}

	// پوشه‌ی سال/ماه یا نام پوشه‌ی اصلی
	public static function ym( $rel ) {
		if ( preg_match( '#^(\d{4}/\d{2})/#', $rel, $m ) ) {
			return $m[1];
		}
		$i = strpos( $rel, '/' );
		return false === $i ? '(ریشه)' : substr( $rel, 0, $i );
	}

	public static function top( $rel ) {
		$i = strpos( $rel, '/' );
		return false === $i ? '(root)' : substr( $rel, 0, $i );
	}

	// مسیر مطلق امن یک فایل داخل uploads، یا false (جلوگیری از path traversal / symlink)
	public static function safe_abs( $rel ) {
		$rel = (string) $rel;
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || '/' === $rel[0] || preg_match( '#(^|/)\.\.(/|$)#', $rel ) ) {
			return false;
		}
		list( , $name ) = self::split( $rel );
		if ( '' === $name || '.' === $name[0] || 'index.php' === $name ) {
			return false;
		}
		$base = self::base();
		$abs  = $base . '/' . $rel;
		if ( is_link( $abs ) || ! is_file( $abs ) ) {
			return false;
		}
		$rb = realpath( $base );
		$ra = realpath( $abs );
		if ( ! $rb || ! $ra || 0 !== strpos( wp_normalize_path( $ra ), wp_normalize_path( $rb ) . '/' ) ) {
			return false;
		}
		return $abs;
	}

	public static function bucket_of( $status ) {
		return in_array( $status, array( 'publish', 'private', 'future' ), true ) ? 'live' : 'off';
	}

	/* ---------------------------------------------- استخراج ارجاع‌ها */

	// مقدارهای عددی/لیست عددی/آرایه‌ی سریالایز‌شده → ID پیوست‌ها
	public static function take_ids( array &$R, $b, $v ) {
		$v = maybe_unserialize( $v );
		if ( is_array( $v ) ) {
			foreach ( $v as $x ) {
				self::take_ids( $R, $b, $x );
			}
			return;
		}
		if ( is_scalar( $v ) ) {
			$s = trim( str_replace( array( '[', ']', '"', ' ' ), '', (string) $v ) );
			if ( '' !== $s && preg_match( '/^\d+(,\d+)*$/', $s ) ) {
				foreach ( explode( ',', $s ) as $i ) {
					$R['id'][ $b ][ (int) $i ] = 1;
				}
			}
		}
	}

	// آپشن‌های سریالایز‌شده (theme_mods، ویجت‌ها، ...): فقط مقدار کلیدهایی که شبیه تصویر/ID هستند
	public static function take_keyed( array &$R, $b, $v ) {
		$v = maybe_unserialize( $v );
		if ( ! is_array( $v ) ) {
			return;
		}
		foreach ( $v as $k => $x ) {
			if ( is_string( $k ) && preg_match( '/' . self::KEY_RX . '/i', $k ) && ! preg_match( self::NOISE, $k ) ) {
				self::take_ids( $R, $b, $x );
			}
			if ( is_array( $x ) ) {
				self::take_keyed( $R, $b, $x );
			}
		}
	}

	// متن (محتوا، JSON، CSS، ...) → مسیرهای uploads + ارجاع‌های مبتنی بر ID
	public static function take_text( array &$R, $b, $t ) {
		if ( ! is_string( $t ) || '' === $t ) {
			return;
		}
		$t = str_replace( array( '\\/', '&amp;' ), array( '/', '&' ), $t );
		if ( false !== strpos( $t, '\\u' ) ) {
			$t = preg_replace_callback(
				'/\\\\u([0-9a-f]{4})/i',
				function ( $m ) {
					return (string) json_decode( '"\\u' . $m[1] . '"' );
				},
				$t
			);
		}
		if ( preg_match_all( '#' . preg_quote( self::mk(), '#' ) . '/([^\s"\'<>()\\\\,;?\#|]+)#', $t, $m ) ) {
			foreach ( $m[1] as $p ) {
				$R['p'][ $b ][ self::fold( rawurldecode( $p ) ) ] = 1;
			}
		}
		// ارجاع با ID: wp-image-12 ، "id":12 ، ids="1,2,3"
		if ( false !== strpos( $t, 'wp-image-' ) && preg_match_all( '/wp-image-(\d+)/', $t, $m ) ) {
			foreach ( $m[1] as $i ) {
				$R['id'][ $b ][ (int) $i ] = 1;
			}
		}
		if ( preg_match_all( '/"(?:id|ids|mediaId|imageId|attachmentId)"\s*:\s*(\[[\d,"\s]*\]|"?\d+)/', $t, $m ) ) {
			foreach ( $m[1] as $v ) {
				self::take_ids( $R, $b, $v );
			}
		}
		if ( preg_match_all( '/\bids\s*=\s*["\']?([\d,\s]+)/', $t, $m ) ) {
			foreach ( $m[1] as $v ) {
				self::take_ids( $R, $b, $v );
			}
		}
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				self::rrmdir( $p );
			} else {
				@unlink( $p );
			}
		}
		@rmdir( $dir );
	}

	public static function dir_size( $dir ) {
		$n = 0;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $f ) {
			$n += (int) $f->getSize();
		}
		return $n;
	}
}
