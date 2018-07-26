<?php
/*
 * Plugin Name: Divi Builder
 * Plugin URI: http://elegantthemes.com
 * Description: A drag and drop page builder for any WordPress theme.
 * Version: 2.10.2
 * Author: Elegant Themes
 * Author URI: http://elegantthemes.com
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'ET_BUILDER_PLUGIN_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'ET_BUILDER_PLUGIN_URI', plugins_url('', __FILE__) );
define( 'ET_BUILDER_PLUGIN_VERSION', '2.10.2' );

if ( ! class_exists( 'ET_Dashboard_v2' ) ) {
	require_once( ET_BUILDER_PLUGIN_DIR . 'dashboard/dashboard.php' );
}

class ET_Builder_Plugin extends ET_Dashboard_v2 {
	var $plugin_version = ET_BUILDER_PLUGIN_VERSION;
	var $_options_pagename = 'et_builder_options';
	var $menu_page;
	private static $_this;

	function __construct() {
		// Don't allow more than one instance of the class
		if ( isset( self::$_this ) ) {
			wp_die( sprintf( esc_html__( '%s is a singleton class and you cannot create a second instance.', 'et_builder' ),
				get_class( $this ) )
			);
		}

		if ( ( defined( 'ET_BUILDER_THEME' ) && ET_BUILDER_THEME ) || function_exists( 'et_divi_fonts_url' ) ) {
			return; // Disable the plugin, if the theme comes with the Builder
		}

		self::$_this = $this;

		$this->protocol = is_ssl() ? 'https' : 'http';

		$this->et_plugin_setup_builder();

		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );

		add_action( 'admin_init', array( $this, 'construct_dashboard' ) );

		add_action( 'wp_ajax_et_builder_save_settings', array( $this, 'builder_save_settings' ) );

		add_action( 'wp_ajax_et_builder_refresh_lists', array( $this, 'refresh_lists' ) );

		add_action( 'wp_ajax_et_builder_save_updates_settings', array( $this, 'save_updates_settings' ) );

		add_action( 'wp_ajax_et_builder_save_google_api_settings', array( $this, 'save_google_api_settings' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'et_pb_hide_options_menu' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts_styles' ) );

		add_filter( 'et_shortcodes_strings_handle', array( $this, 'shortcodes_strings_handle' ) );

		add_filter( 'et_builder_modules_script_handle', array( $this, 'builder_modules_script_handle' ) );

		add_filter( 'et_builder_optimized_style_handle', array( $this, 'builder_optimized_style_handle' ) );

		add_filter( 'et_pb_builder_options_array', array( $this, 'get_builder_options' ) );

		$theme_file_path_length = strlen( get_theme_file_path() );

		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_locate_template' ) && substr( wc_locate_template( 'archive-product.php' ), 0, $theme_file_path_length ) === get_theme_file_path() ) {
			add_action( 'et_pb_shop_before_print_shop', array( $this, 'force_woocommerce_default_templates' ) );
			add_action( 'et_pb_shop_after_print_shop', array( $this, 'return_woocommerce_default_templates' ) );
		}
	}

	function construct_dashboard() {
		$dashboard_args = array(
			'et_dashboard_options_pagename'  => $this->_options_pagename,
			'et_dashboard_plugin_name'       => 'pb_builder',
			'et_dashboard_save_button_text'  => esc_html__( 'Save', 'et_builder' ),
			'et_dashboard_options_path'      => ET_BUILDER_PLUGIN_DIR . '/dashboard/includes/options.php',
			'et_dashboard_options_page'      => 'toplevel_page',
			'et_dashboard_options_pagename'  => 'et_divi_options',
			'et_dashboard_plugin_class_name' => 'et_builder',
		);

		parent::__construct( $dashboard_args );
	}

	function builder_save_settings() {
		self::dashboard_save_settings();
	}

	/**
	 * Retrieves the Builder options from DB
	 * @return array
	 */
	function get_builder_options() {
		$auto_updates_settings = get_option( 'et_automatic_updates_options' ) ? get_option( 'et_automatic_updates_options' ) : array();
		$google_api_settings = get_option( 'et_google_api_settings' ) ? get_option( 'et_google_api_settings' ) : array();
		$builder_options = get_option( 'et_pb_builder_options' ) ? get_option( 'et_pb_builder_options' ) : array();
		$processed_updates_settings = array();

		// prepare array of Auto Updates settings
		$processed_updates_settings['updates_main_updates_username'] = isset( $auto_updates_settings['username'] ) ? $auto_updates_settings['username'] : '';
		$processed_updates_settings['updates_main_updates_api_key'] = isset( $auto_updates_settings['api_key'] ) ? $auto_updates_settings['api_key'] : '';

		// prepare array of Google API settings
		$processed_updates_settings['api_main_google_api_key'] = isset( $google_api_settings['api_key'] ) ? $google_api_settings['api_key'] : '';
		$processed_updates_settings['api_main_enqueue_google_maps_script'] = isset( $google_api_settings['enqueue_google_maps_script'] ) ? $google_api_settings['enqueue_google_maps_script'] : false;
		$processed_updates_settings['api_main_use_google_fonts'] = isset( $google_api_settings['use_google_fonts'] ) ? $google_api_settings['use_google_fonts'] : false;

		$complete_options_set = array_merge( $builder_options, $processed_updates_settings );
		return $complete_options_set;
	}

	function options_page() {
		// display wp error screen if plugin options disabled for current user
		if ( ! et_pb_is_allowed( 'theme_options' ) ) {
			wp_die( esc_html__( "You don't have sufficient permissions to access this page", 'et_builder_plugin' ) );
		}

		printf(
			'<div class="et_pb_save_settings_button_wrapper">
				<a href="#" id="et_pb_save_plugin" class="button button-primary button-large">%1$s</a>
				<h3 class="et_pb_settings_title">
					%2$s
				</h3>
			</div>',
			esc_html__( 'Save Settings', 'et_builder_plugin' ),
			esc_html__( 'Divi Builder Options', 'et_builder_plugin' )
		);

		self::generate_options_page();
	}

	function et_plugin_setup_builder() {
		define( 'ET_BUILDER_PLUGIN_ACTIVE', true );

		define( 'ET_BUILDER_VERSION', ET_BUILDER_PLUGIN_VERSION );

		define( 'ET_BUILDER_DIR', ET_BUILDER_PLUGIN_DIR . 'includes/builder/' );
		define( 'ET_BUILDER_URI', trailingslashit( plugins_url( '', __FILE__ ) ) . 'includes/builder' );
		define( 'ET_BUILDER_LAYOUT_POST_TYPE', 'et_pb_layout' );

		load_theme_textdomain( 'et_builder', ET_BUILDER_DIR . 'languages' );

		load_plugin_textdomain( 'et_builder_plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );

		require ET_BUILDER_PLUGIN_DIR . 'functions.php';
		require ET_BUILDER_PLUGIN_DIR . 'theme-compat.php';
		require ET_BUILDER_DIR . 'framework.php';

		et_pb_register_posttypes();

		add_action( 'admin_menu', array( $this, 'add_divi_menu' ));
	}

	function add_divi_menu() {
		add_menu_page( 'Divi', 'Divi', 'switch_themes', 'et_divi_options', array( $this, 'options_page' ) );

		// Add Theme Options menu only if it's enabled for current user
		if ( et_pb_is_allowed( 'theme_options' ) ) {
			add_submenu_page( 'et_divi_options', esc_html__( 'Plugin Options', 'et_builder_plugin' ), esc_html__( 'Plugin Options', 'et_builder_plugin' ), 'manage_options', 'et_divi_options', array( $this, 'options_page' ) );
		}
		// Add Divi Library menu only if it's enabled for current user
		if ( et_pb_is_allowed( 'divi_library' ) ) {
			add_submenu_page( 'et_divi_options', esc_html__( 'Divi Library', 'et_builder' ), esc_html__( 'Divi Library', 'et_builder' ), 'manage_options', 'edit.php?post_type=et_pb_layout' );
		}
		add_submenu_page( 'et_divi_options', esc_html__( 'Divi Role Editor', 'et_builder' ), esc_html__( 'Divi Role Editor', 'et_builder' ), 'manage_options', 'et_divi_role_editor', 'et_pb_display_role_editor' );
	}

	/**
	 *
	 * Adds js script which removes the top menu item from Divi menu if it's disabled
	 *
	 */
	function et_pb_hide_options_menu() {
		// do nothing if plugin options should be displayed in the menu
		if ( et_pb_is_allowed( 'theme_options' ) ) {
			return;
		}

		wp_enqueue_script( 'et-builder-custom-admin-menu', ET_BUILDER_PLUGIN_URI . '/js/menu_fix.js', array( 'jquery' ), $this->plugin_version, true );
	}

	function load_scripts_styles() {
		if ( ! et_load_unminified_scripts() ) {
			wp_enqueue_script( 'divi-builder-custom-script', ET_BUILDER_PLUGIN_URI . '/js/divi-builder.min.js', array( 'jquery' ) , $this->plugin_version, true );
		}
	}

	function shortcodes_strings_handle( $handle ) {
		return et_load_unminified_scripts() ? $handle : 'divi-builder-custom-script';
	}

	function builder_modules_script_handle( $handle ) {
		return et_load_unminified_scripts() ? $handle : 'divi-builder-custom-script';
	}

	function builder_optimized_style_handle() {
		return 'et-builder-modules-style';
	}

	function register_scripts( $hook ) {
		if ( "toplevel_page_et_divi_options" !== $hook ) {
			return;
		}

		et_core_load_main_fonts();

		wp_enqueue_style( 'et-builder-css', ET_BUILDER_PLUGIN_URI . '/css/admin.css', array(), $this->plugin_version );
		wp_enqueue_script( 'et-builder-js', ET_BUILDER_PLUGIN_URI . '/js/admin.js', array( 'jquery' ), $this->plugin_version, true );
		wp_localize_script( 'et-builder-js', 'builder_settings', array(
			'et_builder_nonce' => wp_create_nonce( 'et_builder_nonce' ),
			'ajaxurl'          => admin_url( 'admin-ajax.php', $this->protocol ),
			'authorize_text'   => esc_html__( 'Authorize', 'et_builder_plugin' ),
			'reauthorize_text' => esc_html__( 'Re-Authorize', 'et_builder_plugin' ),
			'save_settings'    => wp_create_nonce( 'save_settings' ),
			'et_core_nonces'   => et_core_get_nonces(),
		) );
	}

	function save_updates_settings() {
		if ( ! wp_verify_nonce( $_POST['et_builder_nonce'] , 'et_builder_nonce' ) ) {
			die( -1 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			die( -1 );
		}

		$username = ! empty( $_POST['et_builder_updates_username'] ) ? sanitize_text_field( $_POST['et_builder_updates_username'] ) : '';
		$api_key = ! empty( $_POST['et_builder_updates_api_key'] ) ? sanitize_text_field( $_POST['et_builder_updates_api_key'] ) : '';

		update_option( 'et_automatic_updates_options', array(
			'username' => $username,
			'api_key' => $api_key,
		) );

		die();
	}

	function save_google_api_settings() {
		if ( ! wp_verify_nonce( $_POST['et_builder_nonce'] , 'et_builder_nonce' ) ) {
			die( -1 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			die( -1 );
		}

		$api_key = ! empty( $_POST['et_builder_google_api_key'] ) ? sanitize_text_field( $_POST['et_builder_google_api_key'] ) : '';
		$enqueue_google_maps_script = ( isset( $_POST['et_builder_enqueue_google_maps_script'] ) && 'on' === $_POST['et_builder_enqueue_google_maps_script'] ) ? 'on' : 'off';
		$use_google_fonts = ( isset( $_POST['et_builder_use_google_fonts'] ) && 'on' === $_POST['et_builder_use_google_fonts'] ) ? 'on' : 'off';

		update_option( 'et_google_api_settings', array(
			'api_key'                    => $api_key,
			'enqueue_google_maps_script' => $enqueue_google_maps_script,
			'use_google_fonts'           => $use_google_fonts,
		));

		die();
	}

	/**
	 * Force WooCommerce to use its default templates (ignoring themes' custom templates) on shop module
	 * @return void
	 */
	function force_woocommerce_default_templates() {
		add_filter( 'woocommerce_template_path', '__return_false' );
	}

	/**
	 * Cleanup force_woocommerce_default_templates(), allowing non shop module WooCommerce shortcode to use theme's WooCommerce template
	 * @return void
	 */
	function return_woocommerce_default_templates() {
		remove_filter( 'woocommerce_template_path', '__return_false' );
	}
}

function et_divi_builder_init_plugin() {
	new ET_Builder_Plugin();
}
add_action( 'init', 'et_divi_builder_init_plugin' );

function et_divi_builder_maybe_load_core() {
	if ( ! defined( 'ET_CORE' ) ) {
		require_once ET_BUILDER_PLUGIN_DIR . 'core/init.php';
		et_core_setup();
	}

	et_core_enable_automatic_updates( ET_BUILDER_PLUGIN_URI, ET_BUILDER_PLUGIN_VERSION );
}
add_action( 'plugins_loaded', 'et_divi_builder_maybe_load_core' );

if ( ! function_exists( 'et_divi_builder_setup_thumbnails' ) ) :
function et_divi_builder_setup_thumbnails() {
	add_theme_support( 'post-thumbnails' );

	global $et_theme_image_sizes;

	$et_theme_image_sizes = array(
		'400x250'   => 'et-pb-post-main-image',
		'1080x675'  => 'et-pb-post-main-image-fullwidth',
		'400x284'   => 'et-pb-portfolio-image',
		'510x382'   => 'et-pb-portfolio-module-image',
		'1080x9999' => 'et-pb-portfolio-image-single',
		'400x516'   => 'et-pb-gallery-module-image-portrait',
	);

	$et_theme_image_sizes = apply_filters( 'et_theme_image_sizes', $et_theme_image_sizes );
	$crop = apply_filters( 'et_post_thumbnails_crop', true );

	if ( is_array( $et_theme_image_sizes ) ){
		foreach ( $et_theme_image_sizes as $image_size_dimensions => $image_size_name ){
			$dimensions = explode( 'x', $image_size_dimensions );

			if ( in_array( $image_size_name, array( 'et-pb-portfolio-image-single' ) ) ){
				$crop = false;
			}

			add_image_size( $image_size_name, $dimensions[0], $dimensions[1], $crop );

			$crop = apply_filters( 'et_post_thumbnails_crop', true );
		}
	}
}
endif;
add_action( 'after_setup_theme', 'et_divi_builder_setup_thumbnails' );

/**
 * Switch the translation of Visual Builder interface to current user's language
 * @return void
 */
if ( ! function_exists( 'et_fb_set_builder_locale' ) ) :
function et_fb_set_builder_locale() {
	// apply translations inside VB only
	if ( empty( $_GET['et_fb'] ) ) {
		return;
	}

	// make sure switch_to_locale() funciton exists. It was introduced in WP 4.7
	if ( ! function_exists( 'switch_to_locale' ) ) {
		return;
	}

	// do not proceed if user language == website language
	if ( get_user_locale() === get_locale() ) {
		return;
	}

	// switch the translation to user language
	switch_to_locale( get_user_locale() );

	// manually restore the translation for all domains except for the 'et_builder' domain
	// otherwise entire page will be translated to user language, but we need to apply it to VB interface only.

	/* The below code adapted from WordPress

	  wp-includes/class-wp-locale-switcher.php:
	    * load_translations()

	  @copyright 2015 by the WordPress contributors.
	  This program is free software; you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation; either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program; if not, write to the Free Software
	  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

	  This program incorporates work covered by the following copyright and
	  permission notices:

	  b2 is (c) 2001, 2002 Michel Valdrighi - m@tidakada.com - http://tidakada.com

	  b2 is released under the GPL

	  WordPress - Web publishing software

	  Copyright 2003-2010 by the contributors

	  WordPress is released under the GPL */

	global $l10n;

	$domains = $l10n ? array_keys( $l10n ) : array();

	load_default_textdomain( get_locale() );

	foreach ( $domains as $domain ) {
		if ( 'et_builder' === $domain ) {
			continue;
		}

		unload_textdomain( $domain );
		get_translations_for_domain( $domain );
	}
}
endif;
add_action( 'after_setup_theme', 'et_fb_set_builder_locale' );

if ( ! function_exists( 'et_divi_builder_minify_combine_scripts' ) ) :
function et_divi_builder_minify_combine_scripts( $load ) {
	$options = get_option( 'et_pb_builder_options', array() );
	$option  = isset( $options['advanced_main_minify_combine_scripts'] ) ? $options['advanced_main_minify_combine_scripts'] : 'on';

	if ( $option === 'off' ) {
		return true;
	}

	return $load;
}
endif;

if ( ! function_exists( 'et_divi_builder_minify_combine_styles' ) ) :
function et_divi_builder_minify_combine_styles( $load ) {
	$options = get_option( 'et_pb_builder_options', array() );
	$option  = isset( $options['advanced_main_minify_combine_styles'] ) ? $options['advanced_main_minify_combine_styles'] : 'on';

	if ( $option === 'off' ) {
		return true;
	}

	return $load;
}
endif;

if ( ! function_exists( 'et_divi_builder_after_theme_setup' ) ) :
function et_divi_builder_after_theme_setup() {
	// Load unminified scripts & styles based on selected plugin options field
	add_filter( 'et_load_unminified_scripts', 'et_divi_builder_minify_combine_scripts' );
	add_filter( 'et_load_unminified_styles', 'et_divi_builder_minify_combine_styles' );
}
endif;
add_action( 'wp_enqueue_scripts', 'et_divi_builder_after_theme_setup', 5 );

if ( ! function_exists( 'et_dbp_body_class_backwards_compatibility' ) ):
function et_dbp_body_class_backwards_compatibility( $classes ) {
	$classes[] = 'et_divi_builder';

	return $classes;
}
add_filter( 'body_class', 'et_dbp_body_class_backwards_compatibility' );
endif;
