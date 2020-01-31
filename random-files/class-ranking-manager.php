<?php
/**
 * The Ranking_Manager Class
 */

namespace FanSided\Ranking;

if ( ! class_exists( 'Ranking_Manager' ) ) {
	class Ranking_Manager {
		const TAXONOMY = 'rankings';
		static $config_sorting, $config_ranking, $config_slug, $config_slugs;

		/**
		 * Rankings_Manager constructor.
		 */
		public function __construct() {
			#=============#
			#   Actions   #
			#=============#
			add_action( 'init', array( $this, 'setup_fandom_types' ) );
			add_action( 'edit_form_after_title', array( $this, 'rankings_archive_meta_box_context' ) );
			add_action( 'add_meta_boxes', array( $this, 'rankings_archive_meta_box_add' ) );
			add_action( 'create_rankings', array( $this, 'edit_ranking_fields' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
			add_action( 'save_post', array( $this, 'rankings_archive_meta_box_save' ), 10, 3 );
			add_action( 'save_post', array( $this, 'rankings_set_default_term_position' ), 10, 3 );
			add_action( 'wp_ajax_update-rankings-order', array( $this, 'update_rankings_order' ) );
			add_action( 'manage_posts_extra_tablenav', array( $this, 'append_sorting_action' ) );
			add_action( 'pre_get_posts', array( $this, 'ranking_admin_pre_get_posts' ) );
			add_action( 'edit_terms', array( $this, 'rankings_save_form_fields' ), 10, 2 );
			add_action( 'pre_get_posts', array( $this, 'setup_single_rewrites' ) );

			#=============#
			#   Filters   #
			#=============#
			add_filter( 'rankings_row_actions', array( $this, 'rankings_row_actions' ) );
			add_filter( 'get_next_post_join', array( $this, 'alter_fandom_join' ), 10, 5 );
			add_filter( 'get_next_post_where', array( $this, 'alter_adjacent_where' ), 10, 5 );
			add_filter( 'get_next_post_sort', array( $this, 'alter_adjacent_sort' ), 10, 2 );
			add_filter( 'get_previous_post_join', array( $this, 'alter_fandom_join' ), 10, 5 );
			add_filter( 'get_previous_post_where', array( $this, 'alter_adjacent_where' ), 10, 5 );
			add_filter( 'get_previous_post_sort', array( $this, 'alter_adjacent_sort' ), 10, 2 );
			add_filter( 'bulk_actions-edit-fandom250', array( $this, 'bulk_dropdown_option' ), 10, 1 );
			add_filter( 'handle_bulk_actions-edit-fandom250', array( $this, 'bulk_dropdown_handler' ), 10, 3 );
			add_filter( 'body_class', array( $this, 'modify_body_class_for_voting' ), 100, 2 );

			self::$config_sorting = get_option( 'fs_option_cpt_config_sorting', 'off' );
			self::$config_ranking = get_option( 'fs_option_cpt_config_ranks', 'off' );
			self::$config_slug    = trim( get_option( 'fs_option_cpt_config_slug', 'fandom250' ) );
			self::$config_slugs   = get_option( 'fs_option_cpt_config_slugs', 'off' );
		}


		#
		#   UI Elements
		#
		public static function admin_styles() {
			wp_register_style( 'ranking_admin_styles', self::plugin_url() . '/admin/assets/admin.css', array(), '2.0.3' );
			wp_enqueue_style( 'ranking_admin_styles' );
		}

		public static function admin_scripts() {
			global $post_type, $plugin_page;
			if ( 'fandom250' === $post_type || is_null( $post_type ) && 'ranking-options' == $plugin_page ) {
				wp_register_script( 'rankings_scripts', self::plugin_url() . '/admin/assets/admin.js', array( 'jquery' ), '2.0.3' );
				wp_enqueue_script( 'rankings_scripts' );
				wp_localize_script( 'rankings_scripts', 'rankings', array(
					'ajaxurl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( Ranking_Layout::AJAX_NONCE ),
					'is_sortable' => self::$config_sorting,
					'is_rankable' => self::$config_ranking,
				) );
			}
		}

		public static function rankings_save_form_fields( $term_id, $taxonomy ) {
			if ( self::TAXONOMY !== $taxonomy ) {
				return;
			}
			update_term_meta( $term_id, 'voting_html', $_POST['voting_html'] );
		}

		public static function rankings_row_actions( $actions = array() ) {
			if ( preg_match( '#rankings/([^/]*)/#', $actions['view'], $manage ) ) {
				preg_match( '/tag_ID=([0-9]+)/', $actions['edit'], $tag_id );
				if ( ! empty( $tag_id ) ) {
					$actions['inline hide-if-no-js'] = sprintf( '<a href="edit.php?post_type=fandom250&rankings=%d">Manage</a>', $tag_id[1] );
					$actions['edit']                 = sprintf( '<a href="edit.php?post_type=fandom250&page=ranking-options&term=%d">Customize</a>', $tag_id[1] );
				} else {
					$actions['inline hide-if-no-js'] = sprintf( '<a href="edit.php?post_type=fandom250&rankings=%s">Manage</a>', $manage[1] );
					$actions['edit']                 = sprintf( '<a href="edit.php?post_type=fandom250&page=ranking-options&term=%s">Customize</a>', $manage[1] );
				}
				$actions['view'] = str_replace( '/rankings/', '/fandom250/', $actions['view'] );
				$actions['view'] = preg_replace( '/\-[0-9]{4}/', '', $actions['view'] );
			}

			return $actions;
		}

		public static function append_sorting_action() {
			global $post_type;
			if ( ( 'fandom250' === $post_type ) && ( 'on' === get_option( 'fs_option_cpt_config_sorting', 'off' ) || ( ! isset( $_GET['rankings'] ) || empty( $_GET['rankings'] ) ) ) ) {
				$save_sort_btn = <<<BTN
<input type="button" class="button-primary ranking-sort-submit" style="float: right; margin-left: 10px;" value="Save Order" disabled>
<div class="spinner rankings_spinner"></div>
BTN;

				$missing_button = <<<BTN
<input type="button" class="button-secondary ranking-missing" style="float: right; margin-left: 10px;" value="Something Missing?">
BTN;

				if ( ! isset( $_GET['show'] ) || ( isset( $_GET['show'] ) && empty( $_GET['show'] ) ) ) {
					// echo( $missing_button ); // TODO: Helpful, but might not need anymore
				}
				echo( $save_sort_btn );
			}

		}

		public static function bulk_dropdown_option( $actions ) {
			if ( ! isset( $_GET['rankings'] ) ) {
				$actions['rankings_set'] = 'Add to Ranking';
			} else {
				$actions['rankings_unset'] = 'Remove from Ranking';
			}

			return $actions;
		}

		public static function setup_fandom_types() {
			global $wp;

			$wp->add_query_var( self::TAXONOMY );
			$wp->add_query_var( 'voting' );
			register_taxonomy_for_object_type( self::TAXONOMY, 'fandom' );

			if ( 'on' === self::$config_slugs ) {
				self::setup_rankings_rewrites();
			} else {
				self::setup_category_rewrites( self::$config_slug );
			}
		}

		public static function modify_body_class_for_voting( $classes, $class ) {

			if ( in_array( 'post-type-archive-fandom250', $classes ) ) {
				global $wp_query;
				$ranking_query = $wp_query->get_queried_object();
				$ranking_url   = $ranking_query->slug;

				$is_votable = false;
				if ( 'true' == get_query_var( 'voting' ) ) {
					$term = get_term_by( 'slug', $ranking_url, 'rankings' );
					if ( ! empty( $term ) ) {
						$classes[] = 'fandom250-voting';
					}
				}

				foreach ( $classes as $class_key => $class_value ) {
					if ( 'ranking-first-page' == $class_value ) {
						unset( $classes[ $class_key ] );
					}
				}
			}

			return $classes;
		}


		#
		#   Handlers
		#
		public static function update_rankings_order() {
			$meta_order_key = '_fandom250_order';
			if ( ! empty( $_POST['rankings'] ) ) {
				$meta_order_key .= '_' . sanitize_text_field( $_POST['rankings'] );
			}

			parse_str( $_POST['order'], $data );

			if ( ! is_array( $data ) ) {
				return false;
			}

			$id_arr = array();
			foreach ( $data as $key => $values ) {
				foreach ( $values as $position => $id ) {
					$id_arr[] = $id;
				}
			}

			$menu_order_arr = array();
			foreach ( $id_arr as $key => $id ) {
				$result           = get_post_meta( intval( $id ), $meta_order_key, true );
				$menu_order_arr[] = $result;
			}

			sort( $menu_order_arr );

			foreach ( $data as $key => $values ) {
				foreach ( $values as $position => $id ) {
					update_post_meta( intval( $id ), $meta_order_key, $menu_order_arr[ $position ] );
				}
			}
		}

		public static function edit_ranking_fields( $term_id, $tt_id, $taxonomy ) {

			if ( isset( $_POST['rankings_duplicate'] ) && 'yes' === $_POST['rankings_duplicate'] ) {
				$args    = array(
					'post_type'      => 'fandom250',
					'posts_per_page' => '-1',
				);
				$fandoms = new \WP_Query( $args );

				while ( $fandoms->have_posts() ) {
					$fandoms->the_post();
					wp_set_post_terms( $fandoms->post->ID, $term_id, 'rankings', true );
					update_post_meta( $fandoms->post->ID, '_fandom250_order_' . $term_id, $fandoms->post->menu_order );
				}
			}
		}

		public static function bulk_dropdown_handler( $sendback, $doaction, $post_ids ) {
			$rankings = ( ! empty( $_GET['rankings'] ) && is_numeric( $_GET['rankings'] ) );
			if ( $rankings ) {
				$term_id = (int) sanitize_text_field( $_GET['rankings'] );
				switch ( $doaction ) {
					case 'rankings_set':
					case 'rankings_unset':
						$sendback = add_query_arg( 'rankChanges', self::$doaction( $term_id, $post_ids ) );
						break;
					default:
						// Silence is golden?
				}
			}

			return $sendback;
		}

		public static function rankings_archive_meta_box_save( $post_id, $post, $update ) {
			$post_type = get_post_type( $post_id );
			if ( 'fandom250' != $post_type ) {
				return;
			}
			$positions = $_POST['_fandom_archive_position'];
			$contents  = $_POST['_fandom_archive_content'];
			if ( ! empty( $positions ) ) {
				foreach ( $positions as $year => $position ) {
					if ( is_int( $year ) ) {
						update_post_meta( $post_id, '_fandom_archive_position_' . $year, sanitize_text_field( $position ) );
						update_post_meta( $post_id, '_fandom_archive_content_' . $year, sanitize_textarea_field( $contents[ $year ] ) );
					}
				}
			}
		}

		public static function rankings_set_default_term_position( $post_id, $post, $update ) {
			$post_type = get_post_type( $post_id );
			if ( 'fandom250' != $post_type ) {
				return;
			}

			$taxonomies = $_POST['tax_input'];
			foreach ( $taxonomies as $taxonomy ) {
				foreach ( $taxonomy as $term ) {
					if ( empty( $current_position = get_post_meta( $post_id, '_fandom250_order_' . $term ) ) ) {
						$last_spot = self::count_in_term( $term );
						$last_spot = ( empty( $last_spot ) ) ? 1 : ( $last_spot + 1 );
						update_post_meta( $post_id, '_fandom250_order_' . $term, $last_spot );
					}
				}
			}
		}


		#
		#   Database Interceptions
		#
		public static function alter_fandom_join( $join, $same_term, $excluded_terms, $taxonomy = null, $post = null ) {
			if ( is_null( $post ) || is_null( $taxonomy ) ) {
				return $join;
			}
			if ( 'fandom250' === $post->post_type ) {
				$rankings = self::get_rankings_id();
				$join     .= "LEFT JOIN wp_229_postmeta AS m ON m.post_id = p.ID AND m.meta_key = '" . $rankings . "'";
			}

			return $join;
		}

		public static function alter_adjacent_where( $query, $same_term, $excluded_terms, $taxonomy = null, $post = null ) {
			if ( is_null( $post ) || is_null( $taxonomy ) ) {
				return $query;
			}
			preg_match( "/p\.(menu_order|post_date) ([<|>]{1}) '([0-9\-\:\s]+)'/", $query, $menu_order );
			if ( ! empty( $menu_order ) && 'fandom250' === $post->post_type ) {
				$directions   = [ '>' => '<', '<' => '>' ];
				$direction    = $directions[ $menu_order[2] ];
				$rankings     = self::get_rankings_id();
				$fandom_order = get_post_meta( $post->ID, $rankings, true );
				$query        = str_replace( $menu_order[0], "m.meta_value $direction $fandom_order", $query );
			}

			return $query;
		}

		public static function alter_adjacent_sort( $sort, $post = null ) {
			if ( is_null( $post ) ) {
				return $sort;
			}
			if ( 'fandom250' === $post->post_type ) {
				$sort = str_replace( 'p.post_date', 'm.meta_value+0', $sort );
				if ( stristr( $sort, 'DESC' ) ) {
					$sort = str_replace( 'DESC', 'ASC', $sort );
				} else {
					$sort = str_replace( 'ASC', 'DESC', $sort );
				}
			}

			return $sort;
		}

		public static function ranking_admin_pre_get_posts( \WP_Query $query ) {
			if ( is_admin() ) {
				if ( false == $query->is_post_type_archive( 'fandom250' ) ) {
					return;
				}

				$query->set( 'order', 'asc' );
				$query->set( 'orderby', 'menu_order' );
				if ( isset( $_GET['rankings'] ) && ! ( isset( $_GET['show'] ) ) ) {
					$query->set( 'orderby', 'meta_value_num' );
					$ranking_id = sanitize_text_field( $_GET['rankings'] );
					$query->set( 'meta_key', '_fandom250_order_' . $ranking_id );
				}
			}
		}

		#
		#	Manage Custom Meta Box on Fandom Editor
		#
		public static function rankings_archive_meta_box_context( $post ) {
			do_meta_boxes( null, 'rankings-metabox-holder', $post );
		}

		public static function rankings_archive_meta_box_add() {
			add_meta_box( 'ranking-article-data', __( 'Fandom Archives', 'rankings' ), array(
				'\FanSided\Ranking\Ranking_Manager',
				'rankings_archive_meta_box_output',
			), 'fandom250', 'normal', 'high' );
		}

		public static function rankings_archive_meta_box_output( $post ) {
			global $thepostid;
			$thepostid = $post->ID;
			include( __DIR__ . '/admin/meta-boxes/views/html-data-panel.php' );
		}


		#
		#   Some Private Functions, for me, not you
		#
		private static function rankings_redirect( $slug, $parent ) {

			$slug = preg_replace( '/\-([0-9]{4})$/', '', $slug );

			// Setup Rewrite Rule for Ranking
			if ( empty( $parent ) ) {
				add_rewrite_rule( $slug . '/?$', 'index.php?post_type=fandom250&rankings=' . $slug, 'top' );
				add_rewrite_rule( $slug . '[-|/]([0-9]{4})/?$', 'index.php?post_type=fandom250&rankings=' . $slug . '-$matches[1]', 'top' );
			} else {
				add_rewrite_rule( $slug . '/?$', 'index.php?post_type=fandom250&rankings=' . $slug . '-' . date( 'Y' ), 'top' );
				add_rewrite_rule( $slug . '[-|/]([0-9]{4})/?$', 'index.php?post_type=fandom250&rankings=' . $slug . '-$matches[1]', 'top' );
			}

			// Setup Rewrite Rule for Ranking Pagination
			add_rewrite_rule( $slug . '/page/([0-9]{1,})/?', 'index.php?post_type=fandom250&rankings=' . $slug . '&paged=$matches[1]', 'top' );

			// Setup Rewrite Rule for Passing in Ranking Slug to Single Template
			add_rewrite_rule( 'fandom250/([^\/]*)/' . $slug . '/?', 'index.php?post_type=fandom250&fandom250=$matches[1]&name=$matches[1]&rankings=' . $slug, 'top' );

			// Setup Rewrite Rule for Passing in Ranking Year to Single Template
			add_rewrite_rule( 'fandom250/([^\/]*)/([0-9]{4})/?', 'index.php?post_type=fandom250&fandom250=$matches[1]&name=$matches[1]&archive=$matches[2]', 'top' );
		}

		private static function rankings_set( $term_id, $post_ids ) {
			$ranked = 0;
			foreach ( $post_ids as $post_id ) {
				if ( is_numeric( $post_id ) ) {
					$last_spot = Ranking_Manager::count_in_term( $term_id );
					$last_spot = ( empty( $last_spot ) ) ? 1 : ( $last_spot + 1 );
					wp_set_post_terms( $post_id, $term_id, 'rankings', true );
					update_post_meta( $post_id, '_fandom250_order_' . $term_id, $last_spot );
					$ranked ++;
				}
			}

			return $ranked;
		}

		private static function rankings_unset( $term_id, $post_ids ) {
			$unranked = 0;
			foreach ( $post_ids as $post_id ) {
				if ( is_numeric( $post_id ) ) {
					if ( wp_remove_object_terms( $post_id, $term_id, 'rankings' ) ) {
						delete_post_meta( $post_id, '_fandom250_order_' . $term_id );
						$unranked ++;
					}
				}
			}

			return $unranked;
		}

		private static function setup_category_rewrites( $slug ) {
			# Voting
			add_rewrite_rule( $slug . '/fanvote/?', 'index.php?post_type=fandom250&voting=true&' . self::TAXONOMY . '=' . date( 'Y' ), 'top' );
			add_rewrite_rule( $slug . '/fanvote/([^/]+)/?', 'index.php?post_type=fandom250&voting=true&' . self::TAXONOMY . '=$matches[1]-' . date( 'Y' ), 'top' );

			# Paging
			add_rewrite_rule( $slug . '/([0-9]{4})/page/?([0-9]{1,})/?', 'index.php?post_type=fandom250&' . self::TAXONOMY . '=$matches[1]&paged=$matches[2]', 'top' );
			add_rewrite_rule( $slug . '/page/?([0-9]{1,})/?', 'index.php?post_type=fandom250&paged=$matches[1]&' . self::TAXONOMY . '=' . date( 'Y' ), 'top' );
			add_rewrite_rule( $slug . '/([0-9]{4})/([^/]+)/page/?([0-9]{1,})/?', 'index.php?post_type=fandom250&' . self::TAXONOMY . '=$matches[2]-$matches[1]&paged=$matches[3]', 'top' );
			add_rewrite_rule( $slug . '/([^/]+)/page/?([0-9]{1,})/?', 'index.php?post_type=fandom250&' . self::TAXONOMY . '=$matches[1]-' . date( 'Y' ) . '&paged=$matches[2]', 'top' );

			# Filtered
			add_rewrite_rule( $slug . '/([0-9]{4})/([^/]+)/?', 'index.php?post_type=fandom250&rankings=$matches[2]-$matches[1]', 'top' );
			add_rewrite_rule( $slug . '/([^/]+)/([0-9]{4})/?', 'index.php?post_type=fandom250&fandom250=$matches[1]&' . self::TAXONOMY . '=$matches[2]', 'top' );
			add_rewrite_rule( $slug . '/([^/]+)/([^/]+)/?', 'index.php?post_type=fandom250&fandom250=$matches[1]&' . self::TAXONOMY . '=$matches[2]', 'top' );

			# Defaults ( no filter )
			add_rewrite_rule( $slug . '/([0-9]{4})/?', 'index.php?post_type=fandom250&' . self::TAXONOMY . '=$matches[1]', 'top' );
			add_rewrite_rule( $slug . '/([0-9]{4})/?', 'index.php?post_type=fandom250&' . self::TAXONOMY . '=$matches[1]', 'top' );
			add_rewrite_rule( $slug . '/([^/]+)/?', 'index.php?post_type=fandom250&' . self::TAXONOMY . '=$matches[1]-' . date( 'Y' ), 'top' );
			add_rewrite_rule( $slug . '/?', 'index.php?post_type=fandom250&' . self::TAXONOMY . '=' . date( 'Y' ), 'top' );
		}

		/**
		 * If post is called without term set to latest year.
		 *
		 * @param $query
		 *
		 * @return mixed
		 */
		public static function setup_single_rewrites( $query ) {
			$current_ranking = $query->query_vars['rankings'];
			$if_term_exists  = ( false != get_term_by( 'slug', $current_ranking, 'rankings' ) ? true : false );

			// TODO check for post_type
			if ( 'fandom250' === $query->query_vars['post_type']
			     && ! $if_term_exists
			     && ! is_admin()
			     && $query->is_main_query() ) {
				$post_name = preg_replace( '/-[0-9]{4}/', '', $query->query_vars['rankings'] );

				$query->query['fandom250'] = $post_name;
				$query->query['name']      = $post_name;

				$query->query_vars['rankings']  = date( 'Y' );
				$query->query_vars['fandom250'] = $post_name;
				$query->query_vars['name']      = $post_name;

				$query->tax_query            = null;
				$query->is_single            = true;
				$query->is_archive           = false;
				$query->is_tax               = false;
				$query->is_singular          = true;
				$query->is_post_type_archive = false;
			}

			return $query;
		}

		private static function setup_rankings_rewrites() {
			$terms = self::get_all_rankings();
			foreach ( $terms as $term ) {
				self::rankings_redirect( $term->slug, $term->parent );
			}
		}


		#
		#   Helpers
		#
		public static function plugin_url() {
			return untrailingslashit( plugins_url( '/', __FILE__ ) );
		}

		public static function get_rankings_id( $rankings_term = null ) {
			if ( is_null( $rankings_term ) ) {
				if ( isset( $_GET['rankings'] ) ) {
					$rankings_term = $_GET['rankings'];
				} else {
					$rankings_term = get_query_var( 'rankings' );
				}
			}
			if ( $rankings_term = get_term_by( 'slug', sanitize_text_field( $rankings_term ), 'rankings' ) ) {
				$rankings = '_fandom250_order_' . $rankings_term->term_id;
			} else {
				$rankings = '_fandom250_order';
			}

			return $rankings;
		}

		public static function get_all_rankings() {
			global $wpdb;
			$terms = $wpdb->get_results(
				$wpdb->prepare( 'SELECT 
					t.name, t.slug, ta.parent, t.term_id
					FROM wp_229_terms AS t
					INNER JOIN wp_229_term_taxonomy AS ta
					ON ta.term_id = t.term_id
					WHERE ta.taxonomy = %s', self::TAXONOMY )
			);

			return $terms;
		}

		public static function count_in_term( $term_id ) {
			global $wpdb;

			$term_count = $wpdb->get_results(
				$wpdb->prepare( 'SELECT COUNT(meta_key) AS post_count FROM wp_229_postmeta WHERE meta_key = %s', '_fandom250_order_' . $term_id )
			);

			if ( ! is_array( $term_count ) ) {
				return 1;
			}

			return $term_count[0]->post_count;
		}

		/**
		 * @param string $config_slug Custom slug set in Rankings Settings
		 * @param string $year        Year portion of slug of term to be used in URL
		 *
		 * @return string       Full or partial URL | Depends on final use case
		 */
		public static function build_social_link( $config_slug, $year ) {
			$site_url = site_url();

			if ( ! empty( $year ) ) {
				return $site_url . '/' . $config_slug . '/' . $year;
			}

			return $site_url . '/' . $config_slug;
		}

		/**
		 * Return term id for overall ranking
		 * i.e. 'movies-2017' returns the term id for the term 2017.
		 *
		 * @param $ranking_slug
		 *
		 * @return array|false|\WP_Term
		 */
		public static function get_ranking_id_by_slug( $ranking_slug ) {
			preg_match( '/[0-9]{4}/', $ranking_slug, $matches );

			return $matches[0];
		}

		/**
		 * Build a link using the ranking slug which should always be in
		 * the form of `ranking_group-year` (i.e. `entertainment-2016`)
		 * into custom_slug/year/ranking_group
		 *
		 * @param $ranking_slug
		 *
		 * @return string
		 */
		public static function build_archive_link_rel( $ranking_slug ) {
			preg_match( '/[0-9]{4}/', $ranking_slug, $matches );
			$request_year = $matches[0];
			$ranking      = substr( $ranking_slug, 0, - 5 );

			if ( date( 'Y') === $request_year && false === $ranking ) {
				return '/' . Ranking_Manager::$config_slug;
			}

			return '/' . Ranking_Manager::$config_slug . '/' . $request_year . '/' . $ranking;
		}

		public static function get_top_level_terms() {
			return $top_terms = get_terms( array(
				'taxonomy'   => 'rankings',
				'parent'     => 0,
				'hide_empty' => true,
			) );
		}

		/**
		 * Get rankings (terms) attached to single Fandoms
		 *
		 * @param $rankings_id
		 * @param $context
		 *
		 * @return array|int|\WP_Error
		 */
		public static function get_ranking_terms( $rankings_id, $context ) {
			$child_terms = get_terms( array(
				'taxonomy'   => 'rankings',
				'parent'     => $rankings_id,
				'hide_empty' => false,
			) );

			if ( 'fanvote' == $context ) {
				$active_fanvote_terms = array_filter( $child_terms, function( $term ) {
					return ( ! empty( get_option( 'fs_option_' . $term->term_id . '_cpt_fanvote_embed' ) ) );
				} );

				return $active_fanvote_terms;
			}

			return $child_terms;
		}

		public static function get_filtered_categories( $post_id, $ranking_slug, $requested_year ) {
			$filtered_categories = get_the_terms( $post_id, 'rankings' );
			$filtered_categories = array_filter( $filtered_categories, function( $term ) use ( $requested_year, $ranking_slug ) {
				return ( stristr( $term->slug, $requested_year ) && $term->slug !== $ranking_slug );
			} );

			return $filtered_categories;
		}

		/**
		 * @param $rankings_dropdown
		 * @param $fanvote_dropdown
		 * @param $request_year
		 *
		 * @return string
		 */
		public static function build_mobile_top_nav( $rankings_dropdown, $fanvote_dropdown, $request_year ) {
			ob_start(); ?>
			<nav id="ranking-nav-mobile" class="ranking-header-nav-wrap" role="navigation">
				<?php if ( ! empty( $rankings_dropdown ) ) { ?>
					<div id="ranking-nav-toggle" class="button"></div>
				<?php } ?>

				<div class="ranking-logo-mobile">
					<a href="<?php echo '/' . Ranking_Manager::$config_slug ?>">
						<img src="https://cdn.fansided.com/wp-content/assets/site_images/fansided/rankings/Fandom250_nav-logo_colored.svg" />
					</a>
				</div>

				<?php if ( ! empty( $rankings_dropdown ) ) { ?>
					<ul id="ranking-main-menu">
						<li><a href="/<?php echo Ranking_Manager::$config_slug ?>">Full Rankings</a></li>
						<li>
							<a href="/">By Category</a>
							<ul class="submenu">
								<?php
								foreach ( $rankings_dropdown as $term ) {
									$term_name     = preg_replace( '/-[0-9]{4}/', '', $term->slug );
									$fanvote_value = Ranking_Manager::build_social_link( Ranking_Manager::$config_slug, $request_year ) . '/' . $term_name;
									?>
									<li><a class='ranking-category-item' href="<?php echo $fanvote_value ?>"><?php echo $term->name ?></a></li>
								<?php } ?>
							</ul>
						</li>
						<li>
							<a href="/">Fan Vote</a>
							<ul class="submenu">
								<?php
								foreach ( $fanvote_dropdown as $term ) {
									$term_name     = preg_replace( '/-[0-9]{4}/', '', $term->slug );
									$fanvote_value = Ranking_Manager::build_social_link( Ranking_Manager::$config_slug, $request_year ) . '/' . $term_name . '?voting=true'; ?>
									<li><a class='ranking-category-item' href="<?php echo $fanvote_value ?>"><?php echo $term->name ?></a></li>
								<?php } ?>
							</ul>
						</li>
					</ul>
				<?php } ?>
			</nav> <!-- end article section -->

			<?php return ob_get_clean();
		}

		/**
		 * @param $rankings_dropdown
		 * @param $fanvote_dropdown
		 * @param $request_year
		 *
		 * @return string
		 */
		public static function build_header_category_nav( $full_rankings_dropdown, $rankings_dropdown, $fanvote_dropdown, $request_year ) {
			ob_start(); ?>

			<nav id="nav" class="ranking-header-nav-wrap" role="navigation">
				<!--			<div class="ranking-header-links">-->
				<ul>
					<li><a href="/<?php echo Ranking_Manager::$config_slug ?>">Full Rankings</a>
					<ul class="submenu">
						<?php
						foreach ( $full_rankings_dropdown as $term ) {
							$term_name     = preg_replace( '/-[0-9]{4}/', '', $term->slug );
							$ranking_link = site_url() . '/' . Ranking_Manager::$config_slug . '/' . $term->slug;
							?>
							<li><a class='ranking-category-item' href="<?php echo $ranking_link ?>"><?php echo $term->name ?></a></li>
						<?php } ?>
					</ul>
					</li>
					<li>By Category
						<ul class="submenu">
							<?php
							foreach ( $rankings_dropdown as $term ) {
								$term_name     = preg_replace( '/-[0-9]{4}/', '', $term->slug );
								$fanvote_value = Ranking_Manager::build_social_link( Ranking_Manager::$config_slug, $request_year ) . '/' . $term_name;
								?>
								<li><a class='ranking-category-item' href="<?php echo $fanvote_value ?>"><?php echo $term->name ?></a></li>
							<?php } ?>
						</ul>
					</li>
					<li>Fan Vote
						<ul class="submenu">
							<?php
							foreach ( $fanvote_dropdown as $term ) {
								$term_name     = preg_replace( '/-[0-9]{4}/', '', $term->slug );
								$fanvote_value = Ranking_Manager::build_social_link( Ranking_Manager::$config_slug, $request_year ) . '/' . $term_name . '?voting=true'; ?>
								<li><a class='ranking-category-item' href="<?php echo $fanvote_value ?>"><?php echo $term->name ?></a></li>
							<?php } ?>
						</ul>
					</li>
				</ul>
			</nav> <!-- end article section -->

			<?php return ob_get_clean();
		}


		/**
		 * Get [$field] of [$post_meta_topic] like 'vertical' of 'Arizona Cardinals'
		 *
		 * @param        $post_meta_topic
		 * @param string $field
		 *
		 * @return string
		 *
		 */
		public static function topic_data( $post_meta_topic, $field = '' ) {
			$remote_args     = array(
				'wp-rest-cache' => [
					'tag' => 'fsv5,fandom250',
				],
			);
			$fsapi_args      = array(
				'version' => 2,
				'view'    => 'topics/by-label',
			);
			$topic_response  = \FSv5_API_Utility::query( $remote_args, $fsapi_args );
			$filtered_topics = json_decode( $topic_response, true );

			$topic_logo = 'https://cdn.fansided.com/logos/' . $filtered_topics[ $post_meta_topic ]['topic_image_dir'] . '/' . $filtered_topics[ $post_meta_topic ]['team_logo'];
			$vertical   = $filtered_topics[ $post_meta_topic ]['vertical'];
			$topic_dark = $filtered_topics[ $post_meta_topic ]['topic_darker_color'];

			switch ( $field ) {
				case 'logo':
					return '<img class="topic" src="' . $topic_logo . '" />';
					break;
				case 'vertical':
					return $vertical;
					break;
				case 'topic_dark':
					return $topic_dark;
				default:
					return '';
			}

		}

	}
}




