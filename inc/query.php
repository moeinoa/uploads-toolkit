<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * فیلتر، مرتب‌سازی، گروه‌بندی و صفحه‌بندی نتایج اسکن.
 */
final class UTK_Query {

	private static $res = null;

	public static function result() {
		if ( null === self::$res ) {
			$r         = UTK_Store::read( 'result' );
			self::$res = is_array( $r ) ? $r : false;
		}
		return self::$res;
	}

	public static function reset() {
		self::$res = null;
	}

	public static function clean_filter( $f ) {
		$f   = is_array( $f ) ? $f : array();
		$in  = function ( $v, $list ) {
			return in_array( $v, $list, true ) ? $v : '';
		};
		return array(
			'status' => $in( isset( $f['status'] ) ? $f['status'] : '', array( 'used', 'inactive', 'unused' ) ),
			'src'    => $in( isset( $f['src'] ) ? $f['src'] : '', array( 'media', 'file' ) ),
			'kind'   => $in( isset( $f['kind'] ) ? $f['kind'] : '', array( 'image', 'video', 'audio', 'pdf', 'doc', 'archive', 'other' ) ),
			'ym'     => isset( $f['ym'] ) ? substr( (string) $f['ym'], 0, 80 ) : '',
			'q'      => isset( $f['q'] ) ? substr( trim( (string) $f['q'] ), 0, 120 ) : '',
			'min_mb' => isset( $f['min_mb'] ) ? max( 0, (float) $f['min_mb'] ) : 0,
			'min_n'  => isset( $f['min_n'] ) ? max( 0, (int) $f['min_n'] ) : 0,
			'stale'  => ! empty( $f['stale'] ) ? 1 : 0,
			'alt'    => ! empty( $f['alt'] ) ? 1 : 0,
			'dup'    => ! empty( $f['dup'] ) ? 1 : 0,
			'old'    => ! empty( $f['old'] ) ? 1 : 0, // فقط گروه‌هایی که از «حداقل سن» گذشته‌اند و محافظت‌شده نیستند
		);
	}

	public static function dup_gids( $D ) {
		$s = array();
		foreach ( (array) $D as $set ) {
			foreach ( $set['gids'] as $g ) {
				$s[ $g ] = 1;
			}
		}
		return $s;
	}

	// فهرست gid های منطبق با فیلتر
	public static function match( array $G, array $f, array $D = array() ) {
		$q    = '' === $f['q'] ? '' : UTK_Util::fold( $f['q'] );
		$min  = $f['min_mb'] * 1048576;
		$dups = $f['dup'] ? self::dup_gids( $D ) : array();
		$ym   = $f['ym'];
		$out  = array();
		foreach ( $G as $gid => $g ) {
			if ( $f['status'] && $g['status'] !== $f['status'] ) {
				continue;
			}
			if ( $f['src'] && $g['src'] !== $f['src'] ) {
				continue;
			}
			if ( $f['kind'] && $g['kind'] !== $f['kind'] ) {
				continue;
			}
			if ( '' !== $ym && $g['ym'] !== $ym && 0 !== strpos( $g['ym'], $ym . '/' ) ) {
				continue;
			}
			if ( $min && $g['bytes'] < $min ) {
				continue;
			}
			if ( $f['min_n'] && $g['n'] < $f['min_n'] ) {
				continue;
			}
			if ( $f['stale'] && $g['stale'] < 1 ) {
				continue;
			}
			if ( $f['alt'] && $g['alt'] < 1 ) {
				continue;
			}
			if ( $f['old'] && ( $g['recent'] || $g['prot'] ) ) {
				continue;
			}
			if ( $f['dup'] && ! isset( $dups[ $gid ] ) ) {
				continue;
			}
			if ( '' !== $q && false === strpos( UTK_Util::fold( $g['title'] . ' ' . $g['main'] ), $q ) ) {
				continue;
			}
			$out[] = (string) $gid;
		}
		return $out;
	}

	private static function gkey( $g, $by ) {
		switch ( $by ) {
			case 'status':
				return $g['status'];
			case 'ym':
				return $g['ym'];
			case 'kind':
				return $g['kind'];
			case 'src':
				return $g['src'];
			case 'parent':
				return (string) $g['parent'];
			case 'n':
				$n = $g['n'];
				return $n <= 5 ? (string) $n : ( $n <= 10 ? '6–10' : ( $n <= 20 ? '11–20' : '21+' ) );
		}
		return '';
	}

	public static function thumb_url( $rel ) {
		return '' === $rel ? '' : UTK_Util::baseurl() . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) );
	}

	public static function listing( $req ) {
		$r = self::result();
		if ( ! $r ) {
			return array( 'empty' => true );
		}
		$G    = $r['G'];
		$f    = self::clean_filter( isset( $req['f'] ) ? $req['f'] : array() );
		$sort = isset( $req['sort'] ) && in_array( $req['sort'], array( 'bytes', 'reclaim', 'n', 'mt', 'title', 'stale_bytes' ), true ) ? $req['sort'] : 'bytes';
		$dir  = ( isset( $req['dir'] ) && 'asc' === $req['dir'] ) ? 1 : -1;
		$by   = isset( $req['group'] ) && in_array( $req['group'], array( 'status', 'ym', 'kind', 'src', 'parent', 'n' ), true ) ? $req['group'] : '';
		$per  = isset( $req['per'] ) ? max( 10, min( 500, (int) $req['per'] ) ) : 100;
		$page = isset( $req['page'] ) ? max( 1, (int) $req['page'] ) : 1;

		$ids   = self::match( $G, $f, $r['D'] );
		$total = count( $ids );
		$bytes = $reclaim = 0;
		$agg   = array();
		foreach ( $ids as $gid ) {
			$g        = $G[ $gid ];
			$bytes   += $g['bytes'];
			$reclaim += $g['reclaim'];
			if ( $by ) {
				$k = self::gkey( $g, $by );
				if ( ! isset( $agg[ $k ] ) ) {
					$agg[ $k ] = array( 'label' => $k, 'count' => 0, 'bytes' => 0, 'reclaim' => 0 );
				}
				$agg[ $k ]['count']++;
				$agg[ $k ]['bytes']   += $g['bytes'];
				$agg[ $k ]['reclaim'] += $g['reclaim'];
			}
		}

		// ترتیب گروه‌ها
		$rank = array();
		if ( $by ) {
			$keys = array_keys( $agg );
			if ( 'status' === $by ) {
				$order = array_flip( array( 'unused', 'inactive', 'used' ) );
				usort( $keys, function ( $a, $b ) use ( $order ) {
					return $order[ $a ] <=> $order[ $b ];
				} );
			} elseif ( 'ym' === $by ) {
				rsort( $keys, SORT_STRING );
			} else {
				usort( $keys, function ( $a, $b ) use ( $agg ) {
					return $agg[ $b ]['bytes'] <=> $agg[ $a ]['bytes'];
				} );
			}
			$rank = array_flip( array_map( 'strval', $keys ) );
		}
		usort(
			$ids,
			function ( $a, $b ) use ( $G, $sort, $dir, $by, $rank ) {
				if ( $by ) {
					$ra = $rank[ self::gkey( $G[ $a ], $by ) ];
					$rb = $rank[ self::gkey( $G[ $b ], $by ) ];
					if ( $ra !== $rb ) {
						return $ra <=> $rb;
					}
				}
				$x = $G[ $a ][ $sort ];
				$y = $G[ $b ][ $sort ];
				$c = is_string( $x ) ? strcmp( $x, $y ) : $x <=> $y;
				return 0 === $c ? strcmp( (string) $a, (string) $b ) : $c * $dir;
			}
		);

		$pages = max( 1, (int) ceil( $total / $per ) );
		$page  = min( $page, $pages );
		$rows  = array();
		foreach ( array_slice( $ids, ( $page - 1 ) * $per, $per ) as $gid ) {
			$g      = $G[ $gid ];
			$rows[] = array(
				'gid'         => $gid,
				'id'          => $g['id'],
				'src'         => $g['src'],
				'title'       => $g['title'],
				'main'        => $g['main'],
				'status'      => $g['status'],
				'n'           => $g['n'],
				'bytes'       => $g['bytes'],
				'reclaim'     => $g['reclaim'],
				'stale'       => $g['stale'],
				'stale_bytes' => $g['stale_bytes'],
				'alt'         => $g['alt'],
				'alt_bytes'   => $g['alt_bytes'],
				'recent'      => $g['recent'],
				'prot'        => $g['prot'],
				'kind'        => $g['kind'],
				'ym'          => $g['ym'],
				'mt'          => $g['mt'],
				'thumb'       => self::thumb_url( $g['thumb'] ),
				'edit'        => $g['id'] ? admin_url( 'post.php?post=' . $g['id'] . '&action=edit' ) : '',
				'grp'         => $by ? self::gkey( $g, $by ) : '',
			);
		}
		if ( 'parent' === $by ) {
			foreach ( $rows as $row ) {
				$pid = (int) $row['grp'];
				if ( ! isset( $agg[ $row['grp'] ]['named'] ) ) {
					$agg[ $row['grp'] ]['label'] = $pid ? ( get_the_title( $pid ) ?: '#' . $pid ) : '';
					$agg[ $row['grp'] ]['named'] = 1;
				}
			}
		}
		return array(
			'total'   => $total,
			'bytes'   => $bytes,
			'reclaim' => $reclaim,
			'page'    => $page,
			'pages'   => $pages,
			'rows'    => $rows,
			'groups'  => $agg,
		);
	}

	/* ---------------------------------------------------------- duplicates */

	// اعضای یک مجموعه‌ی تکراری + پیشنهاد نگه‌داشتن/حذف
	public static function dup_members( array $set, array $G ) {
		$m = array();
		foreach ( $set['gids'] as $gid ) {
			if ( isset( $G[ $gid ] ) ) {
				$m[] = $gid;
			}
		}
		if ( count( $m ) < 2 ) {
			return null;
		}
		$rank = array( 'used' => 0, 'inactive' => 1, 'unused' => 2 );
		usort(
			$m,
			function ( $a, $b ) use ( $G, $rank ) {
				$x = $G[ $a ];
				$y = $G[ $b ];
				if ( $x['status'] !== $y['status'] ) {
					return $rank[ $x['status'] ] <=> $rank[ $y['status'] ];
				}
				if ( $x['src'] !== $y['src'] ) {
					return 'media' === $x['src'] ? -1 : 1;
				}
				return $x['id'] <=> $y['id'];
			}
		);
		$any_used = 'used' === $G[ $m[0] ]['status'];
		$out      = array();
		foreach ( $m as $i => $gid ) {
			$g       = $G[ $gid ];
			$keeper  = $any_used ? 'used' === $g['status'] : 0 === $i;
			$can     = 'used' !== $g['status'] && ! $g['recent'] && ! $g['prot'];
			$out[]   = array(
				'gid'     => $gid,
				'id'      => $g['id'],
				'title'   => $g['title'],
				'main'    => $g['main'],
				'status'  => $g['status'],
				'n'       => $g['n'],
				'bytes'   => $g['bytes'],
				'thumb'   => self::thumb_url( $g['thumb'] ),
				'edit'    => $g['id'] ? admin_url( 'post.php?post=' . $g['id'] . '&action=edit' ) : '',
				'can'     => $can,
				'suggest' => ! $keeper && $can && 'unused' === $g['status'],
			);
		}
		return $out;
	}

	public static function dups( $req ) {
		$r = self::result();
		if ( ! $r ) {
			return array( 'empty' => true );
		}
		$per  = isset( $req['per'] ) ? max( 5, min( 100, (int) $req['per'] ) ) : 20;
		$page = isset( $req['page'] ) ? max( 1, (int) $req['page'] ) : 1;
		$sets = array();
		$waste = 0;
		foreach ( $r['D'] as $set ) {
			$m = self::dup_members( $set, $r['G'] );
			if ( ! $m ) {
				continue;
			}
			$w = 0;
			foreach ( $m as $x ) {
				if ( $x['suggest'] ) {
					$w += $x['bytes'];
				}
			}
			$waste += $w;
			$sets[] = array( 'h' => $set['h'], 'size' => $set['size'], 'waste' => $w, 'members' => $m );
		}
		usort( $sets, function ( $a, $b ) {
			return $b['waste'] <=> $a['waste'] ?: $b['size'] <=> $a['size'];
		} );
		$total = count( $sets );
		$pages = max( 1, (int) ceil( $total / $per ) );
		$page  = min( $page, $pages );
		return array(
			'total'    => $total,
			'waste'    => $waste,
			'page'     => $page,
			'pages'    => $pages,
			'algo'     => isset( $r['meta']['algo'] ) ? $r['meta']['algo'] : 'md5',
			'hash_run' => ! empty( $r['meta']['hash'] ),
			'sets'     => array_slice( $sets, ( $page - 1 ) * $per, $per ),
		);
	}

	// پیشنهاد حذف برای همه‌ی مجموعه‌ها: [ ['h'=>..., 'del'=>[gid...]], ... ]
	public static function dups_suggestions() {
		$r   = self::result();
		$out = array();
		if ( ! $r ) {
			return $out;
		}
		foreach ( $r['D'] as $set ) {
			$m = self::dup_members( $set, $r['G'] );
			if ( ! $m ) {
				continue;
			}
			$del = array();
			foreach ( $m as $x ) {
				if ( $x['suggest'] ) {
					$del[] = $x['gid'];
				}
			}
			if ( $del ) {
				$out[] = array( 'h' => $set['h'], 'del' => $del );
			}
		}
		return $out;
	}
}
