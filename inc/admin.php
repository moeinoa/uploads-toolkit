<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * صفحه‌ی مدیریت (ابزارها → Uploads Toolkit) و روتر AJAX.
 */
final class UTK_Admin {

	const SLUG = 'uploads-toolkit';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_utk', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_utk_csv', array( __CLASS__, 'csv' ) );
		add_action( 'utk_daily', array( 'UTK_Ops', 'purge_old' ) );
		if ( ! wp_next_scheduled( 'utk_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'utk_daily' );
		}
	}

	public static function menu() {
		add_management_page( 'Uploads Toolkit', 'Uploads Toolkit', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'utk', UTK_URL . 'assets/app.css', array(), UTK_VERSION );
		wp_enqueue_script( 'utk', UTK_URL . 'assets/app.js', array(), UTK_VERSION, true );
		$cfg = array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'utk' ),
			'csv'   => wp_nonce_url( admin_url( 'admin-post.php?action=utk_csv' ), 'utk_csv' ),
			'algos' => UTK_Store::algos(),
		);
		wp_add_inline_script( 'utk', 'window.UTK = ' . wp_json_encode( $cfg ) . ';', 'before' );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Uploads Toolkit</h1><div id="utk" dir="rtl"><p>در حال بارگذاری…</p></div></div>';
	}

	/* ---------------------------------------------------------------- ajax */

	private static function p( $k, $d = null ) {
		return isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : $d;
	}

	private static function j( $k ) {
		$v = self::p( $k );
		if ( is_string( $v ) ) {
			$x = json_decode( $v, true );
			return is_array( $x ) ? $x : array();
		}
		return is_array( $v ) ? $v : array();
	}

	public static function ajax() {
		check_ajax_referer( 'utk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید' ), 403 );
		}
		try {
			$data = self::dispatch( sanitize_key( (string) self::p( 'op', '' ) ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		if ( is_array( $data ) && isset( $data['error'] ) ) {
			wp_send_json_error( array( 'message' => $data['error'] ) );
		}
		wp_send_json_success( $data );
	}

	private static function overview() {
		$r  = UTK_Query::result();
		$tb = UTK_Ops::batches();
		return array(
			'scan'     => UTK_Scan::status(),
			'has'      => (bool) $r,
			'meta'     => $r ? $r['meta'] : null,
			'S'        => $r ? $r['S'] : null,
			'disk'     => $r ? $r['disk'] : null,
			'dups'     => $r ? count( $r['D'] ) : 0,
			'trash'    => array( 'batches' => count( $tb ), 'bytes' => array_sum( wp_list_pluck( $tb, 'bytes' ) ) ),
			'changed'  => (int) get_option( 'utk_changed', 0 ),
			'settings' => UTK_Store::settings(),
			'now'      => time(),
			'ready'    => $r ? UTK_Ops::check_ready() : 'ابتدا یک اسکن کامل اجرا کنید.',
			'trash_dir' => str_replace( wp_normalize_path( ABSPATH ), '', UTK_Store::dir() ),
		);
	}

	private static function dispatch( $op ) {
		switch ( $op ) {
			case 'scan_start':
				$o = array();
				if ( null !== self::p( 'hash' ) ) {
					$o['hash'] = (int) self::p( 'hash' );
				}
				if ( self::p( 'algo' ) ) {
					$o['algo'] = (string) self::p( 'algo' );
				}
				return UTK_Scan::start( $o );
			case 'scan_tick':
				return UTK_Scan::tick();
			case 'scan_status':
				return UTK_Scan::status();
			case 'scan_cancel':
				return UTK_Scan::cancel();
			case 'overview':
				return self::overview();
			case 'list':
				return UTK_Query::listing( array(
					'f'     => self::j( 'f' ),
					'sort'  => self::p( 'sort', 'bytes' ),
					'dir'   => self::p( 'dir', 'desc' ),
					'group' => self::p( 'group', '' ),
					'page'  => self::p( 'page', 1 ),
					'per'   => self::p( 'per', 100 ),
				) );
			case 'ids':
				$r = UTK_Query::result();
				if ( ! $r ) {
					return array( 'error' => 'ابتدا اسکن کنید' );
				}
				$f   = UTK_Query::clean_filter( self::j( 'f' ) );
				$ids = UTK_Query::match( $r['G'], $f, $r['D'] );
				$b   = 0;
				foreach ( $ids as $gid ) {
					$b += $r['G'][ $gid ]['bytes'];
				}
				return array( 'gids' => $ids, 'bytes' => $b );
			case 'files':
				$F   = UTK_Store::read( 'files' );
				$gid = (string) self::p( 'gid', '' );
				$out = array();
				if ( is_array( $F ) && isset( $F[ $gid ] ) ) {
					foreach ( array_slice( $F[ $gid ], 0, 300, true ) as $rel => $f ) {
						$out[] = array( 'rel' => (string) $rel, 'size' => $f[0], 'flag' => $f[1], 'mt' => $f[2] );
					}
				}
				return array( 'files' => $out );
			case 'dups':
				return UTK_Query::dups( array( 'page' => self::p( 'page', 1 ), 'per' => self::p( 'per', 20 ) ) );
			case 'dups_all':
				return array( 'sets' => UTK_Query::dups_suggestions() );
			case 'op_begin':
				$perm = (int) self::p( 'perm', 0 ) === 1;
				if ( $perm && 'DELETE' !== (string) self::p( 'confirm', '' ) ) {
					return array( 'error' => 'تأیید حذف دائمی ارسال نشده است.' );
				}
				$err = UTK_Ops::check_ready();
				if ( $err ) {
					return array( 'error' => $err );
				}
				return array( 'bid' => UTK_Ops::begin( (string) self::p( 'mode', '' ), $perm, sanitize_text_field( (string) self::p( 'label', '' ) ) ) );
			case 'op_run':
				return UTK_Ops::run(
					(string) self::p( 'mode', '' ),
					array_map( 'strval', self::j( 'gids' ) ),
					(int) self::p( 'perm', 0 ) === 1,
					(string) self::p( 'bid', '' ),
					array( 'inactive' => (int) self::p( 'inactive', 0 ), 'alt' => (int) self::p( 'alt', 0 ) )
				);
			case 'op_run_dups':
				return UTK_Ops::run_dups( self::j( 'sets' ), (int) self::p( 'perm', 0 ) === 1, (string) self::p( 'bid', '' ) );
			case 'op_end':
				UTK_Ops::end( (int) self::p( 'perm', 0 ) === 1, (string) self::p( 'bid', '' ), (string) self::p( 'mode', '' ), self::j( 'stats' ), sanitize_text_field( (string) self::p( 'label', '' ) ) );
				return array( 'ok' => 1 );
			case 'trash_list':
				return array( 'batches' => UTK_Ops::batches() );
			case 'trash_restore':
				return UTK_Ops::restore( (string) self::p( 'bid', '' ) );
			case 'trash_purge':
				return array( 'bytes' => UTK_Ops::purge( (string) self::p( 'bid', '' ) ) );
			case 'trash_purge_all':
				if ( 'DELETE' !== (string) self::p( 'confirm', '' ) ) {
					return array( 'error' => 'تأیید ارسال نشده است.' );
				}
				return array( 'bytes' => UTK_Ops::purge_all() );
			case 'settings_save':
				return array( 'settings' => UTK_Store::save_settings( self::j( 'settings' ) ) );
			case 'log':
				return array( 'log' => UTK_Store::read_log( 200 ) );
		}
		return array( 'error' => 'عملیات ناشناخته: ' . $op );
	}

	/* ----------------------------------------------------------------- csv */

	public static function csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'utk_csv' );
		$r = UTK_Query::result();
		$F = UTK_Store::read( 'files' );
		if ( ! $r || ! is_array( $F ) ) {
			wp_die( 'ابتدا اسکن کنید.' );
		}
		$f    = UTK_Query::clean_filter( isset( $_GET['f'] ) ? json_decode( wp_unslash( $_GET['f'] ), true ) : array() );
		$ids  = UTK_Query::match( $r['G'], $f, $r['D'] );
		$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="uploads-toolkit-' . $host . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		$cell = function ( $v ) {
			$v = (string) $v;
			return '' !== $v && false !== strpos( '=+-@', $v[0] ) ? "'" . $v : $v;
		};
		fputcsv( $out, array( 'status', 'type', 'kind', 'attachment_id', 'title', 'main_file', 'folder', 'versions', 'registered', 'stale', 'optimizer_copies', 'total_bytes', 'reclaimable_bytes', 'newest_mtime', 'files' ), ',', '"', '\\' );
		foreach ( $ids as $gid ) {
			$g = $r['G'][ $gid ];
			fputcsv(
				$out,
				array( $g['status'], $g['src'], $g['kind'], $g['id'] ? $g['id'] : '', $cell( $g['title'] ), $cell( $g['main'] ), $g['ym'], $g['n'], $g['reg'], $g['stale'], $g['alt'], $g['bytes'], $g['reclaim'], gmdate( 'Y-m-d H:i', $g['mt'] ), $cell( implode( ' | ', array_map( 'strval', array_keys( isset( $F[ $gid ] ) ? $F[ $gid ] : array() ) ) ) ) ),
				',',
				'"',
				'\\'
			);
		}
		fclose( $out );
		exit;
	}
}
