<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * دستورهای WP-CLI (برای سایت‌های خیلی سنگین یا اجرا با cron/SSH).
 *
 *   wp utk scan [--no-hash] [--algo=<algo>]
 *   wp utk clean <stale|unused|dups> [--dry-run] [--permanent] [--alt] [--yes]
 *   wp utk trash [--restore=<batch>] [--purge=<batch>] [--purge-all]
 */
final class UTK_CLI {

	public static function init() {
		WP_CLI::add_command( 'utk', __CLASS__ );
	}

	/**
	 * اسکن کامل uploads و ذخیره‌ی نتیجه.
	 *
	 * ## OPTIONS
	 *
	 * [--no-hash]
	 * : هش‌گیری برای یافتن تکراری‌ها انجام نشود.
	 *
	 * [--algo=<algo>]
	 * : الگوریتم هش (md5, sha1, sha256, ...).
	 */
	public function scan( $args, $assoc ) {
		$o = array( 'hash' => ! isset( $assoc['no-hash'] ) );
		if ( isset( $assoc['algo'] ) ) {
			$o['algo'] = $assoc['algo'];
		}
		$last = '';
		$s    = UTK_Scan::run_all(
			$o,
			function ( $st ) use ( &$last ) {
				if ( $st['label'] !== $last ) {
					$last = $st['label'];
					WP_CLI::log( sprintf( '[%d%%] %s', $st['pct'], $st['label'] ) );
				}
			}
		);
		if ( 'done' !== $s['status'] ) {
			WP_CLI::error( 'اسکن ناموفق: ' . $s['msg'] );
		}
		UTK_Query::reset();
		$r = UTK_Query::result();
		$S = $r['S'];
		foreach ( array( 'used' => 'استفاده‌شده', 'inactive' => 'فقط پیش‌نویس/زباله‌دان', 'unused' => 'بلااستفاده' ) as $k => $label ) {
			WP_CLI::log( sprintf( '%s: %d گروه، %d فایل، %s', $label, $S['st'][ $k ][0], $S['st'][ $k ][1], size_format( $S['st'][ $k ][2] ) ) );
		}
		WP_CLI::log( sprintf( 'نسخه‌های اضافه (stale): %d فایل، %s', $S['stale'][0], size_format( $S['stale'][1] ) ) );
		WP_CLI::success( 'اسکن کامل شد. حجم تقریباً قابل بازیابی: ' . size_format( $S['reclaim'] ) );
	}

	/**
	 * پاک‌سازی بر اساس آخرین اسکن؛ پیش‌فرض: انتقال به سطل بازیافت.
	 *
	 * ## OPTIONS
	 *
	 * <what>
	 * : stale (نسخه‌های اضافه‌ی ثبت‌نشده) | unused (بلااستفاده‌ها) | dups (نسخه‌های تکراری)
	 *
	 * [--dry-run]
	 * : فقط نمایش تعداد و حجم.
	 *
	 * [--permanent]
	 * : حذف دائمی (بدون سطل).
	 *
	 * [--alt]
	 * : در حالت stale، نسخه‌های webp/avif ثبت‌نشده هم حذف شوند.
	 *
	 * [--yes]
	 * : بدون پرسیدن تأیید.
	 */
	public function clean( $args, $assoc ) {
		$what = isset( $args[0] ) ? $args[0] : '';
		if ( ! in_array( $what, array( 'stale', 'unused', 'dups' ), true ) ) {
			WP_CLI::error( 'یکی از stale / unused / dups را بدهید.' );
		}
		$err = UTK_Ops::check_ready();
		if ( $err ) {
			WP_CLI::error( $err );
		}
		$r    = UTK_Query::result();
		$G    = $r['G'];
		$perm = isset( $assoc['permanent'] );
		$opts = array( 'alt' => isset( $assoc['alt'] ) ? 1 : 0 );
		$sets = array();
		if ( 'dups' === $what ) {
			$sets  = UTK_Query::dups_suggestions();
			$gids  = array();
			foreach ( $sets as $s ) {
				$gids = array_merge( $gids, $s['del'] );
			}
			$mode = 'group';
		} elseif ( 'stale' === $what ) {
			$mode = 'stale';
			$gids = UTK_Query::match( $G, UTK_Query::clean_filter( array( 'stale' => 1, 'old' => 1 ) ) );
		} else {
			$mode = 'group';
			$gids = UTK_Query::match( $G, UTK_Query::clean_filter( array( 'status' => 'unused', 'old' => 1 ) ) );
		}
		$bytes = 0;
		foreach ( $gids as $gid ) {
			$bytes += 'stale' === $mode ? ( $G[ $gid ]['stale_bytes'] + ( $opts['alt'] ? $G[ $gid ]['alt_bytes'] : 0 ) ) : $G[ $gid ]['bytes'];
		}
		WP_CLI::log( sprintf( '%d مورد، حدود %s (%s)', count( $gids ), size_format( $bytes ), $perm ? 'حذف دائمی' : 'انتقال به سطل' ) );
		if ( ! $gids || isset( $assoc['dry-run'] ) ) {
			return;
		}
		WP_CLI::confirm( $perm ? 'حذف دائمی انجام شود؟' : 'به سطل بازیافت منتقل شود؟', $assoc );
		$bid   = UTK_Ops::begin( $mode, $perm, 'wp-cli ' . $what );
		$total = array( 'items' => 0, 'files' => 0, 'bytes' => 0 );
		$chunks = 'dups' === $what ? array_chunk( $sets, 10 ) : array_chunk( $gids, 20 );
		foreach ( $chunks as $c ) {
			$out = 'dups' === $what ? UTK_Ops::run_dups( $c, $perm, $bid ) : UTK_Ops::run( $mode, $c, $perm, $bid, $opts );
			if ( isset( $out['error'] ) ) {
				WP_CLI::error( $out['error'] );
			}
			foreach ( $total as $k => $v ) {
				$total[ $k ] += $out[ $k ];
			}
			foreach ( $out['errors'] as $e ) {
				WP_CLI::warning( $e );
			}
		}
		UTK_Ops::end( $perm, $bid, $mode, $total, 'wp-cli ' . $what );
		WP_CLI::success( sprintf( '%d مورد، %d فایل، %s %s.', $total['items'], $total['files'], size_format( $total['bytes'] ), $perm ? 'حذف شد' : 'به سطل منتقل شد' ) );
	}

	/**
	 * مدیریت سطل بازیافت.
	 *
	 * ## OPTIONS
	 *
	 * [--restore=<batch>]
	 * : بازگردانی یک دسته.
	 *
	 * [--purge=<batch>]
	 * : حذف دائمی یک دسته.
	 *
	 * [--purge-all]
	 * : خالی کردن کل سطل.
	 */
	public function trash( $args, $assoc ) {
		if ( isset( $assoc['restore'] ) ) {
			$o = UTK_Ops::restore( $assoc['restore'] );
			isset( $o['error'] ) ? WP_CLI::error( $o['error'] ) : WP_CLI::success( $o['files'] . ' فایل بازگردانی شد.' );
			return;
		}
		if ( isset( $assoc['purge'] ) ) {
			WP_CLI::success( size_format( UTK_Ops::purge( $assoc['purge'] ) ) . ' آزاد شد.' );
			return;
		}
		if ( isset( $assoc['purge-all'] ) ) {
			WP_CLI::confirm( 'کل سطل خالی شود؟', $assoc );
			WP_CLI::success( size_format( UTK_Ops::purge_all() ) . ' آزاد شد.' );
			return;
		}
		foreach ( UTK_Ops::batches() as $b ) {
			WP_CLI::log( sprintf( '%s  %s  %d مورد  %s  [%s]', $b['id'], $b['mode'], $b['items'], size_format( $b['bytes'] ), $b['label'] ) );
		}
	}
}
