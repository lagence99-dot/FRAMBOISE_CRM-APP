<?php
/**
 * Internal REST API used by the CRM screen (namespace frcrm/v1, logged-in CRM users only).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRCRM_REST {

	const NS = 'frcrm/v1';

	public static function routes() {
		$perm = 'frcrm_can';
		$r    = function ( $path, $methods, $cb ) use ( $perm ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $methods,
					'callback'            => array( __CLASS__, $cb ),
					'permission_callback' => $perm,
				)
			);
		};
		$r( '/boot', 'GET', 'boot' );
		$r( '/dashboard', 'GET', 'dashboard' );
		$r( '/clients', 'GET', 'clients' );
		$r( '/clients', 'POST', 'client_save' );
		$r( '/clients/(?P<id>\d+)', 'GET', 'client_get' );
		$r( '/clients/(?P<id>\d+)', 'PUT', 'client_save' );
		$r( '/clients/(?P<id>\d+)', 'DELETE', 'client_delete' );
		$r( '/clients/(?P<id>\d+)/status', 'POST', 'client_status' );
		$r( '/clients/(?P<id>\d+)/note', 'POST', 'client_note' );
		$r( '/clients/(?P<id>\d+)/exchanges', 'POST', 'exchange_save' );
		$r( '/exchanges/(?P<id>\d+)', 'DELETE', 'exchange_delete' );
		$r( '/services', 'GET', 'services' );
		$r( '/services', 'POST', 'service_save' );
		$r( '/services/(?P<id>\d+)', 'PUT', 'service_save' );
		$r( '/services/(?P<id>\d+)', 'DELETE', 'service_delete' );
		$r( '/quotes', 'GET', 'quotes' );
		$r( '/quotes', 'POST', 'quote_save' );
		$r( '/quotes/(?P<id>\d+)', 'GET', 'quote_get' );
		$r( '/quotes/(?P<id>\d+)', 'PUT', 'quote_save' );
		$r( '/quotes/(?P<id>\d+)', 'DELETE', 'quote_delete' );
		$r( '/quotes/(?P<id>\d+)/status', 'POST', 'quote_status' );
		$r( '/quotes/(?P<id>\d+)/duplicate', 'POST', 'quote_duplicate' );
		$r( '/reports', 'POST', 'report_save' );
		$r( '/reports/(?P<id>\d+)', 'PUT', 'report_save' );
		$r( '/reports/(?P<id>\d+)', 'DELETE', 'report_delete' );
		$r( '/reports/(?P<id>\d+)/action', 'POST', 'report_action' );
		$r( '/import', 'POST', 'import' );
		$r( '/settings', 'GET', 'settings_get' );
		$r( '/settings', 'PUT', 'settings_save' );
		$r( '/ai', 'POST', 'ai' );
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                            */
	/* ------------------------------------------------------------------ */

	private static function txt( $v, $multi = false ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		return $multi ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
	}

	private static function date_or_null( $v ) {
		$v = self::txt( $v );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : null;
	}

	private static function num( $v ) {
		return round( (float) str_replace( array( ' ', ',' ), array( '', '.' ), (string) $v ), 2 );
	}

	private static function err( $msg, $code = 400 ) {
		return new WP_Error( 'frcrm', $msg, array( 'status' => $code ) );
	}

	private static function client_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FRCRM_DB::t( 'clients' ) . ' WHERE id = %d', $id ), ARRAY_A );
	}

	private static function quote_row( $id ) {
		global $wpdb;
		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FRCRM_DB::t( 'quotes' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( $q ) {
			$q['items'] = json_decode( (string) $q['items'], true ) ?: array();
			$q['specs'] = json_decode( (string) $q['specs'], true ) ?: array();
		}
		return $q;
	}

	public static function report_rows( $client_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FRCRM_DB::t( 'reports' ) . ' WHERE client_id = %d ORDER BY meeting_date DESC, id DESC', $client_id ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['actions'] = json_decode( (string) $r['actions'], true ) ?: array();
		}
		return $rows;
	}

	/** Quote totals; shared with the print view. */
	public static function totals( $items, $discount, $exempt ) {
		$sub   = 0.0;
		$byvat = array();
		foreach ( (array) $items as $it ) {
			if ( ( $it['type'] ?? 'line' ) !== 'line' ) {
				continue;
			}
			$ht   = (float) $it['qty'] * (float) $it['price'] * ( 1 - (float) ( $it['discount'] ?? 0 ) / 100 );
			$sub += $ht;
			$rate = $exempt ? 0 : (float) $it['vat'];
			$key  = (string) $rate;
			$byvat[ $key ] = ( $byvat[ $key ] ?? 0 ) + $ht;
		}
		$f    = 1 - (float) $discount / 100;
		$ht   = $sub * $f;
		$vat  = array();
		$tva  = 0.0;
		foreach ( $byvat as $rate => $base ) {
			if ( (float) $rate > 0 ) {
				$amount       = $base * $f * (float) $rate / 100;
				$vat[ $rate ] = round( $amount, 2 );
				$tva         += $amount;
			}
		}
		return array(
			'subtotal' => round( $sub, 2 ),
			'discount' => round( $sub - $ht, 2 ),
			'ht'       => round( $ht, 2 ),
			'vat'      => $vat,
			'tva'      => round( $tva, 2 ),
			'ttc'      => round( $ht + $tva, 2 ),
		);
	}

	private static function next_number( $prefix ) {
		$year = wp_date( 'Y' );
		$all  = get_option( 'frcrm_counters', array() );
		$key  = $prefix . '-' . $year;
		$n    = (int) ( $all[ $key ] ?? 0 ) + 1;
		$all[ $key ] = $n;
		update_option( 'frcrm_counters', $all, false );
		return sprintf( '%s-%s-%03d', $prefix ? $prefix : 'D', $year, $n );
	}

	/* ------------------------------------------------------------------ */
	/* Boot and dashboard                                                 */
	/* ------------------------------------------------------------------ */

	public static function boot() {
		$s = FRCRM_Settings::get();
		return array(
			'statuses'       => FRCRM_Settings::statuses(),
			'quoteStatuses'  => FRCRM_Settings::quote_statuses(),
			'sectors'        => FRCRM_Settings::sectors(),
			'exchangeTypes'  => FRCRM_Settings::exchange_types(),
			'sources'        => FRCRM_Settings::sources(),
			'entities'       => array_map(
				function ( $e ) {
					return array(
						'key'        => $e['key'],
						'name'       => $e['name'],
						'color'      => $e['color'],
						'vat_exempt' => (int) $e['vat_exempt'],
					);
				},
				$s['entities']
			),
			'defaultEntity'  => $s['default_entity'],
			'specs'          => $s['specs'],
			'paymentTerms'   => $s['payment_terms'],
			'validityDays'   => (int) $s['validity_days'],
			'deposit'        => (int) $s['deposit'],
			'ai'             => class_exists( 'FRR_Claude' ) && class_exists( 'FRR_Admin' ) && '' !== FRR_Admin::settings()['api_key'],
			'isAdmin'        => current_user_can( 'manage_options' ),
		);
	}

	public static function dashboard() {
		global $wpdb;
		$C      = FRCRM_DB::t( 'clients' );
		$Q      = FRCRM_DB::t( 'quotes' );
		$today  = wp_date( 'Y-m-d' );
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) n FROM $C GROUP BY status", OBJECT_K ); // phpcs:ignore
		$by     = array();
		foreach ( FRCRM_Settings::statuses() as $k => $v ) {
			$by[ $k ] = isset( $counts[ $k ] ) ? (int) $counts[ $k ]->n : 0;
		}
		$year     = wp_date( 'Y' );
		$pipeline = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_ht),0) FROM $Q WHERE status = 'envoye'" ); // phpcs:ignore
		$signed   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_ht),0) FROM $Q WHERE status = 'accepte' AND YEAR(COALESCE(decided_at, updated_at)) = %d", $year ) ); // phpcs:ignore
		$sent_n   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $Q WHERE status IN ('envoye','accepte','refuse','expire') AND YEAR(COALESCE(sent_at, created_at)) = %d", $year ) ); // phpcs:ignore
		$won_n    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $Q WHERE status = 'accepte' AND YEAR(COALESCE(sent_at, created_at)) = %d", $year ) ); // phpcs:ignore

		return array(
			'byStatus' => $by,
			'pipeline' => $pipeline,
			'signed'   => $signed,
			'rate'     => $sent_n ? round( $won_n / $sent_n * 100 ) : null,
			'todo'     => FRCRM_Notify::todo( 14 ),
			'activity' => $wpdb->get_results( "SELECT a.*, c.company FROM " . FRCRM_DB::t( 'activity' ) . " a LEFT JOIN $C c ON c.id = a.client_id ORDER BY a.id DESC LIMIT 12", ARRAY_A ), // phpcs:ignore
			'today'    => $today,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Clients                                                            */
	/* ------------------------------------------------------------------ */

	public static function clients( WP_REST_Request $req ) {
		global $wpdb;
		$C     = FRCRM_DB::t( 'clients' );
		$K     = FRCRM_DB::t( 'contacts' );
		$Q     = FRCRM_DB::t( 'quotes' );
		$where = '1=1';
		$args  = array();
		$s     = self::txt( $req['search'] );
		if ( '' !== $s ) {
			$like  = '%' . $wpdb->esc_like( $s ) . '%';
			$digits = preg_replace( '/\D+/', '', $s );
			$tail   = strlen( $digits ) >= 9 ? substr( $digits, -9 ) : $digits;
			$where .= " AND (c.company LIKE %s OR c.problem LIKE %s OR c.trade LIKE %s OR c.city LIKE %s OR EXISTS (SELECT 1 FROM $K k WHERE k.client_id = c.id AND (k.first_name LIKE %s OR k.last_name LIKE %s OR k.email LIKE %s" . ( strlen( $tail ) >= 4 ? " OR REPLACE(REPLACE(REPLACE(REPLACE(k.phone,' ',''),'.',''),'-',''),'+','') LIKE %s" : '' ) . ')))';
			array_push( $args, $like, $like, $like, $like, $like, $like, $like );
			if ( strlen( $tail ) >= 4 ) {
				$args[] = '%' . $wpdb->esc_like( ltrim( $tail, '0' ) ) . '%';
			}
		}
		$sql  = "SELECT c.*,
			(SELECT CONCAT_WS(' ', k.first_name, k.last_name) FROM $K k WHERE k.client_id = c.id ORDER BY k.is_main DESC, k.id ASC LIMIT 1) AS contact,
			(SELECT k.email FROM $K k WHERE k.client_id = c.id ORDER BY k.is_main DESC, k.id ASC LIMIT 1) AS contact_email,
			(SELECT k.phone FROM $K k WHERE k.client_id = c.id ORDER BY k.is_main DESC, k.id ASC LIMIT 1) AS contact_phone,
			(SELECT COALESCE(SUM(q.total_ht),0) FROM $Q q WHERE q.client_id = c.id AND q.status = 'envoye') AS open_quotes
			FROM $C c WHERE $where ORDER BY c.updated_at DESC";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		return $rows;
	}

	public static function client_get( WP_REST_Request $req ) {
		global $wpdb;
		$id = (int) $req['id'];
		$c  = self::client_row( $id );
		if ( ! $c ) {
			return self::err( 'Client introuvable.', 404 );
		}
		$c['contacts'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FRCRM_DB::t( 'contacts' ) . ' WHERE client_id = %d ORDER BY is_main DESC, id ASC', $id ), ARRAY_A );
		$c['quotes']   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, number, title, status, entity, issue_date, valid_until, total_ht, total_ttc FROM ' . FRCRM_DB::t( 'quotes' ) . ' WHERE client_id = %d ORDER BY id DESC', $id ), ARRAY_A );
		$c['reports']  = self::report_rows( $id );
		$c['activity'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FRCRM_DB::t( 'activity' ) . ' WHERE client_id = %d ORDER BY id DESC LIMIT 60', $id ), ARRAY_A );
		$c['exchanges'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FRCRM_DB::t( 'exchanges' ) . ' WHERE client_id = %d ORDER BY exchange_date DESC, id DESC', $id ), ARRAY_A );
		return $c;
	}

	public static function client_save( WP_REST_Request $req ) {
		global $wpdb;
		$p   = $req->get_json_params();
		$id  = (int) $req['id'];
		$old = $id ? self::client_row( $id ) : null;
		if ( $id && ! $old ) {
			return self::err( 'Client introuvable.', 404 );
		}
		$statuses = FRCRM_Settings::statuses();
		$data     = array(
			'company'     => self::txt( $p['company'] ?? '' ),
			'sector'      => self::txt( $p['sector'] ?? '' ),
			'source'      => self::txt( $p['source'] ?? '' ),
			'entity'      => sanitize_key( $p['entity'] ?? '' ),
			'problem'     => self::txt( $p['problem'] ?? '', true ),
			'website'     => esc_url_raw( $p['website'] ?? '' ),
			'address'     => self::txt( $p['address'] ?? '', true ),
			'notes'       => self::txt( $p['notes'] ?? '', true ),
			'budget'      => self::txt( $p['budget'] ?? '' ),
			'next_action' => self::txt( $p['next_action'] ?? '' ),
			'next_date'   => self::date_or_null( $p['next_date'] ?? '' ),
			'updated_at'  => FRCRM_DB::now(),
		);
		$data = array_merge( $data, self::prospect_fields( $p ) );
		$contacts = is_array( $p['contacts'] ?? null ) ? $p['contacts'] : array();
		if ( '' === $data['company'] ) {
			$first = $contacts[0] ?? array();
			$name  = trim( self::txt( $first['first_name'] ?? '' ) . ' ' . self::txt( $first['last_name'] ?? '' ) );
			if ( '' === $name ) {
				return self::err( 'Indiquez au moins le nom du client ou d\'un contact.' );
			}
			$data['company'] = $name;
		}
		if ( ! $id ) {
			$st                 = sanitize_key( $p['status'] ?? 'prospect' );
			$data['status']     = isset( $statuses[ $st ] ) ? $st : 'prospect';
			$data['created_at'] = FRCRM_DB::now();
			$wpdb->insert( FRCRM_DB::t( 'clients' ), $data );
			$id = (int) $wpdb->insert_id;
			FRCRM_DB::log( $id, 'create', 'Fiche client créée' );
		} else {
			$wpdb->update( FRCRM_DB::t( 'clients' ), $data, array( 'id' => $id ) );
		}

		// Contacts: the list sent replaces the stored list.
		$K    = FRCRM_DB::t( 'contacts' );
		$keep = array();
		foreach ( $contacts as $i => $k ) {
			$row = array(
				'client_id'  => $id,
				'first_name' => self::txt( $k['first_name'] ?? '' ),
				'last_name'  => self::txt( $k['last_name'] ?? '' ),
				'role'       => self::txt( $k['role'] ?? '' ),
				'email'      => sanitize_email( $k['email'] ?? '' ),
				'phone'      => self::txt( $k['phone'] ?? '' ),
				'is_main'    => 0 === $i ? 1 : 0,
			);
			if ( '' === $row['first_name'] . $row['last_name'] . $row['email'] . $row['phone'] ) {
				continue;
			}
			$kid = (int) ( $k['id'] ?? 0 );
			if ( $kid && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $K WHERE id = %d AND client_id = %d", $kid, $id ) ) ) { // phpcs:ignore
				$wpdb->update( $K, $row, array( 'id' => $kid ) );
			} else {
				$wpdb->insert( $K, $row );
				$kid = (int) $wpdb->insert_id;
			}
			$keep[] = $kid;
		}
		if ( $keep ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM $K WHERE client_id = %d AND id NOT IN (" . implode( ',', array_map( 'intval', $keep ) ) . ')', $id ) ); // phpcs:ignore
		} else {
			$wpdb->delete( $K, array( 'client_id' => $id ) );
		}

		$req->set_param( 'id', $id );
		return self::client_get( $req );
	}

	public static function client_delete( WP_REST_Request $req ) {
		if ( ! FRCRM_DB::delete_client( (int) $req['id'] ) ) {
			return self::err( 'Client introuvable.', 404 );
		}
		return array( 'deleted' => true );
	}

	public static function set_client_status( $id, $status, $why = '' ) {
		global $wpdb;
		$statuses = FRCRM_Settings::statuses();
		$c        = self::client_row( $id );
		if ( ! $c || ! isset( $statuses[ $status ] ) || $c['status'] === $status ) {
			return false;
		}
		$wpdb->update(
			FRCRM_DB::t( 'clients' ),
			array(
				'status'     => $status,
				'updated_at' => FRCRM_DB::now(),
			),
			array( 'id' => $id )
		);
		$from = $statuses[ $c['status'] ][0] ?? $c['status'];
		$to   = $statuses[ $status ][0];
		FRCRM_DB::log( $id, 'status', 'État : ' . $from . ' → ' . $to . ( $why ? ' (' . $why . ')' : '' ) );
		FRCRM_Notify::status_changed( $c, $from, $to );
		return true;
	}

	public static function client_status( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		$st = sanitize_key( $req->get_json_params()['status'] ?? '' );
		if ( ! isset( FRCRM_Settings::statuses()[ $st ] ) ) {
			return self::err( 'État inconnu.' );
		}
		self::set_client_status( $id, $st );
		return self::client_row( $id );
	}

	public static function client_note( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$msg = self::txt( $req->get_json_params()['message'] ?? '', true );
		if ( ! self::client_row( $id ) || '' === $msg ) {
			return self::err( 'Note vide.' );
		}
		FRCRM_DB::log( $id, 'note', $msg );
		global $wpdb;
		$wpdb->update( FRCRM_DB::t( 'clients' ), array( 'updated_at' => FRCRM_DB::now() ), array( 'id' => $id ) );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ */
	/* Services                                                           */
	/* ------------------------------------------------------------------ */

	public static function services() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . FRCRM_DB::t( 'services' ) . ' ORDER BY active DESC, sort ASC, name ASC', ARRAY_A ); // phpcs:ignore
	}

	public static function service_save( WP_REST_Request $req ) {
		global $wpdb;
		$p    = $req->get_json_params();
		$id   = (int) $req['id'];
		$data = array(
			'name'         => self::txt( $p['name'] ?? '' ),
			'category'     => self::txt( $p['category'] ?? '' ),
			'description'  => self::txt( $p['description'] ?? '', true ),
			'deliverables' => self::txt( $p['deliverables'] ?? '', true ),
			'unit'         => self::txt( $p['unit'] ?? 'forfait' ),
			'price'        => self::num( $p['price'] ?? 0 ),
			'vat'          => self::num( $p['vat'] ?? 20 ),
			'active'       => empty( $p['active'] ) ? 0 : 1,
			'sort'         => (int) ( $p['sort'] ?? 0 ),
		);
		if ( '' === $data['name'] ) {
			return self::err( 'Le nom de la prestation est obligatoire.' );
		}
		if ( $id ) {
			$wpdb->update( FRCRM_DB::t( 'services' ), $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( FRCRM_DB::t( 'services' ), $data );
		}
		return self::services();
	}

	public static function service_delete( WP_REST_Request $req ) {
		global $wpdb;
		$wpdb->delete( FRCRM_DB::t( 'services' ), array( 'id' => (int) $req['id'] ) );
		return self::services();
	}

	/* ------------------------------------------------------------------ */
	/* Quotes                                                             */
	/* ------------------------------------------------------------------ */

	public static function quotes( WP_REST_Request $req ) {
		global $wpdb;
		$Q   = FRCRM_DB::t( 'quotes' );
		$C   = FRCRM_DB::t( 'clients' );
		$sql = "SELECT q.id, q.client_id, q.number, q.title, q.status, q.entity, q.issue_date, q.valid_until, q.total_ht, q.total_ttc, c.company FROM $Q q LEFT JOIN $C c ON c.id = q.client_id";
		if ( (int) $req['client_id'] ) {
			return $wpdb->get_results( $wpdb->prepare( $sql . ' WHERE q.client_id = %d ORDER BY q.id DESC', (int) $req['client_id'] ), ARRAY_A ); // phpcs:ignore
		}
		return $wpdb->get_results( $sql . ' ORDER BY q.id DESC LIMIT 300', ARRAY_A ); // phpcs:ignore
	}

	public static function quote_get( WP_REST_Request $req ) {
		$q = self::quote_row( (int) $req['id'] );
		if ( ! $q ) {
			return self::err( 'Devis introuvable.', 404 );
		}
		$ent         = FRCRM_Settings::entity( $q['entity'] );
		$q['totals'] = self::totals( $q['items'], $q['discount'], ! empty( $ent['vat_exempt'] ) );
		$r           = new WP_REST_Request();
		$r->set_param( 'id', $q['client_id'] );
		$q['client'] = self::client_get( $r );
		return $q;
	}

	private static function clean_items( $items ) {
		$out = array();
		foreach ( (array) $items as $it ) {
			$type = ( $it['type'] ?? 'line' ) === 'title' ? 'title' : 'line';
			$name = self::txt( $it['name'] ?? '' );
			if ( '' === $name && 'title' === $type ) {
				continue;
			}
			$out[] = array(
				'type'        => $type,
				'name'        => $name,
				'description' => self::txt( $it['description'] ?? '', true ),
				'qty'         => self::num( $it['qty'] ?? 1 ),
				'unit'        => self::txt( $it['unit'] ?? '' ),
				'price'       => self::num( $it['price'] ?? 0 ),
				'vat'         => self::num( $it['vat'] ?? 20 ),
				'discount'    => max( 0, min( 100, self::num( $it['discount'] ?? 0 ) ) ),
				'service_id'  => (int) ( $it['service_id'] ?? 0 ),
			);
		}
		return $out;
	}

	private static function clean_specs( $specs ) {
		$out = array();
		foreach ( (array) $specs as $sp ) {
			$title = self::txt( $sp['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$out[] = array(
				'key'   => sanitize_key( $sp['key'] ?? '' ),
				'title' => $title,
				'text'  => self::txt( $sp['text'] ?? '', true ),
				'on'    => empty( $sp['on'] ) && isset( $sp['on'] ) ? 0 : 1,
			);
		}
		return $out;
	}

	public static function quote_save( WP_REST_Request $req ) {
		global $wpdb;
		$p   = $req->get_json_params();
		$id  = (int) $req['id'];
		$old = $id ? self::quote_row( $id ) : null;
		if ( $id && ! $old ) {
			return self::err( 'Devis introuvable.', 404 );
		}
		$client_id = (int) ( $p['client_id'] ?? ( $old['client_id'] ?? 0 ) );
		$client    = self::client_row( $client_id );
		if ( ! $client ) {
			return self::err( 'Choisissez un client.' );
		}
		$s      = FRCRM_Settings::get();
		$entity = sanitize_key( $p['entity'] ?? ( $old['entity'] ?? ( $client['entity'] ? $client['entity'] : $s['default_entity'] ) ) );
		$ent    = FRCRM_Settings::entity( $entity );
		$entity = $ent['key'];
		$items  = self::clean_items( $p['items'] ?? ( $old['items'] ?? array() ) );
		$disc   = max( 0, min( 100, self::num( $p['discount'] ?? 0 ) ) );
		$tot    = self::totals( $items, $disc, ! empty( $ent['vat_exempt'] ) );
		$issue  = self::date_or_null( $p['issue_date'] ?? '' );
		$issue  = $issue ? $issue : wp_date( 'Y-m-d' );
		$valid  = self::date_or_null( $p['valid_until'] ?? '' );
		$valid  = $valid ? $valid : wp_date( 'Y-m-d', strtotime( $issue . ' +' . (int) $s['validity_days'] . ' days' ) );

		$data = array(
			'client_id'     => $client_id,
			'contact_id'    => (int) ( $p['contact_id'] ?? 0 ),
			'entity'        => $entity,
			'title'         => self::txt( $p['title'] ?? '' ),
			'issue_date'    => $issue,
			'valid_until'   => $valid,
			'intro'         => self::txt( $p['intro'] ?? '', true ),
			'specs'         => wp_json_encode( self::clean_specs( $p['specs'] ?? array() ) ),
			'items'         => wp_json_encode( $items ),
			'discount'      => $disc,
			'deposit'       => max( 0, min( 100, self::num( $p['deposit'] ?? $s['deposit'] ) ) ),
			'payment_terms' => self::txt( $p['payment_terms'] ?? $s['payment_terms'], true ),
			'notes'         => self::txt( $p['notes'] ?? '', true ),
			'total_ht'      => $tot['ht'],
			'total_ttc'     => $tot['ttc'],
			'updated_at'    => FRCRM_DB::now(),
		);
		if ( '' === $data['title'] ) {
			$data['title'] = 'Proposition pour ' . $client['company'];
		}

		if ( ! $id ) {
			$data['number']     = self::next_number( $ent['prefix'] );
			$data['status']     = 'brouillon';
			$data['created_at'] = FRCRM_DB::now();
			$wpdb->insert( FRCRM_DB::t( 'quotes' ), $data );
			$id = (int) $wpdb->insert_id;
			FRCRM_DB::log( $client_id, 'quote', 'Devis ' . $data['number'] . ' créé' );
		} else {
			$wpdb->update( FRCRM_DB::t( 'quotes' ), $data, array( 'id' => $id ) );
		}
		$wpdb->update( FRCRM_DB::t( 'clients' ), array( 'updated_at' => FRCRM_DB::now() ), array( 'id' => $client_id ) );

		$r = new WP_REST_Request();
		$r->set_param( 'id', $id );
		return self::quote_get( $r );
	}

	public static function quote_delete( WP_REST_Request $req ) {
		global $wpdb;
		$q = self::quote_row( (int) $req['id'] );
		if ( ! $q ) {
			return self::err( 'Devis introuvable.', 404 );
		}
		$wpdb->delete( FRCRM_DB::t( 'quotes' ), array( 'id' => (int) $q['id'] ) );
		FRCRM_DB::log( $q['client_id'], 'quote', 'Devis ' . $q['number'] . ' supprimé' );
		return array( 'deleted' => true );
	}

	public static function quote_duplicate( WP_REST_Request $req ) {
		$q = self::quote_row( (int) $req['id'] );
		if ( ! $q ) {
			return self::err( 'Devis introuvable.', 404 );
		}
		$r = new WP_REST_Request( 'POST' );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body(
			wp_json_encode(
				array_merge(
					$q,
					array(
						'title'       => $q['title'] . ' (copie)',
						'issue_date'  => '',
						'valid_until' => '',
					)
				)
			)
		);
		return self::quote_save( $r );
	}

	public static function quote_status( WP_REST_Request $req ) {
		global $wpdb;
		$q  = self::quote_row( (int) $req['id'] );
		$st = sanitize_key( $req->get_json_params()['status'] ?? '' );
		$qs = FRCRM_Settings::quote_statuses();
		if ( ! $q || ! isset( $qs[ $st ] ) ) {
			return self::err( 'Devis ou statut inconnu.' );
		}
		$data = array(
			'status'     => $st,
			'updated_at' => FRCRM_DB::now(),
		);
		if ( 'envoye' === $st && empty( $q['sent_at'] ) ) {
			$data['sent_at'] = FRCRM_DB::now();
		}
		if ( in_array( $st, array( 'accepte', 'refuse' ), true ) ) {
			$data['decided_at'] = FRCRM_DB::now();
		}
		$wpdb->update( FRCRM_DB::t( 'quotes' ), $data, array( 'id' => (int) $q['id'] ) );
		FRCRM_DB::log( $q['client_id'], 'quote', 'Devis ' . $q['number'] . ' : ' . $qs[ $st ] );

		// Client pipeline follows the quote when it moves forward.
		$order  = array_keys( FRCRM_Settings::statuses() );
		$client = self::client_row( $q['client_id'] );
		$pos    = array_search( $client['status'], $order, true );
		if ( 'envoye' === $st && $pos < array_search( 'devis', $order, true ) ) {
			self::set_client_status( $q['client_id'], 'devis', 'devis ' . $q['number'] . ' envoyé' );
		}
		if ( 'accepte' === $st && $pos < array_search( 'signe', $order, true ) ) {
			self::set_client_status( $q['client_id'], 'signe', 'devis ' . $q['number'] . ' accepté' );
		}
		if ( in_array( $st, array( 'accepte', 'refuse' ), true ) ) {
			FRCRM_Notify::quote_decided( $q, $client, $qs[ $st ] );
		}
		$r = new WP_REST_Request();
		$r->set_param( 'id', $q['id'] );
		return self::quote_get( $r );
	}

	/* ------------------------------------------------------------------ */
	/* Reports                                                            */
	/* ------------------------------------------------------------------ */

	public static function report_save( WP_REST_Request $req ) {
		global $wpdb;
		$p         = $req->get_json_params();
		$id        = (int) $req['id'];
		$client_id = (int) ( $p['client_id'] ?? 0 );
		if ( $id ) {
			$client_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . FRCRM_DB::t( 'reports' ) . ' WHERE id = %d', $id ) );
		}
		if ( ! self::client_row( $client_id ) ) {
			return self::err( 'Client introuvable.', 404 );
		}
		$actions = array();
		foreach ( (array) ( $p['actions'] ?? array() ) as $a ) {
			$t = self::txt( $a['text'] ?? '' );
			if ( '' === $t ) {
				continue;
			}
			$actions[] = array(
				'text' => $t,
				'who'  => self::txt( $a['who'] ?? '' ),
				'due'  => self::date_or_null( $a['due'] ?? '' ),
				'done' => empty( $a['done'] ) ? 0 : 1,
			);
		}
		$data = array(
			'client_id'    => $client_id,
			'meeting_date' => self::date_or_null( $p['meeting_date'] ?? '' ) ?? wp_date( 'Y-m-d' ),
			'type'         => sanitize_key( $p['type'] ?? 'rdv' ),
			'title'        => self::txt( $p['title'] ?? '' ),
			'participants' => self::txt( $p['participants'] ?? '', true ),
			'notes'        => self::txt( $p['notes'] ?? '', true ),
			'decisions'    => self::txt( $p['decisions'] ?? '', true ),
			'actions'      => wp_json_encode( $actions ),
			'updated_at'   => FRCRM_DB::now(),
		);
		if ( $id ) {
			$wpdb->update( FRCRM_DB::t( 'reports' ), $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = FRCRM_DB::now();
			$wpdb->insert( FRCRM_DB::t( 'reports' ), $data );
			FRCRM_DB::log( $client_id, 'report', 'Compte-rendu ajouté : ' . ( $data['title'] ? $data['title'] : $data['meeting_date'] ) );
		}
		$wpdb->update( FRCRM_DB::t( 'clients' ), array( 'updated_at' => FRCRM_DB::now() ), array( 'id' => $client_id ) );
		return self::report_rows( $client_id );
	}

	public static function report_delete( WP_REST_Request $req ) {
		global $wpdb;
		$id        = (int) $req['id'];
		$client_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . FRCRM_DB::t( 'reports' ) . ' WHERE id = %d', $id ) );
		$wpdb->delete( FRCRM_DB::t( 'reports' ), array( 'id' => $id ) );
		return self::report_rows( $client_id );
	}

	/** Ticks or unticks one follow-up action. */
	public static function report_action( WP_REST_Request $req ) {
		global $wpdb;
		$p   = $req->get_json_params();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FRCRM_DB::t( 'reports' ) . ' WHERE id = %d', (int) $req['id'] ), ARRAY_A );
		if ( ! $row ) {
			return self::err( 'Compte-rendu introuvable.', 404 );
		}
		$acts = json_decode( (string) $row['actions'], true ) ?: array();
		$i    = (int) ( $p['index'] ?? -1 );
		if ( ! isset( $acts[ $i ] ) ) {
			return self::err( 'Action introuvable.' );
		}
		$acts[ $i ]['done'] = empty( $p['done'] ) ? 0 : 1;
		$wpdb->update( FRCRM_DB::t( 'reports' ), array( 'actions' => wp_json_encode( $acts ) ), array( 'id' => (int) $row['id'] ) );
		if ( $acts[ $i ]['done'] ) {
			FRCRM_DB::log( $row['client_id'], 'action', 'Action faite : ' . $acts[ $i ]['text'] );
		}
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ */
	/* Settings and AI                                                    */
	/* ------------------------------------------------------------------ */

	/** Prospecting follow-up fields, shared by the client form and the import. */
	private static function prospect_fields( $p ) {
		$url = function ( $v ) { $v = trim( (string) $v ); return esc_url_raw( $v && ! preg_match( '#^https?://#i', $v ) ? 'https://' . $v : $v ); };
		$yes = function ( $v ) { return in_array( strtolower( trim( (string) $v ) ), array( '1', 'oui', 'yes', 'true', 'on', 'x' ), true ) ? 1 : 0; };
		$out = array(
			'trade'         => self::txt( $p['trade'] ?? ( $p['activity'] ?? '' ) ),
			'city'          => self::txt( $p['city'] ?? '' ),
			'linkedin'      => $url( $p['linkedin'] ?? '' ),
			'project'       => self::txt( $p['project'] ?? '' ),
			'channels'      => self::txt( $p['channels'] ?? '' ),
			'source_url'    => $url( $p['source_url'] ?? '' ),
			'need'          => self::txt( $p['need'] ?? '', true ),
			'first_contact' => self::date_or_null( $p['first_contact'] ?? '' ),
			'last_contact'  => self::date_or_null( $p['last_contact'] ?? '' ),
			'replied'       => $yes( $p['replied'] ?? 0 ),
			'replied_date'  => self::date_or_null( $p['replied_date'] ?? '' ),
			'optout'        => $yes( $p['optout'] ?? 0 ),
			'optout_date'   => self::date_or_null( $p['optout_date'] ?? '' ),
		);
		if ( $out['replied'] && ! $out['replied_date'] ) {
			$out['replied_date'] = current_time( 'Y-m-d' );
		}
		if ( $out['optout'] && ! $out['optout_date'] ) {
			$out['optout_date'] = current_time( 'Y-m-d' );
		}
		return $out;
	}

	/** Adds one exchange; a phone, WhatsApp, SMS, e-mail exchange or a meeting also marks the client as « a répondu ». */
	private static function add_exchange( $cid, $type, $date, $note, $dedupe = false ) {
		global $wpdb;
		$X     = FRCRM_DB::t( 'exchanges' );
		$types = FRCRM_Settings::exchange_types();
		$type  = isset( $types[ $type ] ) ? $type : 'autre';
		$date  = self::date_or_null( $date ) ? $date : current_time( 'Y-m-d' );
		if ( $dedupe && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $X WHERE client_id = %d AND type = %s AND exchange_date = %s AND note = %s LIMIT 1", $cid, $type, $date, $note ) ) ) { // phpcs:ignore
			return 0;
		}
		$wpdb->insert( $X, array( 'client_id' => $cid, 'type' => $type, 'exchange_date' => $date, 'note' => $note, 'user_id' => get_current_user_id(), 'created_at' => FRCRM_DB::now() ) );
		$upd = array( 'updated_at' => FRCRM_DB::now() );
		$c   = self::client_row( $cid );
		if ( in_array( $type, array( 'appel', 'whatsapp', 'sms', 'email', 'rdv', 'reponse' ), true ) && ! (int) $c['replied'] ) {
			$upd['replied']      = 1;
			$upd['replied_date'] = $date;
		}
		if ( ! $c['last_contact'] || $date > $c['last_contact'] ) {
			$upd['last_contact'] = $date;
		}
		if ( ! $c['first_contact'] || $date < $c['first_contact'] ) {
			$upd['first_contact'] = $date;
		}
		$wpdb->update( FRCRM_DB::t( 'clients' ), $upd, array( 'id' => $cid ) );
		return (int) $wpdb->insert_id;
	}

	public static function exchange_save( WP_REST_Request $req ) {
		$id = (int) $req['id'];
		if ( ! self::client_row( $id ) ) {
			return self::err( 'Client introuvable.', 404 );
		}
		$p = $req->get_json_params();
		self::add_exchange( $id, sanitize_key( $p['type'] ?? 'appel' ), self::txt( $p['date'] ?? '' ), self::txt( $p['note'] ?? '', true ) );
		$types = FRCRM_Settings::exchange_types();
		FRCRM_DB::log( $id, 'note', ( $types[ sanitize_key( $p['type'] ?? '' ) ] ?? 'Échange' ) . ' noté' );
		return self::client_get( $req );
	}

	public static function exchange_delete( WP_REST_Request $req ) {
		global $wpdb;
		$ok = $wpdb->delete( FRCRM_DB::t( 'exchanges' ), array( 'id' => (int) $req['id'] ) );
		return $ok ? array( 'deleted' => true ) : self::err( 'Échange introuvable.', 404 );
	}

	/* ------------------------------------------------------------------ */
	/* Import from the prospecting app (Framboise CRM on claude.ai)       */
	/* ------------------------------------------------------------------ */

	/** Last 9 digits of a phone number, so 06…, +33 6… and 0033 6… match. */
	private static function phone_key( $p ) {
		$d = preg_replace( '/\D+/', '', (string) $p );
		return strlen( $d ) >= 9 ? substr( $d, -9 ) : '';
	}

	/** Free-text activity (« Couvreur », « Coiffeur »…) → one of the CRM sectors. */
	public static function map_sector( $txt ) {
		$sectors = FRCRM_Settings::sectors();
		foreach ( $sectors as $s ) {
			if ( 0 === strcasecmp( $s, trim( (string) $txt ) ) ) {
				return $s;
			}
		}
		$t   = remove_accents( strtolower( (string) $txt ) );
		$map = array(
			'Artisans du bâtiment'   => 'batiment|btp|plomb|electric|macon|menuis|couvre|toiture|peint|carrel|chauff|climat|renovation|charpent|serrur|isolation|facade|terrass|paysag|piscine|vitr|store|agencement|cuisin',
			'Beauté & esthétique'    => 'coiff|esthet|beaute|barbier|ongl|spa|maquill|institut',
			'Restauration'           => 'restaur|boulang|patiss|traiteur|pizz|cafe|bar\b|brasserie|chocolat',
			'Hôtellerie'             => 'hotel|chambre d.hote|gite|residence',
			'Immobilier'             => 'immobil|agence immo|syndic|promoteur',
			'Santé'                  => 'kine|osteo|dentist|medec|infirm|pharma|sante|psycho|podolog|orthophon|veterin',
			'Sport & bien-être'      => 'sport|coach|fitness|yoga|pilates|bien-etre|massage|salle de',
			'Avocats & notaires'     => 'avocat|notaire|juriste|huissier',
			'Architectes & déco'     => 'architect|decorat|design d.interieur',
			'Commerces & magasins'   => 'boutique|magasin|commerce|fleur|epicer|caviste|opticien|garage|auto|carross|photograph',
			'Événementiel & mariage' => 'evenement|mariage|dj\b|wedding',
			'Tourisme & loisirs'     => 'tourism|loisir|voyage|location de',
			'Banque & assurance'     => 'banque|assuran|courtier|credit|patrimoine',
			'Textile & mode'         => 'mode|textile|pret-a-porter|couture',
			'Luxe'                   => 'luxe|joaill|haute',
		);
		foreach ( $map as $sector => $re ) {
			if ( preg_match( '/' . $re . '/', $t ) ) {
				return $sector;
			}
		}
		return '' === trim( (string) $txt ) ? '' : 'Autre';
	}

	/**
	 * Rows exported by the prospecting app: a client is matched by phone, then e-mail, then company name.
	 * New people are created; known people only get their empty fields filled, the next action and new history lines.
	 */
	public static function import( WP_REST_Request $req ) {
		global $wpdb;
		$rows = $req->get_json_params()['rows'] ?? array();
		if ( ! is_array( $rows ) || ! $rows ) {
			return self::err( 'Aucune ligne à importer.' );
		}
		$C        = FRCRM_DB::t( 'clients' );
		$K        = FRCRM_DB::t( 'contacts' );
		$A        = FRCRM_DB::t( 'activity' );
		$statuses = FRCRM_Settings::statuses();
		$order    = array_keys( $statuses );
		$ents     = wp_list_pluck( FRCRM_Settings::get()['entities'], 'key' );
		$out      = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'history' => 0 );

		foreach ( array_slice( $rows, 0, 500 ) as $r ) {
			if ( ! is_array( $r ) ) {
				$out['skipped']++;
				continue;
			}
			$company = self::txt( $r['company'] ?? '' );
			$first   = self::txt( $r['first_name'] ?? '' );
			$last    = self::txt( $r['last_name'] ?? '' );
			$email   = sanitize_email( $r['email'] ?? '' );
			$phone   = self::txt( $r['phone'] ?? '' );
			if ( '' === $company ) {
				$company = trim( $first . ' ' . $last );
			}
			if ( '' === $company && '' === $phone && '' === $email ) {
				$out['skipped']++;
				continue;
			}
			if ( '' === $company ) {
				$company = $phone ? $phone : $email;
			}

			// Find the client already in the CRM.
			$cid = 0;
			$pk  = self::phone_key( $phone );
			if ( $pk ) {
				foreach ( $wpdb->get_results( "SELECT client_id, phone FROM $K WHERE phone <> ''", ARRAY_A ) as $k ) { // phpcs:ignore
					if ( self::phone_key( $k['phone'] ) === $pk ) {
						$cid = (int) $k['client_id'];
						break;
					}
				}
			}
			if ( ! $cid && $email ) {
				$cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM $K WHERE email = %s LIMIT 1", $email ) ); // phpcs:ignore
			}
			if ( ! $cid ) {
				$cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $C WHERE company = %s LIMIT 1", $company ) ); // phpcs:ignore
			}
			if ( $cid && ! self::client_row( $cid ) ) {
				$cid = 0;
			}

			$st     = sanitize_key( $r['status'] ?? '' );
			$st     = isset( $statuses[ $st ] ) ? $st : 'contacte';
			$entity = sanitize_key( $r['entity'] ?? '' );
			$entity = in_array( $entity, $ents, true ) ? $entity : '';
			$fields = array(
				'company'     => $company,
				'sector'      => self::map_sector( $r['sector'] ?? '' ),
				'source'      => self::txt( $r['source'] ?? '' ),
				'entity'      => $entity,
				'problem'     => self::txt( $r['problem'] ?? '', true ),
				'website'     => esc_url_raw( ( $r['website'] ?? '' ) && ! preg_match( '#^https?://#', $r['website'] ) ? 'https://' . $r['website'] : ( $r['website'] ?? '' ) ),
				'address'     => self::txt( $r['address'] ?? '', true ),
				'notes'       => self::txt( $r['notes'] ?? '', true ),
				'budget'      => self::txt( $r['budget'] ?? '' ),
				'next_action' => self::txt( $r['next_action'] ?? '' ),
				'next_date'   => self::date_or_null( $r['next_date'] ?? '' ),
			);
			$fields = array_merge( $fields, self::prospect_fields( $r ) );
			if ( '' === $fields['city'] && '' !== $fields['address'] ) {
				$fields['city'] = $fields['address'];
			}

			if ( ! $cid ) {
				$wpdb->insert( $C, array_merge( $fields, array( 'status' => $st, 'created_at' => FRCRM_DB::now(), 'updated_at' => FRCRM_DB::now() ) ) );
				$cid = (int) $wpdb->insert_id;
				if ( ! $cid ) {
					$out['skipped']++;
					continue;
				}
				FRCRM_DB::log( $cid, 'create', 'Fiche importée depuis la prospection (Framboise CRM)' );
				$out['created']++;
			} else {
				$old = self::client_row( $cid );
				$upd = array();
				foreach ( $fields as $k => $v ) {
					if ( in_array( $k, array( 'next_action', 'next_date', 'notes', 'need', 'channels', 'last_contact' ), true ) ) {
						if ( $v && (string) $v !== (string) $old[ $k ] ) {
							$upd[ $k ] = $v; // the prospecting app holds the latest follow-up for these
						}
					} elseif ( in_array( $k, array( 'replied', 'optout' ), true ) ) {
						if ( $v && ! (int) $old[ $k ] ) {
							$upd[ $k ]           = 1; // « a répondu » / « ne plus contacter » are never undone by an import
							$upd[ $k . '_date' ] = $fields[ $k . '_date' ];
						}
					} elseif ( in_array( $k, array( 'replied_date', 'optout_date' ), true ) ) {
						continue;
					} elseif ( 'first_contact' === $k ) {
						if ( $v && ( ! $old[ $k ] || $v < $old[ $k ] ) ) {
							$upd[ $k ] = $v;
						}
					} elseif ( '' !== (string) $v && '' === (string) $old[ $k ] ) {
						$upd[ $k ] = $v;
					}
				}
				if ( $upd ) {
					$upd['updated_at'] = FRCRM_DB::now();
					$wpdb->update( $C, $upd, array( 'id' => $cid ) );
				}
				// The state only moves forward (never back from « Signé » to « Contacté »), except to « Perdu ».
				$from = array_search( $old['status'], $order, true );
				$to   = array_search( $st, $order, true );
				if ( $st !== $old['status'] && ( 'perdu' === $st || ( false !== $to && ( false === $from || $to > $from ) && ! in_array( $old['status'], array( 'signe', 'production', 'livre' ), true ) ) ) ) {
					self::set_client_status( $cid, $st, 'import prospection' );
				}
				$out['updated']++;
			}

			// Main contact (added if this phone / e-mail is not already on the card).
			if ( $first . $last . $email . $phone ) {
				$known = false;
				foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT email, phone FROM $K WHERE client_id = %d", $cid ), ARRAY_A ) as $k ) { // phpcs:ignore
					if ( ( $pk && self::phone_key( $k['phone'] ) === $pk ) || ( $email && 0 === strcasecmp( $k['email'], $email ) ) ) {
						$known = true;
						break;
					}
				}
				if ( ! $known ) {
					$has_main = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $K WHERE client_id = %d", $cid ) ); // phpcs:ignore
					$wpdb->insert( $K, array( 'client_id' => $cid, 'first_name' => $first, 'last_name' => $last, 'role' => self::txt( $r['role'] ?? '' ), 'email' => $email, 'phone' => $phone, 'is_main' => $has_main ? 0 : 1 ) );
				}
			}

			// Exchanges: « 2026-11-06 | appel | note » lines (v1.2), messages sent « 2026-11-06 | WhatsApp | texte »,
			// and the v1.1 « 2026-11-06 | Échange téléphonique : note » history. Never added twice.
			$types  = FRCRM_Settings::exchange_types();
			$labels = array();
			foreach ( $types as $k => $l ) {
				$labels[ remove_accents( strtolower( $l ) ) ] = $k;
			}
			$lines = function ( $v ) {
				if ( is_array( $v ) ) {
					return $v;
				}
				return array_filter( array_map( 'trim', explode( "\n", str_replace( array( ' || ', '||' ), "\n", (string) $v ) ) ) );
			};
			foreach ( $lines( $r['exchanges'] ?? '' ) as $line ) {
				$parts = array_map( 'trim', explode( '|', (string) $line, 3 ) );
				if ( count( $parts ) < 2 || ! self::date_or_null( $parts[0] ) ) {
					continue;
				}
				$type = sanitize_key( $parts[1] );
				if ( ! isset( $types[ $type ] ) ) {
					$type = $labels[ remove_accents( strtolower( $parts[1] ) ) ] ?? 'autre';
				}
				$out['history'] += self::add_exchange( $cid, $type, $parts[0], self::txt( $parts[2] ?? '', true ), true ) ? 1 : 0;
			}
			foreach ( $lines( $r['prospect_messages'] ?? '' ) as $line ) {
				$parts = array_map( 'trim', explode( '|', (string) $line, 3 ) );
				if ( count( $parts ) < 3 || ! self::date_or_null( $parts[0] ) ) {
					continue;
				}
				$out['history'] += self::add_exchange( $cid, 'prospection', $parts[0], self::txt( $parts[1] . ' : ' . str_replace( ' ⏎ ', "\n", $parts[2] ), true ), true ) ? 1 : 0;
			}
			foreach ( $lines( $r['history'] ?? '' ) as $line ) {
				if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})\s*\|\s*(.+)$/', self::txt( $line ), $m ) ) {
					continue;
				}
				$type = 'autre';
				$note = $m[2];
				foreach ( $labels as $l => $k ) {
					if ( 0 === strpos( remove_accents( strtolower( $m[2] ) ), $l ) ) {
						$type = $k;
						$note = trim( ltrim( substr( $m[2], strlen( $types[ $k ] ) ), ' :' ) );
						break;
					}
				}
				if ( preg_match( '/^Message de prospection envoy/i', $m[2] ) ) {
					$type = 'prospection';
					$note = $m[2];
				}
				$out['history'] += self::add_exchange( $cid, $type, $m[1], $note, true ) ? 1 : 0;
			}
		}
		return $out;
	}

	public static function settings_get() {
		return FRCRM_Settings::get();
	}

	public static function settings_save( WP_REST_Request $req ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::err( 'Seul un administrateur peut modifier les réglages.', 403 );
		}
		return FRCRM_Settings::save( $req->get_json_params() );
	}

	/** Optional help from Claude, through the key saved in Framboise Rédacteur. */
	public static function ai( WP_REST_Request $req ) {
		if ( ! class_exists( 'FRR_Claude' ) ) {
			return self::err( 'L\'aide IA utilise la clé de l\'extension Framboise Rédacteur : installez-la et enregistrez la clé.' );
		}
		$p      = $req->get_json_params();
		$task   = sanitize_key( $p['task'] ?? '' );
		$client = self::client_row( (int) ( $p['client_id'] ?? 0 ) );
		$ctx    = $client ? "Client : {$client['company']} (secteur : {$client['sector']})\nProblématique : {$client['problem']}\n" : '';
		$rules  = 'Écris en français professionnel, phrases claires, sans tiret long, sans formule creuse. N\'invente aucun fait, chiffre ou engagement absent des éléments fournis.';

		if ( 'cr' === $task ) {
			$notes = self::txt( $p['text'] ?? '', true );
			if ( '' === $notes ) {
				return self::err( 'Ajoutez d\'abord vos notes brutes.' );
			}
			$r = FRR_Claude::ask(
				"Tu mets au propre des comptes-rendus de rendez-vous client pour une agence de conseil. $rules Réponds uniquement par un objet JSON.",
				$ctx . "Notes brutes :\n$notes\n\nRéponds avec : {\"notes\":\"compte-rendu rédigé, paragraphes courts\",\"decisions\":\"décisions prises, une par ligne\",\"actions\":[{\"text\":\"action\",\"who\":\"qui\",\"due\":\"AAAA-MM-JJ ou vide\"}]}",
				2500
			);
			if ( is_wp_error( $r ) ) {
				return self::err( $r->get_error_message() );
			}
			$j = FRR_Claude::json( $r['text'] );
			return $j ? $j : self::err( 'Réponse illisible, réessayez.' );
		}

		if ( 'specs' === $task ) {
			$items  = self::clean_items( $p['items'] ?? array() );
			$lines  = implode( "\n", array_map( function ( $i ) { return '- ' . $i['name'] . ( $i['description'] ? ' : ' . $i['description'] : '' ); }, $items ) );
			$titles = array_map( function ( $s ) { return $s['title']; }, self::clean_specs( $p['specs'] ?? array() ) );
			$r      = FRR_Claude::ask(
				"Tu rédiges le cahier des charges de livraison d'un devis d'agence (conseil UX, digital, communication). $rules Réponds uniquement par un objet JSON.",
				$ctx . "Prestations du devis :\n$lines\n\nRédige le contenu de chacune de ces sections, de façon concrète et adaptée au client : " . implode( ' | ', $titles ) . "\nRéponds avec un objet dont les clés sont exactement ces titres et les valeurs le texte (retours à la ligne autorisés, listes avec un tiret simple).",
				4000
			);
			if ( is_wp_error( $r ) ) {
				return self::err( $r->get_error_message() );
			}
			$j = FRR_Claude::json( $r['text'] );
			return $j ? $j : self::err( 'Réponse illisible, réessayez.' );
		}
		return self::err( 'Demande inconnue.' );
	}
}
