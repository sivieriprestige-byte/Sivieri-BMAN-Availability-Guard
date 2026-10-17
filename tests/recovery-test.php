<?php
/** Run with php -n tests/recovery-test.php. Fakes only external WordPress/WooCommerce services. */
define( 'ABSPATH', __DIR__ );
define( 'REST_REQUEST', true );
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function remove_filter( ...$args ) {}
function apply_filters( $name, $value, ...$args ) { return $value; }
function register_activation_hook( ...$args ) {}
function register_deactivation_hook( ...$args ) {}
function absint( $n ) { return abs( (int) $n ); }
function sanitize_title( $s ) { return trim( strtolower( preg_replace( '/[^a-zA-Z0-9_-]+/', '-', $s ) ), '-' ); }
function sanitize_key( $s ) { return strtolower( $s ); }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can( $cap, $id = null ) { return $GLOBALS['allowed'] && ! in_array( $id, $GLOBALS['denied_ids'], true ); }
function current_time( $format ) { return '2026-10-17 12:00:00'; }
function wp_generate_uuid4() { return 'test-token-' . ++$GLOBALS['tokens']; }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( ...$args ) {}
function wp_json_encode( $a ) { return json_encode( $a ); }
function is_wp_error( $e ) { return $e instanceof WP_Error; }
function wc_attribute_taxonomy_name_by_id( $id ) { return array( 1 => 'pa_colore', 2 => 'pa_taglia' )[ $id ] ?? ''; }
function rest_url( $route ) { return 'https://example.test/wp-json' . $route; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) {
	if ( isset( $GLOBALS['options'][ $key ] ) ) return false;
	$GLOBALS['options'][ $key ] = $value; return true;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); }
function metadata_exists( $type, $id, $key ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ); }
function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
function wc_get_product( $id ) { return $GLOBALS['products'][ $id ] ?? false; }
function get_post_status( $id ) { return $GLOBALS['products'][ $id ]->data['status'] ?? false; }
function get_post_type( $id ) { $p = wc_get_product( $id ); return $p ? ( $p instanceof WC_Product_Variation ? 'product_variation' : 'product' ) : false; }
function get_post( $id ) {
	$p = wc_get_product( $id ); if ( ! $p ) return null;
	return (object) array( 'ID' => $id, 'post_name' => $p->data['slug'], 'post_title' => $p->data['name'], 'post_status' => $p->data['status'], 'post_type' => get_post_type( $id ) );
}
function wp_update_post( $data ) {
	$id = $data['ID'];
	$data = SP_BMAN_Availability_Guard::guard_new_product_before_insert( $data + array( 'post_type' => get_post_type( $id ), 'post_name' => wc_get_product( $id )->data['slug'] ), array( 'ID' => $id ), array(), true );
	if ( isset( $data['post_status'] ) ) wc_get_product( $id )->data['status'] = $data['post_status'];
	if ( isset( $data['post_name'] ) ) wc_get_product( $id )->data['slug'] = $data['post_name'];
	return $id;
}
function wp_untrash_post( $id ) {
	if ( $GLOBALS['untrash_failure'] ) return false;
	wc_get_product( $id )->data['status'] = 'draft';
	wc_get_product( $id )->data['slug'] = get_post_meta( $id, '_wp_desired_post_slug' ) ?: preg_replace( '/__trashed$/', '', wc_get_product( $id )->data['slug'] );
	return get_post( $id );
}
function wp_trash_post( $id ) { wc_get_product( $id )->data['status'] = 'trash'; return get_post( $id ); }
function wc_delete_product_transients( $id ) {}

class WP_Error {
	public function __construct( public $code, public $message, public $data = array() ) {}
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
class WP_REST_Request {
	private $json = array(); private $query = array(); private $body = array(); private $url = array();
	public function __construct( private $method, private $route ) {}
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_param( $key ) { return $this->json[ $key ] ?? $this->body[ $key ] ?? $this->query[ $key ] ?? $this->url[ $key ] ?? null; }
	public function set_param( $k, $v ) { $this->query[ $k ] = $v; }
	public function set_header( $k, $v ) {}
	public function set_body( $s ) { $this->json = json_decode( $s, true ); }
	public function set_url_params( $a ) { $this->url = $a; }
	public function get_json_params() { return $this->json; }
	public function get_body_params() { return $this->body; }
	public function get_query_params() { return $this->query; }
}
class WP_REST_Response {
	public $status = 200; public $headers = array();
	public function __construct( private $data, private $error = false ) {}
	public function is_error() { return $this->error; }
	public function get_data() { return $this->data; }
	public function set_status( $s ) { $this->status = $s; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
class WC_Product {
	public function __construct( public $data ) {}
	public function get_id() { return $this->data['id']; }
	public function get_type() { return $this->data['type']; }
	public function is_type( $t ) { return $this->get_type() === $t; }
	public function get_parent_id() { return $this->data['parent_id'] ?? 0; }
	public function get_attributes() { return $this->data['raw_attributes'] ?? array(); }
	public function get_sku() { return $this->data['sku']; }
	public function get_meta( $key, $single = true ) { return get_post_meta( $this->get_id(), $key ); }
	public function get_stock_status() { return $this->data['stock_status']; }
	public function managing_stock() { return $this->data['manage_stock']; }
	public function get_stock_quantity() { return $this->data['stock_quantity']; }
	public function is_in_stock() { return 'instock' === $this->get_stock_status(); }
	public function get_catalog_visibility() { return $this->data['catalog_visibility']; }
	public function set_catalog_visibility( $v ) { $this->data['catalog_visibility'] = $v; }
	public function save() { return $this->get_id(); }
}
class WC_Product_Variation extends WC_Product {}
class WC_REST_Products_Controller {
	public function update_item_permissions_check( $request ) { ++$GLOBALS['permission_checks']; return $GLOBALS['native_permission']; }
}
class WC_REST_Product_Variations_Controller extends WC_REST_Products_Controller {}
class FakeDB {
	public $prefix = 'wp_'; public $posts = 'posts'; public $postmeta = 'postmeta'; public $options = 'options'; public $wc_product_meta_lookup = 'lookup';
	public $lookup = array(); public $queries = array();
	public function prepare( $sql, ...$args ) { return (object) array( 'sql' => $sql, 'args' => $args ); }
	public function get_row( $q ) { return (object) array( 'description' => $GLOBALS['key_description'], 'permissions' => $GLOBALS['key_permissions'], 'user_id' => 1 ); }
	public function get_var( $q ) {
		foreach ( $GLOBALS['products'] as $id => $p ) if ( $id !== $q->args[0] && 'trash' !== $p->data['status'] && $p->data['slug'] === $q->args[1] ) return $id;
		return null;
	}
	public function get_col( $q ) {
		$out = array();
		foreach ( $GLOBALS['products'] as $id => $p ) {
			if ( str_contains( $q->sql, "m.meta_key = '_sku'" ) && 0 === strcasecmp( $p->data['sku'], $q->args[0] ) ) $out[] = $id;
			elseif ( str_contains( $q->sql, 'post_parent = %d' ) && $p instanceof WC_Product_Variation && $p->get_parent_id() === $q->args[0] && ( str_contains( $q->sql, "'trash'" ) || 'trash' !== $p->data['status'] ) ) $out[] = $id;
		}
		return $out;
	}
	public function get_results( $q ) {
		$out = array();
		foreach ( $this->lookup as $id => $sku ) if ( 0 === strcasecmp( $sku, $q->args[0] ) ) $out[] = (object) array( 'product_id' => $id, 'live_id' => isset( $GLOBALS['products'][ $id ] ) ? $id : null );
		return $out;
	}
	public function query( $q ) {
		$this->queries[] = $q;
		if ( str_contains( $q->sql, 'DELETE FROM options' ) ) {
			[ $key, $value ] = $q->args;
			if ( isset( $GLOBALS['options'][ $key ] ) && serialize( $GLOBALS['options'][ $key ] ) === $value ) { unset( $GLOBALS['options'][ $key ] ); return 1; }
			return 0;
		}
		if ( $GLOBALS['cleanup_failure'] ) return false;
		$count = 0;
		foreach ( $this->lookup as $id => $sku ) if ( 0 === strcasecmp( $sku, $q->args[0] ) && ! isset( $GLOBALS['products'][ $id ] ) ) { unset( $this->lookup[ $id ] ); ++$count; }
		return $count;
	}
}
function rest_do_request( $request ) {
	$id = (int) basename( $request->get_route() );
	if ( 'GET' === $request->get_method() ) return new WP_REST_Response( wc_get_product( $id )->data );
	if ( $GLOBALS['update_failure'] ) return new WP_REST_Response( array( 'code' => 'simulated_failure' ), true );
	$payload = $request->get_json_params();
	$GLOBALS['last_payload'] = $payload;
	$p = wc_get_product( $id );
	$p->data = array_merge( $p->data, $payload );
	wp_update_post( array( 'ID' => $id, 'post_status' => $p->data['status'] ) );
	return new WP_REST_Response( $p->data );
}
require dirname( __DIR__ ) . '/sivieri-bman-availability-guard.php';

$passed = 0;
function check( $condition, $name ) {
	if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $name );
	++$GLOBALS['passed']; echo 'PASS: ' . $name . "\n";
}
function reset_case() {
	SP_BMAN_Sync_Recovery::release_all_locks();
	$GLOBALS['wpdb'] = new FakeDB();
	foreach ( array( 'products', 'options', 'meta', 'denied_ids', 'last_payload' ) as $k ) $GLOBALS[ $k ] = array();
	$GLOBALS['uid'] = 1; $GLOBALS['allowed'] = true; $GLOBALS['tokens'] = 0; $GLOBALS['permission_checks'] = 0;
	$GLOBALS['key_description'] = 'BMAN'; $GLOBALS['key_permissions'] = 'read_write'; $GLOBALS['native_permission'] = true;
	$GLOBALS['untrash_failure'] = false; $GLOBALS['update_failure'] = false; $GLOBALS['cleanup_failure'] = false;
	SP_BMAN_Sync_Recovery::capture_authenticated_key( false, 1, 1 );
}
function product( $id = 10, $changes = array(), $variation = false ) {
	$data = array_merge( array( 'id' => $id, 'type' => $variation ? 'variation' : 'simple', 'sku' => 'TEST-SKU', 'status' => 'publish', 'slug' => 'original-url', 'name' => 'Editorial name', 'description' => 'Editorial text', 'short_description' => 'Short text', 'images' => array( array( 'id' => 123 ) ), 'categories' => array( array( 'id' => 3 ) ), 'attributes' => array(), 'stock_quantity' => 0, 'manage_stock' => true, 'stock_status' => 'outofstock', 'catalog_visibility' => 'visible' ), $changes );
	$p = $variation ? new WC_Product_Variation( $data ) : new WC_Product( $data );
	$GLOBALS['products'][ $id ] = $p; $GLOBALS['wpdb']->lookup[ $id ] = $data['sku']; return $p;
}
function request( $data = array(), $parent = 0, $method = 'POST' ) {
	$r = new WP_REST_Request( $method, $parent ? '/wc/v3/products/' . $parent . '/variations' : '/wc/v3/products' );
	$r->set_body( json_encode( $data + array( 'sku' => 'TEST-SKU', 'name' => 'Bman name' ) ) ); return $r;
}
function run_recovery( $r, $parent = false ) {
	$controller = $parent ? new WC_REST_Product_Variations_Controller() : new WC_REST_Products_Controller();
	return SP_BMAN_Sync_Recovery::recover_create( null, $r, '', array( 'callback' => array( $controller, 'create_item' ) ) );
}

reset_case();
$r = request(); check( null === run_recovery( $r ), 'new SKU continues through native creation' );
SP_BMAN_Sync_Recovery::finish_request( null, array(), $r );
check( ! array_filter( array_keys( $GLOBALS['options'] ), fn( $s ) => str_starts_with( $s, 'spbag_sync_lock_' ) ), 'request lock released after native callback' );

reset_case();
$GLOBALS['wpdb']->lookup = array( 999 => 'TEST-SKU', 998 => 'OTHER-SKU' );
check( null === run_recovery( request() ), 'deleted product with orphan SKU can be recreated' );
check( ! isset( $GLOBALS['wpdb']->lookup[999] ) && isset( $GLOBALS['wpdb']->lookup[998] ), 'only requested orphan SKU is removed' );
check( 'indice_residuo_riparato' === get_option( SP_BMAN_Sync_Recovery::LOG_OPTION )[0]['event'], 'orphan repair is recorded' );

reset_case(); $p = product();
$r = request( array( 'stock_quantity' => 2, 'stock_status' => 'instock', 'description' => 'overwrite', 'slug' => 'new-url', 'images' => array(), 'categories' => array(), 'meta_data' => array( array( 'key' => 'seo', 'value' => 'overwrite' ) ), 'status' => 'draft' ) );
$response = run_recovery( $r );
check( $response instanceof WP_REST_Response && 201 === $response->status && 10 === $response->get_data()['id'], 'repeated create returns original ID with create-compatible response' );
check( 1 === count( $GLOBALS['products'] ), 'no duplicate product is created' );
check( 2 === $p->data['stock_quantity'] && 'instock' === $p->data['stock_status'], 'Bman operational stock is applied' );
check( 'Editorial name' === $p->data['name'] && 'Editorial text' === $p->data['description'] && 'original-url' === $p->data['slug'], 'editorial name, content and URL survive recovery' );
check( array( array( 'id' => 123 ) ) === $p->data['images'] && array( array( 'id' => 3 ) ) === $p->data['categories'] && ! isset( $GLOBALS['last_payload']['meta_data'] ), 'images, categories and SEO metadata are not replaced' );
check( 'publish' === $p->data['status'] && 1 === $GLOBALS['permission_checks'], 'native update permission checked and prior status retained' );

reset_case(); $p = product( 10, array( 'status' => 'trash', 'slug' => 'original-url__trashed' ) );
update_post_meta( 10, '_wp_desired_post_slug', 'original-url' );
$response = run_recovery( request( array( 'stock_quantity' => 1, 'stock_status' => 'instock' ) ) );
check( 201 === $response->status && 'draft' === $p->data['status'], 'trashed parent returns as a draft for review' );
check( 'original-url' === $p->data['slug'] && '1' === get_post_meta( 10, SP_BMAN_Availability_Guard::NEW_PRODUCT_DRAFT_META ), 'recovered parent keeps old URL and enters publication queue' );
SP_BMAN_Availability_Guard::apply_product( 10 );
check( 'draft' === $p->data['status'], 'positive stock cannot automatically publish a recovered draft' );

reset_case(); $p = product( 10, array( 'status' => 'draft', 'slug' => '' ) );
update_post_meta( 10, SP_BMAN_Availability_Guard::NEW_PRODUCT_DRAFT_META, '1' );
run_recovery( request( array( 'status' => 'publish', 'stock_quantity' => 1, 'stock_status' => 'instock' ) ) );
check( 'draft' === $p->data['status'] && '' === $p->data['slug'], 'existing new Bman draft stays guarded with no fixed slug' );

reset_case(); product( 10 ); product( 11 );
check( 'spbag_ambiguous_sku' === run_recovery( request() )->get_error_code(), 'ambiguous SKU stops without merging products' );
reset_case(); product( 10, array( 'sku' => 'OTHER' ) ); $GLOBALS['wpdb']->lookup[10] = 'TEST-SKU';
check( 'spbag_lookup_mismatch' === run_recovery( request() )->get_error_code(), 'index pointing to an unrelated live product is preserved and flagged' );
reset_case(); product();
check( 'spbag_product_type_changed' === run_recovery( request( array( 'type' => 'variable' ) ) )->get_error_code(), 'simple/variable type mismatch requires review' );

reset_case(); product(); $GLOBALS['key_description'] = 'OTHER API';
check( null === run_recovery( request() ) && ! $GLOBALS['options'], 'other integrations are not intercepted' );
reset_case(); product(); SP_BMAN_Sync_Recovery::capture_authenticated_key( false, 0, 0 );
check( null === run_recovery( request() ), 'cookie/application-password requests cannot impersonate Bman' );
reset_case(); product(); $GLOBALS['allowed'] = false;
check( null === run_recovery( request() ), 'missing management permissions do not trigger recovery' );
reset_case(); product(); $GLOBALS['native_permission'] = new WP_Error( 'forbidden', 'denied' );
check( 'forbidden' === run_recovery( request() )->get_error_code(), 'native update refusal is respected' );
reset_case(); product();
check( null === run_recovery( request( array(), 0, 'GET' ) ), 'GET never repairs or modifies records' );

reset_case(); $first = request(); run_recovery( $first );
check( 'spbag_sync_busy' === run_recovery( request() )->get_error_code(), 'concurrent duplicate create is refused without waiting' );
SP_BMAN_Sync_Recovery::finish_request( null, array(), $first );
check( null === run_recovery( request() ), 'next retry proceeds after completion' );
reset_case(); $lock = 'spbag_sync_lock_' . hash( 'sha256', 'sku:test-sku' );
$GLOBALS['options'][$lock] = array( 'token' => 'expired', 'expires' => time() - 10 );
check( null === run_recovery( request() ), 'abandoned expired lock can be reclaimed' );

reset_case(); product( 100, array( 'sku' => 'PARENT', 'type' => 'variable' ) );
$child = product( 101, array( 'parent_id' => 100, 'attributes' => array( array( 'id' => 1, 'option' => 'Nero' ) ), 'raw_attributes' => array( 'pa_colore' => 'nero' ), 'status' => 'private' ), true );
$response = run_recovery( request( array( 'attributes' => array( array( 'id' => 1, 'option' => 'Nero' ) ), 'stock_quantity' => 1, 'stock_status' => 'instock' ), 100 ), true );
check( 101 === $response->get_data()['id'] && 1 === $child->data['stock_quantity'], 'private existing variation is reused under the same parent' );
SP_BMAN_Availability_Guard::apply_variation( 101 );
check( 'publish' === $child->data['status'], 'returning variation becomes visible through the availability rules' );
reset_case(); product( 100, array( 'sku' => 'PARENT', 'type' => 'variable' ) ); product( 101, array( 'parent_id' => 200 ), true );
check( 'spbag_wrong_product' === run_recovery( request( array(), 100 ), true )->get_error_code(), 'variation belonging to another parent is never relinked' );
reset_case(); product( 100, array( 'sku' => 'PARENT', 'type' => 'variable' ) );
product( 101, array( 'sku' => 'OTHER-VARIANT', 'parent_id' => 100, 'raw_attributes' => array( 'pa_colore' => 'nero' ) ), true );
check( 'spbag_variation_sku_changed' === run_recovery( request( array( 'attributes' => array( array( 'id' => 1, 'option' => 'Nero' ) ) ), 100 ), true )->get_error_code(), 'existing attribute combination with different SKU is flagged instead of duplicated' );

reset_case(); $p = product( 10, array( 'status' => 'trash' ) ); $GLOBALS['update_failure'] = true;
check( run_recovery( request() )->is_error() && 'trash' === $p->data['status'], 'failed update restores the original trash state' );
reset_case(); $p = product( 10, array( 'status' => 'trash' ) ); $GLOBALS['untrash_failure'] = true;
check( 'spbag_untrash_failed' === run_recovery( request() )->get_error_code() && 'trash' === $p->data['status'], 'failed untrash cannot proceed to update' );
reset_case(); $GLOBALS['wpdb']->lookup = array( 999 => 'TEST-SKU' ); $GLOBALS['cleanup_failure'] = true;
check( 'spbag_lookup_cleanup_failed' === run_recovery( request() )->get_error_code() && isset( $GLOBALS['wpdb']->lookup[999] ), 'failed cleanup stops creation and retains the index row' );

reset_case(); $p = product();
SP_BMAN_Availability_Guard::apply_product( 10 );
check( 'publish' === $p->data['status'] && 'hidden' === $p->data['catalog_visibility'] && get_post_meta( 10, '_spbag_out_of_stock_since' ), 'sold-out published product keeps URL and enters review queue' );
$p->data['stock_quantity'] = 1; $p->data['stock_status'] = 'instock';
SP_BMAN_Availability_Guard::apply_product( 10 );
check( 'publish' === $p->data['status'] && 'visible' === $p->data['catalog_visibility'] && '' === get_post_meta( 10, '_spbag_out_of_stock_since' ) && 'original-url' === $p->data['slug'], 'restock restores catalog visibility and removes review flag with same URL' );
update_post_meta( 10, '_spbag_discontinued', 'yes' );
SP_BMAN_Availability_Guard::apply_product( 10 );
check( 'hidden' === $p->data['catalog_visibility'], 'manual permanent assortment exclusion survives restock' );

$existing = array( 'status' => 'publish', 'attributes' => array( array( 'id' => 1, 'name' => 'Colore', 'visible' => true, 'variation' => true, 'options' => array( 'Nero', 'Blu' ) ) ) );
$merged = SP_BMAN_Sync_Recovery::recovery_payload( array( 'attributes' => array( array( 'id' => 1, 'options' => array( 'Bordeaux' ), 'visible' => false ) ) ), $existing, false );
check( array( 'Nero', 'Blu', 'Bordeaux' ) === $merged['attributes'][0]['options'] && true === $merged['attributes'][0]['visible'], 'new color options merge without removing old colors or editorial settings' );
check( is_wp_error( SP_BMAN_Sync_Recovery::recovery_payload( array( 'attributes' => array( array( 'id' => 2, 'name' => 'Taglia', 'options' => array( 'S' ) ) ) ), $existing, false ) ), 'new attribute dimension requires review' );

reset_case(); product( 10, array( 'status' => 'trash', 'slug' => 'original-url__trashed' ) );
update_post_meta( 10, '_wp_desired_post_slug', 'original-url' );
product( 11, array( 'sku' => 'ANOTHER-PRODUCT', 'slug' => 'original-url' ) );
check( 'spbag_slug_conflict' === run_recovery( request() )->get_error_code() && 'trash' === get_post_status( 10 ), 'occupied original URL prevents automatic recovery' );
reset_case(); product( 10, array( 'status' => 'trash', 'slug' => 'original-url__trashed' ) );
check( 'spbag_original_slug_unknown' === run_recovery( request() )->get_error_code(), 'unknown historical slug is flagged before restoring' );
reset_case(); product( 10, array( 'status' => 'auto-draft' ) );
check( 'spbag_incomplete_product' === run_recovery( request() )->get_error_code(), 'incomplete concurrent product is not reused' );
reset_case(); product(); $GLOBALS['key_permissions'] = 'read';
check( null === run_recovery( request() ), 'read-only API key cannot trigger recovery writes' );

reset_case(); product( 100, array( 'sku' => 'PARENT', 'type' => 'variable' ) );
product( 101, array( 'parent_id' => 100, 'attributes' => array( array( 'id' => 1, 'option' => 'Nero' ) ) ), true );
check( 'spbag_variation_attributes_changed' === run_recovery( request( array( 'attributes' => array( array( 'id' => 1, 'option' => 'Blu' ) ) ), 100 ), true )->get_error_code(), 'matching SKU with different variant attributes is rejected' );

SP_BMAN_Sync_Recovery::release_all_locks();
echo "All $passed regression checks passed.\n";
