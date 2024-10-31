<?php
/*
 * Plugin Name: Pagelog
 * Plugin URI:
 * Description: Registers all pageloads
 * Version: 1.8
 * Author: opajaap
 * Author URI:
 * Text Domain: pagelog
 * Domain Path: /languages
*/

global $wpdb;

define( 'PAGELOG', $wpdb->prefix . 'pagelog' );

// Plugin activation
function pagelog_activate_plugin() {
global $wpdb;

	$pagelog = 	"CREATE TABLE " . PAGELOG . " (
					id bigint(20) NOT NULL AUTO_INCREMENT,
					page bigint(20) NOT NULL,
					timestamp tinytext NOT NULL,
					ip tinytext NOT NULL,
					user tinytext NOT NULL,
					PRIMARY KEY  (id),
					KEY timestampkey (timestamp(10)),
					KEY pagekey (page),
					KEY userkey (user(10)),
					KEY ipkey (ip(20))
				) DEFAULT CHARACTER SET utf8;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta( $pagelog );
}
register_activation_hook( __FILE__, 'pagelog_activate_plugin' );

// Add to menu
function pagelog_add_admin() {
    add_management_page( __('PageLog', 'pagelog'), __('Page Log', 'pagelog'), 'administrator', 'pagelog', 'pagelog_proc' );
}
add_action( 'admin_menu', 'pagelog_add_admin' );

// Add scripts
function pagelog_add_scripts() {

	if ( is_admin() ) {
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-core');
	}
}
add_action( 'wp_enqueue_scripts', 'pagelog_add_scripts' );

// Load language
function pagelog_load_language() {
	load_plugin_textdomain( 'pagelog', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );
}
add_action( 'init', 'pagelog_load_language' );

// Main admin page procedure
function pagelog_proc() {
global $pagelog_display_period;
global $pagelog_void_ips;
global $wpdb;

	// Submit?
	if ( isset( $_POST['submit'] ) ) {
		if ( ! wp_verify_nonce( $_POST['pagelog-nonce'], 'pagelog' ) ) {
			wp_die( 'Security check failure' );
		}
		if ( isset( $_POST['void_ips'] ) ) {
			$void_ips = pagelog_sanitize_ips( $_POST['void_ips'] );
			update_option( 'pagelog_void_ips', $void_ips );
		}
		if ( isset( $_POST['display_period'] ) ) {
			$dp = pagelog_sanitize_period( $_POST['display_period'] );
			update_option( 'pagelog_display_period', $dp );
		}
		if ( isset( $_POST['save_period'] ) ) {
			$sp = pagelog_sanitize_period( $_POST['save_period'] );
			update_option( 'pagelog_save_period', $sp );
		}
		if ( isset( $_POST['show'] ) ) {
			$s = $_POST['show'];
			if ( in_array( $s, array( 'yes', 'login', 'no' ) ) ) {
				update_option( 'pagelog_show', $s );
			}
		}
		if ( isset( $_POST['size'] ) ) {
			$s = $_POST['size'];
			if ( $s === strval( intval( $s ) ) && $s > '6' ) {
				update_option( 'pagelog_size', $s );
			}
		}
		$tab = '1';
		while ( $tab < '6' ) {
			if ( isset( $_POST['tab'.$tab.'check'] ) ) {
				update_option( 'pagelog_tab'.$tab.'check', '1' );
			}
			else {
				update_option( 'pagelog_tab'.$tab.'check', '0' );
			}
			$tab++;
		}
		$tab = '1';
		while ( $tab < '7' ) {
			if ( isset( $_POST['pagelog_edit_links_'.$tab.'_check'] ) ) {
				update_option( 'pagelog_edit_links_'.$tab.'_check', '1' );
			}
			else {
				update_option( 'pagelog_edit_links_'.$tab.'_check', '0' );
			}
			$tab++;
		}
		if ( isset( $_POST['add_shortcodes_pages'] ) ) {
			$pages = $wpdb->get_results( "SELECT ID, post_content 
										  FROM {$wpdb->prefix}posts
										  WHERE post_status = 'publish' 
										  AND post_type = 'page'", ARRAY_A );
			if ( $pages ) {
				foreach( $pages as $page ) {
					$txt = $page['post_content'];
					if ( strpos( $page['post_content'], '[pagelog]' ) === false ) {
						$txt .= '[pagelog]';
						$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}posts
													   SET post_content = %s 
													   WHERE ID = %s", $txt, $page['ID'] ) );
					}
				}
			}
		}
		if ( isset( $_POST['add_shortcodes_posts'] ) ) {
			$pages = $wpdb->get_results( "SELECT ID, post_content 
										  FROM {$wpdb->prefix}posts
										  WHERE post_status = 'publish' 
										  AND post_type = 'post'", ARRAY_A );
			if ( $pages ) {
				foreach( $pages as $page ) {
					$txt = $page['post_content'];
					if ( strpos( $page['post_content'], '[pagelog]' ) === false ) {
						$txt .= '[pagelog]';
						$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}posts
													   SET post_content = %s 
													   WHERE ID = %s", $txt, $page['ID'] ) );
					}
				}
			}
		}

	}

	// Format void ips for use in query
	$pagelog_void_ips = get_option( 'pagelog_void_ips', false );
	if ( $pagelog_void_ips ) {
		$pagelog_void_ips = "'" . implode( "','", explode( ',', $pagelog_void_ips ) ) . "'";
	}
	else {
		$pagelog_void_ips = "'0'";
	}

	// See if obsolete logs must be removed
	$save_period = get_option( 'pagelog_save_period', 0 );
	if ( $save_period ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pagelog
									   WHERE timestamp < %d", time() - $save_period ) );
	}

	// Get display time
	$pagelog_display_period = get_option( 'pagelog_display_period', 0 );

	// CSS
	echo
	'<style>

		.wrap th, .wrap td {
			padding:0 4px 0;
		}

		.wrap div table {
			border:1px solid #72777c;
			position:relative;
			z-index:1;
			top:-1px;
			min-width:300px;
		}

		.tabs li {
			list-style:none;
			display:inline;
			margin-top:0;
			margin-left:3px;
			margin-right:3px;
			margin-bottom:0;
			position:relative;
			z-index:2;
		}

		.tabs a {
			padding:5px 10px;
			display:inline-block;
			background:#ddd;
			text-decoration:none;
			color:#72777c;
			border:1px solid #72777c;
			border-top-left-radius:6px;
			border-top-right-radius:6px;
		}

		.tabs a.active {
			background:#eee;
			font-weight:bold;
			color:rgb(35,40,45);
			z-index:1000;
			border-bottom:1px solid #eee;
		}

	</style>';

	if ( isset( $_GET['tab'] ) ) {
		$active = min( '9', max( '1', strval( intval( $_GET['tab'] ) ) ) );
	}
	else {
		$active = get_option( 'pagelog_last_tab', '7' );
	}

	// On page javascript
	echo
	'<script type="text/javascript" >
		jQuery(document).ready(function(){
			
			jQuery( "#label' . $active .'" ).addClass("active");
			
		});
	</script>';

	// HTML: Open wrapper
	echo '<div class="wrap">';

	// The Title
	echo 	'<h1>' . 
				__( 'Page Log statistics', 'pagelog' ) . 
				' ' .
				'<span style="font-size:13px;font-weight:normsl;">' . 
					__( 'at:', 'pagelog' ) . ' ' . pagelog_local_time() .
				'</span>' .
			'</h1>';

	// The tabs
	$link = admin_url( 'tools.php' ) . '?page=pagelog&tab=';
	echo 		'<ul class="tabs" style="margin-bottom:0;" >' .
					( get_option( 'pagelog_tab1check', true ) ? '<li><a id="label1" href="' . $link . '1" >' . esc_html( __( 'By frequency', 'pagelog' ) ) . '</a></li>' : '' ) .
					( get_option( 'pagelog_tab2check', true ) ? '<li><a id="label2" href="' . $link . '2" >' . esc_html( __( 'By Permalink', 'pagelog' ) ) . '</a></li>' : '' ) .
					( get_option( 'pagelog_tab3check', true ) ? '<li><a id="label3" href="' . $link . '3" >' . esc_html( __( 'Most recent', 'pagelog' ) ) . '</a></li>' : '' ) .
					( get_option( 'pagelog_tab4check', true ) ? '<li><a id="label4" href="' . $link . '4" >' . esc_html( __( 'By user', 'pagelog' ) ) . '</a></li>' : '' ) .
					( get_option( 'pagelog_tab5check', true ) ? '<li><a id="label5" href="' . $link . '5" >' . esc_html( __( 'By IP', 'pagelog' ) ) . '</a></li>' : '' ) .
					( get_option( 'pagelog_tab6check', true ) ? '<li><a id="label6" href="' . $link . '6" >' . esc_html( __( 'History', 'pagelog' ) ) . '</a></li>' : '' ) .
																'<li><a id="label7" href="' . $link . '7" >' . esc_html( __( 'Settings', 'pagelog' ) ) . '</a></li>' .
				'</ul>';

	// Pages that show up in the logs
	if ( $pagelog_display_period ) {
	$log_page_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT page
													 FROM {$wpdb->prefix}pagelog
													 WHERE timestamp > %d", time() - $pagelog_display_period ) );
	}
	else {
	$log_page_ids = $wpdb->get_col( "SELECT DISTINCT page
									 FROM {$wpdb->prefix}pagelog" );
	}
								
	// Pages that have [pagelog] in the text
	$sc_page_ids = $wpdb->get_col( "SELECT ID
								    FROM {$wpdb->prefix}posts
									WHERE post_content LIKE '%[pagelog]%'" );

	// Relevant page ids
	$page_ids = array_intersect( $log_page_ids, $sc_page_ids );
	
	// Get the page info we will need
	if ( $page_ids ) {
		$pages = $wpdb->get_results( "SELECT ID, post_title
									  FROM {$wpdb->prefix}posts
									  WHERE post_status = 'publish'
									  AND ID IN ('" . implode( "','", $page_ids ) . "')
									  ORDER BY post_title ASC", ARRAY_A );
	}
	else {
		$pages = false;
	}

	// Make an array of pages where the title is replaced by the relative permalink
	if ( $pages ) {
		$permalinks = $pages;
		foreach( array_keys( $permalinks ) as $key ) {
			$permalinks[$key]['post_title'] = str_replace( get_home_url(), '', get_permalink( $permalinks[$key]['ID'] ) );
		}
		$permalinks = pagelog_array_sort( $permalinks, 'post_title' );
	}
	else {
		$permalinks = false;
	}

	// Tab 1: By page name
	if ( $active == '1' ) {
		echo 
		'<div id="tab1" >';
			pagelog_by_frequency( $pages );
		echo
		'</div>';
	}

	// Tab 2: By permalink
	if ( $active == '2' ) {
		echo 
		'<div id="tab2" >';
			pagelog_by_permalink( $permalinks );
		echo
		'</div>';
	}

	// Tab 3: Most recent
	if ( $active == '3' ) {
		echo 
		'<div id="tab3" >';
			pagelog_by_recent( $pages );
		echo
		'</div>';
	}

	// Tab 4: By user (logged in)
	if ( $active == '4' ) {
		echo 
		'<div id="tab4" >';
			pagelog_by_user( $pages );
		echo
		'</div>';
	}

	// Tab 5: By ip (logged out)
	if ( $active == '5' ) {
		echo 
		'<div id="tab5" >';
			pagelog_by_ip( $pages );
		echo
		'</div>';
	}

	// Tab 6: History
	if ( $active == '6' ) {
		echo 
		'<div id="tab6" >';
			pagelog_history( $pages );
		echo
		'</div>';
	}
	
	// Tab 7: Settings
	if ( $active == '7' ) {
		echo 
		'<div id="tab9" style="max-width:600px;" >';
			pagelog_settings();
		echo
		'</div>';
	}

	$any = $wpdb->get_var( "SELECT COUNT(*)
							FROM {$wpdb->prefix}posts
							WHERE post_status = 'publish'
							AND post_content LIKE '%[pagelog%'" );
	if ( ! $any ) {
		echo 	'<span style="color:red" >' .
					esc_html( __( 'There are no posts/pages with the required shortcode [pagelog] in the text', 'pagelog' ) ) .
				'</span>';
	}
	
	// Close wrapper
	echo '</div>';
	
	// Reload after 5 minutes
	echo 	'<script type="text/javascript" >' .
				'setTimeout( function() { location.reload(true) }, 300000 )' .
			'</script>';
			
	// Update last tab
	update_option( 'pagelog_last_tab', $active );
}

// Shortcode handler
function pagelog_shortcode_handler( $xatts ) {
global $wpdb;
global $post;
static $been_here;

	$atts = shortcode_atts( array(
									'size' 		=> get_option( 'pagelog_size', '10' ),
									'show' 		=> get_option( 'pagelog_show', 'yes'),
								),
							$xatts
						);

	$page 		= $post->ID;
	$timestamp 	= time();
	$remoteaddr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0';
	$ip 		= pagelog_sanitize_ips( $remoteaddr );

	if ( is_user_logged_in() ) {
		$user = wp_get_current_user();
		$user = $user->user_login;
	}
	else {
		$user = '';
	}

	// To avoid duplicates when the frontpage with a list of posts is displayed; only score the top post.
	if ( ! $been_here ) {
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}pagelog
													(
														page,
														timestamp,
														ip,
														user
													)
											VALUES ( %s, %s, %s, %s )",

													$page,
													$timestamp,
													$ip,
													$user
													)
					);
		$been_here = true;
	}
/*hbi*/ 
	if ( 	$atts['show'] == 'yes' || 
			( $atts['show'] == 'login' && is_user_logged_in() ) ||
			( $atts['show'] == 'admin' && current_user_can( 'administrator' ) )
			) {
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `" . PAGELOG . "` WHERE `page` = %s", $page ) );
		if ( is_user_logged_in() ) {
			$mycount = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `" . PAGELOG . "` WHERE `page` = %s AND `user` = %s", $page, $user ) );
		}
		else {
			$mycount = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `" . PAGELOG . "` WHERE `page` = %s AND `user` = '' AND `ip` = %s", $page, $ip ) );
		}
		$size = intval( $atts['size'] );
		return '<div style="clear:both;font-size:' . $size . 'px;" >' . $count . '(' . $mycount . ')' . '</div>';
	}
	else {
		return '';
	}
}

// Init
add_shortcode( 'pagelog', 'pagelog_shortcode_handler' );

// Sort function
function pagelog_array_sort( $array, $on, $order = SORT_ASC ) {

    $new_array = array();
    $sortable_array = array();

    if ( count( $array ) > 0 ) {
        foreach ( $array as $k => $v ) {
            if ( is_array( $v ) ) {
                foreach ( $v as $k2 => $v2 ) {
                    if ( $k2 == $on ) {
                        $sortable_array[$k] = $v2;
                    }
                }
            } else {
                $sortable_array[$k] = $v;
            }
        }

        switch ( $order ) {
            case SORT_ASC:
                asort( $sortable_array );
            break;
            case SORT_DESC:
                arsort( $sortable_array );
            break;
        }

        foreach ( array_keys( $sortable_array ) as $k ) {
            $new_array[] = $array[$k];
        }
    }

	return $new_array;
}

// Tab 1 html
function pagelog_by_frequency( $pages ) {
global $pagelog_display_period;
global $pagelog_void_ips;
global $wpdb;

	// Construct the array with data. array( 'ID' => $page['ID'], 'post_title' => $page['post_title'], 'loggedin' => $loggedin, 'loggedout' => $total - $loggedin, 'total' => $total );
	$data = array();
	$total_general = 0;
	
	if ( $pages ) foreach ( $pages as $page ) {
		$total = $wpdb->get_var( 	"SELECT COUNT(*) " .
									"FROM `" . PAGELOG . "` " .
									"WHERE `page` = '" . $page['ID'] . "' " .
									( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
									"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) " );
									
		$loggedin = $wpdb->get_var( "SELECT COUNT(*) " .
									"FROM `" . PAGELOG . "` " .
									"WHERE `page` = '" . $page['ID'] . "' " .
									"AND `user` <> '' " .
									( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
									"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) " );
									
		if ( $total ) {
			$data[] = array( 'ID' => $page['ID'], 'post_title' => $page['post_title'], 'loggedin' => $loggedin, 'loggedout' => $total - $loggedin, 'total' => $total );
			$total_general += $total;
		}
	}
	$data = pagelog_array_sort( $data, 'total', SORT_DESC );
	
	echo 	'<table style="width:600px;" >' .
				'<thead>' .
					'<tr>' .
						'<th style="width:50%" >' .
							esc_html( __( 'Page', 'pagelog' ) ) .
						'</th>' .
						'<th style="width:50%" >' .
							esc_html( __( 'Total', 'pagelog' ) ) . ' (' . $total_general . ')' .
						'</th>' .
					'</tr>' .
				'</thead>' .
				'<tbody>';

				$show_edit_links = get_option( 'pagelog_edit_links_1_check', false );
				
				if ( ! empty( $data ) ) foreach( $data as $item ) {
					$li_perc = sprintf( '%5.2f', 100 * $item['loggedin'] / $total_general ) . '%';
					$lo_perc = sprintf( '%5.2f', 100 * $item['loggedout'] / $total_general ) . '%';
					$li_size = sprintf( '%5.2f', 99 * $item['loggedin'] / $data[0]['total'] ) . '%';
					$lo_size = sprintf( '%5.2f', 99 * $item['loggedout'] / $data[0]['total'] ) . '%';
					echo 	'<tr>' .
								'<td title="Page ID = ' . $item['ID'] . '" >';
									if ( $show_edit_links ) {
										echo
										'<a href="' . admin_url( 'post.php?post=' . $item['ID'] . '&action=edit' ) . '" >' .
											$item['post_title'] .
										'</a>';
									}
									else {
										echo
										$item['post_title'];
									}
								echo
								'</td>' .
								'<td style="text-align:left;" >';
									if ( $item['loggedin'] ) {
										echo
										'<div' .
											' style="background-color:#0f0;float:left;height:1em;min-width:1px;width:' . $li_size .';"' .
											' title="' . $item['loggedin'] . ' = ' . $li_perc . '"' .
											' >' .
										'</div>';
									}
									if ( $item['loggedout'] ) {
										echo
										'<div' .
											' style="background-color:#22f;float:left;height:1em;min-width:1px;width:' . $lo_size .';"' .
											' title="' . $item['loggedout'] . ' = ' . $lo_perc . '"' .
											' >' .
										'</div>';
									}
								echo
								'</td>' .
							'</tr>';
				}

	echo		'</tbody>' .
			'</table>';
}

// Tab 2 html
function pagelog_by_permalink( $permalinks ) {
global $pagelog_display_period;
global $pagelog_void_ips;
global $wpdb;

	echo 	'<table style="" >' .
				'<thead>' .
					'<tr>' .
						'<th>' .
							esc_html( __( 'Page', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'Total', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'Users', 'pagelog' ) ) .
						'</th>' .
					'</tr>' .
				'</thead>' .
				'<tbody>';

					if ( $permalinks ) foreach ( $permalinks as $page ) {
							$total = $wpdb->get_var( 		"SELECT COUNT(*) " .
															"FROM `" . PAGELOG . "` " .
															"WHERE `page` = '" . $page['ID'] . "'" .
															( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
															"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) " );

							if ( $total ) {
								$logs  = $wpdb->get_results( 	"SELECT * " .
																"FROM `" . PAGELOG . "` " .
																"WHERE `page` = '" . $page['ID'] . "' " .
																"AND `user` <> '' " .
																( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
																"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) " .
																"ORDER BY `user` ASC", ARRAY_A );

								$uspag = array();
								foreach( $logs as $log ) {
									if ( ! isset( $uspag[$log['user']] ) ) {
										$uspag[$log['user']] = 1;
									}
									else {
										$uspag[$log['user']] += 1;
									}
								}
								echo 	'<tr>' .
											'<td>' .
												$page['post_title'] .
											'</td>' .
											'<td style="text-align:right;" >' .
												$total .
											'</td>' .
											'<td>';
												foreach( array_keys( $uspag ) as $usr ) {
								echo				$usr . ': ' . $uspag[$usr] . ', ';
												}
												$lout = $total - count( $logs );
												if ( $lout ) {
								echo				__( 'Logged out:', 'pagelog' ) . $lout;
												}
											'</td>' .
										'</tr>';
							}
					}

	echo		'</tbody>' .
			'</table>';
}

// Tab 3 html
function pagelog_by_recent( $pages ) {
global $pagelog_display_period;
global $pagelog_void_ips;
global $wpdb;

	$logs = $wpdb->get_results( "SELECT * " .
								"FROM `" . PAGELOG . "` " .
								"WHERE `ip` NOT IN ( " . $pagelog_void_ips . " ) " .
								( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
								"ORDER BY `timestamp` DESC ",
								ARRAY_A );

	echo 	'<table style="width:600px;" >' .
				'<thead>' .
					'<tr>' .
						'<th>' .
							esc_html( __( 'Date / Time', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'Page', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'User', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'IP', 'pagelog' ) ) .
						'</th>' .
					'</tr>' .
				'</thead>' .
				'<tbody>';
				foreach( $logs as $log ) {
					$date = $log['timestamp'];
					$page = '';
					foreach( $pages as $p ) {
						if ( ! $page && $p['ID'] == $log['page'] ) {
							$page = $p['post_title'];
						}
					}
					$user = $log['user'];
					$ip   = $log['ip'];
					echo 	'<tr>' .
								'<td>' .
									pagelog_local_time( '', $date ) .
								'</td>' .
								'<td>' .
									$page .
								'</td>' .
								'<td>' .
									$user .
								'</td>' .
								'<td>' .
									$ip .
								'</td>' .
							'</tr>';
				}
	echo		'</tbody>' .
			'</table>';
}

// Tab 4 html
function pagelog_by_user( $pages ) {
global $pagelog_display_period;
global $pagelog_void_ips;
global $wpdb;

	echo 	'<table style="width:600px;" >' .
				'<thead>' .
					'<tr>' .
						'<th>' .
							esc_html( __( 'User', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'Page', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'Count', 'pagelog' ) ) .
						'</th>' .
					'</tr>' .
				'</thead>' .
				'<tbody>';

				$users = $wpdb->get_col( 	"SELECT DISTINCT `user` " .
											"FROM `" . PAGELOG . "` " .
											"WHERE `user` <> '' " .
											"ORDER BY `user`" );

				$logs = $wpdb->get_results( 	"SELECT `user`, `page` " .
												"FROM `" . PAGELOG . "` " .
												"WHERE `user` <> '' " .
												( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
												"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) ", ARRAY_A );

				$accu = array();
				if ( $logs ) foreach ( $logs as $log ) {
					$page = $log['page'];
					$user = $log['user'];
					if ( isset( $accu[$user][$page] ) ) {
						$accu[$user][$page]++;
					}
					else {
						$accu[$user][$page] = 1;
					}
				}

				if ( $users ) foreach ( $users as $user ) {
					if ( $pages ) foreach ( $pages as $page ) {

						$count = isset( $accu[$user][$page['ID']] ) ? $accu[$user][$page['ID']] : 0;

						if ( $count ) {
	echo 					'<tr>' .
								'<td>' .
								$user .
								'</td>' .
								'<td>' .
								$page['post_title'] .
								'</td>' .
								'<td style="text-align:right;" >' .
								$count .
								'</td>' .
							'</tr>';
						}
					}
				}
	echo 		'</tbody>' .
			'</table>';
}

// Tab 5 html
function pagelog_by_ip( $pages ) {
global $pagelog_display_period;
global $pagelog_void_ips;
global $wpdb;

	echo 	'<table style="width:600px;" >' .
				'<thead>' .
					'<tr>' .
						'<th>' .
							esc_html( __( 'IP (logged out)', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'Page', 'pagelog' ) ) .
						'</th>' .
						'<th>' .
							esc_html( __( 'Count', 'pagelog' ) ) .
						'</th>' .
					'</tr>' .
				'</thead>' .
				'<tbody>';

				$lo_users = $wpdb->get_col( 	"SELECT DISTINCT `ip` " .
												"FROM `" . PAGELOG . "` " .
												"WHERE `user` = '' " .
												( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
												"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) " .
												"ORDER BY `ip`" );

				$logs = $wpdb->get_results( 	"SELECT `ip`, `page` " .
												"FROM `" . PAGELOG . "` " .
												"WHERE `user` = '' " .
												( $pagelog_display_period ? "AND `timestamp` > " . ( time() - $pagelog_display_period ) . " " : "" ) .
												"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) ", ARRAY_A );

				$accu = array();
				if ( $logs ) foreach ( $logs as $log ) {
					$page = $log['page'];
					$user = $log['ip'];
					if ( isset( $accu[$user][$page] ) ) {
						$accu[$user][$page]++;
					}
					else {
						$accu[$user][$page] = 1;
					}
				}

				if ( $lo_users ) foreach ( $lo_users as $user ) {
					if ( $pages ) foreach ( $pages as $page ) {

						$count = isset( $accu[$user][$page['ID']] ) ? $accu[$user][$page['ID']] : 0;

						if ( $count ) {
	echo 					'<tr>' .
								'<td>' .
								$user .
								'</td>' .
								'<td>' .
								$page['post_title'] .
								'</td>' .
								'<td style="text-align:right;" >' .
								$count .
								'</td>' .
							'</tr>';
						}
					}
				}
	echo 		'</tbody>' .
			'</table>';
}

// Tab 6 html
function pagelog_history( $pages ) {
global $pagelog_display_period;
global $pagelog_void_ips;
global $wpdb;

	$hour 	= 3600;
	$day 	= 24 * $hour;
	$week 	= 7 * $day;
	$month 	= 30 * $day;
	$year 	= 365 * $day;
	$dp 	= get_option( 'pagelog_display_period', 0 );
	$sp 	= get_option( 'pagelog_save_period', 0 );

	// Find display resolution and count. It is one level smaller than the display period
	switch ( $dp ) {
		case 3600:			// Hour
			$res = 60;
			$cnt = 60;
			$format = '';
			break;
		case 86400:			// Day
			$res = 3600;
			$cnt = 24;
			$format = '';
			break;
		case 604800:		// Week
			$res = 86400;
			$cnt = 7;
			$format = get_option( 'date_format' );
			break;
		case 2592000:		// Month
			$res = 86400;
			$cnt = 30;
			$format = get_option( 'date_format' );
			break;
		case 31536000:		// Year
			$res = 2592000;
			$cnt = 12;
			$format = get_option( 'date_format' );
			break;
		default:			// Forever
			$res = 31536000;
			$first = $wpdb->get_var( 	"SELECT `timestamp` " .
										"FROM `" . PAGELOG . "` " .
										"ORDER BY `timestamp` DESC " .
										"LIMIT 1" 
										);
			$cnt = ceil( ( time() - $first ) / $res );
			$format = get_option( 'date_format' );
	}
	if ( $res < 31536000 ) {
		$format = str_replace( 'Y', '', $format );
		$format = str_replace( 'Y,', '', $format );
		$format = str_replace( 'Y/', '', $format );
	}
	
	// Find common data
	$_dp = $dp ? $dp : time();
	$total_general = $wpdb->get_var( 	"SELECT COUNT(*) " .
										"FROM `" . PAGELOG . "` " .
										"WHERE `timestamp` > " . ( time() - $_dp ) . " " .
										"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) "
										);
										
	echo 	'<table style="width:600px;" >' .
				'<thead>' .
					'<tr>' .
						'<th style="width:50%" >' .
							esc_html( __( 'Period', 'pagelog' ) ) .
						'</th>' .
						'<th style="width:50%" >' .
							esc_html( __( 'Total', 'pagelog' ) ) . ' (' . $total_general . ')' .
						'</th>' .
					'</tr>' .
				'</thead>' .
				'<tbody>';
				
				$line = 0;
				$loggedin = array();
				$loggedout = array();
				
				while( $line < $cnt ) {

					$to 		= time() - $line * $res;
					$from 		= $to - $res;

					$loggedin[] 	= $wpdb->get_var( 	"SELECT COUNT(*) " .
													"FROM `" . PAGELOG . "` " .
													"WHERE `timestamp` >= $from " . 
													"AND `timestamp` < $to " .
													"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) " .
													"AND `user` <> ''" );
													
					$loggedout[] 	= $wpdb->get_var( 	"SELECT COUNT(*) " .
													"FROM `" . PAGELOG . "` " .
													"WHERE `timestamp` >= $from " . 
													"AND `timestamp` < $to " .
													"AND `ip` NOT IN ( " . $pagelog_void_ips . " ) " .
													"AND `user` = ''" );
					$line++;
				}
				
				$line = 0;
				$max_total = 0;
				
				while( $line < $cnt ) {
					$max_total = max( $max_total, $loggedin[$line] + $loggedout[$line] );
					$line++;
				}
				
				$line = 0;
				
				while( $line < $cnt ) {
					
					$to 		= time() - $line * $res;
					$from 		= $to - $res;
					$period_txt = pagelog_local_time( $format, $from ) . ' - ' . pagelog_local_time( $format, $to );

					
					if ( $max_total ) {
						$li_perc = sprintf( '%5.2f', 100 * $loggedin[$line] / $total_general ) . '%';
						$lo_perc = sprintf( '%5.2f', 100 * $loggedout[$line] / $total_general ) . '%';
						$li_size = sprintf( '%5.2f', 99 * $loggedin[$line] / $max_total ) . '%';
						$lo_size = sprintf( '%5.2f', 99 * $loggedout[$line] / $max_total ) . '%';
					}
					else {
						$li_perc = 0;
						$lo_perc = 0;
						$li_size = 0;
						$li_size = 0;
					}

					echo	'<tr>' .
								'<td>' .
									$period_txt .
								'</td>' .
								'<td style="text-align:left;" >';
									if ( $loggedin[$line] ) {
										echo
										'<div' .
											' style="background-color:#0f0;float:left;height:1em;min-width:1px;width:' . $li_size .';"' .
											' title="' . $loggedin[$line] . ' = ' . $li_perc . '"' .
											' >' .
										'</div>';
									}
									if ( $loggedout[$line] ) {
										echo
										'<div' .
											' style="background-color:#22f;float:left;height:1em;min-width:1px;width:' . $lo_size .';"' .
											' title="' . $loggedout[$line] . ' = ' . $lo_perc . '"' .
											' >' .
										'</div>';
									}
					echo		'</td>' .
							'</tr>';
					
					$line++;
				}
	
	echo		'</tbody>' .
			'</table>';	
}

// Dummy display for de-activated tab
function pagelog_dummy() {
	echo 	'<table>' .
				'<thead>' .
					'<tr>' .
						'<th>' .
						esc_html( __( 'This display type is disabled', 'pagelog' ) ) .
						'</th>' .
					'</tr>' .
				'</thead>' .
			'</table>';
}

// Sanitize ips. See if a given string contains only ip addresses separated by comma's
function pagelog_sanitize_ips( $ips ) {
	
	// Strip unwanted chars, tags, spaces, etc
	$result = sanitize_text_field( $ips );
	$result = str_replace( ' ', '', $result );
	
	// If the result contains only digits, lowercase chars a..f, dots, commas and colons, it is safe to assume it is a (list of) valid ips
	$temp = str_replace( array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f', '.', ',', ':' ), '', $result );
	if ( ! $temp ) {	// Valid
		return $result;
	}
	else {				// Not valid, return an empty string
		return '';
	}
}

// Make sure a time is a valid value, otherwise return zero
function pagelog_sanitize_period( $time ) {
	
	$hour 	= 3600;
	$day 	= 24 * $hour;
	$week 	= 7 * $day;
	$month 	= 30 * $day;
	$year 	= 365 * $day;
	
	if ( in_array( $time, array( $hour, $day, $week, $month, $year ) ) ) {
		return $time;
	}
	else {
		return '0';
	}
}

// Get date/time in local format, time zone corrected
function pagelog_local_time( $format = false, $timestamp = false ) {

	// Fill in default format if not supplied
	if ( ! $format ) {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	// Fill in default timestamp if not suplied
	if ( $timestamp ) {
		$time = $timestamp;
	}
	else {
		$time = time();
	}

	// Find timezonestring
	$tzstring = get_option( 'timezone_string' );
	if ( empty( $tzstring ) ) {

		// Correct $time according to gmt_offset
		$current_offset = get_option( 'gmt_offset', 0 );
		
		$tzstring = 'UTC';
		
		if ( is_numeric( $current_offset ) ) {
			$time += $current_offset * 3600;
		}
	}

	// Get the right output
	date_default_timezone_set( $tzstring );
	$result = date_i18n( $format, $time );

	// Reset default timezone to wp standard
	date_default_timezone_set( 'GMT' );
	return $result;
}

// Setttings form
function pagelog_settings() {
	
	$hour 	= 3600;
	$day 	= 24 * $hour;
	$week 	= 7 * $day;
	$month 	= 30 * $day;
	$year 	= 365 * $day;
	$dp 	= get_option( 'pagelog_display_period', 0 );
	$sp 	= get_option( 'pagelog_save_period', 0 );

	echo
	'<form method="post" onsubmit="' . admin_url( 'tools.php?page=pagelog' ) . '" >';
		wp_nonce_field( 'pagelog', 'pagelog-nonce' );
		
		echo
		'<table style="border:1px solid #72777c;width:600px;" >' .
			'<tbody>' .
				'<tr>' .
					'<td>' .
						__( 'Exclude ip(s):', 'pagelog' ) .
					'</td>' .
					'<td colspan="2" >' .
						'<input name="void_ips" type="text" value="' . get_option( 'pagelog_void_ips' ) . '" />' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' . 
						__( 'Display period:', 'pagelog' ) .
					'</td>' .
					'<td>' .
						'<select name="display_period" >' .
							'<option value="0" >' . __( 'Forever', 'pagelog' ) . '</option>' .
							'<option value="' . $hour . '" ' . ( $dp == $hour ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last hour', 'pagelog' ) ) . '</option>' .
							'<option value="' . $day . '" ' . ( $dp == $day ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last day', 'pagelog' ) ) . '</option>' .
							'<option value="' . $week . '" ' . ( $dp == $week ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last week', 'pagelog' ) ) . '</option>' .
							'<option value="' . $month . '" ' . ( $dp == $month ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last month', 'pagelog' ) ) . '</option>' .
							'<option value="' . $year . '" ' . ( $dp == $year ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last year', 'pagelog' ) ) . '</option>' .
						'</select>' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						__( 'Save period:', 'pagelog' ) .
					'</td>' .
					'<td>' .
						'<select name="save_period" >' .
							'<option value="0" >' . __( 'Forever', 'pagelog' ) . '</option>' .
							'<option value="' . $hour . '" ' . ( $sp == $hour ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last hour', 'pagelog' ) ) . '</option>' .
							'<option value="' . $day . '" ' . ( $sp == $day ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last day', 'pagelog' ) ) . '</option>' .
							'<option value="' . $week . '" ' . ( $sp == $week ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last week', 'pagelog' ) ) . '</option>' .
							'<option value="' . $month . '" ' . ( $sp == $month ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last month', 'pagelog' ) ) . '</option>' .
							'<option value="' . $year . '" ' . ( $sp == $year ? 'selected="selected"' : '' ) . ' >' . esc_html( __( 'Last year', 'pagelog' ) ) . '</option>' .
						'</select>' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						__( 'Show counter:', 'pagelog' ) .
					'</td>' .
					'<td>' .
						'<select name="show" >' .
							'<option value="yes"' . ( get_option( 'pagelog_show' ) == 'yes' ? ' selected="selected"' : '' ) . ' >' . __( 'Yes', 'pagelog' ) . '</option>' .
							'<option value="login"' . ( get_option( 'pagelog_show' ) == 'login' ? ' selected="selected"' : '' ) . ' >' . __( 'Logged in only', 'pagelog' ) . '</option>' .
							'<option value="admin"' . ( get_option( 'pagelog_show' ) == 'admin' ? ' selected="selected"' : '' ) . ' >' . __( 'Admin only', 'pagelog' ) . '</option>' .
							'<option value="no"' . ( get_option( 'pagelog_show' ) == 'no' ? ' selected="selected"' : '' ) . ' >' . __( 'No', 'pagelog' ) . '</option>' .
						'</select>' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						__( 'Font size:', 'pagelog' ) .
					'</td>' .
					'<td>' .
						'<input name="size" type="text" style="width:50px;" value="' . get_option( 'pagelog_size', '10' ) . '" >' .
						' ' . __( 'pixels.', 'pagelog' ) .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						__( 'Add shortcodes', 'pagelog' ) .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="add_shortcodes_pages" name="add_shortcodes_pages" />' .
					'</td>' .
					'<td>' .
						__( 'Add shortcode [pagelog] at the end of all pages', 'pagelog' ) .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						__( 'Add shortcodes', 'pagelog' ) .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="add_shortcodes_posts" name="add_shortcodes_posts" />' .
					'</td>' .
					'<td>' .
						__( 'Add shortcode [pagelog] at the end of all posts', 'pagelog' ) .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td style="font-weight:bold;" >' .
						esc_html( __( 'Display type', 'pagelog' ) ) .
					'</td>' .
					'<td style="font-weight:bold;" >' .
						esc_html( __( 'Enable', 'pagelog' ) ) .
					'</td>' .
					'<td style="font-weight:bold;" >' .
						esc_html( __( 'Edit page links', 'pagelog' ) ) .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						esc_html( __( 'By frequency', 'pagelog' ) ) . 
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="tab1check" name="tab1check" ' . ( get_option( 'pagelog_tab1check', '1' ) == '1' ? 'checked="checked"' : '' ) . ' />' .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="pagelog_edit_links_1_check" name="pagelog_edit_links_1_check" ' . ( get_option( 'pagelog_edit_links_1_check', '1' ) == '1' ? 'checked="checked"' : '' ) . ' />' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						esc_html( __( 'By Permalink', 'pagelog' ) ) .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="tab2check" name="tab2check" ' . ( get_option( 'pagelog_tab2check', '1' ) == '1' ? 'checked="checked"' : '' ) . ' />' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						esc_html( __( 'Most recent', 'pagelog' ) ) .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="tab3check" name="tab3check" ' . ( get_option( 'pagelog_tab3check', '1' ) == '1' ? 'checked="checked"' : '' ) . ' />' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						esc_html( __( 'By user', 'pagelog' ) ) .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="tab4check" name="tab4check" ' . ( get_option( 'pagelog_tab4check', '1' ) == '1' ? 'checked="checked"' : '' ) . ' />' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						esc_html( __( 'By IP', 'pagelog' ) ) .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="tab5check" name="tab5check" ' . ( get_option( 'pagelog_tab5check', '1' ) == '1' ? 'checked="checked"' : '' ) . ' />' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td>' .
						esc_html( __( 'History', 'pagelog' ) ) .
					'</td>' .
					'<td>' .
						'<input type="checkbox" id="tab6check" name="tab6check" ' . ( get_option( 'pagelog_tab6check', '1' ) == '1' ? 'checked="checked"' : '' ) . ' />' .
					'</td>' .
					'<td>' .
					'</td>' .
				'</tr>' .
				'<tr>' .
					'<td colspan="3" >' .
						'<input name="submit" type="submit" value="Submit" />' .
					'</td>' .
				'</tr>' .
			'</tbody>' .
		'</table>' .
	'</form>';
}