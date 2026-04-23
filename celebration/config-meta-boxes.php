<?php
/**
 * Registering meta boxes
 *
 * All the definitions of meta boxes are listed below with comments.
 * Please read them CAREFULLY.
 *
 * You also should read the changelog to know what has been changed before updating.
 *
 * For more information, please visit:
 * @link http://www.deluxeblogtips.com/meta-box/
 */


add_filter( 'rwmb_meta_boxes', 'boldthemes_register_meta_boxes' );

/**
 * Register meta boxes
 *
 * @return void
 */
if ( ! function_exists( 'boldthemes_register_meta_boxes' ) ) {
	function boldthemes_register_meta_boxes( $meta_boxes ) {
		/**
		 * Prefix of meta keys (optional)
		 * Use underscore (_) at the beginning to make keys hidden
		 * Alt.: You also can make prefix empty to disable it
		 */
		// Better has an underscore as last sign
		
		$prefix = BoldThemesPFX . '_';
		
		// PAGE
		$meta_boxes[] = array(
			// Meta box id, UNIQUE per meta box. Optional since 4.1.5
			'id' => 'boldthemes_meta_settings',

			// Meta box title - Will appear at the drag and drop handle bar. Required.
			'title' => esc_html__( 'Settings', 'celebration' ),

			// Post types, accept custom post types as well - DEFAULT is array('post'). Optional.
			'pages' => array( 'page' ),

			// Where the meta box appear: normal (default), advanced, side. Optional.
			'context' => 'normal',

			// Order of meta box: high (default), low. Optional.
			'priority' => 'high',

			// Auto save: true, false (default). Optional.
			'autosave' => true,

			// List of meta fields
			'fields' => array(
				array(
					'name' => esc_html__( 'Menu Name', 'celebration' ),
					'id'   => "{$prefix}menu_name",
					'type' => 'text'
				),
				array(
					'name' => esc_html__( 'Animations', 'celebration' ),
					'id'   => "{$prefix}animations",
					'type' => 'select',
					'options' => array(
						'normal' => esc_html__( 'Normal', 'celebration' ),
						'half_page' => esc_html__( 'Half Page', 'celebration' ),
						'impress' => esc_html__( 'Impress', 'celebration' )
					)
				),				
				array(
					// Field name - Will be used as label
					'name'  => esc_html__( 'Override Global Settings', 'celebration' ),
					// Field ID, i.e. the meta key
					'id'    => "{$prefix}override",
					// Field description (optional)
					'desc'  => '',
					'type'  => 'boldthemestext',
					// CLONES: Add to make the field cloneable (i.e. have multiple value)
					'clone' => true,
				)		
			)
		);
		
		// BLOG
		$meta_boxes[] = array(
			// Meta box id, UNIQUE per meta box. Optional since 4.1.5
			'id' => 'boldthemes_meta_blog_settings',

			// Meta box title - Will appear at the drag and drop handle bar. Required.
			'title' => esc_html__( 'Settings', 'celebration' ),

			// Post types, accept custom post types as well - DEFAULT is array('post'). Optional.
			'pages' => array( 'post' ),

			// Where the meta box appear: normal (default), advanced, side. Optional.
			'context' => 'normal',

			// Order of meta box: high (default), low. Optional.
			'priority' => 'high',

			// Auto save: true, false (default). Optional.
			'autosave' => true,

			// List of meta fields
			'fields' => array(
				array(
					'name' => esc_html__( 'Meta Description', 'celebration' ),
					'id'   => "{$prefix}description",
					'type' => 'textarea'
				),
				array(
					'name' => esc_html__( 'Images', 'celebration' ),
					'id'   => "{$prefix}images",
					'type' => 'image_advanced'
				),
				array(
					'name' => esc_html__( 'Grid Gallery', 'celebration' ),
					'id'   => "{$prefix}grid_gallery",
					'type' => 'checkbox'
				),
				array(
					'name'  => esc_html__( 'Grid Gallery Format', 'celebration' ),
					'id'    => "{$prefix}grid_gallery_format",
					'type'  => 'text'
				),
				array(
					'name'  => esc_html__( 'Video', 'celebration' ),
					'id'    => "{$prefix}video",
					'type'  => 'text'
				),
				array(
					'name'  => esc_html__( 'Audio', 'celebration' ),
					'id'    => "{$prefix}audio",
					'type'  => 'text'
				),
				array(
					'name'  => esc_html__( 'Link', 'celebration' ),
					'id'    => "{$prefix}link_title",
					'type'  => 'text'
				),
				array(
					'name'  => esc_html__( 'Link URL', 'celebration' ),
					'id'    => "{$prefix}link_url",
					'type'  => 'text'
				),				
				array(
					'name'  => esc_html__( 'Quote', 'celebration' ),
					'id'    => "{$prefix}quote",
					'type'  => 'text'
				),
				array(
					'name'  => esc_html__( 'Quote Author', 'celebration' ),
					'id'    => "{$prefix}quote_author",
					'type'  => 'text'
				),
				array(
					'name' => esc_html__( 'Tile Format', 'celebration' ),
					'id'   => "{$prefix}tile_format",
					'type' => 'select',
					'options' => array( '11' => '1:1', '21' => '2:1', '22' => '2:2', '12' => '1:2' )
				),
				array(
					// Field name - Will be used as label
					'name'  => esc_html__( 'Override Global Settings', 'celebration' ),
					// Field ID, i.e. the meta key
					'id'    => "{$prefix}override",
					// Field description (optional)
					'desc'  => '',
					'type'  => 'boldthemestext',
					// CLONES: Add to make the field cloneable (i.e. have multiple value)
					'clone' => true,
				)
			)
		);
		
		// PORTFOLIO
		$meta_boxes[] = array(
			// Meta box id, UNIQUE per meta box. Optional since 4.1.5
			'id' => 'boldthemes_meta_portfolio_settings',

			// Meta box title - Will appear at the drag and drop handle bar. Required.
			'title' => esc_html__( 'Settings', 'celebration' ),

			// Post types, accept custom post types as well - DEFAULT is array('post'). Optional.
			'pages' => array( 'portfolio' ),

			// Where the meta box appear: normal (default), advanced, side. Optional.
			'context' => 'normal',

			// Order of meta box: high (default), low. Optional.
			'priority' => 'high',

			// Auto save: true, false (default). Optional.
			'autosave' => true,

			// List of meta fields
			'fields' => array(
				array(
					'name'  => esc_html__( 'Subheading', 'celebration' ),
					'id'    => "{$prefix}subheading",
					'type'  => 'text'
				),			
				array(
					'name' => esc_html__( 'Meta Description', 'celebration' ),
					'id'   => "{$prefix}description",
					'type' => 'textarea'
				),
				array(
					'name' => esc_html__( 'Images', 'celebration' ),
					'id'   => "{$prefix}images",
					'type' => 'image_advanced'
				),
				array(
					'name' => esc_html__( 'Grid Gallery', 'celebration' ),
					'id'   => "{$prefix}grid_gallery",
					'type' => 'checkbox'
				),
				array(
					'name'  => esc_html__( 'Grid Gallery Format', 'celebration' ),
					'id'    => "{$prefix}grid_gallery_format",
					'type'  => 'text'
				),			
				array(
					'name'  => esc_html__( 'Video', 'celebration' ),
					'id'    => "{$prefix}video",
					'type'  => 'text'
				),
				array(
					'name'  => esc_html__( 'Audio', 'celebration' ),
					'id'    => "{$prefix}audio",
					'type'  => 'text'
				),
				array(
					'name' => esc_html__( 'Tile Format', 'celebration' ),
					'id'   => "{$prefix}tile_format",
					'type' => 'select',
					'options' => array( '11' => '1:1', '21' => '2:1', '22' => '2:2', '12' => '1:2' )
				),
				array(
					// Field name - Will be used as label
					'name'  => esc_html__( 'Custom Fields', 'celebration' ),
					// Field ID, i.e. the meta key
					'id'    => "{$prefix}custom_fields",
					// Field description (optional)
					'desc'  => '',
					'type'  => 'boldthemestext1',
					// CLONES: Add to make the field cloneable (i.e. have multiple value)
					'clone' => true,
				),		
				array(
					// Field name - Will be used as label
					'name'  => esc_html__( 'Override Global Settings', 'celebration' ),
					// Field ID, i.e. the meta key
					'id'    => "{$prefix}override",
					// Field description (optional)
					'desc'  => '',
					'type'  => 'boldthemestext',
					// CLONES: Add to make the field cloneable (i.e. have multiple value)
					'clone' => true,
				)
			)
		);
		
		// PRODUCT
		$meta_boxes[] = array(
			// Meta box id, UNIQUE per meta box. Optional since 4.1.5
			'id' => 'boldthemes_meta_product_settings',

			// Meta box title - Will appear at the drag and drop handle bar. Required.
			'title' => esc_html__( 'Settings', 'celebration' ),

			// Post types, accept custom post types as well - DEFAULT is array('post'). Optional.
			'pages' => array( 'product' ),

			// Where the meta box appear: normal (default), advanced, side. Optional.
			'context' => 'normal',

			// Order of meta box: high (default), low. Optional.
			'priority' => 'high',

			// Auto save: true, false (default). Optional.
			'autosave' => true,

			// List of meta fields
			'fields' => array(			
				array(
					// Field name - Will be used as label
					'name'  => esc_html__( 'Override Global Settings', 'celebration' ),
					// Field ID, i.e. the meta key
					'id'    => "{$prefix}override",
					// Field description (optional)
					'desc'  => '',
					'type'  => 'boldthemestext',
					// CLONES: Add to make the field cloneable (i.e. have multiple value)
					'clone' => true,
				)		
			)
		);		

		return $meta_boxes;
	}
}