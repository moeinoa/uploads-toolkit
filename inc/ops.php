<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * عملیات حذف: انتقال به سطل بازیافت (قابل بازگردانی) یا حذف دائمی؛ بازگردانی، پاک‌سازی سطل.
 *
 * همه‌ی مسیرها از نتیجه‌ی اسکن ذخیره‌شده در سرور خوانده می‌شوند (نه از مرورگر) و قبل از هر حذف دوباره
 * اعتبارسنجی می‌شوند (داخل uploads بودن، symlink نبودن، تغییرنکردن فایل پس از اسکن، سنّ حداقلی، الگوهای محافظت‌شده).
 */
final class UTK_Ops {

	const BID_RX = '/^\d{8}-\d{6}-[a-z0-9]{4}$/';

	/* ----------------------------------------------------------- preflight */

	public static function check_ready() {
		$s = UTK_Store::read( 'state' );
		if ( is_array( $s ) && 'running' === $s['status'] ) {
			return 'یک اسکن در حال اجراست؛ تا پایان آن صبر کنید.';
		}
		$r = UTK_Query::result();
		if ( ! $r ) {
			return 'ابتدا یک اسکن کامل اجرا کنید.';
		}
		$age = time() - (int) $r['meta']['finished'];
		$max = (int) UTK_Store::settings()['max_age_h'] * HOUR_IN_SECONDS;
		if ( $age > $max ) {
			return 'نتیجه‌ی اسکن قدیمی است (بیش از ' . (int) UTK_Store::settings()['max_age_h'] . ' ساعت). برای امنیت، دوباره اسکن کنید.';
		}
		return '';
	}

	private static function protected_patterns() {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) UTK_Store::settings()['protect'] ) ) ) );
	}

	private static function is_prot( $rel, array $pats ) {
		foreach ( $pats as $p ) {
			if ( fnmatch( $p, $rel ) ) {
				return true;
			}
		}
		return false;
	}

	// خروجی: رشته (دلیل رد) یا آرایه‌ی فایل‌های قابل حذف: rel => [size, flag, mtime]
	private static function plan( $mode, array $g, array $files, array $opts ) {
		if ( 'group' === $mode ) {
			if ( 'used' === $g['status'] ) {
				return 'در حال استفاده';
			}
			if ( 'inactive' === $g['status'] && empty( $opts['inactive'] ) ) {
				return 'فقط در پیش‌نویس/زباله‌دان (نیازمند تأیید)';
			}
			if ( $g['recent'] ) {
				return 'جدیدتر از حداقل سن';
			}
			if ( $g['prot'] ) {
				return 'محافظت‌شده';
			}
			return $files;
		}
		$cut  = UTK_Scan::recent_cut();
		$pats = self::protected_patterns();
		$sel  = array();
		foreach ( $files as $rel => $f ) {
			$rel = (string) $rel;
			if ( ( 's' === $f[1] || ( 'a' === $f[1] && ! empty( $opts['alt'] ) ) ) && $f[2] <= $cut && ! self::is_prot( $rel, $pats ) ) {
				$sel[ $rel ] = $f;
			}
		}
		return $sel ? $sel : 'نسخه‌ی اضافه‌ای نیست';
	}

	/* ------------------------------------------------------------- batches */

	public static function begin( $mode, $perm, $label = '' ) {
		if ( $perm ) {
			return 'perm';
		}
		$bid = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );
		$dir = UTK_Store::trash_dir() . '/' . $bid;
		wp_mkdir_p( $dir );
		UTK_Store::write_file(
			$dir . '/manifest.php',
			array(
				'id'    => $bid,
				'time'  => time(),
				'user'  => wp_get_current_user()->user_login,
				'mode'  => $mode,
				'label' => $label,
				'items' => array(),
			)
		);
		return $bid;
	}

	private static function man_path( $bid ) {
		return preg_match( self::BID_RX, $bid ) ? UTK_Store::trash_dir() . '/' . $bid . '/manifest.php' : false;
	}

	// مسیر فایل داخل سطل؛ پسوند .utk اضافه می‌شود تا فایل (مثلاً php مشکوک) هرگز اجرا یا سرو نشود
	private static function stash_path( $bid, $rel ) {
		return UTK_Store::trash_dir() . '/' . $bid . '/f/' . $rel . '.utk';
	}

	// انتقال به سطل: rename (همان فایل‌سیستم) یا copy+unlink
	private static function stash( $bid, $rel, $abs ) {
		$dest = self::stash_path( $bid, $rel );
		wp_mkdir_p( dirname( $dest ) );
		if ( @rename( $abs, $dest ) ) {
			return true;
		}
		if ( @copy( $abs, $dest ) ) {
			if ( @unlink( $abs ) ) {
				return true;
			}
			@unlink( $dest );
		}
		return false;
	}

	/* ----------------------------------------------------------------- run */

	// حذف/انتقال gid ها. $mode: group (کل گروه) | stale (فقط نسخه‌های اضافه). $opts: inactive, alt
	public static function run( $mode, array $gids, $perm, $bid, array $opts = array() ) {
		global $wpdb;
		@set_time_limit( 120 );
		$err = self::check_ready();
		if ( $err ) {
			return array( 'error' => $err );
		}
		if ( ! in_array( $mode, array( 'group', 'stale' ), true ) ) {
			return array( 'error' => 'حالت نامعتبر' );
		}
		$man = null;
		if ( ! $perm ) {
			$mp  = self::man_path( $bid );
			$man = $mp ? UTK_Store::read_file( $mp ) : null;
			if ( ! is_array( $man ) ) {
				return array( 'error' => 'دسته‌ی سطل پیدا نشد؛ عملیات را دوباره شروع کنید.' );
			}
		}
		$res  = UTK_Query::result();
		$F    = UTK_Store::read( 'files' );
		$G    = $res['G'];
		$stat = array( 'items' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => array(), 'errors' => array() );
		$done = array();
		$skip = function ( $why ) use ( &$stat ) {
			$stat['skipped'][ $why ] = ( isset( $stat['skipped'][ $why ] ) ? $stat['skipped'][ $why ] : 0 ) + 1;
		};

		foreach ( $gids as $gid ) {
			$gid = (string) $gid;
			if ( ! isset( $G[ $gid ], $F[ $gid ] ) ) {
				$skip( 'قبلاً حذف شده / نامعتبر' );
				continue;
			}
			$g    = $G[ $gid ];
			$plan = self::plan( $mode, $g, $F[ $gid ], $opts );
			if ( is_string( $plan ) ) {
				$skip( $plan );
				continue;
			}
			// بررسی تغییر نکردن فایل‌ها پس از اسکن
			$abs = array();
			$bad = false;
			foreach ( $plan as $rel => $f ) {
				$rel = (string) $rel;
				$a   = UTK_Util::safe_abs( $rel );
				if ( ! $a ) {
					continue; // قبلاً حذف شده یا مسیر نامعتبر
				}
				if ( (int) @filemtime( $a ) !== (int) $f[2] || (int) @filesize( $a ) !== (int) $f[0] ) {
					$bad = true;
					break;
				}
				$abs[ $rel ] = $a;
			}
			if ( $bad ) {
				$skip( 'فایل پس از اسکن تغییر کرده' );
				continue;
			}
			$moved = array();   // rel => size
			$rows  = null;
			$id    = (int) $g['id'];
			$full  = false;

			if ( 'group' === $mode && $id ) {
				$post = $wpdb->get_row( "SELECT * FROM {$wpdb->posts} WHERE ID = {$id} AND post_type = 'attachment'", ARRAY_A );
				if ( $post ) {
					$rows = array( 'post' => $post, 'meta' => (array) $wpdb->get_results( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = {$id}", ARRAY_A ) );
					$undo = function () use ( &$moved, $bid ) {
						foreach ( array_keys( $moved ) as $rel ) {
							@rename( self::stash_path( $bid, $rel ), UTK_Util::base() . '/' . $rel );
						}
						$moved = array();
					};
					if ( ! $perm ) {
						$fail = false;
						foreach ( $abs as $rel => $a ) {
							if ( self::stash( $bid, $rel, $a ) ) {
								$moved[ $rel ] = $plan[ $rel ][0];
							} else {
								$fail = true;
								break;
							}
						}
						if ( $fail ) {
							// اگر حتی یک فایل به سطل منتقل نشود، wp_delete_attachment را اجرا نمی‌کنیم
							$undo();
							$stat['errors'][] = "#{$id}: انتقال به سطل ناموفق بود (دسترسی نوشتن؟)";
							continue;
						}
					}
					$ok = wp_delete_attachment( $id, true );
					if ( ! $ok ) {
						$undo();
						$stat['errors'][] = "#{$id}: wp_delete_attachment ناموفق بود";
						continue;
					}
					if ( $perm ) {
						foreach ( $abs as $rel => $a ) {
							if ( ! is_file( $a ) || @unlink( $a ) ) {
								$moved[ $rel ] = $plan[ $rel ][0];
							}
						}
					}
					$full = true;
				}
			}
			if ( ! $full ) {
				foreach ( $abs as $rel => $a ) {
					$okf = $perm ? @unlink( $a ) : self::stash( $bid, $rel, $a );
					if ( $okf ) {
						$moved[ $rel ] = $plan[ $rel ][0];
					} else {
						$stat['errors'][] = $rel . ': حذف/انتقال ناموفق';
					}
				}
				if ( ! $moved ) {
					$skip( 'فایل‌ها وجود نداشتند' );
					continue;
				}
			}
			$stat['items']++;
			$stat['files'] += count( $moved );
			$stat['bytes'] += array_sum( $moved );
			$done[]         = array( 'gid' => $gid, 'full' => $full || ( 'group' === $mode && count( $moved ) === count( $plan ) ), 'rels' => $moved );
			if ( $man ) {
				$man['items'][] = array( 'gid' => $gid, 'id' => $full ? $id : 0, 'files' => $moved, 'rows' => $rows );
			}
		}
		if ( $man ) {
			UTK_Store::write_file( self::man_path( $bid ), $man );
		}
		if ( $done ) {
			self::patch( $done );
		}
		return $stat;
	}

	// نتیجه‌ی ذخیره‌شده را بعد از حذف به‌روز می‌کند تا بدون اسکن مجدد هم UI درست بماند
	private static function patch( array $done ) {
		$res = UTK_Store::read( 'result' );
		$F   = UTK_Store::read( 'files' );
		if ( ! is_array( $res ) || ! is_array( $F ) ) {
			return;
		}
		$cut = UTK_Scan::recent_cut();
		foreach ( $done as $d ) {
			$gid = $d['gid'];
			foreach ( $d['rels'] as $rel => $size ) {
				$res['disk']['all'][0]--;
				$res['disk']['all'][1] -= $size;
				$top = UTK_Util::top( (string) $rel );
				if ( isset( $res['disk']['dirs'][ $top ] ) ) {
					$res['disk']['dirs'][ $top ][0]--;
					$res['disk']['dirs'][ $top ][1] -= $size;
				}
				unset( $F[ $gid ][ $rel ] );
			}
			if ( $d['full'] || empty( $F[ $gid ] ) ) {
				unset( $res['G'][ $gid ], $F[ $gid ] );
				foreach ( $res['D'] as $i => $set ) {
					$set['gids'] = array_values( array_diff( $set['gids'], array( $gid ) ) );
					if ( count( $set['gids'] ) < 2 ) {
						unset( $res['D'][ $i ] );
					} else {
						$res['D'][ $i ] = $set;
					}
				}
				$res['D'] = array_values( $res['D'] );
			} elseif ( isset( $res['G'][ $gid ] ) ) {
				UTK_Scan::recount( $res['G'][ $gid ], $F[ $gid ], $cut );
			}
		}
		$res['S'] = UTK_Scan::summary( $res['G'] );
		UTK_Store::write( 'result', $res );
		UTK_Store::write( 'files', $F );
		UTK_Query::reset();
	}

	/* ---------------------------------------------------------- duplicates */

	// حذف نسخه‌های تکراری: sets = [ ['h'=>hash, 'del'=>[gid,...]], ... ]
	public static function run_dups( array $sets, $perm, $bid ) {
		$err = self::check_ready();
		if ( $err ) {
			return array( 'error' => $err );
		}
		$res  = UTK_Query::result();
		$G    = $res['G'];
		$bySet = array();
		foreach ( $res['D'] as $s ) {
			$bySet[ $s['h'] ] = $s;
		}
		$verified = array();
		$extra    = array( 'skipped' => array(), 'errors' => array() );
		$skip     = function ( $why ) use ( &$extra ) {
			$extra['skipped'][ $why ] = ( isset( $extra['skipped'][ $why ] ) ? $extra['skipped'][ $why ] : 0 ) + 1;
		};
		foreach ( $sets as $s ) {
			$h   = isset( $s['h'] ) ? (string) $s['h'] : '';
			$del = isset( $s['del'] ) ? array_map( 'strval', (array) $s['del'] ) : array();
			if ( ! isset( $bySet[ $h ] ) || ! $del ) {
				$skip( 'مجموعه‌ی تکراری معتبر نیست' );
				continue;
			}
			$members = UTK_Query::dup_members( $bySet[ $h ], $G );
			if ( ! $members ) {
				$skip( 'مجموعه دیگر تکراری نیست' );
				continue;
			}
			$all    = array_column( $members, 'gid' );
			$can    = array();
			foreach ( $members as $m ) {
				$can[ $m['gid'] ] = $m['can'];
			}
			$keep = array_values( array_diff( $all, $del ) );
			if ( ! $keep || array_diff( $del, $all ) ) {
				$skip( 'حداقل یک نسخه باید باقی بماند' );
				continue;
			}
			$kabs = UTK_Util::safe_abs( $G[ $keep[0] ]['main'] );
			$khash = $kabs ? @hash_file( 'sha256', $kabs ) : false;
			foreach ( $del as $gid ) {
				if ( empty( $can[ $gid ] ) ) {
					$skip( 'نسخه در حال استفاده/محافظت‌شده/جدید است' );
					continue;
				}
				$dabs = UTK_Util::safe_abs( $G[ $gid ]['main'] );
				// تأیید نهایی محتوا با sha256 (مستقل از الگوریتم اسکن)
				if ( ! $khash || ! $dabs || @hash_file( 'sha256', $dabs ) !== $khash ) {
					$skip( 'محتوای دو فایل یکسان نیست یا فایل پیدا نشد' );
					continue;
				}
				$verified[] = $gid;
			}
		}
		$out = $verified ? self::run( 'group', $verified, $perm, $bid, array( 'inactive' => true ) ) : array( 'items' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => array(), 'errors' => array() );
		if ( isset( $out['error'] ) ) {
			return $out;
		}
		foreach ( $extra['skipped'] as $k => $v ) {
			$out['skipped'][ $k ] = ( isset( $out['skipped'][ $k ] ) ? $out['skipped'][ $k ] : 0 ) + $v;
		}
		return $out;
	}

	public static function end( $perm, $bid, $mode, array $stats, $label = '' ) {
		UTK_Store::log(
			array(
				'a'     => $perm ? 'delete' : 'trash',
				'mode'  => $mode,
				'batch' => $perm ? '' : $bid,
				'items' => (int) ( isset( $stats['items'] ) ? $stats['items'] : 0 ),
				'files' => (int) ( isset( $stats['files'] ) ? $stats['files'] : 0 ),
				'bytes' => (int) ( isset( $stats['bytes'] ) ? $stats['bytes'] : 0 ),
				'label' => $label,
			)
		);
		if ( ! $perm ) {
			$mp  = self::man_path( $bid );
			$man = $mp ? UTK_Store::read_file( $mp ) : null;
			if ( is_array( $man ) && ! $man['items'] ) {
				UTK_Util::rrmdir( dirname( $mp ) ); // دسته‌ی خالی
			}
		}
	}

	/* --------------------------------------------------------------- trash */

	public static function batches() {
		$out = array();
		$dir = UTK_Store::trash_dir();
		foreach ( (array) scandir( $dir ) as $bid ) {
			if ( ! preg_match( self::BID_RX, $bid ) ) {
				continue;
			}
			$m = UTK_Store::read_file( $dir . '/' . $bid . '/manifest.php' );
			if ( ! is_array( $m ) ) {
				continue;
			}
			$bytes = $files = $items = 0;
			foreach ( $m['items'] as $it ) {
				if ( ! empty( $it['restored'] ) ) {
					continue;
				}
				$items++;
				$files += count( $it['files'] );
				$bytes += array_sum( $it['files'] );
			}
			$out[] = array( 'id' => $bid, 'time' => $m['time'], 'user' => $m['user'], 'mode' => $m['mode'], 'label' => isset( $m['label'] ) ? $m['label'] : '', 'items' => $items, 'files' => $files, 'bytes' => $bytes );
		}
		usort( $out, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );
		return $out;
	}

	public static function trash_bytes() {
		$n = 0;
		foreach ( self::batches() as $b ) {
			$n += $b['bytes'];
		}
		return $n;
	}

	public static function restore( $bid ) {
		global $wpdb;
		$mp = self::man_path( $bid );
		$m  = $mp ? UTK_Store::read_file( $mp ) : null;
		if ( ! is_array( $m ) ) {
			return array( 'error' => 'دسته پیدا نشد' );
		}
		$dir  = dirname( $mp );
		$base = UTK_Util::base();
		$n    = $conf = 0;
		foreach ( $m['items'] as $i => $it ) {
			if ( ! empty( $it['restored'] ) ) {
				continue;
			}
			if ( ! empty( $it['rows'] ) && ! empty( $it['id'] ) ) {
				$id = (int) $it['id'];
				if ( ! $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE ID = {$id}" ) ) {
					$wpdb->insert( $wpdb->posts, $it['rows']['post'] );
					foreach ( $it['rows']['meta'] as $mr ) {
						$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $id, 'meta_key' => $mr['meta_key'], 'meta_value' => $mr['meta_value'] ) );
					}
					clean_post_cache( $id );
				}
			}
			$all_ok = true;
			foreach ( $it['files'] as $rel => $size ) {
				$rel = (string) $rel;
				$src = $dir . '/f/' . $rel . '.utk';
				$dst = $base . '/' . $rel;
				if ( preg_match( '#(^|/)\.\.(/|$)#', $rel ) || '/' === $rel[0] ) {
					continue;
				}
				if ( ! is_file( $src ) ) {
					continue;
				}
				if ( file_exists( $dst ) ) {
					$conf++;
					$all_ok = false;
					continue;
				}
				wp_mkdir_p( dirname( $dst ) );
				if ( @rename( $src, $dst ) || ( @copy( $src, $dst ) && @unlink( $src ) ) ) {
					$n++;
				} else {
					$all_ok = false;
				}
			}
			if ( $all_ok ) {
				$m['items'][ $i ]['restored'] = true;
			}
		}
		$left = false;
		foreach ( $m['items'] as $it ) {
			if ( empty( $it['restored'] ) ) {
				$left = true;
			}
		}
		if ( $left ) {
			UTK_Store::write_file( $mp, $m );
		} else {
			UTK_Util::rrmdir( $dir );
		}
		UTK_Store::log( array( 'a' => 'restore', 'batch' => $bid, 'files' => $n, 'conflicts' => $conf ) );
		update_option( 'utk_changed', time(), false );
		return array( 'files' => $n, 'conflicts' => $conf );
	}

	public static function purge( $bid ) {
		$mp = self::man_path( $bid );
		if ( ! $mp || ! is_dir( dirname( $mp ) ) ) {
			return 0;
		}
		$bytes = UTK_Util::dir_size( dirname( $mp ) );
		UTK_Util::rrmdir( dirname( $mp ) );
		UTK_Store::log( array( 'a' => 'purge', 'batch' => $bid, 'bytes' => $bytes ) );
		return $bytes;
	}

	public static function purge_all() {
		$n = 0;
		foreach ( self::batches() as $b ) {
			$n += self::purge( $b['id'] );
		}
		return $n;
	}

	// کرون روزانه: پاک‌سازی دسته‌های قدیمی‌تر از «مدت نگه‌داری سطل»
	public static function purge_old() {
		$days = (int) UTK_Store::settings()['trash_days'];
		if ( $days < 1 ) {
			return 0;
		}
		$n = 0;
		foreach ( self::batches() as $b ) {
			if ( $b['time'] < time() - $days * DAY_IN_SECONDS ) {
				$n += self::purge( $b['id'] );
			}
		}
		return $n;
	}
}
