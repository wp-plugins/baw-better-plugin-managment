<?php
/*
Plugin Name: Baw Better Plugin Managment
Description: 2 new tabs "Recently Updated" and "Recently Deleted" + automatically deactivate plugins to be deleted.
Author: Julio Potier
Author URI: http://boiteaweb.fr
Plugin URI: http://boiteaweb.fr/better-plugin-managment-ameliorez-interface-plugins-8105.html
Version: 1.3
Licence: GPLv2
*/

if ( is_admin() ):

add_action( 'admin_init', 'bawbpm_l10n' );
function bawbpm_l10n() {
	load_plugin_textdomain( 'bawbpm', '', dirname( plugin_basename( __FILE__ ) ) . '/lang' );
}

add_filter( 'wp_parse_str', 'bawbpm_hack_wp_parse_str_to_hack_plugin_status' );
function bawbpm_hack_wp_parse_str_to_hack_plugin_status( $array ) {
	if ( is_admin() && isset( $GLOBALS['current_screen'], $_REQUEST['plugin_status'] ) && 'plugins' == $GLOBALS['current_screen']->id 
		&& in_array( $_REQUEST['plugin_status'], array( 'recently_updated', 'recently_deleted' ) ) 
	) {
		$GLOBALS['status'] = $_REQUEST['plugin_status'];
	}
	return $array;
}

add_action( 'upgrader_process_complete', 'bawbpm_set_recently_updated', 10, 2 );
function bawbpm_set_recently_updated( $this, $hook_extra ) {
	if ( isset( $hook_extra['action'], $hook_extra['type'] ) && 
		'update' == $hook_extra['action'] && 'plugin' == $hook_extra['type'] ) {
		$recently_updated = get_option( 'recently_updated' );
		if ( isset( $hook_extra['plugin'] ) ) {
			$recently_updated[ $hook_extra['plugin'] ] = time();
		} elseif ( isset( $hook_extra['plugins'] ) ) {
			$recently_updated = array_fill_keys( $hook_extra['plugins'], time() );
		}
		update_option( 'recently_updated', $recently_updated );
	}
}

add_action( 'admin_init', 'bawbpm_pre_plugins_delete_result' );
function bawbpm_pre_plugins_delete_result() {
	add_filter( 'pre_set_transient_plugins_delete_result_' . $GLOBALS['current_user']->ID, 'bawbpm_pre_set_transient_plugins_delete_result_user' );
}

function bawbpm_pre_set_transient_plugins_delete_result_user( $value ) {
	if ( true === $value ) {
		$recently_deleted = get_option( 'recently_deleted' );
		$plugins = isset( $_REQUEST['checked'] ) ? (array) $_REQUEST['checked'] : array(); 
		$recently_deleted = array_unique( array_merge( (array) $recently_deleted, array_fill_keys( $plugins, time() ) ) );
		update_option( 'recently_deleted', $recently_deleted );
		$recently_updated = get_option( 'recently_updated' );
		foreach ( $recently_deleted as $plugin_file => $dummy ) {
			if ( isset( $recently_updated[ $plugin_file ] ) ) {
				unset( $recently_updated[ $plugin_file ] );
			}
		}
		update_option( 'recently_updated', $recently_updated );
	}
	return $value;
}

// add_action( 'admin_head-plugins.php', 'bawbpm_add_class_BAW_Plugins_List_Table' );
function bawbpm_add_class_BAW_Plugins_List_Table() {

	class BAW_Plugins_List_Table extends WP_Plugins_List_Table {

		function __construct( $args = array() ) {
			global $status, $page;

			parent::__construct( array(
				'plural' => 'plugins',
				'screen' => isset( $args['screen'] ) ? $args['screen'] : null,
			) );

			$status = 'all';
			if ( isset( $_REQUEST['plugin_status'] ) && 
				in_array( $_REQUEST['plugin_status'], 
					array( 'active', 'inactive', 'recently_activated', 'recently_updated', 'recently_deleted', 'upgrade', 'mustuse', 'dropins', 'search' ) 
					) 
				) {
				$status = $_REQUEST['plugin_status'];
			}

			if ( isset( $_REQUEST['s'] ) ) {
				$_SERVER['REQUEST_URI'] = add_query_arg( 's', wp_unslash( $_REQUEST['s'] ) );
			}

			$page = $this->get_pagenum();
		}

		function prepare_items() {
			global $status, $plugins, $totals, $page, $orderby, $order, $s;

			wp_reset_vars( array( 'orderby', 'order', 's' ) );

			/**
			 * Filter the full array of plugins to list in the Plugins list table.
			 *
			 * @since 3.0.0
			 *
			 * @see get_plugins()
			 *
			 * @param array $plugins An array of plugins to display in the list table.
			 */
			$plugins = array(
				'all' => apply_filters( 'all_plugins', get_plugins() ),
				'search' => array(),
				'active' => array(),
				'inactive' => array(),
				'recently_activated' => array(),
				'recently_updated' => array(),
				'recently_deleted' => array(),
				'upgrade' => array(),
				'mustuse' => array(),
				'dropins' => array()
			);

			$screen = $this->screen;

			if ( ! is_multisite() || ( $screen->in_admin( 'network' ) && current_user_can( 'manage_network_plugins' ) ) ) {

				/**
				 * Filter whether to display the advanced plugins list table.
				 *
				 * There are two types of advanced plugins - must-use and drop-ins -
				 * which can be used in a single site or Multisite network.
				 *
				 * The $type parameter allows you to differentiate between the type of advanced
				 * plugins to filter the display of. Contexts include 'mustuse' and 'dropins'.
				 *
				 * @since 3.0.0
				 *
				 * @param bool   $show Whether to show the advanced plugins for the specified
				 *                     plugin type. Default true.
				 * @param string $type The plugin type. Accepts 'mustuse', 'dropins'.
				 */
				if ( apply_filters( 'show_advanced_plugins', true, 'mustuse' ) ) {
					$plugins['mustuse'] = get_mu_plugins();
				}

				/** This action is documented in wp-admin/includes/class-wp-plugins-list-table.php */
				if ( apply_filters( 'show_advanced_plugins', true, 'dropins' ) )
					$plugins['dropins'] = get_dropins();

				if ( current_user_can( 'update_plugins' ) ) {
					$current = get_site_transient( 'update_plugins' );
					foreach ( (array) $plugins['all'] as $plugin_file => $plugin_data ) {
						if ( isset( $current->response[ $plugin_file ] ) ) {
							$plugins['all'][ $plugin_file ]['update'] = true;
							$plugins['upgrade'][ $plugin_file ] = $plugins['all'][ $plugin_file ];
						}
					}
				}
			}

			set_transient( 'plugin_slugs', array_keys( $plugins['all'] ), DAY_IN_SECONDS );

			if ( ! $screen->in_admin( 'network' ) ) {
				$recently_activated = get_option( 'recently_activated', array() );

				foreach ( $recently_activated as $key => $time ) {
					if ( $time + WEEK_IN_SECONDS < time() ) {
						unset( $recently_activated[$key] );
					}
				}
				update_option( 'recently_activated', $recently_activated );
			}

			$plugin_info = get_site_transient( 'update_plugins' );

			if ( ! $screen->in_admin( 'network' ) ) {
				$recently_updated = get_option( 'recently_updated', array() );

				foreach ( $recently_updated as $key => $time ) {
					if ( $time + WEEK_IN_SECONDS < time() ) {
						unset( $recently_updated[$key] );
					}
				}
				update_option( 'recently_updated', $recently_updated );
			}

			if ( ! $screen->in_admin( 'network' ) ) {
				$recently_deleted = get_option( 'recently_deleted', array() );

				foreach ( $recently_deleted as $key => $time ) {
					if ( $time + WEEK_IN_SECONDS < time() ) {
						unset( $recently_deleted[$key] );
					}
				}
				update_option( 'recently_deleted', $recently_deleted );
			}

			foreach ( (array) $plugins['all'] as $plugin_file => $plugin_data ) {
				// Extra info if known. array_merge() ensures $plugin_data has precedence if keys collide.
				if ( isset( $plugin_info->response[ $plugin_file ] ) ) {
					$plugins['all'][ $plugin_file ] = $plugin_data = array_merge( (array) $plugin_info->response[ $plugin_file ], $plugin_data );
					// Make sure that $plugins['upgrade'] also receives the extra info since it is used on ?plugin_status=upgrade
					if ( isset( $plugins['upgrade'][ $plugin_file ] ) ) {
						$plugins['upgrade'][ $plugin_file ] = $plugin_data = array_merge( (array) $plugin_info->response[ $plugin_file ], $plugin_data );
					}

				} elseif ( isset( $plugin_info->no_update[ $plugin_file ] ) ) {
					$plugins['all'][ $plugin_file ] = $plugin_data = array_merge( (array) $plugin_info->no_update[ $plugin_file ], $plugin_data );
					// Make sure that $plugins['upgrade'] also receives the extra info since it is used on ?plugin_status=upgrade
					if ( isset( $plugins['upgrade'][ $plugin_file ] ) ) {
						$plugins['upgrade'][ $plugin_file ] = $plugin_data = array_merge( (array) $plugin_info->no_update[ $plugin_file ], $plugin_data );
					}
				}				
				// Filter into individual sections
				if ( is_multisite() && ! $screen->in_admin( 'network' ) && is_network_only_plugin( $plugin_file ) && ! is_plugin_active( $plugin_file ) ) {
					// On the non-network screen, filter out network-only plugins as long as they're not individually activated
					unset( $plugins['all'][ $plugin_file ] );
				} elseif ( ! $screen->in_admin( 'network' ) && is_plugin_active_for_network( $plugin_file ) ) {
					// On the non-network screen, filter out network activated plugins
					unset( $plugins['all'][ $plugin_file ] );
				} elseif ( ( ! $screen->in_admin( 'network' ) && is_plugin_active( $plugin_file ) )
					|| ( $screen->in_admin( 'network' ) && is_plugin_active_for_network( $plugin_file ) ) ) {
					// On the non-network screen, populate the active list with plugins that are individually activated
					// On the network-admin screen, populate the active list with plugins that are network activated
					$plugins['active'][ $plugin_file ] = $plugin_data;
					if ( ! $screen->in_admin( 'network' ) && isset( $recently_updated[ $plugin_file ] ) ) {
						// On the non-network screen, populate the recently activated list with plugins that have been recently activated
						$plugins['recently_updated'][ $plugin_file ] = $plugin_data;
					}
				} else {
					if ( ! $screen->in_admin( 'network' ) && isset( $recently_activated[ $plugin_file ] ) ) {
						// On the non-network screen, populate the recently activated list with plugins that have been recently activated
						$plugins['recently_activated'][ $plugin_file ] = $plugin_data;
					}
					// Populate the inactive list with plugins that aren't activated
					$plugins['inactive'][ $plugin_file ] = $plugin_data;
					if ( ! $screen->in_admin( 'network' ) && isset( $recently_updated[ $plugin_file ] ) ) {
						// On the non-network screen, populate the recently activated list with plugins that have been recently activated
						$plugins['recently_updated'][ $plugin_file ] = $plugin_data;
					}
				}
				if ( ! $screen->in_admin( 'network' ) ) {
					$recently_deleted = get_option( 'recently_deleted', array() );
					if ( isset( $plugins['active'][ $plugin_file ] ) || isset( $plugins['inactive'][ $plugin_file ] ) ) {
						unset( $recently_deleted[ $plugin_file ] );
					}
					update_option( 'recently_deleted', $recently_deleted );
				}
			}
			foreach ( (array) $recently_deleted as $plugin_file => $plugin_data ) {
				if ( ! function_exists( 'plugins_api' ) ) {
					require ABSPATH . '/wp-admin/includes/plugin-install.php';
				}
				$api = plugins_api( 'plugin_information', array( 'slug' => dirname( $plugin_file ) ) );
				if ( $api ) {
					$_data = array( 	'Name' => $api->name, 
									'PluginURI' => 'https://wordpress.org/plugins/' . dirname( $plugin_file ) . '/',
									'Version' => $api->version,
									'Description' => substr( preg_replace( '/(\S)\1{3,}/', '', strip_tags( $api->sections['description'] ) ), 0, 120 ) . '...',
									'Author' => strip_tags( $api->author ),
									'AuthorURI' => isset( $api->homepage ) ? $api->homepage : $api->author_profile,
									'WPRepo' => true,

								);
				} else {
					$_data = array( 'Name' => dirname( $plugin_file ), 'WPRepo' => false );
				}
				$plugins['recently_deleted'][ $plugin_file ] = $_data;
			}
			if ( $s ) {
				$status = 'search';
				$plugins['search'] = array_filter( $plugins['all'], array( $this, '_search_callback' ) );
			}

			$totals = array();
			foreach ( $plugins as $type => $list ) {
				$totals[ $type ] = count( $list );
			}

			if ( empty( $plugins[ $status ] ) && ! in_array( $status, array( 'all', 'search' ) ) ) {
				$status = 'all';
			}

			$this->items = array();
			foreach ( $plugins[ $status ] as $plugin_file => $plugin_data ) {
				// Translate, Don't Apply Markup, Sanitize HTML
				$this->items[$plugin_file] = _get_plugin_data_markup_translate( $plugin_file, $plugin_data, false, true );
			}

			$total_this_page = $totals[ $status ];

			if ( ! $orderby ) {
				$orderby = 'Name';
			} else {
				$orderby = ucfirst( $orderby );
			}

			$order = strtoupper( $order );

			uasort( $this->items, array( $this, '_order_callback' ) );

			$plugins_per_page = $this->get_items_per_page( str_replace( '-', '_', $screen->id . '_per_page' ), 999 );

			$start = ( $page - 1 ) * $plugins_per_page;

			if ( $total_this_page > $plugins_per_page ) {
				$this->items = array_slice( $this->items, $start, $plugins_per_page );
			}

			$this->set_pagination_args( array(
				'total_items' => $total_this_page,
				'per_page' => $plugins_per_page,
			) );
		} 
	}
	if ( ! isset( $_REQUEST['action'] ) ) {
		global $wp_list_table;
		$wp_list_table = new BAW_Plugins_List_Table;
		$wp_list_table->prepare_items();
	}
}

add_filter( 'plugin_action_links', 'bawbpm_new_plugin_actions', PHP_INT_MAX, 4 );
function bawbpm_new_plugin_actions( $actions, $plugin_file, $plugin_data, $context ) {
	if ( current_user_can( 'install_plugins' ) && 'recently_deleted' == $context && $plugin_data['WPRepo'] ) {
		$link = sprintf( '<a href="%s">%s</a>', wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . dirname( $plugin_file ) ), 'install-plugin_' . dirname( $plugin_file  ) ), __( 'Run the install' ) );
		$actions = array( 'reinstall' => $link );
	}
	return $actions;
}

add_action( 'admin_footer-plugins.php', 'bawbpm_add_clear_list_buttons' );
function bawbpm_add_clear_list_buttons() {
	global $current_screen, $status;
	if ( ! $current_screen->in_admin( 'network' ) && in_array( $status, array( 'recently_updated', 'recently_deleted' ) ) ) {
		$format = '<a href="%s" class="button button-secondary" style="display:inline-block;margin:1px 8px 0 0" id="link_%s">%s</a>';
		$link = wp_nonce_url( admin_url( 'admin-post.php?action=' . $status ), $status );
		printf( $format, $link, $status, __( 'Clear List' ) );
	?>
	<script>
	jQuery(document).ready( function($) {
		$('#link_<?php echo $status; ?>').appendTo('div.alignleft.actions');
	});
	</script>
	<?php
	}
}

add_action( 'admin_post_recently_updated', 'bawbpm_admin_post_clear_lists' );
add_action( 'admin_post_recently_deleted', 'bawbpm_admin_post_clear_lists' );
function bawbpm_admin_post_clear_lists() {
	$action = $_GET['action'];
	if ( ! is_network_admin() && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], $action ) ) {
		update_option( $action, array() );
		wp_redirect( self_admin_url( 'plugins.php?plugin_status=all' ) );
		die();
	}
}

add_filter( 'views_plugins', 'bawbpm_add_updated_plugin_view' ); 
function bawbpm_add_updated_plugin_view( $views ) {
	global $status;
	$type = 'recently_updated';
	$count = count( (array) get_option( $type ) );
	if ( $count ) {
		$text = _x( 'Recently Updated', 'Plugin', 'bawbpm' ) . sprintf( ' <span class="count">(%s)</span>', number_format_i18n( $count ) );
		$link = sprintf( '<a href="%s"%s>%s</a>',
					add_query_arg( 'plugin_status', $type, admin_url( 'plugins.php' ) ),
					$type == $status ? ' class="current"' : '',
					$text
				); 
		$views[ $type ] = $link;
	}
	$type = 'recently_deleted';
	$count = count( (array) get_option( $type ) );
	if ( $count ) {
		$text = _x( 'Recently Deleted', 'Plugin', 'bawbpm' ) . sprintf( ' <span class="count">(%s)</span>', number_format_i18n( $count ) );
		$link = sprintf( '<a href="%s"%s>%s</a>',
					add_query_arg( 'plugin_status', $type, admin_url( 'plugins.php' ) ),
					$type == $status ? ' class="current"' : '',
					$text
				); 
		$views[ $type ] = $link;
	}
	return $views;
}

function bawbpm_add_plugins_deactivated_notice() {
	?><div id="message" class="updated"><p><?php _e( 'Selected plugins <strong>deactivated</strong>.' ); ?></p></div><?php
}
function bawbpm_add_plugin_deactivated_notice() {
	?><div id="message" class="updated"><p><?php _e( 'Plugin <strong>deactivated</strong>.' ) ?></p></div><?php
}

add_action( 'load-plugins.php', 'bawbpm_action_force_uninstall' );
function bawbpm_action_force_uninstall() { //// admin-action ?
	if ( isset( $_REQUEST['_wpnonce'], $_REQUEST['checked'], $_REQUEST['action'] ) 
		&& 'delete-selected' == $_REQUEST['action']
		&& is_array( $_REQUEST['checked'] ) 
		&& ! empty( $_REQUEST['checked'] ) 
		&& current_user_can( 'delete_plugins' ) 
		&& wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-plugins' ) 
	) {
		$plugins = array_filter( $_REQUEST['checked'], 'is_plugin_inactive' );
		deactivate_plugins( $_REQUEST['checked'], false, is_network_admin() );
		if ( empty( $plugins ) ) { 
			if ( 1 === count( $_REQUEST['checked'] ) ) {
				add_action( 'admin_notices', 'bawbpm_add_plugin_deactivated_notice' );
			} else {
				add_action( 'admin_notices', 'bawbpm_add_plugins_deactivated_notice' );
			}
		}
	}
}

add_filter( 'plugin_action_links', 'bawbpm_always_delete', 11, 4 );
function bawbpm_always_delete( $actions, $plugin_file, $plugin_data, $context ) {
	if ( current_user_can( 'delete_plugins' ) ) {
		global $page, $s;
		$actions['delete'] = '<a href="' . wp_nonce_url('plugins.php?action=delete-selected&amp;checked[]=' . $plugin_file . '&amp;plugin_status=' . $context . '&amp;paged=' . $page . '&amp;s=' . $s, 'bulk-plugins') . '" title="' . esc_attr__('Delete this plugin') . '" class="delete">' . __('Delete') . '</a>';
	}
	return $actions;
}

endif;