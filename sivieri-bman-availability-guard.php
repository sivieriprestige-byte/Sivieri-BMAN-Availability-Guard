<?php
/**
 * Plugin Name: Sivieri BMAN Availability Guard
 * Description: Gestione leggera della visibilità prodotti/varianti WooCommerce dopo sincronizzazione BMAN. Nasconde automaticamente varianti esaurite, propone una combinazione valida e aggiorna le informazioni d'acquisto in tempo reale.
 * Version: 1.4.0
 * Author: Sivieri Prestige
 * Requires Plugins: woocommerce
 * Text Domain: sivieri-bman-availability-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SP_BMAN_Availability_Guard' ) ) {
	final class SP_BMAN_Availability_Guard {

		const VERSION = '1.4.0';

		const PRODUCT_MODE_META   = '_spbag_product_mode';
		const VARIATION_MODE_META = '_spbag_variation_mode';
		const SKU_RULES_OPTION    = 'spbag_sku_rules';
		const SCAN_RUNNING_OPTION = 'spbag_scan_running';
		const SCAN_LAST_ID_OPTION = 'spbag_scan_last_product_id';
		const SCAN_STATS_OPTION   = 'spbag_scan_stats';

		const CRON_HOURLY = 'spbag_hourly_guard_scan';
		const CRON_BATCH  = 'spbag_process_background_scan';

		// 1.3.0 — prodotti padre creati da sincronizzazione esterna/BMAN.
		// Restano in bozza finché non vengono pubblicati manualmente dal backend.
		const NEW_PRODUCT_DRAFT_META    = '_spbag_bman_new_product_draft';
		const NEW_PRODUCT_GUARDED_AT    = '_spbag_bman_new_product_guarded_at';

		// 1.4.0 — prodotti già pubblicati che diventano esauriti restano online per SEO,
		// ma vengono esclusi da catalogo/ricerca finché non tornano disponibili.
		const OUT_OF_STOCK_SINCE_META          = '_spbag_out_of_stock_since';
		const DISCONTINUED_META                = '_spbag_discontinued';
		const DISCONTINUED_AT_META             = '_spbag_discontinued_at';
		const PREVIOUS_CATALOG_VISIBILITY_META = '_spbag_previous_catalog_visibility';

		private static $running = false;
		private static $pending_product_updates = array();
		private static $reconciling_updates = false;
		private static $pending_new_product_guard = false;

		public static function init() {
			add_action( 'plugins_loaded', array( __CLASS__, 'boot' ), 20 );
		}

		public static function boot() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
				return;
			}

			self::maybe_schedule_events();

			// 1.3.0 — intercetta i NUOVI prodotti padre prima della prima pubblicazione.
			// WooCommerce REST (caso tipico BMAN) + rete di sicurezza WordPress.
			add_filter( 'woocommerce_rest_pre_insert_product_object', array( __CLASS__, 'guard_rest_new_product_before_save' ), 5, 3 );
			add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_new_product_before_insert' ), 5, 4 );
			add_action( 'wp_after_insert_post', array( __CLASS__, 'mark_guarded_new_product_after_insert' ), 5, 4 );
			add_action( 'transition_post_status', array( __CLASS__, 'unlock_guarded_product_on_manual_publish' ), 5, 3 );

			// Admin UI.
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
			add_action( 'admin_post_spbag_start_scan', array( __CLASS__, 'handle_start_scan' ) );
			add_action( 'admin_post_spbag_process_scan_batch', array( __CLASS__, 'handle_process_scan_batch' ) );
			add_action( 'admin_post_spbag_stop_scan', array( __CLASS__, 'handle_stop_scan' ) );
			add_action( 'admin_post_spbag_mark_discontinued', array( __CLASS__, 'handle_mark_discontinued' ) );
			add_action( 'admin_post_spbag_restore_review', array( __CLASS__, 'handle_restore_review' ) );

			// Product admin fields.
			add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_product_visibility_field' ) );
			add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_visibility_field' ), 30 );

			// Variation admin fields.
			add_action( 'woocommerce_variation_options_inventory', array( __CLASS__, 'render_variation_visibility_field' ), 30, 3 );
			add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_visibility_field' ), 30, 2 );

			// WooCommerce stock/product lifecycle hooks.
			add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_product_stock_status_changed' ), 30, 3 );
			add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_variation_stock_status_changed' ), 30, 3 );
			add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_updated' ), 30, 1 );
			add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_quantity_changed' ), 30, 1 );
			add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_quantity_changed' ), 30, 1 );
			add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_product_updated' ), 30, 1 );
			add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_product_updated' ), 30, 1 );
			// Reconcile once, after WooCommerce has persisted stock and cleared its caches.
			add_action( 'shutdown', array( __CLASS__, 'reconcile_product_updates' ), 99 );

			// Safety net after import/sync: background scans, not frontend scans.
			add_action( self::CRON_HOURLY, array( __CLASS__, 'start_background_scan' ) );
			add_action( self::CRON_BATCH, array( __CLASS__, 'process_background_scan_event' ) );

			// Frontend variation safety. This is lightweight and protects against BMAN republishing before cron catches it.
			add_filter( 'woocommerce_available_variation', array( __CLASS__, 'filter_available_variation' ), 30, 3 );
			add_filter( 'woocommerce_variation_is_visible', array( __CLASS__, 'filter_variation_is_visible' ), 30, 4 );
			add_filter( 'woocommerce_variation_is_active', array( __CLASS__, 'filter_variation_is_active' ), 30, 2 );
			add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'filter_product_is_visible' ), 30, 2 );
			add_filter( 'woocommerce_get_children', array( __CLASS__, 'filter_product_children' ), 30, 3 );
			add_filter( 'woocommerce_dropdown_variation_attribute_options_args', array( __CLASS__, 'filter_dropdown_variation_attribute_options_args' ), 40, 1 );
			add_filter( 'woocommerce_product_get_default_attributes', array( __CLASS__, 'filter_default_attributes' ), 40, 2 );
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_required_variation_attributes' ), 30, 6 );
			add_action( 'wp_head', array( __CLASS__, 'print_frontend_swatch_css' ), 20 );
			add_action( 'wp_footer', array( __CLASS__, 'print_frontend_variation_selection_script' ), 30 );
		}

		public static function activate() {
			self::maybe_schedule_events();
			self::start_background_scan();
		}

		public static function deactivate() {
			wp_clear_scheduled_hook( self::CRON_HOURLY );
			wp_clear_scheduled_hook( self::CRON_BATCH );
		}

		public static function missing_woocommerce_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			?>
			<div class="notice notice-warning">
				<p><strong>Sivieri BMAN Availability Guard</strong> richiede WooCommerce attivo.</p>
			</div>
			<?php
		}

		private static function maybe_schedule_events() {
			if ( ! wp_next_scheduled( self::CRON_HOURLY ) ) {
				wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOURLY );
			}
		}

		/* ---------------------------------------------------------------------
		 * Admin page
		 * ------------------------------------------------------------------ */

		public static function admin_menu() {
			add_submenu_page(
				'woocommerce',
				'Sivieri BMAN Availability',
				'Sivieri BMAN Availability',
				'manage_woocommerce',
				'spbag-availability-guard',
				array( __CLASS__, 'render_admin_page' )
			);
		}

		public static function render_admin_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$running = get_option( self::SCAN_RUNNING_OPTION, 'no' ) === 'yes';
			$last_id = (int) get_option( self::SCAN_LAST_ID_OPTION, 0 );
			$stats   = get_option( self::SCAN_STATS_OPTION, array() );

			$start_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=spbag_start_scan' ),
				'spbag_start_scan'
			);

			$batch_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=spbag_process_scan_batch' ),
				'spbag_process_scan_batch'
			);

			$stop_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=spbag_stop_scan' ),
				'spbag_stop_scan'
			);
			?>
			<div class="wrap">
				<h1>Sivieri BMAN Availability Guard</h1>

				<p>
					Questo plugin lascia BMAN libero di sincronizzare prodotti e varianti, ma decide cosa mostrare al cliente in base a stock e regole locali Sivieri.
				</p>

				<h2>Regole attive</h2>
				<ul style="list-style:disc;margin-left:20px;max-width:920px;">
					<li><strong>Prodotti già pubblicati in AUTO:</strong> se disponibili restano normalmente visibili; se completamente esauriti restano <strong>pubblicati</strong> per conservare URL e valore SEO, ma vengono nascosti da catalogo, categorie e ricerca interna.</li>
					<li><strong>Esauriti da verificare:</strong> il primo esaurimento registra una data e inserisce automaticamente il prodotto nella coda di controllo. Se lo stock torna disponibile, il prodotto esce dalla coda e torna visibile senza interventi manuali.</li>
					<li><strong>Varianti in AUTO:</strong> stock maggiore di 0 → visibile; esaurita → nascosta automaticamente.</li>
					<li><strong>Swatches colore/taglia:</strong> le varianti non visibili vengono filtrate dalla scheda prodotto e nascoste nei loop quando i plugin usano classi standard di non disponibilità.</li>
					<li><strong>Combinazione proposta:</strong> nei prodotti in AUTO viene proposta all'apertura una combinazione completa e disponibile; se il default WooCommerce non è acquistabile, viene scelta la prima disponibile.</li>
					<li><strong>Spedizione gratuita:</strong> nella scheda compare automaticamente quando il prezzo effettivo della combinazione supera la soglia impostata per Sivieri Prestige.</li>
					<li><strong>Nascondi manualmente / Escludi:</strong> la regola viene salvata anche per SKU, così BMAN non la annulla alla sincronizzazione successiva.</li>
					<li><strong>Nuovi prodotti BMAN:</strong> un nuovo prodotto padre creato dalla sincronizzazione nasce direttamente in <strong>bozza</strong>, senza passare da pubblicato e senza fissare lo slug definitivo. Le nuove varianti di prodotti già esistenti non vengono bloccate.</li>
				</ul>

				<h2>Esauriti da verificare</h2>
				<p>
					Questi prodotti non compaiono nel catalogo né nella ricerca interna, ma la loro pagina resta pubblicata e raggiungibile.
					Controllali periodicamente e marca come <strong>Fuori assortimento definitivo</strong> solo quelli che sai che non torneranno.
				</p>
				<?php self::render_availability_queue( 'review' ); ?>

				<h2>Fuori assortimento definitivo</h2>
				<p>
					Questi prodotti restano nascosti dal catalogo e conservano la pagina pubblica finché non viene decisa la destinazione SEO definitiva (sostituto, categoria, brand o altra gestione).
				</p>
				<?php self::render_availability_queue( 'discontinued' ); ?>

				<h2>Scansione catalogo esistente</h2>
				<p>
					Serve per applicare le regole anche ai prodotti e alle varianti già presenti prima dell’installazione del plugin.
				</p>

				<p>
					<a href="<?php echo esc_url( $start_url ); ?>" class="button button-primary">Avvia scansione completa</a>
					<?php if ( $running ) : ?>
						<a href="<?php echo esc_url( $batch_url ); ?>" class="button">Esegui prossimo batch ora</a>
						<a href="<?php echo esc_url( $stop_url ); ?>" class="button">Ferma scansione</a>
					<?php endif; ?>
				</p>

				<table class="widefat striped" style="max-width:720px;">
					<tbody>
						<tr>
							<th scope="row">Stato scansione</th>
							<td><?php echo $running ? 'In corso' : 'Ferma / completata'; ?></td>
						</tr>
						<tr>
							<th scope="row">Ultimo ID prodotto controllato</th>
							<td><?php echo esc_html( (string) $last_id ); ?></td>
						</tr>
						<tr>
							<th scope="row">Ultimo batch</th>
							<td>
								<?php
								if ( ! empty( $stats ) ) {
									echo esc_html( sprintf( '%s — prodotti controllati: %d, varianti aggiornate: %d, prodotti aggiornati: %d',
										isset( $stats['time'] ) ? $stats['time'] : '',
										isset( $stats['products_checked'] ) ? (int) $stats['products_checked'] : 0,
										isset( $stats['variations_updated'] ) ? (int) $stats['variations_updated'] : 0,
										isset( $stats['products_updated'] ) ? (int) $stats['products_updated'] : 0
									) );
								} else {
									echo 'Nessuna scansione ancora registrata.';
								}
								?>
							</td>
						</tr>
					</tbody>
				</table>

				<h2>Dove modificare manualmente</h2>
				<p>
					Nella scheda prodotto WooCommerce trovi il campo <strong>Visibilità Sivieri</strong> nella sezione inventario. Nelle varianti trovi il campo <strong>Visibilità variante Sivieri</strong> dentro ogni variante.
				</p>
			</div>
			<?php
		}

		public static function handle_start_scan() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Permessi insufficienti.' );
			}
			check_admin_referer( 'spbag_start_scan' );

			update_option( self::SCAN_RUNNING_OPTION, 'yes', false );
			update_option( self::SCAN_LAST_ID_OPTION, 0, false );
			self::process_scan_batch( 75 );
			self::schedule_next_background_batch();

			wp_safe_redirect( admin_url( 'admin.php?page=spbag-availability-guard&spbag_notice=scan_started' ) );
			exit;
		}

		public static function handle_process_scan_batch() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Permessi insufficienti.' );
			}
			check_admin_referer( 'spbag_process_scan_batch' );

			self::process_scan_batch( 75 );
			if ( get_option( self::SCAN_RUNNING_OPTION, 'no' ) === 'yes' ) {
				self::schedule_next_background_batch();
			}

			wp_safe_redirect( admin_url( 'admin.php?page=spbag-availability-guard&spbag_notice=batch_done' ) );
			exit;
		}

		public static function handle_stop_scan() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Permessi insufficienti.' );
			}
			check_admin_referer( 'spbag_stop_scan' );

			update_option( self::SCAN_RUNNING_OPTION, 'no', false );
			wp_clear_scheduled_hook( self::CRON_BATCH );

			wp_safe_redirect( admin_url( 'admin.php?page=spbag-availability-guard&spbag_notice=scan_stopped' ) );
			exit;
		}

		private static function get_queue_product_ids( $queue ) {
			$args = array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			);

			if ( 'discontinued' === $queue ) {
				$args['meta_query'] = array(
					array(
						'key'     => self::DISCONTINUED_META,
						'value'   => 'yes',
						'compare' => '=',
					),
				);
			} else {
				$args['meta_query'] = array(
					'relation' => 'AND',
					array(
						'key'     => self::OUT_OF_STOCK_SINCE_META,
						'compare' => 'EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::DISCONTINUED_META,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::DISCONTINUED_META,
							'value'   => 'yes',
							'compare' => '!=',
						),
					),
				);
			}

			return array_map( 'absint', get_posts( $args ) );
		}

		private static function render_availability_queue( $queue ) {
			$product_ids = self::get_queue_product_ids( $queue );

			if ( empty( $product_ids ) ) {
				echo '<p><em>Nessun prodotto in questa coda.</em></p>';
				return;
			}

			echo '<table class="widefat striped" style="max-width:1180px">';
			echo '<thead><tr><th>Prodotto</th><th>SKU</th><th>Stato</th><th>Data</th><th>Azioni</th></tr></thead><tbody>';

			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof WC_Product || $product instanceof WC_Product_Variation ) {
					continue;
				}

				$date = 'discontinued' === $queue
					? (string) get_post_meta( $product_id, self::DISCONTINUED_AT_META, true )
					: (string) get_post_meta( $product_id, self::OUT_OF_STOCK_SINCE_META, true );

				$edit_url = get_edit_post_link( $product_id, '' );
				$page_url = get_permalink( $product_id );
				$status   = get_post_status( $product_id );

				if ( 'discontinued' === $queue ) {
					$action_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=spbag_restore_review&product_id=' . $product_id ),
						'spbag_restore_review_' . $product_id
					);
					$action_label = 'Rimetti da verificare';
				} else {
					$action_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=spbag_mark_discontinued&product_id=' . $product_id ),
						'spbag_mark_discontinued_' . $product_id
					);
					$action_label = 'Fuori assortimento definitivo';
				}

				echo '<tr>';
				echo '<td><strong>' . esc_html( $product->get_name() ) . '</strong><br><small>ID ' . esc_html( (string) $product_id ) . '</small></td>';
				echo '<td>' . esc_html( (string) $product->get_sku() ) . '</td>';
				echo '<td>' . esc_html( (string) $status ) . ' / ' . esc_html( (string) $product->get_stock_status() ) . '</td>';
				echo '<td>' . esc_html( $date ? $date : '—' ) . '</td>';
				echo '<td>';
				if ( $edit_url ) {
					echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">Modifica</a> ';
				}
				if ( 'publish' === $status && $page_url ) {
					echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url( $page_url ) . '">Apri pagina</a> ';
				}
				echo '<a class="button button-small" href="' . esc_url( $action_url ) . '">' . esc_html( $action_label ) . '</a>';
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		public static function handle_mark_discontinued() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Permessi insufficienti.' );
			}

			$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'spbag_mark_discontinued_' . $product_id );

			if ( $product_id && 'product' === get_post_type( $product_id ) ) {
				update_post_meta( $product_id, self::DISCONTINUED_META, 'yes' );
				if ( '' === (string) get_post_meta( $product_id, self::DISCONTINUED_AT_META, true ) ) {
					update_post_meta( $product_id, self::DISCONTINUED_AT_META, current_time( 'mysql' ) );
				}
				self::apply_product( $product_id );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=spbag-availability-guard&spbag_notice=marked_discontinued' ) );
			exit;
		}

		public static function handle_restore_review() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Permessi insufficienti.' );
			}

			$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'spbag_restore_review_' . $product_id );

			if ( $product_id && 'product' === get_post_type( $product_id ) ) {
				delete_post_meta( $product_id, self::DISCONTINUED_META );
				delete_post_meta( $product_id, self::DISCONTINUED_AT_META );
				self::apply_product( $product_id );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=spbag-availability-guard&spbag_notice=restored_review' ) );
			exit;
		}

		/* ---------------------------------------------------------------------
		 * Admin product fields
		 * ------------------------------------------------------------------ */

		private static function product_modes() {
			return array(
				'auto'          => 'AUTO — segue stock/BMAN',
				'force_publish' => 'Forza pubblicato',
				'force_draft'   => 'Forza bozza',
				'exclude'       => 'Escludi definitivamente dal sito',
			);
		}

		private static function variation_modes() {
			return array(
				'auto'        => 'AUTO — visibile solo se disponibile',
				'manual_hide' => 'Nascondi manualmente',
				'exclude'     => 'Escludi definitivamente dal sito',
			);
		}

		public static function render_product_visibility_field() {
			global $post;

			if ( ! $post || 'product' !== $post->post_type ) {
				return;
			}

			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				return;
			}

			woocommerce_wp_select(
				array(
					'id'          => self::PRODUCT_MODE_META,
					'label'       => 'Visibilità Sivieri',
					'value'       => self::get_product_mode( $product ),
					'options'     => self::product_modes(),
					'desc_tip'    => true,
					'description' => 'AUTO segue stock e disponibilità. Le regole manuali vengono salvate anche per SKU, così BMAN non le annulla.',
				)
			);

			woocommerce_wp_checkbox(
				array(
					'id'          => self::DISCONTINUED_META,
					'label'       => 'Fuori assortimento definitivo',
					'value'       => self::is_discontinued_product( $product->get_id() ) ? 'yes' : 'no',
					'cbvalue'     => 'yes',
					'desc_tip'    => true,
					'description' => 'Usalo solo quando sai che il prodotto non tornerà. La pagina resta pubblicata e nascosta dal catalogo in attesa della gestione SEO definitiva.',
				)
			);
		}

		public static function save_product_visibility_field( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return;
			}

			$mode = isset( $_POST[ self::PRODUCT_MODE_META ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				? sanitize_key( wp_unslash( $_POST[ self::PRODUCT_MODE_META ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: 'auto';

			if ( ! array_key_exists( $mode, self::product_modes() ) ) {
				$mode = 'auto';
			}

			$product->update_meta_data( self::PRODUCT_MODE_META, $mode );
			self::set_sku_rule( 'products', $product->get_sku(), $mode, 'auto' );

			$discontinued = isset( $_POST[ self::DISCONTINUED_META ] ) && 'yes' === sanitize_key( wp_unslash( $_POST[ self::DISCONTINUED_META ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( $discontinued ) {
				$product->update_meta_data( self::DISCONTINUED_META, 'yes' );
				if ( '' === (string) $product->get_meta( self::DISCONTINUED_AT_META, true ) ) {
					$product->update_meta_data( self::DISCONTINUED_AT_META, current_time( 'mysql' ) );
				}
			} else {
				$product->delete_meta_data( self::DISCONTINUED_META );
				$product->delete_meta_data( self::DISCONTINUED_AT_META );
			}

			// Apply after WooCommerce finishes saving.
			add_action(
				'shutdown',
				function () use ( $product ) {
					self::apply_product( $product->get_id() );
				}
			);
		}

		public static function render_variation_visibility_field( $loop, $variation_data, $variation ) {
			$variation_id = 0;

			if ( $variation instanceof WP_Post ) {
				$variation_id = (int) $variation->ID;
			} elseif ( is_numeric( $variation ) ) {
				$variation_id = (int) $variation;
			}

			if ( ! $variation_id ) {
				return;
			}

			$variation_product = wc_get_product( $variation_id );
			if ( ! $variation_product instanceof WC_Product_Variation ) {
				return;
			}

			woocommerce_wp_select(
				array(
					'id'            => self::VARIATION_MODE_META . '_' . $loop,
					'name'          => self::VARIATION_MODE_META . '[' . $loop . ']',
					'label'         => 'Visibilità variante Sivieri',
					'value'         => self::get_variation_mode( $variation_product ),
					'options'       => self::variation_modes(),
					'wrapper_class' => 'form-row form-row-full',
					'desc_tip'      => true,
					'description'   => 'AUTO nasconde la variante se esaurita e la riattiva quando lo stock torna maggiore di 0. Le regole manuali sono salvate per SKU.',
				)
			);
		}

		public static function save_variation_visibility_field( $variation_id, $i ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				return;
			}

			$mode = isset( $_POST[ self::VARIATION_MODE_META ][ $i ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				? sanitize_key( wp_unslash( $_POST[ self::VARIATION_MODE_META ][ $i ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
				: 'auto';

			if ( ! array_key_exists( $mode, self::variation_modes() ) ) {
				$mode = 'auto';
			}

			update_post_meta( $variation_id, self::VARIATION_MODE_META, $mode );
			self::set_sku_rule( 'variations', $variation->get_sku(), $mode, 'auto' );

			self::apply_variation( $variation_id );
			self::apply_product( $variation->get_parent_id() );
		}

		/* ---------------------------------------------------------------------
		 * Rules and stock logic
		 * ------------------------------------------------------------------ */

		private static function get_rules() {
			$rules = get_option( self::SKU_RULES_OPTION, array() );

			if ( ! is_array( $rules ) ) {
				$rules = array();
			}

			$rules = wp_parse_args(
				$rules,
				array(
					'products'   => array(),
					'variations' => array(),
				)
			);

			if ( ! is_array( $rules['products'] ) ) {
				$rules['products'] = array();
			}

			if ( ! is_array( $rules['variations'] ) ) {
				$rules['variations'] = array();
			}

			return $rules;
		}

		private static function set_sku_rule( $type, $sku, $mode, $auto_mode = 'auto' ) {
			$sku = trim( (string) $sku );
			if ( '' === $sku ) {
				return;
			}

			$rules = self::get_rules();

			if ( ! isset( $rules[ $type ] ) || ! is_array( $rules[ $type ] ) ) {
				$rules[ $type ] = array();
			}

			if ( $mode === $auto_mode ) {
				unset( $rules[ $type ][ $sku ] );
			} else {
				$rules[ $type ][ $sku ] = $mode;
			}

			update_option( self::SKU_RULES_OPTION, $rules, false );
		}

		private static function get_product_mode( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return 'auto';
			}

			$sku   = trim( (string) $product->get_sku() );
			$rules = self::get_rules();

			if ( '' !== $sku && isset( $rules['products'][ $sku ] ) ) {
				$mode = sanitize_key( $rules['products'][ $sku ] );
			} else {
				$mode = sanitize_key( (string) $product->get_meta( self::PRODUCT_MODE_META, true ) );
			}

			return array_key_exists( $mode, self::product_modes() ) ? $mode : 'auto';
		}

		private static function get_variation_mode( $variation ) {
			if ( ! $variation instanceof WC_Product_Variation ) {
				return 'auto';
			}

			$sku   = trim( (string) $variation->get_sku() );
			$rules = self::get_rules();

			if ( '' !== $sku && isset( $rules['variations'][ $sku ] ) ) {
				$mode = sanitize_key( $rules['variations'][ $sku ] );
			} else {
				$mode = sanitize_key( (string) $variation->get_meta( self::VARIATION_MODE_META, true ) );
			}

			return array_key_exists( $mode, self::variation_modes() ) ? $mode : 'auto';
		}

		private static function is_effectively_in_stock( $product ) {
			if ( ! $product instanceof WC_Product ) {
				return false;
			}

			if ( 'outofstock' === $product->get_stock_status() ) {
				return false;
			}

			if ( $product->managing_stock() ) {
				$qty = $product->get_stock_quantity();
				return null !== $qty && (float) $qty > 0 && $product->is_in_stock();
			}

			return $product->is_in_stock();
		}

		private static function is_discontinued_product( $product_id ) {
			$product_id = absint( $product_id );
			return $product_id && 'yes' === (string) get_post_meta( $product_id, self::DISCONTINUED_META, true );
		}

		private static function hide_product_from_catalog( $product ) {
			if ( ! $product instanceof WC_Product || $product instanceof WC_Product_Variation || ! is_callable( array( $product, 'get_catalog_visibility' ) ) || ! is_callable( array( $product, 'set_catalog_visibility' ) ) ) {
				return false;
			}

			$current = (string) $product->get_catalog_visibility();
			if ( 'hidden' === $current ) {
				return false;
			}

			if ( ! metadata_exists( 'post', $product->get_id(), self::PREVIOUS_CATALOG_VISIBILITY_META ) ) {
				update_post_meta( $product->get_id(), self::PREVIOUS_CATALOG_VISIBILITY_META, $current ? $current : 'visible' );
			}

			$product->set_catalog_visibility( 'hidden' );
			$product->save();
			return true;
		}

		private static function restore_product_catalog_visibility( $product ) {
			if ( ! $product instanceof WC_Product || $product instanceof WC_Product_Variation || ! is_callable( array( $product, 'get_catalog_visibility' ) ) || ! is_callable( array( $product, 'set_catalog_visibility' ) ) ) {
				return false;
			}

			$previous = (string) get_post_meta( $product->get_id(), self::PREVIOUS_CATALOG_VISIBILITY_META, true );
			if ( ! in_array( $previous, array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
				return false;
			}

			$changed = false;
			if ( (string) $product->get_catalog_visibility() !== $previous ) {
				$product->set_catalog_visibility( $previous );
				$product->save();
				$changed = true;
			}

			delete_post_meta( $product->get_id(), self::PREVIOUS_CATALOG_VISIBILITY_META );
			return $changed;
		}

		private static function sync_product_catalog_state( $product, $available ) {
			if ( ! $product instanceof WC_Product || $product instanceof WC_Product_Variation ) {
				return;
			}

			$product_id   = $product->get_id();
			$discontinued = self::is_discontinued_product( $product_id );

			if ( $available ) {
				delete_post_meta( $product_id, self::OUT_OF_STOCK_SINCE_META );
				if ( $discontinued ) {
					self::hide_product_from_catalog( $product );
				} else {
					self::restore_product_catalog_visibility( $product );
				}
				return;
			}

			// Le bozze protette BMAN e le bozze manuali non devono entrare nella coda SEO.
			if ( 'publish' === get_post_status( $product_id ) || $discontinued ) {
				if ( '' === (string) get_post_meta( $product_id, self::OUT_OF_STOCK_SINCE_META, true ) ) {
					update_post_meta( $product_id, self::OUT_OF_STOCK_SINCE_META, current_time( 'mysql' ) );
				}
				self::hide_product_from_catalog( $product );
			}
		}


		private static function get_all_variation_ids( $product_id ) {
			global $wpdb;

			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return array();
			}

			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'product_variation'
					 AND post_parent = %d
					 AND post_status IN ('publish','private','draft','pending')
					 ORDER BY menu_order ASC, ID ASC",
					$product_id
				)
			);

			return array_map( 'absint', (array) $ids );
		}

		public static function get_visible_variation_ids( $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return array();
			}

			$visible_ids = array();

			foreach ( self::get_all_variation_ids( $product_id ) as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( $variation instanceof WC_Product_Variation && self::should_variation_be_frontend_visible( $variation ) ) {
					$visible_ids[] = $variation_id;
				}
			}

			return $visible_ids;
		}

		private static function normalize_attribute_key( $attribute ) {
			$attribute = (string) $attribute;
			$attribute = str_replace( 'attribute_', '', $attribute );
			return sanitize_title( $attribute );
		}

		private static function normalize_attribute_value( $value ) {
			$value = (string) $value;
			return sanitize_title( $value );
		}

		private static function get_visible_attribute_values( $product, $attribute ) {
			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
				return array();
			}

			$attribute_key = self::normalize_attribute_key( $attribute );
			$values        = array();

			foreach ( self::get_visible_variation_ids( $product->get_id() ) as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( ! $variation instanceof WC_Product_Variation ) {
					continue;
				}

				foreach ( $variation->get_attributes() as $var_attribute => $var_value ) {
					if ( self::normalize_attribute_key( $var_attribute ) !== $attribute_key ) {
						continue;
					}

					$var_value = (string) $var_value;

					if ( '' !== $var_value ) {
						$values[] = $var_value;
						$values[] = self::normalize_attribute_value( $var_value );
					}
				}
			}

			return array_values( array_unique( array_filter( $values ) ) );
		}

		/* ---------------------------------------------------------------------
		 * 1.3.0 — Nuovi prodotti BMAN sempre in bozza PRIMA della pubblicazione
		 * ------------------------------------------------------------------ */

		private static function is_external_sync_request() {
			$is_sync = false;

			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				$is_sync = true;
			} elseif ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ) {
				$is_sync = true;
			} elseif ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
				$is_sync = true;
			} elseif ( ! is_admin() && isset( $_SERVER['REQUEST_METHOD'] ) ) {
				$method = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
				if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
					$is_sync = true;
				}
			}

			/**
			 * Consente di dichiarare esplicitamente altre richieste come sincronizzazione BMAN
			 * senza modificare il core del plugin.
			 */
			return (bool) apply_filters( 'spbag_is_bman_sync_request', $is_sync );
		}

		private static function is_guarded_new_product( $product_id ) {
			$product_id = absint( $product_id );
			return $product_id && '1' === (string) get_post_meta( $product_id, self::NEW_PRODUCT_DRAFT_META, true );
		}

		public static function guard_rest_new_product_before_save( $product, $request, $creating ) {
			if ( ! $creating || ! $product instanceof WC_Product || $product->is_type( 'variation' ) ) {
				return $product;
			}

			// Questa callback viene eseguita prima del primo salvataggio WooCommerce.
			$product->set_status( 'draft' );
			if ( is_callable( array( $product, 'set_slug' ) ) ) {
				$product->set_slug( '' );
			}
			$product->update_meta_data( self::NEW_PRODUCT_DRAFT_META, '1' );
			$product->update_meta_data( self::NEW_PRODUCT_GUARDED_AT, current_time( 'mysql' ) );

			self::$pending_new_product_guard = true;

			return $product;
		}

		public static function guard_new_product_before_insert( $data, $postarr, $unsanitized_postarr, $update ) {
			if ( empty( $data['post_type'] ) || 'product' !== $data['post_type'] ) {
				return $data;
			}

			$post_id = 0;
			if ( ! empty( $postarr['ID'] ) ) {
				$post_id = absint( $postarr['ID'] );
			} elseif ( ! empty( $unsanitized_postarr['ID'] ) ) {
				$post_id = absint( $unsanitized_postarr['ID'] );
			}

			// Prodotto già protetto: una sincronizzazione successiva non può pubblicarlo.
			if ( $update && $post_id && self::is_guarded_new_product( $post_id ) && self::is_external_sync_request() ) {
				$data['post_status'] = 'draft';
				$data['post_name']   = '';
				return $data;
			}

			// Nuovo prodotto padre: blocco solo nelle richieste di sincronizzazione/API.
			if ( ! $update && self::is_external_sync_request() ) {
				$data['post_status'] = 'draft';
				$data['post_name']   = '';
				self::$pending_new_product_guard = true;
			}

			return $data;
		}

		public static function mark_guarded_new_product_after_insert( $post_id, $post, $update, $post_before ) {
			if ( $update || ! self::$pending_new_product_guard || ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
				return;
			}

			update_post_meta( $post_id, self::NEW_PRODUCT_DRAFT_META, '1' );
			update_post_meta( $post_id, self::NEW_PRODUCT_GUARDED_AT, current_time( 'mysql' ) );

			// Garanzia ulteriore: nessun permalink definitivo al primo inserimento.
			if ( 'draft' !== get_post_status( $post_id ) || '' !== (string) $post->post_name ) {
				remove_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_new_product_before_insert' ), 5 );
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
						'post_name'   => '',
					)
				);
				add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_new_product_before_insert' ), 5, 4 );
			}

			self::$pending_new_product_guard = false;
		}

		public static function unlock_guarded_product_on_manual_publish( $new_status, $old_status, $post ) {
			if (
				'publish' !== $new_status
				|| 'publish' === $old_status
				|| ! $post instanceof WP_Post
				|| 'product' !== $post->post_type
				|| ! self::is_guarded_new_product( $post->ID )
			) {
				return;
			}

			// Una richiesta BMAN/API non può sbloccare la bozza.
			if ( self::is_external_sync_request() ) {
				return;
			}

			// Pubblicazione manuale dal backend: da questo momento AUTO può lavorare normalmente.
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				delete_post_meta( $post->ID, self::NEW_PRODUCT_DRAFT_META );
				delete_post_meta( $post->ID, self::NEW_PRODUCT_GUARDED_AT );
			}
		}

		private static function set_post_status_if_needed( $post_id, $status ) {
			$post_id = absint( $post_id );
			if ( ! $post_id || ! in_array( $status, array( 'publish', 'draft', 'private' ), true ) ) {
				return false;
			}

			$current = get_post_status( $post_id );
			if ( $current === $status ) {
				return false;
			}

			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				)
			);

			return true;
		}

		public static function apply_variation( $variation_id ) {
			if ( self::$running ) {
				return false;
			}

			self::$running = true;

			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				self::$running = false;
				return false;
			}

			$mode = self::get_variation_mode( $variation );

			if ( 'exclude' === $mode || 'manual_hide' === $mode ) {
				$target_status = 'private';
			} else {
				$target_status = self::is_effectively_in_stock( $variation ) ? 'publish' : 'private';
			}

			$updated = self::set_post_status_if_needed( $variation->get_id(), $target_status );

			$parent_id = $variation->get_parent_id();
			if ( $parent_id ) {
				wc_delete_product_transients( $parent_id );
			}

			self::$running = false;
			return $updated;
		}

		public static function apply_product( $product_id ) {
			if ( self::$running ) {
				return array(
					'product_updated'   => false,
					'variations_updated' => 0,
				);
			}

			self::$running = true;

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				self::$running = false;
				return array(
					'product_updated'   => false,
					'variations_updated' => 0,
				);
			}

			$mode                = self::get_product_mode( $product );
			$product_updated     = false;
			$variations_updated  = 0;
			$protected_new_draft = self::is_guarded_new_product( $product->get_id() );
			$discontinued        = self::is_discontinued_product( $product->get_id() );

			if ( $product->is_type( 'variation' ) ) {
				self::$running = false;
				$variation_updated = self::apply_variation( $product->get_id() );
				return array(
					'product_updated'   => false,
					'variations_updated' => $variation_updated ? 1 : 0,
				);
			}

			if ( 'exclude' === $mode || 'force_draft' === $mode ) {
				$product_updated = self::set_post_status_if_needed( $product->get_id(), 'draft' );
				wc_delete_product_transients( $product->get_id() );
				self::$running = false;
				return array(
					'product_updated'   => $product_updated,
					'variations_updated' => 0,
				);
			}

			if ( 'force_publish' === $mode ) {
				$product_updated = self::set_post_status_if_needed( $product->get_id(), $protected_new_draft ? 'draft' : 'publish' );
				if ( $discontinued ) {
					self::sync_product_catalog_state( $product, self::is_effectively_in_stock( $product ) );
				} else {
					delete_post_meta( $product->get_id(), self::OUT_OF_STOCK_SINCE_META );
					self::restore_product_catalog_visibility( $product );
				}
				wc_delete_product_transients( $product->get_id() );
				self::$running = false;
				return array(
					'product_updated'   => $product_updated,
					'variations_updated' => 0,
				);
			}

			// AUTO mode.
			if ( $product->is_type( 'variable' ) ) {
				$children = self::get_all_variation_ids( $product->get_id() );
				$has_visible_variation = false;

				self::$running = false;
				foreach ( $children as $variation_id ) {
					$variation_updated = self::apply_variation( $variation_id );
					if ( $variation_updated ) {
						$variations_updated++;
					}

					$variation = wc_get_product( $variation_id );
					if (
						$variation instanceof WC_Product_Variation
						&& 'publish' === get_post_status( $variation_id )
						&& 'auto' === self::get_variation_mode( $variation )
						&& self::is_effectively_in_stock( $variation )
					) {
						$has_visible_variation = true;
					}
				}

				self::$running = true;
				if ( $protected_new_draft ) {
					$product_updated = self::set_post_status_if_needed( $product->get_id(), 'draft' );
				} elseif ( ! $discontinued && $has_visible_variation ) {
					$product_updated = self::set_post_status_if_needed( $product->get_id(), 'publish' );
				}

				self::sync_product_catalog_state( $product, $has_visible_variation );
			} else {
				$available = self::is_effectively_in_stock( $product );

				if ( $protected_new_draft ) {
					$product_updated = self::set_post_status_if_needed( $product->get_id(), 'draft' );
				} elseif ( ! $discontinued && $available ) {
					$product_updated = self::set_post_status_if_needed( $product->get_id(), 'publish' );
				}

				self::sync_product_catalog_state( $product, $available );
			}

			wc_delete_product_transients( $product->get_id() );

			self::$running = false;
			return array(
				'product_updated'   => $product_updated,
				'variations_updated' => $variations_updated,
			);
		}

		/* ---------------------------------------------------------------------
		 * Hooks
		 * ------------------------------------------------------------------ */

		public static function on_product_stock_status_changed( $product_id, $stock_status, $product = null ) {
			self::queue_product_update( $product_id );
		}

		public static function on_variation_stock_status_changed( $variation_id, $stock_status, $variation = null ) {
			self::queue_product_update( $variation_id );
		}

		public static function on_stock_quantity_changed( $product ) {
			if ( $product instanceof WC_Product ) {
				self::queue_product_update( $product->get_id() );
			}
		}

		public static function on_product_updated( $product_id ) {
			self::queue_product_update( $product_id );
		}

		private static function queue_product_update( $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id || self::$running || self::$reconciling_updates ) {
				return;
			}
			self::$pending_product_updates[ $product_id ] = true;
		}

		/**
		 * Stock hooks can run before the current WooCommerce save has finished.
		 * Read the final persisted state at shutdown, then update visibility only.
		 * Quantities remain exclusively under WooCommerce/BMAN control.
		 */
		public static function reconcile_product_updates() {
			if ( empty( self::$pending_product_updates ) || self::$running || self::$reconciling_updates ) {
				return;
			}

			$product_ids = array_keys( self::$pending_product_updates );
			self::$pending_product_updates = array();
			self::$reconciling_updates = true;
			$parents = array();

			try {
				foreach ( $product_ids as $product_id ) {
					// Never restore deleted products or unfinished WordPress auto-drafts.
					if ( ! in_array( get_post_status( $product_id ), array( 'publish', 'private', 'draft', 'pending' ), true ) ) {
						continue;
					}
					clean_post_cache( $product_id );
					wc_delete_product_transients( $product_id );
					$product = wc_get_product( $product_id );
					if ( ! $product instanceof WC_Product ) {
						continue;
					}

					if ( $product instanceof WC_Product_Variation ) {
						self::apply_variation( $product_id );
						$parent_id = $product->get_parent_id();
						if ( $parent_id ) {
							$parents[ $parent_id ] = true;
						}
					} else {
						$parents[ $product_id ] = true;
					}
				}

				foreach ( array_keys( $parents ) as $parent_id ) {
					if ( ! in_array( get_post_status( $parent_id ), array( 'publish', 'private', 'draft', 'pending' ), true ) ) {
						continue;
					}
					clean_post_cache( $parent_id );
					wc_delete_product_transients( $parent_id );
					self::apply_product( $parent_id );

					// A re-enabled child must also refresh the parent's stock status.
					$parent = wc_get_product( $parent_id );
					if ( $parent instanceof WC_Product_Variable && ! $parent->get_manage_stock() ) {
						WC_Product_Variable::sync_stock_status( $parent_id );
					}
					wc_delete_product_transients( $parent_id );
				}
			} finally {
				self::$reconciling_updates = false;
			}
		}

		/* ---------------------------------------------------------------------
		 * Background scan
		 * ------------------------------------------------------------------ */

		public static function start_background_scan() {
			update_option( self::SCAN_RUNNING_OPTION, 'yes', false );
			update_option( self::SCAN_LAST_ID_OPTION, 0, false );
			self::schedule_next_background_batch();
		}

		private static function schedule_next_background_batch() {
			if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
				wp_schedule_single_event( time() + 60, self::CRON_BATCH );
			}
		}

		public static function process_background_scan_event() {
			if ( get_option( self::SCAN_RUNNING_OPTION, 'no' ) !== 'yes' ) {
				return;
			}

			self::process_scan_batch( 75 );

			if ( get_option( self::SCAN_RUNNING_OPTION, 'no' ) === 'yes' ) {
				self::schedule_next_background_batch();
			}
		}

		private static function process_scan_batch( $limit = 75 ) {
			global $wpdb;

			$limit   = max( 10, min( 200, absint( $limit ) ) );
			$last_id = (int) get_option( self::SCAN_LAST_ID_OPTION, 0 );

			$product_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					 WHERE post_type = 'product'
					 AND post_status IN ('publish','draft','private','pending')
					 AND ID > %d
					 ORDER BY ID ASC
					 LIMIT %d",
					$last_id,
					$limit
				)
			);

			$products_checked   = 0;
			$products_updated   = 0;
			$variations_updated = 0;
			$new_last_id        = $last_id;

			foreach ( $product_ids as $product_id ) {
				$product_id = absint( $product_id );
				$new_last_id = max( $new_last_id, $product_id );
				$products_checked++;

				$result = self::apply_product( $product_id );
				if ( ! empty( $result['product_updated'] ) ) {
					$products_updated++;
				}
				$variations_updated += isset( $result['variations_updated'] ) ? (int) $result['variations_updated'] : 0;
			}

			update_option( self::SCAN_LAST_ID_OPTION, $new_last_id, false );

			$stats = array(
				'time'               => current_time( 'mysql' ),
				'products_checked'   => $products_checked,
				'products_updated'   => $products_updated,
				'variations_updated' => $variations_updated,
			);
			update_option( self::SCAN_STATS_OPTION, $stats, false );

			if ( count( $product_ids ) < $limit ) {
				update_option( self::SCAN_RUNNING_OPTION, 'no', false );
				update_option( self::SCAN_LAST_ID_OPTION, 0, false );
				wp_clear_scheduled_hook( self::CRON_BATCH );
			}

			return $stats;
		}

		/* ---------------------------------------------------------------------
		 * Frontend safety filters for variations
		 * ------------------------------------------------------------------ */

		private static function should_variation_be_frontend_visible( $variation ) {
			if ( ! $variation instanceof WC_Product_Variation ) {
				return false;
			}

			$mode = self::get_variation_mode( $variation );

			if ( 'exclude' === $mode || 'manual_hide' === $mode ) {
				return false;
			}

			return self::is_effectively_in_stock( $variation );
		}

		public static function filter_available_variation( $variation_data, $product, $variation ) {
			if ( $variation instanceof WC_Product_Variation && ! self::should_variation_be_frontend_visible( $variation ) ) {
				return false;
			}

			return $variation_data;
		}

		public static function filter_variation_is_visible( $visible, $variation_id, $product_id, $variation = null ) {
			$variation_product = $variation instanceof WC_Product_Variation ? $variation : wc_get_product( $variation_id );

			if ( $variation_product instanceof WC_Product_Variation && ! self::should_variation_be_frontend_visible( $variation_product ) ) {
				return false;
			}

			return $visible;
		}

		public static function filter_variation_is_active( $active, $variation ) {
			if ( $variation instanceof WC_Product_Variation && ! self::should_variation_be_frontend_visible( $variation ) ) {
				return false;
			}

			return $active;
		}

		public static function filter_product_is_visible( $visible, $product_id ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $visible;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product ) {
				return $visible;
			}

			if ( $product instanceof WC_Product_Variation ) {
				return self::should_variation_be_frontend_visible( $product );
			}

			$mode = self::get_product_mode( $product );

			if ( self::is_discontinued_product( $product->get_id() ) ) {
				return false;
			}

			if ( 'exclude' === $mode || 'force_draft' === $mode ) {
				return false;
			}

			if ( 'force_publish' === $mode ) {
				return true;
			}

			if ( $product->is_type( 'variable' ) ) {
				return count( self::get_visible_variation_ids( $product->get_id() ) ) > 0;
			}

			return $visible && self::is_effectively_in_stock( $product );
		}

		public static function filter_product_children( $children, $product = null, $visible_only = null ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $children;
			}

			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
				return $children;
			}

			$filtered = array();

			foreach ( (array) $children as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( $variation instanceof WC_Product_Variation && self::should_variation_be_frontend_visible( $variation ) ) {
					$filtered[] = absint( $variation_id );
				}
			}

			return $filtered;
		}

		public static function filter_dropdown_variation_attribute_options_args( $args ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $args;
			}

			if ( empty( $args['product'] ) || ! $args['product'] instanceof WC_Product || empty( $args['attribute'] ) || empty( $args['options'] ) ) {
				return $args;
			}

			$product = $args['product'];
			if ( ! $product->is_type( 'variable' ) ) {
				return $args;
			}

			$visible_values = self::get_visible_attribute_values( $product, $args['attribute'] );

			if ( empty( $visible_values ) ) {
				return $args;
			}

			$args['options'] = array_values(
				array_filter(
					(array) $args['options'],
					function ( $option ) use ( $visible_values ) {
						$option_string = (string) $option;

						return in_array( $option_string, $visible_values, true )
							|| in_array( self::normalize_attribute_value( $option_string ), $visible_values, true );
					}
				)
			);

			return $args;
		}

		private static function get_first_visible_variation( $product ) {
			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
				return null;
			}

			$visible_ids = self::get_visible_variation_ids( $product->get_id() );

			if ( empty( $visible_ids ) ) {
				return null;
			}

			$variation = wc_get_product( reset( $visible_ids ) );

			return $variation instanceof WC_Product_Variation ? $variation : null;
		}

		private static function get_defaultable_attributes_from_variation( $variation ) {
			if ( ! $variation instanceof WC_Product_Variation ) {
				return array();
			}

			$defaults = array();

			foreach ( $variation->get_attributes() as $attribute => $value ) {
				$value = (string) $value;

				if ( '' === $value ) {
					continue;
				}

				$attribute_key = self::normalize_attribute_key( $attribute );

				if ( '' !== $attribute_key ) {
					$defaults[ $attribute_key ] = $value;
				}
			}

			return $defaults;
		}

		/**
		 * Un default parziale (solo colore o solo taglia) non è una combinazione
		 * proposta: per la scheda deve esistere un valore per ogni attributo.
		 */
		private static function default_attributes_are_complete( $default_attributes, $product ) {
			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || ! is_array( $default_attributes ) ) {
				return false;
			}

			$defaults = array();
			foreach ( $default_attributes as $attribute => $value ) {
				$defaults[ self::normalize_attribute_key( $attribute ) ] = (string) $value;
			}

			foreach ( (array) $product->get_variation_attributes() as $attribute => $options ) {
				$key = self::normalize_attribute_key( $attribute );

				if ( empty( $defaults[ $key ] ) ) {
					return false;
				}
			}

			return ! empty( $defaults );
		}

		private static function default_attributes_match_visible_variation( $default_attributes, $product ) {
			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || empty( $default_attributes ) || ! is_array( $default_attributes ) ) {
				return false;
			}

			foreach ( self::get_visible_variation_ids( $product->get_id() ) as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( ! $variation instanceof WC_Product_Variation ) {
					continue;
				}

				$matches = true;
				$variation_attributes = $variation->get_attributes();

				foreach ( $default_attributes as $attribute => $default_value ) {
					$attribute_key = self::normalize_attribute_key( $attribute );
					$default_value = self::normalize_attribute_value( $default_value );
					$variation_value = '';

					foreach ( $variation_attributes as $variation_attribute => $value ) {
						if ( self::normalize_attribute_key( $variation_attribute ) === $attribute_key ) {
							$variation_value = self::normalize_attribute_value( $value );
							break;
						}
					}

					// Un valore vuoto della variazione WooCommerce significa "qualsiasi".
					if ( '' !== $default_value && '' !== $variation_value && $default_value !== $variation_value ) {
						$matches = false;
						break;
					}
				}

				if ( $matches ) {
					return true;
				}
			}

			return false;
		}

		public static function filter_default_attributes( $default_attributes, $product ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $default_attributes;
			}

			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
				return $default_attributes;
			}

			if ( ! is_array( $default_attributes ) ) {
				$default_attributes = array();
			}

			$clean_defaults = $default_attributes;

			foreach ( $clean_defaults as $attribute => $value ) {
				$visible_values = self::get_visible_attribute_values( $product, $attribute );

				if ( empty( $visible_values ) ) {
					continue;
				}

				if (
					'' !== (string) $value
					&& ! in_array( (string) $value, $visible_values, true )
					&& ! in_array( self::normalize_attribute_value( $value ), $visible_values, true )
				) {
					unset( $clean_defaults[ $attribute ] );
				}
			}


			/*
			 * In AUTO la scheda non deve apparire senza una proposta. Manteniamo il
			 * default WooCommerce solo quando è completo e corrisponde a una variante
			 * visibile; altrimenti proponiamo la prima combinazione disponibile.
			 */
			if (
				'auto' === self::get_product_mode( $product )
				&& ! ( self::default_attributes_are_complete( $clean_defaults, $product ) && self::default_attributes_match_visible_variation( $clean_defaults, $product ) )
			) {
				$first_visible_variation = self::get_first_visible_variation( $product );
				$auto_defaults           = self::get_defaultable_attributes_from_variation( $first_visible_variation );

				if ( ! empty( $auto_defaults ) ) {
					return $auto_defaults;
				}
			}

			return $clean_defaults;
		}

		/**
		 * WooCommerce normalmente valida le selezioni, ma alcune integrazioni di
		 * swatches/gestionali possono inviare una variation_id pur senza tutti gli
		 * attributi. La verifica lato server resta quindi la protezione definitiva.
		 */
		public static function validate_required_variation_attributes( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
			if ( ! $passed ) {
				return false;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
				return $passed;
			}

			$missing = array();

			foreach ( (array) $product->get_variation_attributes() as $attribute_name => $options ) {
				$field_name = 'attribute_' . $attribute_name;
				$value      = isset( $variations[ $field_name ] ) ? $variations[ $field_name ] : '';

				if ( '' === (string) $value && isset( $_REQUEST[ $field_name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$value = wc_clean( wp_unslash( $_REQUEST[ $field_name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}

				if ( '' === (string) $value ) {
					$missing[] = wc_attribute_label( $attribute_name, $product );
				}
			}

			if ( ! empty( $missing ) ) {
				wc_add_notice(
					sprintf(
						'Seleziona %s prima di aggiungere il prodotto al carrello.',
						implode( ' e ', array_map( 'wp_strip_all_tags', $missing ) )
					),
					'error'
				);

				return false;
			}

			if ( $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( $variation instanceof WC_Product_Variation && ! self::should_variation_be_frontend_visible( $variation ) ) {
					wc_add_notice( 'La combinazione selezionata non è più disponibile. Scegli un’altra opzione.', 'error' );
					return false;
				}
			}

			return $passed;
		}

		/**
		 * Dati compatti per il filtro lato browser. Sono presenti anche quando
		 * WooCommerce passa alle variazioni via AJAX (cataloghi oltre la soglia).
		 */
		private static function frontend_variation_selection_data( $product ) {
			if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
				return array();
			}

			$data = array();

			foreach ( self::get_visible_variation_ids( $product->get_id() ) as $variation_id ) {
				$variation = wc_get_product( $variation_id );

				if ( ! $variation instanceof WC_Product_Variation || ! self::should_variation_be_frontend_visible( $variation ) ) {
					continue;
				}

				$attributes = array();
				foreach ( (array) $variation->get_attributes() as $attribute => $value ) {
					$name = 0 === strpos( (string) $attribute, 'attribute_' ) ? (string) $attribute : 'attribute_' . $attribute;
					$attributes[ $name ] = (string) $value;
				}

				$data[] = array(
					'variation_id'         => $variation->get_id(),
					'attributes'           => $attributes,
					'variation_is_active'  => true,
					'is_purchasable'       => $variation->is_purchasable(),
					'is_in_stock'          => $variation->is_in_stock(),
					'display_price'        => (float) wc_get_price_to_display( $variation ),
				);
			}

			return $data;
		}

		/**
		 * L'avviso usa un importo IVA inclusa, come il prezzo percepito dal
		 * cliente. Il filtro permette di allinearlo facilmente a una futura
		 * modifica della soglia di spedizione senza toccare il frontend.
		 */
		private static function frontend_free_shipping_data( $product ) {
			$minimum = (float) apply_filters( 'spbag_free_shipping_minimum', 89.01, $product );

			if ( ! $product instanceof WC_Product || $minimum <= 0 ) {
				return array( 'enabled' => false );
			}

			$data = array(
				'enabled'  => true,
				'minimum'  => $minimum,
				'label'    => apply_filters( 'spbag_free_shipping_notice_text', 'Spedizione gratuita in Italia', $product ),
				'variable' => $product->is_type( 'variable' ),
				'price'    => null,
			);

			if ( ! $product->is_type( 'variable' ) && $product->is_purchasable() && self::is_effectively_in_stock( $product ) ) {
				$data['price'] = (float) wc_get_price_to_display( $product );
			}

			return $data;
		}

		public static function print_frontend_swatch_css() {
			if ( is_admin() || ! ( function_exists( 'is_product' ) && is_product() || function_exists( 'is_shop' ) && is_shop() || function_exists( 'is_product_category' ) && is_product_category() || function_exists( 'is_product_tag' ) && is_product_tag() || is_tax() ) ) {
				return;
			}
			?>
			<style id="spbag-frontend-swatch-visibility">
				.variations_form .cfvsw-swatches-option-disabled,
				.variations_form .cfvsw-swatches-option.cfvsw-swatches-disabled,
				.variations_form .cfvsw-swatches-option.disabled,
				.variations_form .cfvsw-swatches-option.out-of-stock,
				.variations_form .variable-item.disabled,
				.variations_form .variable-item.out-of-stock,
				.variations_form .rtwpvs-term.disabled,
				.variations_form .rtwpvs-term.out-of-stock,
				.variations_form [aria-disabled="true"].cfvsw-swatches-option,
				.variations_form [aria-disabled="true"].variable-item,
				.variations_form .spbag-hidden,
				.variations_form [data-spbag-hidden="1"],
				.sp-loop-color-swatches .disabled,
				.sp-loop-color-swatches .out-of-stock,
				.sp-loop-color-swatches .is-disabled,
				.sp-loop-color-swatches .is-out-of-stock,
				.sp-loop-color-swatches [aria-disabled="true"],
				.sp-loop-color-swatches [data-spbag-hidden="1"],
				.sp-loop-swatches .disabled,
				.sp-loop-swatches .out-of-stock,
				.sp-loop-swatches .is-disabled,
				.sp-loop-swatches .is-out-of-stock,
				.sp-loop-swatches [aria-disabled="true"],
				.sp-loop-swatches [data-spbag-hidden="1"] {
					display: none !important;
				}

				.variations_form .spbag-selection-note {
					display: block;
					margin: 10px 0 0;
					color: rgba(38, 28, 21, .72);
					font-size: 13px;
					line-height: 1.35;
				}

				.variations_form .spbag-selection-note[hidden] {
					display: none !important;
				}

				.spbag-free-shipping {
					display: flex;
					align-items: center;
					gap: 8px;
					margin: 12px 0 0;
					color: #252627;
					font-size: 13px;
					font-weight: 600;
					line-height: 1.35;
				}

				.spbag-free-shipping[hidden] {
					display: none !important;
				}

				.spbag-free-shipping__dot {
					width: 8px;
					height: 8px;
					flex: 0 0 8px;
					border-radius: 50%;
					background: #2f8a57;
					box-shadow: 0 0 0 3px rgba(47, 138, 87, .12);
				}
			</style>
			<?php
		}

		public static function print_frontend_variation_selection_script() {
			if ( is_admin() || ! ( function_exists( 'is_product' ) && is_product() ) ) {
				return;
			}

			global $product;
			if ( ! $product instanceof WC_Product ) {
				$product = wc_get_product( get_queried_object_id() );
			}

			$selection_data = self::frontend_variation_selection_data( $product );
			$frontend_data  = array(
				'auto_suggest' => $product instanceof WC_Product && 'auto' === self::get_product_mode( $product ),
				'free_shipping' => self::frontend_free_shipping_data( $product ),
			);
			?>
			<script id="spbag-variation-selection-guard">
				(function () {
					'use strict';
					var serverVariations = <?php echo wp_json_encode( $selection_data ); ?>;
					var frontendData = <?php echo wp_json_encode( $frontend_data ); ?>;

					function selectsFor(form) {
						return Array.prototype.slice.call(form.querySelectorAll('select[name^="attribute_"]'));
					}

					function variationIsUsable(variation) {
						return !!(
							variation && variation.variation_id && variation.variation_is_active !== false &&
							variation.is_purchasable !== false && variation.is_in_stock !== false
						);
					}

					function formVariations(form) {
						var variations = window.jQuery ? window.jQuery(form).data('product_variations') : [];
						return Array.isArray(variations) && variations.length ? variations : serverVariations;
					}

					function selectedValues(form) {
						var values = {};
						selectsFor(form).forEach(function (select) {
							values[select.name] = select.value || '';
						});
						return values;
					}

					function variationMatches(variation, values, candidateName, candidateValue) {
						if (!variationIsUsable(variation)) return false;
						var attributes = variation.attributes || {};

						return Object.keys(values).every(function (name) {
							var selected = name === candidateName ? candidateValue : values[name];
							var expected = attributes[name] || '';

							// Un valore vuoto nella variante WooCommerce significa "qualsiasi".
							return !selected || !expected || expected === selected;
						});
					}

					function optionForValue(select, value) {
						return Array.prototype.slice.call(select.options || []).filter(function (option) {
							return option.value === value;
						})[0] || null;
					}

					function optionValueForSwatch(swatch) {
						return swatch.getAttribute('data-value') || swatch.getAttribute('data-term') ||
							swatch.getAttribute('data-slug') || swatch.getAttribute('data-attribute_value') || '';
					}

					function syncSwatches(select) {
						var scope = select.closest('tr, .value, .variations') || select.parentNode;
						if (!scope) return;

						Array.prototype.slice.call(scope.querySelectorAll('[data-value], [data-term], [data-slug], [data-attribute_value]')).forEach(function (swatch) {
							var value = optionValueForSwatch(swatch);
							if (!value) return;

							var option = optionForValue(select, value);
							var hidden = !option || option.disabled || option.hidden;
							swatch.classList.toggle('spbag-hidden', hidden);
							swatch.setAttribute('data-spbag-hidden', hidden ? '1' : '0');
							if (hidden) swatch.setAttribute('aria-hidden', 'true');
							else swatch.removeAttribute('aria-hidden');
						});
					}

					function selectionIsComplete(form) {
						var selects = selectsFor(form);
						return selects.length > 0 && selects.every(function (select) {
							var option = optionForValue(select, select.value || '');
							return !!(select.value && option && !option.disabled && !option.hidden);
						});
					}

					function selectedVariation(form) {
						if (!form || !selectionIsComplete(form)) return null;

						var values = selectedValues(form);
						var selects = selectsFor(form);

						return formVariations(form).filter(function (variation) {
							if (!variationIsUsable(variation)) return false;
							var attributes = variation.attributes || {};

							return selects.every(function (select) {
								var expected = attributes[select.name] || '';
								return !expected || expected === values[select.name];
							});
						})[0] || null;
					}

					function triggerSelectionChange(form) {
						var selects = selectsFor(form);

						if (window.jQuery) {
							var $form = window.jQuery(form);
							$form.find('select[name^="attribute_"]').trigger('change');
							$form.trigger('woocommerce_variation_select_change');
							$form.trigger('check_variations');
							return;
						}

						selects.forEach(function (select) {
							select.dispatchEvent(new Event('change', { bubbles: true }));
						});
					}

					/*
					 * In AUTO mostriamo subito una combinazione vera, mai un solo
					 * attributo. Se WooCommerce ha già un default completo e valido lo
					 * rispettiamo; altrimenti prendiamo la prima variazione acquistabile.
					 */
					function suggestInitialAvailableCombination(form) {
						if (!form || !frontendData.auto_suggest || form.dataset.spbagInitialised === '1') return false;
						form.dataset.spbagInitialised = '1';

						if (selectedVariation(form)) return false;

						var variation = formVariations(form).filter(variationIsUsable)[0];
						if (!variation) return false;

						var attributes = variation.attributes || {};
						var changed = false;

						selectsFor(form).forEach(function (select) {
							var value = attributes[select.name] || '';
							var option = value ? optionForValue(select, value) : null;

							if (option && !option.disabled && !option.hidden && select.value !== value) {
								select.value = value;
								changed = true;
							}
						});

						if (changed) triggerSelectionChange(form);
						return changed;
					}

					function freeShippingData() {
						return frontendData && frontendData.free_shipping ? frontendData.free_shipping : {};
					}

					function ensureFreeShippingNotice(anchor) {
						var data = freeShippingData();
						if (!data.enabled || !anchor) return null;

						var scope = anchor.closest('.sp-product-summary, .summary, .elementor-widget-woocommerce-product-add-to-cart') || anchor;
						var notice = scope.querySelector('.spbag-free-shipping');
						if (notice) return notice;

						notice = document.createElement('p');
						notice.className = 'spbag-free-shipping';
						notice.setAttribute('aria-live', 'polite');
						notice.innerHTML = '<span class="spbag-free-shipping__dot" aria-hidden="true"></span><span></span>';
						notice.querySelector('span:last-child').textContent = data.label || 'Spedizione gratuita in Italia';

						var target = anchor.querySelector ? (anchor.querySelector('.woocommerce-variation-add-to-cart') || anchor.querySelector('.single_add_to_cart_button')) : null;
						if (target && target.parentNode) {
							target.insertAdjacentElement('afterend', notice);
						} else if (anchor.parentNode) {
							anchor.insertAdjacentElement('afterend', notice);
						}

						return notice;
					}

					function updateFreeShippingNotice(form, button) {
						var data = freeShippingData();
						if (!data.enabled) return;

						var anchor = form || button;
						var notice = ensureFreeShippingNotice(anchor);
						if (!notice) return;

						var price = null;
						if (data.variable) {
							var variation = selectedVariation(form);
							price = variation && typeof variation.display_price !== 'undefined' ? Number(variation.display_price) : null;
						} else if (typeof data.price !== 'undefined' && data.price !== null) {
							price = Number(data.price);
						}

						notice.hidden = !(typeof price === 'number' && !isNaN(price) && price >= Number(data.minimum));
					}

					function ensureNote(form) {