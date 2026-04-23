<?php
add_action( 'init', 'boldthemes_buttons' );
if ( ! function_exists( 'boldthemes_buttons' ) ) {
	function boldthemes_buttons() {
		add_filter( 'mce_external_plugins', 'boldthemes_add_buttons' );
		add_filter( 'mce_buttons_3', 'boldthemes_register_buttons' );
		add_filter( 'mce_external_languages', 'boldthemes_add_tinymce_lang' );
	}
}
if ( ! function_exists( 'boldthemes_add_buttons' ) ) {
	function boldthemes_add_buttons( $plugin_array ) {
		$plugin_array['boldthemes'] = get_template_directory_uri() . '/editor-buttons/editor-buttons-plugin.js';
		return $plugin_array;
	}
}
if ( ! function_exists( 'boldthemes_register_buttons' ) ) {
	function boldthemes_register_buttons( $buttons ) {
		array_push( $buttons, 'drop_cap' );
		array_push( $buttons, 'highlight' );
		return $buttons;
	}
}
if ( ! function_exists( 'boldthemes_add_tinymce_lang' ) ) {
	function boldthemes_add_tinymce_lang( $arr ) {
		$arr['boldthemes'] = get_template_directory() . '/editor-buttons/editor-lang.php';
		return $arr;
	}
}