<?php

add_editor_style();

if ( ! class_exists( 'CelebrationTheme' ) ) {
	
	class CelebrationTheme {
	
		/**
	     * Constructor
	     */
		function __construct() {
		
			// Register action/filter callbacks
			
			add_action( 'after_setup_theme', array( $this, 'boldthemes_init' ) );
			add_action( 'wp_head', array( $this, 'boldthemes_set_global_uri' ) );
			add_action( 'widgets_init', array( $this, 'boldthemes_widgets_init' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'boldthemes_enqueue_scripts_styles' ) );
			add_action( 'customize_controls_enqueue_scripts', array( $this, 'boldthemes_media_script' ) );
			
			add_action( 'wp_enqueue_scripts', array( $this, 'boldthemes_load_fonts' ) );
			add_action( 'admin_head', array( $this, 'boldthemes_admin_style' ) );
			add_action( 'customize_controls_print_styles', array( $this, 'boldthemes_admin_customize_style' ) );
			add_action( 'tgmpa_register', array( $this, 'boldthemes_theme_register_required_plugins' ) );
			add_action( 'after_setup_theme', array( $this, 'boldthemes_woocommerce_support' ) );
			
			add_filter( 'get_search_form', array( $this, 'boldthemes_search_form' ) );
			add_filter( 'the_content_more_link', array( $this, 'boldthemes_remove_more_link_scroll' ) );
			add_filter( 'wp_list_categories', array( $this, 'boldthemes_cat_count_span' ) );
			add_filter( 'get_archives_link', array( $this, 'boldthemes_arch_count_span' ) );
			add_filter( 'wp_nav_menu_items', array( $this, 'boldthemes_remove_menu_item_whitespace' ) );
			add_filter( 'wp_video_shortcode', array( $this, 'boldthemes_wp_video_shortcode' ), 10, 5 );
			add_filter( 'wp_video_shortcode_library', array( $this, 'boldthemes_wp_video_shortcode_library' ) );
			add_filter( 'wp_audio_shortcode_library', array( $this, 'boldthemes_wp_audio_shortcode_library' ) );
			add_filter( 'wp_title', array( $this, 'boldthemes_title' ) );
			add_filter( 'body_class', array( $this, 'boldthemes_classes' ) );
			
			add_filter( 'woocommerce_product_tabs', array( $this, 'boldthemes_woo_remove_product_tabs' ), 98 );
			add_filter( 'woocommerce_show_page_title', '__return_false' );
			add_filter( 'woocommerce_enqueue_styles', '__return_empty_array' );
			add_filter( 'woocommerce_output_related_products_args', array( $this, 'boldthemes_change_number_related_products' ) );
			add_filter( 'loop_shop_per_page', array( $this, 'boldthemes_loop_shop_per_page' ), 20 );
			add_filter( 'loop_shop_columns', array( $this, 'boldthemes_loop_shop_columns' ) );
			 		
			remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
			remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
			remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
			remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );		

		}
		 
		/**
	     * Theme setup
	     */
		function boldthemes_init() {
	
			// add theme support
			add_theme_support( 'automatic-feed-links' );
			add_theme_support( 'post-thumbnails' );
			add_theme_support( 'html5', array( 'comment-list', 'comment-form', 'search-form', 'gallery', 'caption' ) );
			add_theme_support( 'post-formats', array( 'image', 'gallery', 'video', 'audio', 'link', 'quote' ) );
			add_theme_support( 'title-tag' );

			if ( boldthemes_woocommerce_is_new_version() ) {
				add_theme_support('wc-product-gallery-lightbox');
				add_theme_support('wc-product-gallery-zoom');
			}
			
			// register navigation menus
			register_nav_menus( array (
				'primary' => esc_html__( 'Primary Menu', 'celebration' ),
				'footer'  => esc_html__( 'Footer Menu', 'celebration' )
			));
			
			// load translated strings
			load_theme_textdomain( 'celebration', get_template_directory() . '/languages' );
			
			// date format
			if ( function_exists( 'icl_register_string' ) ) {
				icl_register_string( 'celebration', 'Date Format', get_option( 'date_format' ) );
				CelebrationTheme::$boldthemes_date_format = icl_t( 'celebration', 'Date Format', get_option( 'date_format' ) );
			} else {
				CelebrationTheme::$boldthemes_date_format = get_option( 'date_format' );
			}

			// image sizes
			update_option( 'thumbnail_size_w', 160 );
			update_option( 'thumbnail_size_h', 160 );
			update_option( 'medium_size_w', 320 );
			update_option( 'medium_size_h', 0 );
			update_option( 'large_size_w', 1200 );
			update_option( 'large_size_h', 0 );

			add_image_size( 'boldthemes_grid', 540 );

			add_image_size( 'boldthemes_grid_11', 540, 540, true );
			add_image_size( 'boldthemes_grid_22', 1080, 1080, true );
			add_image_size( 'boldthemes_grid_21', 1080, 540, true );
			add_image_size( 'boldthemes_grid_12', 540, 1080, true );

			add_image_size( 'boldthemes_latest_posts', 320, 240, true );	
			add_image_size( 'boldthemes_grid_gallery', 540, 405, true );

		}
		
		// vars
		
		public static $boldthemes_page_for_header_id;
		public static $boldthemes_date_format;
		public static $boldthemes_sidebar;
		public static $boldthemes_has_sidebar;
		public static $boldthemes_fonts;
		
		// callbacks	 
		
		/**
		 * Set JS AJAX URL and JS text labels
		 */
		function boldthemes_set_global_uri() {
			echo '<script>';
			echo 'window.BoldThemesURI = "' . esc_js( get_template_directory_uri() ) . '"; window.BoldThemesAJAXURL = "' . esc_js( admin_url( 'admin-ajax.php' ) ) . '";';
			echo 'window.boldthemes_text = [];';
			echo 'window.boldthemes_text.previous = \'' . esc_html__( 'previous', 'celebration' ) . '\';';
			echo 'window.boldthemes_text.next = \'' . esc_html__( 'next', 'celebration' ) . '\';';		
			echo '</script>';
		}
		
		/**
		 * WooCommerce support
		 */
		function boldthemes_woocommerce_support() {
			add_theme_support( 'woocommerce' );
		}
		
		/**
		 * Remove Recent Comments widget style and register sidebar and widget areas
		 */
		function boldthemes_widgets_init() {  
			global $wp_widget_factory;  
			remove_action( 'wp_head', array( $wp_widget_factory->widgets['WP_Widget_Recent_Comments'], 'recent_comments_style' ) );
			
			register_sidebar( array (
				'name' 			=> esc_html__( 'Sidebar', 'celebration' ),
				'id' 			=> 'primary_widget_area',
				'before_widget' => '<div class="btBox %2$s">',
				'after_widget' 	=> '</div>',
				'before_title' 	=> '<h4><span>',
				'after_title' 	=> '</span></h4>',
			));
		}
		
		/**
		 * Enqueue scripts and styles
		 */
		function boldthemes_enqueue_scripts_styles() {

			global $boldthemes_crush_vars;
			$boldthemes_crush_vars = array();
			
			global $boldthemes_crush_vars_def;
			$boldthemes_crush_vars_def = array( 'accentColor', 'bodyFont', 'menuFont', 'headingFont', 'headingSuperTitleFont', 'headingSubTitleFont' );			

			//custom accent color and font style

			$color = boldthemes_get_option( 'accent_color' );
			$body_font = urldecode( boldthemes_get_option( 'body_font' ) );
			$menu_font = urldecode( boldthemes_get_option( 'menu_font' ) );
			$heading_font = urldecode( boldthemes_get_option( 'heading_font' ) );
			$heading_supertitle_font = urldecode( boldthemes_get_option( 'heading_supertitle_font' ) );
			$heading_subtitle_font = urldecode( boldthemes_get_option( 'heading_subtitle_font' ) );

			if ( $color != '' ) {
				$boldthemes_crush_vars['accentColor'] = $color;
			}

			if ( $body_font != 'no_change' ) {
				$boldthemes_crush_vars['bodyFont'] = $body_font;
			}

			if ( $menu_font != 'no_change' ) {
				$boldthemes_crush_vars['menuFont'] = $menu_font;
			}

			if ( $heading_font != 'no_change' ) {
				$boldthemes_crush_vars['headingFont'] = $heading_font;
			}

			if ( $heading_supertitle_font != 'no_change' ) {
				$boldthemes_crush_vars['headingSuperTitleFont'] = $heading_supertitle_font;
			}

			if ( $heading_subtitle_font != 'no_change' ) {
				$boldthemes_crush_vars['headingSubTitleFont'] = $heading_subtitle_font;
			}

			if ( function_exists( 'boldthemes_csscrush_file' ) ) {
				boldthemes_csscrush_file( get_stylesheet_directory() . '/style.crush.css', array( 'source_map' => true, 'minify' => false, 'output_file' => 'style', 'formatter' => 'block', 'boilerplate' => false, 'plugins' => array( 'loop', 'ease' ) ) );
			}
			
			// custom theme css
			wp_enqueue_style( 'celebration-style', get_template_directory_uri() . '/style.css', array(), false );
			
			// custom magnific popup css
			wp_enqueue_style( 'celebration-magnific-popup', get_template_directory_uri() . '/magnific-popup.css', array(), false );
			
			wp_enqueue_script( 'slick-min', get_template_directory_uri() . '/js/slick.min.js', array( 'jquery' ), '', false );
			wp_enqueue_script( 'jquery-magnific-popup-min', get_template_directory_uri() . '/js/jquery.magnific-popup.min.js', array( 'jquery' ), '', false );
			if ( ! wp_is_mobile() ) wp_enqueue_script( 'iscroll', get_template_directory_uri() . '/js/iscroll.js', array( 'jquery' ), '', false );
			wp_enqueue_script( 'fancySelect', get_template_directory_uri() . '/js/fancySelect.js', array( 'jquery' ), '', false );			
			wp_enqueue_script( 'html5shiv-min', get_template_directory_uri() . '/js/html5shiv.min.js', array(), false );
			wp_enqueue_script( 'respond-min', get_template_directory_uri() . '/js/respond.min.js', array(), false );
			
			// custom miscellaneous js
			wp_enqueue_script( 'celebration-misc', get_template_directory_uri() . '/js/misc.js', array( 'jquery' ), '', false );
			// custom header related js
			wp_enqueue_script( 'celebration-header-misc', get_template_directory_uri() . '/js/header.misc.js', array( 'jquery' ), '', false );
			// custom tile hover effect js
			wp_enqueue_script( 'celebration-dir-hover', get_template_directory_uri() . '/js/dir.hover.js', array( 'jquery' ), '', false );
			// custom slider js
			wp_enqueue_script( 'celebration-sliders', get_template_directory_uri() . '/js/sliders.js', array( 'jquery' ), '', false );			

			// dequeue cost calculator plugin style
			wp_dequeue_style( 'bt_cc_style' );
			
			if ( file_exists( get_template_directory() . '/css-override.php' ) ) {
				require_once( get_template_directory() . '/css-override.php' );
				if( count( $boldthemes_crush_vars ) > 0 ) wp_add_inline_style( 'celebration-style', $css_override );
			}

		}
		
		/**
		 * Custom media manager script
		 */
		function boldthemes_media_script() {
			wp_enqueue_media();
			wp_enqueue_script( 'celebration-media-manager', get_template_directory_uri() . '/js/media_manager.js', array(), '1.0', true );
		}
		
		/**
		 * Loads custom Google Fonts
		 */
		function boldthemes_load_fonts() {
			$body_font = urldecode( boldthemes_get_option( 'body_font' ) );
			$heading_font = urldecode( boldthemes_get_option( 'heading_font' ) );
			$menu_font = urldecode( boldthemes_get_option( 'menu_font' ) );
			$heading_subtitle_font = urldecode( boldthemes_get_option( 'heading_subtitle_font' ) );
			$heading_supertitle_font = urldecode( boldthemes_get_option( 'heading_supertitle_font' ) );
			
			$font_families = array();
			
			if ( $body_font != 'no_change' ) {
				$font_families[] = $body_font . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
			} else {
				/*
				Translators: If there are characters in your language that are not supported
				by chosen font(s), translate this to 'off'. Do not translate into your own language.
				 */
				if ( 'off' !== _x( 'on', 'Josefin Sans font: on or off', 'celebration' ) ) {
					$font_families[] = 'Josefin Sans' . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
				}
			}
			
			if ( $heading_font != 'no_change' ) {
				$font_families[] = $heading_font . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
			} else {
				/*
				Translators: If there are characters in your language that are not supported
				by chosen font(s), translate this to 'off'. Do not translate into your own language.
				 */
				if ( 'off' !== _x( 'on', 'Great Vibes font: on or off', 'celebration' ) ) {
					$font_families[] = 'Great Vibes' . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
				}
			}
			
			if ( $menu_font != 'no_change' ) {
				$font_families[] = $menu_font . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
			} else {
				/*
				Translators: If there are characters in your language that are not supported
				by chosen font(s), translate this to 'off'. Do not translate into your own language.
				 */
				if ( 'off' !== _x( 'on', 'Josefin Sans font: on or off', 'celebration' ) ) {
					$font_families[] = 'Josefin Sans' . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
				}
			}

			if ( $heading_subtitle_font != 'no_change' ) {
				$font_families[] = $heading_subtitle_font . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
			} else {
				/*
				Translators: If there are characters in your language that are not supported
				by chosen font(s), translate this to 'off'. Do not translate into your own language.
				 */
				if ( 'off' !== _x( 'on', 'Josefin Slab font: on or off', 'celebration' ) ) {
					$font_families[] = 'Josefin Slab' . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
				}
			}

			if ( $heading_supertitle_font != 'no_change' ) {
				$font_families[] = $heading_supertitle_font . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
			} else {
				/*
				Translators: If there are characters in your language that are not supported
				by chosen font(s), translate this to 'off'. Do not translate into your own language.
				 */
				if ( 'off' !== _x( 'on', 'Josefin Slab font: on or off', 'celebration' ) ) {
					$font_families[] = 'Josefin Slab' . ':100,200,300,400,500,600,700,800,900,100italic,200italic,300italic,400italic,500italic,600italic,700italic,800italic,900italic';
				}
			}

			if ( count( $font_families ) > 0  ) {
				$query_args = array(
					'family' => urlencode( implode( '|', $font_families ) ),
					'subset' => urlencode( 'latin,latin-ext' ),
				);
				$font_url = add_query_arg( $query_args, 'https://fonts.googleapis.com/css' );
				wp_enqueue_style( 'celebration-fonts', $font_url, array(), '1.0.0' );
			}
		}

		/**
		 * MetaBox custom style
		 */
		function boldthemes_admin_style() {
			wp_register_style( 'celebration-admin-style', false );
			wp_enqueue_style( 'celebration-admin-style' );
			wp_add_inline_style( 'celebration-admin-style', '.rwmb-meta-box input[type="text"], .rwmb-meta-box select { width:250px; } .rwmb-meta-box input[type="text"].bt_bttext { width:250px; }' );
		}
		
		/**
		 * Customize custom style
		 */
		function boldthemes_admin_customize_style() {
			wp_register_style( 'celebration-admin-customize-style', false );
			wp_enqueue_style( 'celebration-admin-customize-style' );
			wp_add_inline_style( 'celebration-admin-customize-style', '.customize-control-image, .customize-control-text, .customize-control-select, .customize-control-radio, .customize-control-checkbox, .customize-control-color { padding-top:5px; padding-bottom:5px; }' );
		}
		
		/**
		 * Register the required plugins for this theme
		 */
		function boldthemes_theme_register_required_plugins() {

			$plugins = array(
		 
				array(
					'name'               => esc_html__( 'Celebration', 'celebration' ), // The plugin name.
					'slug'               => 'celebration', // The plugin slug (typically the folder name).
					'source'             => get_template_directory() . '/plugins/celebration.zip', // The plugin source.
					'required'           => true, // If false, the plugin is only 'recommended' instead of required.
					'version'            => '1.3.9', ///!do not change this comment! E.g. 1.0.0. If set, the active plugin must be this version or higher.
					'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
					'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins.
					'external_url'       => '', // If set, overrides default API URL and points to an external URL.
				),
				array(
					'name'               => esc_html__( 'Cost Calculator', 'celebration' ), // The plugin name.
					'slug'               => 'bt' . '_cost_calculator', // The plugin slug (typically the folder name).
					'source'             => get_template_directory() . '/plugins/' . 'bt' . '_cost_calculator.zip', // The plugin source.
					'required'           => true, // If false, the plugin is only 'recommended' instead of required.
					'version'            => '1.2.4', // E.g. 1.0.0. If set, the active plugin must be this version or higher.
					'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
					'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins.
					'external_url'       => '', // If set, overrides default API URL and points to an external URL.
				),
				array(
					'name'               => esc_html__( 'Rapid Composer', 'celebration' ), // The plugin name.
					'slug'               => 'rapid_composer', // The plugin slug (typically the folder name).
					'source'             => get_template_directory() . '/plugins/rapid_composer.zip', // The plugin source.
					'required'           => true, // If false, the plugin is only 'recommended' instead of required.
					'version'            => '1.2.9', // E.g. 1.0.0. If set, the active plugin must be this version or higher.
					'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
					'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins.
					'external_url'       => '', // If set, overrides default API URL and points to an external URL.
				),
				array(
					'name'               => esc_html__( 'BoldThemes WordPress Importer', 'celebration' ), // The plugin name.
					'slug'               => 'bt' . '_wordpress_importer', // The plugin slug (typically the folder name).
					'source'             => get_template_directory() . '/plugins/' . 'bt' . '_wordpress_importer.zip', // The plugin source.
					'required'           => true, // If false, the plugin is only 'recommended' instead of required.
					'version'            => '1.0.1', // E.g. 1.0.0. If set, the active plugin must be this version or higher.
					'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
					'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins.
					'external_url'       => '', // If set, overrides default API URL and points to an external URL.
				),
				array(
					'name'               => esc_html__( 'Meta Box', 'celebration' ), // The plugin name.
					'slug'               => 'meta-box', // The plugin slug (typically the folder name).
					'required'           => true, // If false, the plugin is only 'recommended' instead of required.
					'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
					'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins.
				),
				array(
					'name'               => esc_html__( 'Contact Form 7', 'celebration' ), // The plugin name.
					'slug'               => 'contact-form-7', // The plugin slug (typically the folder name).
					'required'           => true, // If false, the plugin is only 'recommended' instead of required.
					'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
					'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins.
				),
				array(
					'name'               => esc_html__( 'Lightweight Sidebar Manager', 'celebration' ), // The plugin name.
					'slug'               => 'sidebar-manager', // The plugin slug (typically the folder name).
					'required'           => true, // If false, the plugin is only 'recommended' instead of required.
					'force_activation'   => false, // If true, plugin is activated upon theme activation and cannot be deactivated until theme switch.
					'force_deactivation' => false, // If true, plugin is deactivated upon theme switch, useful for theme-specific plugins.
				)
			);
		 
			$config = array(
				'default_path' => '',                      // Default absolute path to pre-packaged plugins.
				'menu'         => 'tgmpa-install-plugins', // Menu slug.
				'has_notices'  => true,                    // Show admin notices or not.
				'dismissable'  => false,                    // If false, a user cannot dismiss the nag message.
				'dismiss_msg'  => '',                      // If 'dismissable' is false, this message will be output at top of nag.
				'is_automatic' => false,                   // Automatically activate plugins after installation or not.
				'message'      => '',                      // Message to output right before the plugins table.
				'strings'      => array(
					'page_title'                      => esc_html__( 'Install Required Plugins', 'celebration' ),
					'menu_title'                      => esc_html__( 'Install Plugins', 'celebration' ),
					'installing'                      => esc_html__( 'Installing Plugin: %s', 'celebration' ), // %s = plugin name.
					'oops'                            => esc_html__( 'Something went wrong with the plugin API.', 'celebration' ),
					'notice_can_install_required'     => _n_noop( 'This theme requires the following plugin: %1$s.', 'This theme requires the following plugins: %1$s.', 'celebration' ), // %1$s = plugin name(s).
					'notice_can_install_recommended'  => _n_noop( 'This theme recommends the following plugin: %1$s.', 'This theme recommends the following plugins: %1$s.', 'celebration' ), // %1$s = plugin name(s).
					'notice_cannot_install'           => _n_noop( 'Sorry, but you do not have the correct permissions to install the %s plugin. Contact the administrator of this site for help on getting the plugin installed.', 'Sorry, but you do not have the correct permissions to install the %s plugins. Contact the administrator of this site for help on getting the plugins installed.', 'celebration' ), // %1$s = plugin name(s).
					'notice_can_activate_required'    => _n_noop( 'The following required plugin is currently inactive: %1$s.', 'The following required plugins are currently inactive: %1$s.', 'celebration' ), // %1$s = plugin name(s).
					'notice_can_activate_recommended' => _n_noop( 'The following recommended plugin is currently inactive: %1$s.', 'The following recommended plugins are currently inactive: %1$s.', 'celebration' ), // %1$s = plugin name(s).
					'notice_cannot_activate'          => _n_noop( 'Sorry, but you do not have the correct permissions to activate the %s plugin. Contact the administrator of this site for help on getting the plugin activated.', 'Sorry, but you do not have the correct permissions to activate the %s plugins. Contact the administrator of this site for help on getting the plugins activated.', 'celebration' ), // %1$s = plugin name(s).
					'notice_ask_to_update'            => _n_noop( 'The following plugin needs to be updated to its latest version to ensure maximum compatibility with this theme: %1$s.', 'The following plugins need to be updated to their latest version to ensure maximum compatibility with this theme: %1$s.', 'celebration' ), // %1$s = plugin name(s).
					'notice_cannot_update'            => _n_noop( 'Sorry, but you do not have the correct permissions to update the %s plugin. Contact the administrator of this site for help on getting the plugin updated.', 'Sorry, but you do not have the correct permissions to update the %s plugins. Contact the administrator of this site for help on getting the plugins updated.', 'celebration' ), // %1$s = plugin name(s).
					'install_link'                    => _n_noop( 'Begin installing plugin', 'Begin installing plugins', 'celebration' ),
					'activate_link'                   => _n_noop( 'Begin activating plugin', 'Begin activating plugins', 'celebration' ),
					'return'                          => esc_html__( 'Return to Required Plugins Installer', 'celebration' ),
					'plugin_activated'                => esc_html__( 'Plugin activated successfully.', 'celebration' ),
					'complete'                        => esc_html__( 'All plugins installed and activated successfully. %s', 'celebration' ), // %s = dashboard link.
					'nag_type'                        => 'updated' // Determines admin notice type - can only be 'updated', 'update-nag' or 'error'.
				)
			);
		 
			tgmpa( $plugins, $config );
		 
		}
		
		/**
		 * Custom search form
		 *
		 * @return string
		 */
		function boldthemes_search_form( $form ) {
			$form = boldthemes_get_icon_html( 'fa_f002', '#', '', 'btIcoDefaultType btIcoDefaultColor btIcoExtraSmallSize', '' );
			$form .= '
			<div class="btSearchInner" role="search">
				<div class="btSearchInnerContent">
					<form action="' . home_url( '/' ) . '" method="get"><input type="text" name="s" placeholder="' . esc_attr( esc_html__( 'Looking for...', 'celebration' ) ) . '" class="untouched">
					<button type="submit" data-icon="&#xf105;"></button>
					</form>
					<div class="btSearchInnerClose">' . boldthemes_get_icon_html( 'fa_f00d', '#', '', 'btIcoOutlineType btIcoDefaultColor btIcoMediumSize', '' ) . '</div>
				</div>
			</div>';
			return '<div class="btSearch">' . ( $form ) . '</div>';
		}

		/**
		 * Removes more link scroll
		 *
		 * @return string
		 */
		function boldthemes_remove_more_link_scroll( $link ) {
			$link = preg_replace( '|#more-[0-9]+|', '', $link );
			return $link;
		}
		
		/**
		 * Category list custom HTML
		 *
		 * @return string
		 */
		function boldthemes_cat_count_span( $links ) {
			$links = str_replace('</a> (', '</a> <strong>', $links );
			$links = str_replace(')', '</strong>', $links );
			return $links;
		}

		/**
		 * Archive link custom HTML
		 *
		 * @return string 
		 */
		function boldthemes_arch_count_span( $links ) {
			$links = str_replace('&nbsp;(', ' <strong>', $links );
			$links = str_replace(')', '</strong>', $links );
			return $links;
		}
		
		/**
		 * Removes whitespace between tags in menu items
		 */
		function boldthemes_remove_menu_item_whitespace( $items ) {
			return preg_replace( '/>(\s|\n|\r)+</', '><', $items );
		}
		
		/**
		 * Video shortcode custom HTML
		 *
		 * @return string
		 */
		function boldthemes_wp_video_shortcode( $item_html, $atts, $video, $post_id, $library ) {
			$replace_value = 'width: ' . $atts['width'] . 'px';
			$replace_with  = 'width: 100%';
			return str_ireplace( $replace_value, $replace_with, $item_html );
		}

		/**
		 * Enqueue video shortcode custom JS
		 *
		 * @return string 
		 */
		function boldthemes_wp_video_shortcode_library() {
			wp_enqueue_style( 'wp-mediaelement' );
			wp_enqueue_script( 'celebration-video-shortcode', get_template_directory_uri() . '/js/video_shortcode.js', array( 'mediaelement' ), '', true );
			return 'boldthemes_mejs';
		}

		/**
		 * Enqueue audio shortcode custom JS
		 *
		 * @return string 
		 */
		function boldthemes_wp_audio_shortcode_library() {
			wp_enqueue_style( 'wp-mediaelement' );
			wp_enqueue_script( 'celebration-audio-shortcode', get_template_directory_uri() . '/js/audio_shortcode.js', array( 'mediaelement' ), '', true );
			return 'boldthemes_mejs';
		}

		/**
		 * Custom wp_title
		 *
		 * @return string
		 */
		function boldthemes_title( $title, $short = false ) {
			if ( empty( $title ) && ( is_home() || is_front_page() ) ) {
				$title = esc_html__( 'Home', 'celebration' );
			}
			if ( $short ) return trim( $title );
			else return trim( $title ) . ' / ' . get_bloginfo( 'name' );
		}
		
		/**
		 * Body classes
		 *
		 * @return array
		 */
		function boldthemes_classes( $classes ) {
			return array_merge( $classes, boldthemes_get_body_class() );
		}		
		
		/**
		 * Remove product tabs
		 */
		function boldthemes_woo_remove_product_tabs( $tabs ) {
			unset( $tabs['reviews'] ); 			// Remove the reviews tab

			return $tabs;
		}
		
		/**
		 * Change number of related products
		 */
		function boldthemes_change_number_related_products( $args ) {
			$args['posts_per_page'] = 3; // # of related products
			$args['columns'] = 3; // # of columns per row
			return $args;
		}
		
		/**
		 * Loop shop per page
		 */
		function boldthemes_loop_shop_per_page( $cols ) {
			return 12;
		}
		
		/**
		 * Loop columns
		 */
		function boldthemes_loop_shop_columns() {
			return 3; // 3 products per row
		}
		
	}

	$boldthemes_theme = new CelebrationTheme();

}

// set content width
if ( ! isset( $content_width ) ) {
	$content_width = 1200;
}

// define prefix
if ( ! defined( 'BoldThemesPFX' ) ) {
	define( 'BoldThemesPFX', 'boldthemes_theme' );
}

if ( file_exists( get_template_directory() . '/css-crush/CssCrush.php' ) ) {
	require_once( get_template_directory() . '/css-crush/CssCrush.php' );
} else {
	require_once( get_template_directory() . '/php/BTCrushFunctions.php' );
	require_once( get_template_directory() . '/php/BTCrushUtil.php' );
	require_once( get_template_directory() . '/php/BTCrushColor.php' );
	require_once( get_template_directory() . '/php/BTCrushRegex.php' );
}
require_once( get_template_directory() . '/config-meta-boxes.php' );
require_once( get_template_directory() . '/php/breadcrumbs.php' );
require_once( get_template_directory() . '/php/customization.php' );
require_once( get_template_directory() . '/php/boldthemes_functions.php' );
require_once( get_template_directory() . '/editor-buttons/editor-buttons.php' );
require_once( get_template_directory() . '/class-tgm-plugin-activation.php' );

/**
 * Pagination output for post archive
 */
if ( ! function_exists( 'boldthemes_pagination' ) ) {
	function boldthemes_pagination() {
	
		$prev = get_previous_posts_link( esc_html__( 'Newer Posts', 'celebration' ) );
		$next = get_next_posts_link( esc_html__( 'Older Posts', 'celebration' ) );
		
		$pattern = '/(<a href=".*">)(.*)(<\/a>)/';
		
		echo '<div class="btPagination">';
			if ( $prev != '' ) {
				echo '<div class="paging onLeft">';
					echo '<p class="pagePrev">';
						echo preg_replace( $pattern, '<span class="nbsItem"><span class="nbsTitle">$2</span></span>', $prev );
					echo '</p>';
				echo '</div>';
			}
			if ( $next != '' ) {
				echo '<div class="paging onRight">';
					echo '<p class="pageNext">';
						echo preg_replace( $pattern, '<span class="nbsItem"><span class="nbsTitle">$2</span></span>', $next );
					echo '</p>';
				echo '</div>';
			}
		echo '</div>';
	}
}

/**
 * Backwards compatibility
 */
if ( ! function_exists( '_wp_render_title_tag' ) ) {
	function boldthemes_render_title() {
		?>
		<title><?php wp_title( '/', true, 'right' ); ?></title>
		<?php
	}
	add_action( 'wp_head', 'boldthemes_render_title' );
}

/**
 * Custom MetaBox input used for Override Global Settings
 */
if ( ! class_exists( 'RWMB_BoldThemesText_Field' ) && class_exists( 'RWMB_Field' ) ) {
	class RWMB_BoldThemesText_Field extends RWMB_Field {
	
		static function admin_enqueue_scripts() {			
			wp_enqueue_script( 
				'celebration-boldthemes-text',
				get_template_directory_uri() . '/js/boldthemes_text.js',
				array( 'jquery' ),
				'',
				true
			);
		}

		static function html( $meta, $field ) {	
			$meta_key = substr( $meta, 0, strpos( $meta, ':' ) );
			$meta_value = substr( $meta, strpos( $meta, ':' ) + 1 );
			$vars = get_class_vars( 'BoldThemes_Customize_Default' );
			$select = '<select class="boldthemes_key_select" style="vertical-align:baseline;height:auto;">';
			$select .= '<option value=""></option>';
			foreach ( $vars as $key => $var ) {
				$selected_html = '';
				if ( BoldThemesPFX . '_' . $key == $meta_key ) {
					$selected_html = 'selected="selected"';
				}
				$select .= '<option value="' . esc_attr( BoldThemesPFX . '_' . $key ) . '" ' . ( $selected_html ) . '>' . esc_html( $key ) . '</option>';
			}
			$select .= '</select>';
			$input = ' <input type="text" class="boldthemes_value" value="' . esc_attr( $meta_value ) . '">';
			return sprintf(
				'<input type="hidden" class="rwmb-text" name="%s" id="%s" value="%s" placeholder="%s" %s>%s',
				$field['field_name'],
				$field['id'],
				$meta,
				$field['placeholder'],
				'',
				self::datalist_html($field)
			) . $select . $input;
		}

		static function normalize_field( $field ) {
			$field = wp_parse_args( $field, array(
				'size'        => 30,
				'datalist'    => false,
				'placeholder' => '',
			) );
			return $field;
		}

		static function datalist_html( $field ) {
			return '';
		}
	}
}

/**
 * Custom MetaBox input used for custom key-value pairs
 */
if ( ! class_exists( 'RWMB_BoldThemesText1_Field' ) && class_exists( 'RWMB_Field' ) ) {
	class RWMB_BoldThemesText1_Field extends RWMB_Field {
	
		static function admin_enqueue_scripts() {			
			wp_enqueue_script( 
				'celebration-boldthemes-text',
				get_template_directory_uri() . '/js/boldthemes_text.js',
				array( 'jquery' ),
				'',
				true
			);
		}

		static function html( $meta, $field ) {
		
			$meta_key = substr( $meta, 0, strpos( $meta, ':' ) );
			$meta_value = substr( $meta, strpos( $meta, ':' ) + 1 );
			
			$vars = get_class_vars( 'BoldThemes_Customize_Default' );
			
			$key_input = '<input type="text" class="boldthemes_key" value="' . esc_attr( $meta_key ) . '">';
			
			$input = ' <input type="text" class="boldthemes_value" value="' . esc_attr( $meta_value ) . '">';
			
			return sprintf(
				'<input type="hidden" class="rwmb-text" name="%s" id="%s" value="%s" placeholder="%s" %s>%s',
				$field['field_name'],
				$field['id'],
				$meta,
				$field['placeholder'],
				'',
				self::datalist_html($field)
			) . $key_input . $input;
		}
		
		static function normalize_field( $field ) {
			$field = wp_parse_args( $field, array(
				'size'        => 30,
				'datalist'    => false,
				'placeholder' => '',
			) );
			return $field;
		}

		static function datalist_html( $field ) {
			return '';
		}
	}
}

/**
 * Custom comments HTML output
 */
if ( ! function_exists( 'boldthemes_theme_comment' ) ) {
	function boldthemes_theme_comment( $comment, $args, $depth ) {
		$GLOBALS['comment'] = $comment;
		
		$rating = intval( get_comment_meta( $comment->comment_ID, 'rating', true ) );
		
		switch ( $comment->comment_type ) :
			case 'pingback' :
			case 'trackback' :
			// Display trackbacks differently than normal comments.
		?>
		<li <?php comment_class(); ?> id="comment-<?php comment_ID(); ?>">
			<p><?php esc_html_e( 'Pingback:', 'celebration' ); ?> <?php comment_author_link(); ?> <?php edit_comment_link( esc_html__( '(Edit)', 'celebration' ), '<span class="edit-link">', '</span>' ); ?></p>
		<?php
				break;
			default :
			// Proceed with normal comments.
			global $post;
		?>
		<li <?php comment_class(); ?> id="li-comment-<?php comment_ID(); ?>">
			<article id="comment-<?php comment_ID(); ?>" class = "">
				<?php $avatar_html = get_avatar( $comment, 140 ); 
					if ( $avatar_html != '' ) {
						echo '<div class="commentAvatar">' . wp_kses_post( $avatar_html ) . '</div>';
					}
				?>
				<div class="commentTxt">
					<div class="vcard divider">
						<?php
							printf( '<h5 class="author"><span class="fn">%1$s</span></h5>', get_comment_author_link() );
							echo '<p class="posted">' . sprintf( esc_html__( '%1$s at %2$s', 'celebration' ), get_comment_date(), get_comment_time() ) . '</p>';
							if ( $rating > 0 && get_option( 'woocommerce_enable_review_rating' ) == 'yes' ) { ?>
								<div itemprop="reviewRating" itemscope itemtype="http://schema.org/Rating" class="star-rating" title="<?php echo sprintf( esc_html__( 'Rated %d out of 5', 'celebration' ), $rating ) ?>">
									<span style="width:<?php echo ( esc_html( $rating ) / 5 ) * 100; ?>%"><strong itemprop="ratingValue"><?php echo wp_kses_post( $rating ); ?></strong> <?php _e( 'out of 5', 'celebration' ); ?></span>
								</div>
							<?php }
						?>
					</div>

					<?php if ( '0' == $comment->comment_approved ) : ?>
						<p class="comment-awaiting-moderation"><?php esc_html_e( 'Your comment is awaiting moderation.', 'celebration' ); ?></p>
					<?php endif; ?>

					<div class="comment">
						

						<?php comment_text();

						if ( comments_open() ) {
							echo '<p class="reply">';
								comment_reply_link( array_merge( $args, array( 'reply_text' => esc_html__( 'Reply', 'celebration' ), 'depth' => $depth, 'max_depth' => $args['max_depth'] ) ) );
							echo '</p>';
						}
						edit_comment_link( esc_html__( 'Edit', 'celebration' ), '<p class="edit-link">', '</p>' ); ?>
					</div>
				</div>
				
				
			</article>
		<?php
			break;
		endswitch;
	}
}

/**
 * Returns attachment id by url
 *
 * @param string 
 * @return int 
 */
if ( ! function_exists( 'boldthemes_get_attachment_id_from_url' ) ) {
	function boldthemes_get_attachment_id_from_url( $attachment_url = '' ) {
	 
		global $wpdb;
		$attachment_id = false;
	 
		if ( '' == $attachment_url ) {
			return;
		}
	 
		$upload_dir_paths = wp_upload_dir();

		$attachment_url = preg_replace( '/-\d+x\d+(?=\.(jpg|jpeg|png|gif)$)/i', '', $attachment_url );
 
		$attachment_url = str_replace( $upload_dir_paths['baseurl'] . '/', '', $attachment_url );
 
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "SELECT wposts.ID FROM $wpdb->posts wposts, $wpdb->postmeta wpostmeta WHERE wposts.ID = wpostmeta.post_id AND wpostmeta.meta_key = '_wp_attached_file' AND wpostmeta.meta_value = '%s' AND wposts.post_type = 'attachment'", $attachment_url ) );
	 
		return $attachment_id;
	}
}

/**
 * Get array of data for a range of posts, used in grid layout
 *
 * @param int $number
 * @param int $offset
 * @param string $cat_slug Category slug
 * @param string $post_type
 * @param string $related
 * @param string $sticky_in_grid
 * @return array Array of data for a range of posts
 */
if ( ! function_exists( 'boldthemes_get_posts_data' ) ) {
	function boldthemes_get_posts_data( $number, $offset, $cat_slug, $post_type = 'blog' ) {
		
		$posts_data1 = array();
		$posts_data2 = array();
		
		$sticky = false;
		if ( intval( boldthemes_get_option( 'sticky_in_grid' ) == 1 ) ) {
			$sticky = true;
			$sticky_array = get_option( 'sticky_posts' );
		}
		
		if ( $offset == 0 && $sticky ) {
			$recent_posts_q_sticky = new WP_Query( array( 'post__in' => $sticky_array, 'post_status' => 'publish' ) );
			$posts_data1 = boldthemes_get_posts_array( $recent_posts_q_sticky, array() );
		}
		
		if ( $number > 0 ) {
			if ( $post_type == 'portfolio' ) {
				if ( $cat_slug != '' ) {
					$recent_posts_q = new WP_Query( array( 'post_type' => 'portfolio', 'posts_per_page' => $number, 'offset' => $offset, 'tax_query' => array( array( 'taxonomy' => 'portfolio_category', 'field' => 'slug', 'terms' => array( $cat_slug ) ) ), 'post_status' => 'publish' ) );
				} else {
					$recent_posts_q = new WP_Query( array( 'post_type' => 'portfolio', 'posts_per_page' => $number, 'offset' => $offset, 'post_status' => 'publish' ) );
				}
			} else {
				if ( $cat_slug != '' ) {
					$recent_posts_q = new WP_Query( array( 'posts_per_page' => $number, 'offset' => $offset, 'category_name' => $cat_slug, 'post_status' => 'publish' ) );
				} else {
					$recent_posts_q = new WP_Query( array( 'posts_per_page' => $number, 'offset' => $offset, 'post_status' => 'publish' ) );
				}
			}
		}
		
		if ( $sticky ) {
			$posts_data2 = boldthemes_get_posts_array( $recent_posts_q, $post_type, $sticky_array );
		} else {
			$posts_data2 = boldthemes_get_posts_array( $recent_posts_q, $post_type, array() );
		}		
		
		return array_merge( $posts_data1, $posts_data2 );

	}
}

/**
 * boldthemes_get_posts_data helper function
 *
 * @param object
 * @param array 
 * @return array 
 */
if ( ! function_exists( 'boldthemes_get_posts_array' ) ) {
	function boldthemes_get_posts_array( $recent_posts_q, $post_type = 'blog', $sticky_arr ) {
		
		$posts_data = array();

		while ( $recent_posts_q->have_posts() ) {
			$recent_posts_q->the_post();
			$post = get_post();
			$post_author = $post->post_author;
			$post_id = get_the_ID();
			if ( in_array( $post_id, $sticky_arr ) ) {
				continue;
			}
			$posts_data[] = boldthemes_get_posts_array_item( $post_type, $post_id, $post_author );
		}
		
		wp_reset_postdata();
		
		return $posts_data;
	}
}

/**
 * Returns post excerpt by post id
 *
 * @param int
 * @return string 
 */
if ( ! function_exists( 'boldthemes_get_the_excerpt' ) ) {
	function boldthemes_get_the_excerpt( $post_id ) {
		global $post;  
		$save_post = $post;
		$post = get_post( $post_id );
		$output = get_the_excerpt();
		$post = $save_post;
		return $output;
	}
}

/**
 * boldthemes_get_posts_array helper function
 *
 * @return array
 */
if ( ! function_exists( 'boldthemes_get_posts_array_item' ) ) {
	function boldthemes_get_posts_array_item( $post_type = 'blog', $post_id, $post_author ) {
		
		$post_data = array();
		$post_data['permalink'] = get_permalink( $post_id );
		$post_data['format'] = get_post_format( $post_id );
		$post_data['title'] = get_the_title( $post_id );
		
		$post_data['excerpt'] = boldthemes_get_the_excerpt( $post_id );
		
		$post_data['date'] = date_i18n( CelebrationTheme::$boldthemes_date_format, strtotime( get_the_time( 'Y-m-d', $post_id ) ) );
		
		$user_data = get_userdata( $post_author );
		if ( $user_data ) {
			$author = $user_data->data->display_name;
			$author_url = get_author_posts_url( $post_author );
			$post_data['author'] = '<a href="' . esc_url_raw( $author_url ) . '">' . esc_html( $author ) . '</a>';
		} else {
			$post_data['author'] = '';
		}

		if ( $post_type == 'portfolio' ) {
			$categories = wp_get_post_terms( $post_id, 'portfolio_category' );
		} else {
			$categories = get_the_category( $post_id );
		}
		$categories_html = '';
		if ( $categories ) {
			foreach ( $categories as $cat ) {
				if ( $post_type == 'portfolio' ) {
					$categories_html .= esc_html( $cat->name ) . ', ';
				} else {
					$categories_html .= '<a href="' . esc_url_raw( get_category_link( $cat->term_id ) ) . '">' . esc_html( $cat->name ) . '</a>' . ', ';
				}
			}
			$categories_html = trim( $categories_html, ', ' );
		}

		$post_data['category'] = $categories_html;
		
		$comments_open = comments_open( $post_id );
		$comments_number = get_comments_number( $post_id );
		if ( ! $comments_open && $comments_number == 0 ) {
			$comments_number = false;
		}			
		
		$post_data['images'] = boldthemes_rwmb_meta( BoldThemesPFX . '_images', 'type=image', $post_id );
		if ( $post_data['images'] == null ) $post_data['images'] = array();
		$post_data['video'] = boldthemes_rwmb_meta( BoldThemesPFX . '_video', array(), $post_id );
		$post_data['audio'] = boldthemes_rwmb_meta( BoldThemesPFX . '_audio', array(), $post_id );
		$post_data['grid_gallery'] = boldthemes_rwmb_meta( BoldThemesPFX . '_grid_gallery', array(), $post_id );
		$post_data['link_title'] = boldthemes_rwmb_meta( BoldThemesPFX . '_link_title', array(), $post_id );
		$post_data['link_url'] = boldthemes_rwmb_meta( BoldThemesPFX . '_link_url', array(), $post_id );
		$post_data['quote'] = boldthemes_rwmb_meta( BoldThemesPFX . '_quote', array(), $post_id );
		$post_data['quote_author'] = boldthemes_rwmb_meta( BoldThemesPFX . '_quote_author', array(), $post_id );
		$post_data['tile_format'] = boldthemes_rwmb_meta( BoldThemesPFX . '_tile_format', array(), $post_id );
		$post_data['comments'] = $comments_number;
		$post_data['ID'] = $post_id;
		
		return $post_data;
	}
}

/**
 * Custom MetaBox getter function
 *
 * @return string
 */
if ( ! function_exists( 'boldthemes_rwmb_meta' ) ) {
	function boldthemes_rwmb_meta( $key, $args = array(), $post_id = null ) {
		if ( function_exists( 'rwmb_meta' ) ) {
			return rwmb_meta( $key, $args, $post_id );
		} else {
			return null;
		}
	}
}

/**
 * Returns page id by slug
 *
 * @return string
 */
if ( ! function_exists( 'boldthemes_get_id_by_slug' ) ) {
	function boldthemes_get_id_by_slug( $page_slug ) {
		$page = get_posts(
			array(
				'name'      => $page_slug,
				'post_type' => 'page'
			)
		);
		return $page[0]->ID;
	}
}

/**
 * Creates override of global options for individual posts
 */
if ( ! function_exists( 'boldthemes_set_override' ) ) {
	function boldthemes_set_override() {
		global $boldthemes_options;
		$boldthemes_options = get_option( BoldThemesPFX . '_theme_options' );

		global $boldthemes_page_options;
		$boldthemes_page_options = array();
		 
		if ( ! is_404() ) {
			$tmp_boldthemes_page_options = boldthemes_rwmb_meta( BoldThemesPFX . '_override' );
			$tmp_boldthemes_page_options1 = '';
			if ( ( is_search() || is_archive() || is_home() ) && get_option( 'page_for_posts' ) != 0 ) {
				$tmp_boldthemes_page_options1 = boldthemes_rwmb_meta( BoldThemesPFX . '_override', array(), get_option( 'page_for_posts' ) );
			} 			
			if ( is_singular( 'post' ) && isset( $boldthemes_options['blog_settings_page_slug'] ) && isset( $boldthemes_options['blog_settings_page_slug'] ) && $boldthemes_options['blog_settings_page_slug'] != '' ) {
				$tmp_boldthemes_page_options1 = boldthemes_rwmb_meta( BoldThemesPFX . '_override', array(), boldthemes_get_id_by_slug( $boldthemes_options['blog_settings_page_slug'] ) );
			} 
			if ( ( is_post_type_archive( 'portfolio' ) || is_singular( 'portfolio' ) ) && isset( $boldthemes_options['pf_settings_page_slug'] ) && $boldthemes_options['pf_settings_page_slug'] != '' ) {
				$tmp_boldthemes_page_options1 = boldthemes_rwmb_meta( BoldThemesPFX . '_override', array(), boldthemes_get_id_by_slug( $boldthemes_options['pf_settings_page_slug'] ) );
			} 
			if ( function_exists( 'is_shop' ) && is_shop() && get_option( 'woocommerce_shop_page_id' ) ) {
				$tmp_boldthemes_page_options1 = boldthemes_rwmb_meta( BoldThemesPFX . '_override', array(), get_option( 'woocommerce_shop_page_id' ) );
			}
			if ( function_exists( 'is_product_category' ) && is_product_category() && get_option( 'woocommerce_shop_page_id' ) ) {
				$tmp_boldthemes_page_options1 = boldthemes_rwmb_meta( BoldThemesPFX . '_override', array(), get_option( 'woocommerce_shop_page_id' ) );
			}
			if ( function_exists( 'is_product' ) && is_product() && isset( $boldthemes_options['shop_settings_page_slug'] ) && $boldthemes_options['shop_settings_page_slug'] != '' ) {
				$tmp_boldthemes_page_options1 = boldthemes_rwmb_meta( BoldThemesPFX . '_override', array(), boldthemes_get_id_by_slug( $boldthemes_options['shop_settings_page_slug'] ) );
			}
			

			if ( ! is_array( $tmp_boldthemes_page_options ) ) $tmp_boldthemes_page_options = array();

			if ( is_array( $tmp_boldthemes_page_options1 ) ) {
				if ( is_singular() ) {
					$tmp_boldthemes_page_options = array_merge( boldthemes_transform_override( $tmp_boldthemes_page_options1 ), boldthemes_transform_override( $tmp_boldthemes_page_options ) );
				} else {
					$tmp_boldthemes_page_options = boldthemes_transform_override( $tmp_boldthemes_page_options1 );
				}
			} else if ( count( $tmp_boldthemes_page_options ) > 0 ) {
				$tmp_boldthemes_page_options = boldthemes_transform_override( $tmp_boldthemes_page_options );
			}

			foreach ( $tmp_boldthemes_page_options as $key => $value ) {
				$boldthemes_page_options[ $key ] = $value;
			}
		}
	}
}

/**
 * boldthemes_set_override helper function
 *
 * @param array
 * @return array
 */
if ( ! function_exists( 'boldthemes_transform_override' ) ) {
	function boldthemes_transform_override( $arr ) {
		$new_arr = array();
		foreach( $arr as $item ) {
			$key = substr( $item, 0, strpos( $item, ':' ) );
			$value = substr( $item, strpos( $item, ':' ) + 1 );
			$new_arr[ $key ] = $value;
		}
		return $new_arr;
	}
}

/**
 * theme name and version in data attribute
 */
if ( ! function_exists( 'boldthemes_theme_data' ) ) {
	function boldthemes_theme_data() {
		$data = wp_get_theme();
		echo 'data-bt-theme="' . esc_attr( $data['Name'] ) . ' ' . esc_attr( $data['Version'] ) . '"';
	}
}

/**
 * Header meta tags output
 */
if ( ! function_exists( 'boldthemes_header_meta' ) ) {
	function boldthemes_header_meta() {
		$desc = boldthemes_rwmb_meta( BoldThemesPFX . '_description' );
		
		if ( $desc != '' ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">';
		}
		
		if ( is_single() ) {
			echo '<meta property="twitter:card" content="summary">';

			echo '<meta property="og:title" content="' . get_the_title() . '" />';
			echo '<meta property="og:type" content="article" />';
			echo '<meta property="og:url" content="' . get_permalink() . '" />';
			
			$img = null;
			
			$boldthemes_featured_slider = boldthemes_get_option( 'blog_ghost_slider' ) && has_post_thumbnail();
			if ( $boldthemes_featured_slider ) {
				$post_thumbnail_id = get_post_thumbnail_id( get_the_ID() );
				$img = wp_get_attachment_image_src( $post_thumbnail_id, 'full' );
				$img = $img[0];
			} else {
				$images = boldthemes_rwmb_meta( BoldThemesPFX . '_images', 'type=image' );
				if ( is_array( $images ) ) {
					foreach ( $images as $img ) {
						$img = $img['full_url'];
						break;
					}
				}
			}
			if ( $img ) {
				echo '<meta property="og:image" content="' . esc_attr( $img ) . '" />';
			}
			
			if ( $desc != '' ) {
				echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />';
			}
		}
		
		?>
		<meta charset="<?php bloginfo( 'charset' ); ?>">
		<?php echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-capable" content="yes">';
		
	}
}

/**
 * Header menu output
 */
if ( ! function_exists( 'boldthemes_nav_menu' ) ) {
	function boldthemes_nav_menu() {
		if ( boldthemes_rwmb_meta( BoldThemesPFX . '_menu_name' ) != '' ) {
			wp_nav_menu( array( 'menu' => boldthemes_rwmb_meta( BoldThemesPFX . '_menu_name' ), 'container' => '', 'depth' => 3, 'fallback_cb' => false )); 
		} else {
			wp_nav_menu( array( 'theme_location' => 'primary', 'container' => '', 'depth' => 3, 'fallback_cb' => false ) );
		}
	}
}

/**
 * Returns body classes
 */
if ( ! function_exists( 'boldthemes_get_body_class' ) ) {
	function boldthemes_get_body_class() {
		
		$extra_class[] = 'bodyPreloader'; 
		
		$menu_type = boldthemes_get_option( 'menu_type' );
		if ( $menu_type == 'hCenter' ) {
			$extra_class[] = 'btMenuCenterEnabled'; 
		} else if ( $menu_type == 'hRight' ) {
			$extra_class[] = 'btMenuRightEnabled';
		} else if ( $menu_type == 'hLeft' ) {
			$extra_class[] = 'btMenuLeftEnabled';
		} else if ( $menu_type == 'vLeft' ) {
			$extra_class[] = 'btMenuVerticalLeftEnabled';
		} else if ( $menu_type == 'vRight' ) {
			$extra_class[] = 'btMenuVerticalRightEnabled';
		} else {
			$extra_class[] = 'btMenuRightEnabled';
		}

		if ( boldthemes_get_option( 'sticky_header' ) ) {
			$extra_class[] = 'btStickyEnabled';
		}

		if ( boldthemes_get_option( 'hide_menu' ) ) {
			$extra_class[] = 'btHideMenu';
		}

		if ( boldthemes_get_option( 'template_skin' ) ) {
			$extra_class[] = 'btDarkSkin';
		} else {
			$extra_class[] = 'btLightSkin';
		}

		if ( boldthemes_get_option( 'below_menu' ) && ! is_search() ) {
			$extra_class[] = 'btBelowMenu';
		}

		if ( ! boldthemes_get_option( 'sidebar_use_dash' ) ) {
			$extra_class[] = 'btNoDashInSidebar';
		}

		if ( boldthemes_get_option( 'top_tools_in_menu' ) ) {
			$extra_class[] = 'btTopToolsInMenuArea';
		}
		
		if ( boldthemes_get_option( 'boxed_menu' ) ) {
			$extra_class[] = 'btMenuGutter';
		}

		CelebrationTheme::$boldthemes_sidebar = boldthemes_get_option( 'sidebar' );

		if ( ! ( ( CelebrationTheme::$boldthemes_sidebar == 'left' || CelebrationTheme::$boldthemes_sidebar == 'right' ) && ! is_404() && ! is_search() ) ) {
			CelebrationTheme::$boldthemes_has_sidebar = false;
			$extra_class[] = 'btNoSidebar';
		} else {
			CelebrationTheme::$boldthemes_has_sidebar = true;
			if ( CelebrationTheme::$boldthemes_sidebar == 'left' ) {
				$extra_class[] = 'btWithSidebar btSidebarLeft';
			} else {
				$extra_class[] = 'btWithSidebar btSidebarRight';
			}
		}
		
		$animations = boldthemes_rwmb_meta( BoldThemesPFX . '_animations' );
		if ( $animations == 'half_page' ) {
			$extra_class[] = 'btHalfPage';
		}
		
		return $extra_class;
	}
}

/**
 * Enqueue comment script
 */
if ( ! function_exists( 'boldthemes_header_init' ) ) {
	function boldthemes_header_init() {
		if ( is_singular() ) {
			wp_enqueue_script( 'comment-reply' );
		}
	}
}

/**
 * Header headline output
 */
if ( ! function_exists( 'boldthemes_header_headline' ) ) {
	function boldthemes_header_headline() {
		$hide_headline = boldthemes_get_option( 'hide_headline' );
		if ( ( ! $hide_headline && ! is_404() && ! is_single() ) || is_search() ) { 
			echo "<section class='btPageHeadline gutter'><div class='port'>" . boldthemes_get_heading_html( '', wp_title( '/', false, 'right' ),  boldthemes_breadcrumbs(), 'large', 'bottom', '', '' ) . "</div></section>";
		}
 	}
}

if ( class_exists( 'WooCommerce' ) ) {
	global $woocommerce;
	if ( ! defined( 'WOOCOMMERCE_VERSION')  ) {
		define('WOOCOMMERCE_VERSION', $woocommerce->version);
	}
	if ( ! defined( 'NEW_WOOCOMMERCE_VERSION')  ) {
		define('NEW_WOOCOMMERCE_VERSION', '3.0');
	}
}

if ( ! function_exists( 'boldthemes_woocommerce_is_new_version' ) ) {	 
	/**
	 * Get the woocommerce version and set functions.
	 * @return bool
	 */
	function boldthemes_woocommerce_is_new_version() {

		if ( !class_exists( 'WooCommerce' ) ) {
			return false;
		}

		global $woocommerce;
		if ( version_compare( $woocommerce->version, '3.0', '>=') )  {
			return true;
		}
		
		return false;			
	}
}

/**
 * Show the product title in the product loop.
 */
if (  ! function_exists( 'woocommerce_template_loop_product_title' ) ) {
	function woocommerce_template_loop_product_title() {
		global $product;

		$subtitle = '';

		if ( boldthemes_woocommerce_is_new_version() ) {

			$supertitle = '<span class = "btArticleCategories">' . wc_get_product_category_list( $product->get_id(),'', '<span class="btArticleCategory">', '</span>' ) . "</span>";

			if ( get_option( 'woocommerce_enable_review_rating' ) !== 'no' && $rating_html = wc_get_rating_html( $product->get_average_rating() ) ) {
				$subtitle = $rating_html ;
			}

		}else{

			$supertitle = '<span class = "btArticleCategories">' . ( $product->get_categories( '', '<span class="btArticleCategory">', '</span>' ) ) . '</span>';

			if ( get_option( 'woocommerce_enable_review_rating' ) !== 'no' && $rating_html = $product->get_rating_html() ) {
				$subtitle = $rating_html ;
			}

		}


		if ( $subtitle == '' ) {
			$subtitle = '<span class="btNoStarRating"></span>';	;
		}

		$title = '<a href = "' . get_permalink( ) . '">' . get_the_title() . '</a>';

		echo boldthemes_get_heading_html( $supertitle, $title, $subtitle, 'small', '', '', '' ) ;

	}
}
if ( ! function_exists( 'woocommerce_get_product_thumbnail' ) ) {

	/**
	 * Get the product thumbnail, or the placeholder if not set.
	 *
	 * @subpackage	Loop
	 * @param string $size (default: 'shop_catalog')
	 * @param int $deprecated1 Deprecated since WooCommerce 2.0 (default: 0)
	 * @param int $deprecated2 Deprecated since WooCommerce 2.0 (default: 0)
	 * @return string
	 */
	function woocommerce_get_product_thumbnail( $size = 'shop_catalog', $deprecated1 = 0, $deprecated2 = 0 ) {
		global $post;

		if ( has_post_thumbnail() ) {
			$thumbnail = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), $size );
			return boldthemes_get_image_html( $thumbnail[0], '', '', '', '', get_post_permalink(), '_self', false, '', 'btTextCenter' );
		} elseif ( wc_placeholder_img_src() ) {
			return wc_placeholder_img( $size );
		}
	}
}

add_action('wp_print_scripts', function () {
 //Add pages you want to allow to array
 if ( !is_page( array( 'themes','hitch-up','payment' ) ) ){
  wp_dequeue_script( 'google-recaptcha' );
  wp_dequeue_script( 'google-invisible-recaptcha' );
 }
});


