<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * موتور اسکن مرحله‌ای (resumable). هر درخواست AJAX (tick) حداکثر چند ثانیه کار می‌کند و وضعیت را ذخیره می‌کند،
 * پس روی سایت‌های سنگین هم timeout نمی‌شود.
 *
 * مراحل: attachments → دیسک → ارجاع‌ها (محتوا، متا، ACF، term/user meta، options، جدول‌های افزونه‌ها، قالب) → تحلیل → هش
 */
final class UTK_Scan {

	const STEPS = array( 'atts', 'disk', 'posts', 'meta', 'acf', 'termmeta', 'usermeta', 'options', 'optacf', 'tables', 'theme', 'analyse', 'hash' );

	private $st;
	private $d     = array();
	private $dirty = array();

	public static function labels() {
		return array(
			'atts'     => 'خواندن کتابخانه‌ی رسانه',
			'disk'     => 'پیمایش پوشه‌ی uploads',
			'posts'    => 'ارجاع‌ها در محتوای نوشته‌ها',
			'meta'     => 'ارجاع‌ها در متای نوشته‌ها',
			'acf'      => 'فیلدهای ACF',
			'termmeta' => 'متای دسته‌ها',
			'usermeta' => 'متای کاربران',
			'options'  => 'تنظیمات سایت (options)',
			'optacf'   => 'فیلدهای ACF در options',
			'tables'   => 'جدول‌های افزونه‌ها',
			'theme'    => 'فایل‌های قالب',
			'analyse'  => 'تحلیل و گروه‌بندی',
			'hash'     => 'محاسبه‌ی هش فایل‌های هم‌اندازه',
		);
	}

	private function __construct( array $st ) {
		$this->st = $st;
	}

	/* ------------------------------------------------------------ control */

	public static function start( $opts = array() ) {
		$s    = UTK_Store::settings();
		$algo = isset( $opts['algo'] ) && in_array( $opts['algo'], UTK_Store::algos(), true ) ? $opts['algo'] : $s['algo'];
		$st   = array(
			'id'      => wp_generate_password( 8, false ),
			'status'  => 'running',
			'step'    => 0,
			'cur'     => null,
			'max'     => null,
			'frac'    => 0,
			'started' => time(),
			't0'      => microtime( true ),
			'opts'    => array( 'hash' => isset( $opts['hash'] ) ? ! empty( $opts['hash'] ) : ! empty( $s['hash'] ), 'algo' => $algo ),
			'msg'     => '',
		);
		UTK_Store::write( 'state', $st );
		foreach ( array( 'atts', 'disk', 'refs' ) as $n ) {
			UTK_Store::delete( $n );
		}
		return self::status();
	}

	public static function cancel() {
		$st = UTK_Store::read( 'state' );
		if ( is_array( $st ) && 'running' === $st['status'] ) {
			$st['status'] = 'cancelled';
			UTK_Store::write( 'state', $st );
		}
		foreach ( array( 'atts', 'disk', 'refs' ) as $n ) {
			UTK_Store::delete( $n );
		}
		return self::status();
	}

	public static function status() {
		$st = UTK_Store::read( 'state' );
		if ( ! is_array( $st ) ) {
			return array( 'status' => 'none' );
		}
		$total = count( self::STEPS );
		$i     = min( $st['step'], $total - 1 );
		$lab   = self::labels();
		return array(
			'status'  => $st['status'],
			'step'    => $st['step'],
			'total'   => $total,
			'label'   => $lab[ self::STEPS[ $i ] ],
			'pct'     => 'done' === $st['status'] ? 100 : (int) floor( ( $st['step'] + min( 1, max( 0, $st['frac'] ) ) ) / $total * 100 ),
			'msg'     => $st['msg'],
			'started' => $st['started'],
			'elapsed' => ( isset( $st['finished'] ) ? $st['finished'] : time() ) - $st['started'],
		);
	}

	public static function budget() {
		$b   = (int) UTK_Store::settings()['budget'];
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$b = max( 5, min( $b, $max - 8 ) );
		}
		return $b;
	}

	// یک نوبت کار؛ UI مدام صدایش می‌زند تا status به done برسد
	public static function tick( $budget = null ) {
		$st = UTK_Store::read( 'state' );
		if ( ! is_array( $st ) || 'running' !== $st['status'] ) {
			return self::status();
		}
		$lock = UTK_Store::lock( 'scan' );
		if ( ! $lock ) {
			return self::status();
		}
		$job      = new self( $st );
		$budget   = null === $budget ? self::budget() : $budget;
		$deadline = microtime( true ) + $budget;
		try {
			while ( 'running' === $job->st['status'] && microtime( true ) < $deadline ) {
				$name = self::STEPS[ $job->st['step'] ];
				$done = ( 'hash' === $name && empty( $job->st['opts']['hash'] ) ) ? true : $job->{'step_' . $name}( $deadline );
				if ( $done ) {
					$job->st['step']++;
					$job->st['cur']  = null;
					$job->st['max']  = null;
					$job->st['frac'] = 0;
					if ( $job->st['step'] >= count( self::STEPS ) ) {
						$job->finish();
					}
				}
			}
		} catch ( Throwable $e ) {
			$job->st['status'] = 'error';
			$job->st['msg']    = $e->getMessage();
		}
		$job->flush();
		UTK_Store::unlock( $lock );
		return self::status();
	}

	// برای WP-CLI: همه‌ی مراحل بدون محدودیت زمانی
	public static function run_all( $opts = array(), $progress = null ) {
		self::start( $opts );
		do {
			$s = self::tick( 60 );
			if ( $progress ) {
				call_user_func( $progress, $s );
			}
		} while ( 'running' === $s['status'] );
		return $s;
	}

	private function finish() {
		$this->st['status']   = 'done';
		$this->st['finished'] = time();
		$r                    = UTK_Store::read( 'result' );
		if ( is_array( $r ) ) {
			$r['meta']['finished'] = $this->st['finished'];
			$r['meta']['duration'] = (int) round( microtime( true ) - $this->st['t0'] );
			UTK_Store::write( 'result', $r );
		}
		update_option( 'utk_changed', 0, false );
		foreach ( array( 'atts', 'disk', 'refs' ) as $n ) {
			UTK_Store::delete( $n );
			unset( $this->d[ $n ], $this->dirty[ $n ] );
		}
	}

	private function flush() {
		foreach ( array_keys( $this->dirty ) as $n ) {
			if ( isset( $this->d[ $n ] ) ) {
				UTK_Store::write( $n, $this->d[ $n ] );
			}
		}
		UTK_Store::write( 'state', $this->st );
	}

	private function need( $n ) {
		if ( isset( $this->d[ $n ] ) ) {
			return;
		}
		$x = UTK_Store::read( $n );
		if ( ! is_array( $x ) ) {
			$defs = array(
				'atts' => array( 'info' => array(), 'map' => array(), 'stem' => array() ),
				'disk' => array( 'files' => array(), 'dirs' => array(), 'all' => array( 0, 0 ), 'q' => array(), 'n' => 0 ),
				'refs' => array( 'p' => array( 'live' => array(), 'off' => array() ), 'id' => array( 'live' => array(), 'off' => array() ) ),
			);
			$x = $defs[ $n ];
		}
		$this->d[ $n ] = $x;
	}

	/* ------------------------------------------------------ step: attachments */

	private static function attachment_files( $file, $m ) {
		$out = array();
		if ( '' === $file ) {
			return $out;
		}
		$out[] = $file;
		list( $dir ) = UTK_Util::split( $file );
		$meta = isset( $m['_wp_attachment_metadata'] ) ? maybe_unserialize( $m['_wp_attachment_metadata'] ) : array();
		$bak  = isset( $m['_wp_attachment_backup_sizes'] ) ? maybe_unserialize( $m['_wp_attachment_backup_sizes'] ) : array();
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['file'] ) && is_string( $meta['file'] ) ) {
				$out[] = $meta['file'];
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$out[] = $dir . $meta['original_image'];
			}
			$srcs = isset( $meta['sources'] ) && is_array( $meta['sources'] ) ? $meta['sources'] : array();
			foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				if ( ! empty( $s['file'] ) ) {
					$out[] = $dir . $s['file'];
				}
				if ( ! empty( $s['sources'] ) && is_array( $s['sources'] ) ) {
					$srcs = array_merge( $srcs, $s['sources'] );
				}
			}
			foreach ( $srcs as $s ) {
				if ( is_array( $s ) && ! empty( $s['file'] ) ) {
					$out[] = $dir . $s['file'];
				}
			}
		}
		if ( is_array( $bak ) ) {
			foreach ( $bak as $s ) {
				if ( is_array( $s ) && ! empty( $s['file'] ) ) {
					$out[] = $dir . $s['file'];
				}
			}
		}
		return $out;
	}

	private function step_atts( $deadline ) {
		global $wpdb;
		if ( null === $this->st['cur'] ) {
			$this->d['atts']     = array( 'info' => array(), 'map' => array(), 'stem' => array() );
			$this->dirty['atts'] = true;
			$this->st['cur']     = 0;
			$this->st['max']     = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
		}
		$this->need( 'atts' );
		do {
			$last = (int) $this->st['cur'];
			$rows = $wpdb->get_results( "SELECT ID, post_title, post_mime_type, post_parent FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > {$last} ORDER BY ID LIMIT 500" );
			if ( ! $rows ) {
				return true;
			}
			$in    = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ) );
			$by    = array();
			$metas = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')" );
			foreach ( (array) $metas as $m ) {
				$by[ (int) $m->post_id ][ $m->meta_key ] = $m->meta_value;
			}
			foreach ( $rows as $r ) {
				$id                                   = (int) $r->ID;
				$m                                    = isset( $by[ $id ] ) ? $by[ $id ] : array();
				$file                                 = isset( $m['_wp_attached_file'] ) ? $m['_wp_attached_file'] : '';
				$this->d['atts']['info'][ $id ]       = array( $r->post_title, $file, $r->post_mime_type, (int) $r->post_parent );
				foreach ( self::attachment_files( $file, $m ) as $f ) {
					$k                                          = UTK_Util::fold( $f );
					$this->d['atts']['map'][ $k ]               = $id;
					$this->d['atts']['stem'][ UTK_Util::stem_key( $k ) ] = $id;
				}
				$this->st['cur'] = $id;
			}
			$this->dirty['atts'] = true;
			$this->st['frac']    = $this->st['max'] ? min( 1, $this->st['cur'] / $this->st['max'] ) : 1;
			unset( $metas, $by );
		} while ( 500 === count( $rows ) && microtime( true ) < $deadline );
		return count( $rows ) < 500;
	}

	/* ----------------------------------------------------------- step: disk */

	private function step_disk( $deadline ) {
		if ( null === $this->st['cur'] ) {
			$this->d['disk']     = array( 'files' => array(), 'dirs' => array(), 'all' => array( 0, 0 ), 'q' => array( '' ), 'n' => 0 );
			$this->dirty['disk'] = true;
			$this->st['cur']     = 1;
		}
		$this->need( 'disk' );
		$this->need( 'atts' );
		$base = UTK_Util::base();
		$reg  = $this->d['atts']['map'];
		$D    =& $this->d['disk'];
		while ( $D['q'] && microtime( true ) < $deadline ) {
			$dir = array_pop( $D['q'] );
			$D['n']++;
			$abs   = '' === $dir ? $base : $base . '/' . $dir;
			$items = @scandir( $abs );
			if ( ! $items ) {
				continue;
			}
			foreach ( $items as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$p   = $abs . '/' . $name;
				$rel = '' === $dir ? $name : $dir . '/' . $name;
				if ( is_link( $p ) ) {
					continue;
				}
				if ( is_dir( $p ) ) {
					$D['q'][] = $rel;
					continue;
				}
				$size = (int) @filesize( $p );
				$top  = UTK_Util::top( $rel );
				if ( ! isset( $D['dirs'][ $top ] ) ) {
					$D['dirs'][ $top ] = array( 0, 0 );
				}
				$D['dirs'][ $top ][0]++;
				$D['dirs'][ $top ][1] += $size;
				$D['all'][0]++;
				$D['all'][1] += $size;
				if ( '.' === $name[0] || 'index.php' === $name ) {
					continue;
				}
				// فقط پوشه‌های استاندارد سال/ماه (یا ریشه) + هر چیزی که در کتابخانه‌ی رسانه ثبت شده؛
				// پوشه‌های افزونه‌ها (cache، backup، دانلودها، فرم‌ها و ...) تحلیل نمی‌شوند.
				if ( preg_match( '#^(\d{4}/\d{2}/)?[^/]+$#', $rel ) || isset( $reg[ UTK_Util::fold( $rel ) ] ) ) {
					$D['files'][ $rel ] = array( $size, (int) @filemtime( $p ) );
				}
			}
		}
		$this->dirty['disk'] = true;
		$this->st['frac']    = $D['n'] / max( 1, $D['n'] + count( $D['q'] ) );
		if ( ! $D['q'] ) {
			uasort(
				$D['dirs'],
				function ( $a, $b ) {
					return $b[1] <=> $a[1];
				}
			);
			return true;
		}
		return false;
	}

	/* ------------------------------------------------------ steps: references */

	private function post_filter() {
		return "p.post_type NOT IN ('attachment','revision') AND p.post_status <> 'auto-draft'";
	}

	// پیمایش کلید-به-کلید (keyset) یک جدول با ذخیره‌ی مکان‌نما؛ true یعنی تمام شد
	private function chunked( $sql, $key, $cb, $n, $deadline, $maxsql ) {
		global $wpdb;
		$this->need( 'refs' );
		if ( null === $this->st['cur'] ) {
			$this->st['cur'] = 0;
			$this->st['max'] = (int) $wpdb->get_var( $maxsql );
		}
		do {
			$prev = $this->st['cur'];
			$rows = (array) $wpdb->get_results( strtr( $sql, array( '{last}' => (string) $prev, '{n}' => (string) $n ) ) );
			foreach ( $rows as $r ) {
				$cb( $r );
				$this->st['cur'] = (int) $r->$key;
			}
			$this->dirty['refs'] = true;
			$this->st['frac']    = $this->st['max'] ? min( 1, $this->st['cur'] / $this->st['max'] ) : 1;
			if ( count( $rows ) < $n || $this->st['cur'] === $prev ) {
				return true;
			}
		} while ( microtime( true ) < $deadline );
		return false;
	}

	private function step_posts( $deadline ) {
		global $wpdb;
		$ok = $this->post_filter();
		return $this->chunked(
			"SELECT p.ID, p.post_status, p.post_content, p.post_excerpt FROM {$wpdb->posts} p WHERE p.ID > {last} AND {$ok} ORDER BY p.ID LIMIT {n}",
			'ID',
			function ( $r ) {
				UTK_Util::take_text( $this->d['refs'], UTK_Util::bucket_of( $r->post_status ), $r->post_content . "\n" . $r->post_excerpt );
			},
			200,
			$deadline,
			"SELECT MAX(ID) FROM {$wpdb->posts}"
		);
	}

	private function step_meta( $deadline ) {
		global $wpdb;
		$ok  = $this->post_filter();
		$mk  = esc_sql( UTK_Util::mk() );
		$krx = "'" . UTK_Util::KEY_RX . "'";
		return $this->chunked(
			"SELECT m.meta_id, m.meta_key, m.meta_value, p.post_status FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_id > {last} AND {$ok} AND ( m.meta_value LIKE '%{$mk}%' OR m.meta_key IN ('_thumbnail_id','_product_image_gallery') OR m.meta_key REGEXP {$krx} )
			 ORDER BY m.meta_id LIMIT {n}",
			'meta_id',
			function ( $r ) use ( $mk ) {
				$b = UTK_Util::bucket_of( $r->post_status );
				if ( false !== strpos( (string) $r->meta_value, $mk ) ) {
					UTK_Util::take_text( $this->d['refs'], $b, $r->meta_value );
				}
				if ( ! preg_match( UTK_Util::NOISE, $r->meta_key ) ) {
					UTK_Util::take_ids( $this->d['refs'], $b, $r->meta_value );
				}
			},
			200,
			$deadline,
			"SELECT MAX(meta_id) FROM {$wpdb->postmeta}"
		);
	}

	// فیلدهای ACF: کلید عادی + کلید «_کلید» با مقدار field_xxx
	private function step_acf( $deadline ) {
		global $wpdb;
		$ok = $this->post_filter();
		return $this->chunked(
			"SELECT m.meta_id, m.meta_value, p.post_status FROM {$wpdb->postmeta} m
			 JOIN {$wpdb->postmeta} r ON r.post_id = m.post_id AND r.meta_key = CONCAT('_', m.meta_key) AND r.meta_value LIKE 'field\\_%'
			 JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_id > {last} AND m.meta_key NOT LIKE '\\_%' AND {$ok}
			 ORDER BY m.meta_id LIMIT {n}",
			'meta_id',
			function ( $r ) {
				UTK_Util::take_ids( $this->d['refs'], UTK_Util::bucket_of( $r->post_status ), $r->meta_value );
			},
			500,
			$deadline,
			"SELECT MAX(meta_id) FROM {$wpdb->postmeta}"
		);
	}

	private function meta_table_step( $table, $idcol, $deadline ) {
		global $wpdb;
		$mk  = esc_sql( UTK_Util::mk() );
		$krx = "'" . UTK_Util::KEY_RX . "'";
		return $this->chunked(
			"SELECT {$idcol}, meta_key, meta_value FROM {$table} WHERE {$idcol} > {last} AND ( meta_value LIKE '%{$mk}%' OR meta_key REGEXP {$krx} ) ORDER BY {$idcol} LIMIT {n}",
			$idcol,
			function ( $r ) use ( $mk ) {
				if ( false !== strpos( (string) $r->meta_value, $mk ) ) {
					UTK_Util::take_text( $this->d['refs'], 'live', $r->meta_value );
				}
				if ( ! preg_match( UTK_Util::NOISE, $r->meta_key ) ) {
					UTK_Util::take_ids( $this->d['refs'], 'live', $r->meta_value );
				}
			},
			300,
			$deadline,
			"SELECT MAX({$idcol}) FROM {$table}"
		);
	}

	private function step_termmeta( $deadline ) {
		global $wpdb;
		return $this->meta_table_step( $wpdb->termmeta, 'meta_id', $deadline );
	}

	private function step_usermeta( $deadline ) {
		global $wpdb;
		return $this->meta_table_step( $wpdb->usermeta, 'umeta_id', $deadline );
	}

	// options: لوگو، favicon، theme_mods، ویجت‌ها، ... (transient ها نادیده)
	private function step_options( $deadline ) {
		global $wpdb;
		$mk = UTK_Util::mk();
		return $this->chunked(
			"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > {last} AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' ORDER BY option_id LIMIT {n}",
			'option_id',
			function ( $r ) use ( $mk ) {
				$v = (string) $r->option_value;
				$R =& $this->d['refs'];
				if ( false !== strpos( $v, $mk ) ) {
					UTK_Util::take_text( $R, 'live', $v );
				}
				if ( in_array( $r->option_name, array( 'site_icon', 'site_logo', 'woocommerce_placeholder_image' ), true ) ) {
					UTK_Util::take_ids( $R, 'live', $v );
				} elseif ( is_serialized( $v ) ) {
					UTK_Util::take_keyed( $R, 'live', $v );
				} elseif ( preg_match( '/' . UTK_Util::KEY_RX . '/i', $r->option_name ) && ! preg_match( UTK_Util::NOISE, $r->option_name ) ) {
					UTK_Util::take_ids( $R, 'live', $v );
				}
			},
			200,
			$deadline,
			"SELECT MAX(option_id) FROM {$wpdb->options}"
		);
	}

	private function step_optacf( $deadline ) {
		global $wpdb;
		$this->need( 'refs' );
		foreach ( (array) $wpdb->get_col( "SELECT o.option_value FROM {$wpdb->options} o JOIN {$wpdb->options} r ON r.option_name = CONCAT('_', o.option_name) WHERE r.option_value LIKE 'field\\_%'" ) as $v ) {
			UTK_Util::take_ids( $this->d['refs'], 'live', $v );
		}
		$this->dirty['refs'] = true;
		return true;
	}

	// جدول‌های سفارشی افزونه‌ها (اسلایدرها و ...) که ستون متنی دارند
	private function custom_tables() {
		global $wpdb;
		$core = array();
		foreach ( array( 'posts', 'postmeta', 'options', 'termmeta', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'users', 'links', 'comments', 'commentmeta' ) as $t ) {
			$core[] = $wpdb->prefix . $t;
		}
		$out    = array();
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		foreach ( (array) $tables as $t ) {
			if ( in_array( $t, $core, true ) || preg_match( UTK_Util::SKIP_TBL, substr( $t, strlen( $wpdb->prefix ) ) ) ) {
				continue;
			}
			$cols = array();
			foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$t}`" ) as $c ) {
				if ( preg_match( '/char|text|json|blob/i', $c->Type ) ) {
					$cols[] = '`' . $c->Field . '`';
				}
			}
			if ( $cols ) {
				$out[] = array( $t, $cols );
			}
		}
		return $out;
	}

	private function step_tables( $deadline ) {
		global $wpdb;
		$this->need( 'refs' );
		if ( null === $this->st['cur'] ) {
			$this->st['tables'] = $this->custom_tables();
			$this->st['cur']    = array( 0, 0 );
		}
		$mk       = esc_sql( UTK_Util::mk() );
		$T        = $this->st['tables'];
		list( $i, $off ) = $this->st['cur'];
		while ( $i < count( $T ) && microtime( true ) < $deadline ) {
			list( $t, $cols ) = $T[ $i ];
			$cond = implode( ' OR ', array_map( function ( $c ) use ( $mk ) {
				return "{$c} LIKE '%{$mk}%'";
			}, $cols ) );
			$rows = (array) $wpdb->get_results( 'SELECT ' . implode( ',', $cols ) . " FROM `{$t}` WHERE {$cond} LIMIT {$off}, 200", ARRAY_N );
			foreach ( $rows as $row ) {
				foreach ( $row as $cell ) {
					UTK_Util::take_text( $this->d['refs'], 'live', $cell );
				}
			}
			$this->dirty['refs'] = true;
			if ( count( $rows ) < 200 ) {
				$i++;
				$off = 0;
			} else {
				$off += 200;
			}
			$this->st['cur'] = array( $i, $off );
		}
		$this->st['frac'] = $T ? $i / count( $T ) : 1;
		return $i >= count( $T );
	}

	// تصاویر hardcode شده در فایل‌های قالب فعال (و قالب فرزند)
	private function step_theme( $deadline ) {
		$this->need( 'refs' );
		if ( null === $this->st['cur'] ) {
			$list = array();
			foreach ( array_unique( array( get_template_directory(), get_stylesheet_directory() ) ) as $dir ) {
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
				foreach ( $it as $f ) {
					if ( preg_match( '/\.(php|css|scss|js|json|html?|svg)$/i', $f->getFilename() ) && false === strpos( wp_normalize_path( $f->getPathname() ), '/node_modules/' ) && $f->getSize() <= 2097152 ) {
						$list[] = $f->getPathname();
					}
				}
			}
			$this->st['files'] = $list;
			$this->st['cur']   = 0;
		}
		$n = count( $this->st['files'] );
		while ( $this->st['cur'] < $n && microtime( true ) < $deadline ) {
			UTK_Util::take_text( $this->d['refs'], 'live', (string) @file_get_contents( $this->st['files'][ $this->st['cur'] ] ) );
			$this->st['cur']++;
		}
		$this->dirty['refs'] = true;
		$this->st['frac']    = $n ? $this->st['cur'] / $n : 1;
		if ( $this->st['cur'] >= $n ) {
			unset( $this->st['files'] );
			return true;
		}
		return false;
	}

	/* -------------------------------------------------------- step: analyse */

	// شمارنده‌های یک گروه را از لیست فایل‌هایش دوباره می‌سازد. $files: rel => [size, flag, mtime]
	public static function recount( array &$g, array $files, $recent_cut ) {
		$g['n']           = count( $files );
		$g['bytes']       = 0;
		$g['reg']         = 0;
		$g['stale']       = 0;
		$g['stale_bytes'] = 0;
		$g['alt']         = 0;
		$g['alt_bytes']   = 0;
		$g['mt']          = 0;
		foreach ( $files as $f ) {
			$g['bytes'] += $f[0];
			if ( 'r' === $f[1] ) {
				$g['reg']++;
			} elseif ( 's' === $f[1] ) {
				$g['stale']++;
				$g['stale_bytes'] += $f[0];
			} elseif ( 'a' === $f[1] ) {
				$g['alt']++;
				$g['alt_bytes'] += $f[0];
			}
			if ( $f[2] > $g['mt'] ) {
				$g['mt'] = $f[2];
			}
		}
		$g['reclaim'] = 'unused' === $g['status'] ? $g['bytes'] : $g['stale_bytes'];
		$g['recent']  = $g['mt'] > $recent_cut;
	}

	public static function recent_cut() {
		return time() - (int) UTK_Store::settings()['min_age'] * DAY_IN_SECONDS;
	}

	public static function summary( array $G ) {
		$S = array(
			'groups'  => count( $G ),
			'files'   => 0,
			'bytes'   => 0,
			'st'      => array( 'used' => array( 0, 0, 0 ), 'inactive' => array( 0, 0, 0 ), 'unused' => array( 0, 0, 0 ) ),
			'stale'   => array( 0, 0 ),
			'alt'     => array( 0, 0 ),
			'reclaim' => 0,
			'hist'    => array_fill_keys( array( '1', '2', '3', '4', '5', '6–10', '11–20', '21+' ), array( 0, 0 ) ),
			'kinds'   => array(),
			'yms'     => array(),
		);
		foreach ( $G as $g ) {
			$st = $g['status'];
			$n  = $g['n'];
			$S['files'] += $n;
			$S['bytes'] += $g['bytes'];
			$S['st'][ $st ][0]++;
			$S['st'][ $st ][1] += $n;
			$S['st'][ $st ][2] += $g['bytes'];
			$S['reclaim']      += $g['reclaim'];
			$S['stale'][0]     += $g['stale'];
			$S['stale'][1]     += $g['stale_bytes'];
			$S['alt'][0]       += $g['alt'];
			$S['alt'][1]       += $g['alt_bytes'];
			$h                  = $n <= 5 ? (string) $n : ( $n <= 10 ? '6–10' : ( $n <= 20 ? '11–20' : '21+' ) );
			$S['hist'][ $h ][0]++;
			$S['hist'][ $h ][1] += $g['bytes'];
			foreach ( array( 'kinds' => $g['kind'], 'yms' => $g['ym'] ) as $bucket => $key ) {
				if ( ! isset( $S[ $bucket ][ $key ] ) ) {
					$S[ $bucket ][ $key ] = array( 0, 0, 0 );
				}
				$S[ $bucket ][ $key ][0]++;
				$S[ $bucket ][ $key ][1] += $g['bytes'];
				$S[ $bucket ][ $key ][2] += $g['reclaim'];
			}
		}
		uasort( $S['yms'], function ( $a, $b ) {
			return $b[1] <=> $a[1];
		} );
		return $S;
	}

	private function step_analyse( $deadline ) {
		$set = UTK_Store::settings();
		$this->need( 'atts' );
		$this->need( 'disk' );
		$this->need( 'refs' );
		$info = $this->d['atts']['info'];
		$map  = $this->d['atts']['map'];
		$stem = $this->d['atts']['stem'];
		$R    = $this->d['refs'];
		$cut  = self::recent_cut();
		$prot = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $set['protect'] ) ) ) );
		$iu   = ! empty( $set['inactive_used'] );

		$T = array();
		foreach ( $this->d['disk']['files'] as $rel => $fi ) {
			$rel  = (string) $rel;
			$k    = UTK_Util::fold( $rel );
			$sk   = UTK_Util::stem_key( $k );
			$reg  = isset( $map[ $k ] );
			$id   = $reg ? $map[ $k ] : ( isset( $stem[ $sk ] ) ? $stem[ $sk ] : 0 );
			$gid  = $id ? 'a' . $id : 'f:' . $sk;
			$live = isset( $R['p']['live'][ $k ] ) || ( $iu && isset( $R['p']['off'][ $k ] ) );
			$off  = ! $live && isset( $R['p']['off'][ $k ] );
			if ( $reg ) {
				$flag = 'r';
			} elseif ( $live || $off ) {
				$flag = 'u';
			} elseif ( preg_match( '/\.(webp|avif)$/i', $rel ) ) {
				$flag = 'a';
			} else {
				$flag = 's';
			}
			if ( ! isset( $T[ $gid ] ) ) {
				$T[ $gid ] = array( 'id' => $id, 'files' => array(), 'live' => false, 'off' => false, 'prot' => false );
			}
			$T[ $gid ]['files'][ $rel ] = array( (int) $fi[0], $flag, (int) $fi[1] );
			$T[ $gid ]['live']          = $T[ $gid ]['live'] || $live;
			$T[ $gid ]['off']           = $T[ $gid ]['off'] || $off;
			if ( $prot && ! $T[ $gid ]['prot'] ) {
				foreach ( $prot as $pat ) {
					if ( fnmatch( $pat, $rel ) ) {
						$T[ $gid ]['prot'] = true;
						break;
					}
				}
			}
		}

		$G = $F = array();
		foreach ( $T as $gid => $t ) {
			$id     = $t['id'];
			$idlive = $id && ( isset( $R['id']['live'][ $id ] ) || ( $iu && isset( $R['id']['off'][ $id ] ) ) );
			$idoff  = $id && isset( $R['id']['off'][ $id ] );
			$st     = ( $t['live'] || $idlive ) ? 'used' : ( ( $t['off'] || $idoff ) ? 'inactive' : 'unused' );
			$files  = $t['files'];
			// فایل اصلی: فایل ثبت‌شده در کتابخانه، یا بزرگ‌ترین فایل گروه
			$msz   = -1;
			$big   = '';
			$t150  = '';
			$tsmal = '';
			$tsz   = PHP_INT_MAX;
			foreach ( $files as $rel => $f ) {
				$rel = (string) $rel;
				if ( $f[0] > $msz ) {
					$msz = $f[0];
					$big = $rel;
				}
				if ( UTK_Util::is_thumbable( $rel ) ) {
					if ( '' === $t150 && false !== strpos( $rel, '-150x150.' ) ) {
						$t150 = $rel;
					}
					if ( $f[0] < $tsz ) {
						$tsz   = $f[0];
						$tsmal = $rel;
					}
				}
			}
			$thumb = '' !== $t150 ? $t150 : $tsmal;
			if ( $id && ! empty( $info[ $id ][1] ) ) {
				$main = $info[ $id ][1];
			} else {
				$main = $big;
			}
			$g = array(
				'id'     => $id,
				'src'    => $id ? 'media' : 'file',
				'title'  => $id && isset( $info[ $id ] ) ? $info[ $id ][0] : '',
				'parent' => $id && isset( $info[ $id ] ) ? $info[ $id ][3] : 0,
				'main'   => $main,
				'msize'  => isset( $files[ $main ] ) ? $files[ $main ][0] : 0,
				'status' => $st,
				'kind'   => UTK_Util::kind( $main ),
				'ym'     => UTK_Util::ym( $main ),
				'thumb'  => $thumb,
				'prot'   => $t['prot'],
			);
			self::recount( $g, $files, $cut );
			$G[ $gid ] = $g;
			$F[ $gid ] = $files;
		}

		$res = array(
			'meta' => array( 'finished' => 0, 'duration' => 0, 'algo' => $this->st['opts']['algo'], 'hash' => ! empty( $this->st['opts']['hash'] ) ),
			'disk' => array( 'all' => $this->d['disk']['all'], 'dirs' => $this->d['disk']['dirs'] ),
			'S'    => self::summary( $G ),
			'G'    => $G,
			'D'    => array(),
		);
		UTK_Store::write( 'result', $res );
		UTK_Store::write( 'files', $F );
		return true;
	}

	/* ---------------------------------------------------------- step: hash */

	// فایل‌های اصلیِ هم‌اندازه را هش می‌کند تا نسخه‌های تکراری (محتوای یکسان) پیدا شوند
	private function step_hash( $deadline ) {
		$res = UTK_Store::read( 'result' );
		if ( ! is_array( $res ) ) {
			return true;
		}
		if ( null === $this->st['cur'] ) {
			$by = array();
			foreach ( $res['G'] as $gid => $g ) {
				if ( $g['msize'] > 0 ) {
					$by[ $g['msize'] ][] = $gid;
				}
			}
			$cand = array();
			foreach ( $by as $gids ) {
				if ( count( $gids ) > 1 ) {
					$cand = array_merge( $cand, $gids );
				}
			}
			$this->st['cand'] = $cand;
			$this->st['hash'] = array();
			$this->st['cur']  = 0;
		}
		$cand = $this->st['cand'];
		$n    = count( $cand );
		$algo = $this->st['opts']['algo'];
		$base = UTK_Util::base();
		while ( $this->st['cur'] < $n && microtime( true ) < $deadline ) {
			$gid = $cand[ $this->st['cur'] ];
			$h   = @hash_file( $algo, $base . '/' . $res['G'][ $gid ]['main'] );
			if ( $h ) {
				$this->st['hash'][ $gid ] = $h;
			}
			$this->st['cur']++;
		}
		$this->st['frac'] = $n ? $this->st['cur'] / $n : 1;
		if ( $this->st['cur'] < $n ) {
			return false;
		}
		$sets = array();
		foreach ( $this->st['hash'] as $gid => $h ) {
			$sets[ $res['G'][ $gid ]['msize'] . ':' . $h ][] = $gid;
		}
		$D = array();
		foreach ( $sets as $key => $gids ) {
			if ( count( $gids ) > 1 ) {
				list( $size, $h ) = explode( ':', $key, 2 );
				$D[]              = array( 'h' => $h, 'size' => (int) $size, 'gids' => $gids );
			}
		}
		$res['D'] = $D;
		UTK_Store::write( 'result', $res );
		unset( $this->st['cand'], $this->st['hash'] );
		return true;
	}
}
