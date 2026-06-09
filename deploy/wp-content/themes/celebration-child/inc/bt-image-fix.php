<?php
/**
 * bt-image-fix.php - child-owned override of the parent theme's PLUGGABLE
 * boldthemes_get_image_html().
 *
 * The parent guards its own definition with function_exists(), and a child
 * theme's functions.php loads BEFORE the parent's, so this definition wins and
 * the parent's is skipped. Verbatim copy of the current parent function, which
 * carries the HitchStream fix: the <img> alt is the caption text, not the image
 * URL. Drives [bt_image] (+ 5 other call sites) and survives a parent theme update.
 */
if (!defined('ABSPATH')) { exit; }

if (!function_exists('boldthemes_get_image_html')) {
	function boldthemes_get_image_html( $image, $caption, $caption_text, $size, $shape, $url, $target, $show_titles, $el_style, $el_class ) {
        
        $alt_text = sanitize_text_field( $caption_text );
		$el_style = sanitize_text_field( $el_style );
		$el_class = sanitize_text_field( $el_class );
		
		if ( $size == '' ) $size = 'large';
		if ( $shape == 'circle' ) $el_class .= ' btCircleImage';
			
		$style_html = '';
		if ( $el_style != '' ) {
			$style_html= ' ' . 'style="' . esc_attr( $el_style ) . '"';
		}	
		
		$output = '<div class = "btImage"><img src="' . esc_url_raw( $image ) . '" alt="' . $alt_text . '" ></div>';
		
		if ( strpos( $url, '<a href') === 0 ) {
			$link = $url;
		} else {
			$link = "";
		
			if ( $url != '' && $url != '#' && substr( $url, 0, 4 ) != 'http' && substr( $url, 0, 5 ) != 'https'  && substr( $url, 0, 6 ) != 'mailto' ) {
				$link_tmp = get_posts(
					array(
						'name'      => $url,
						'post_type' => 'page'
					)
				);
				
				if ( ( is_array( $link_tmp ) && count( $link_tmp ) > 0 && isset( $link_tmp[0]->ID ) ) ) {
					if ( substr( $url, 0, 4 ) == 'http' ) {
						$link = $url;
					} else {
						$link = get_permalink( $link_tmp[0]->ID );
					}
					
				} else {
					$link = $url;
				}
			} else {
				$link = $url;
			}			
			$link = '<a href="' . esc_url_raw( $link ) . '" target="' . esc_attr( $target ) . '"></a>';
		}

		if ( $caption != '' || $caption_text != '' ) {
		}
		
		if ( $url != '' ) {
			$link_output = '<div class="bpgPhoto ' . $el_class . '" ' . $style_html . '> 
					' . $link . '
					<div class="boldPhotoBox"><div class="bpbItem">' . $output . '</div></div>
					<div class="captionPane">
						<div class="captionTable">
							<div class="captionCell">
								<div class="captionTxt">';
			if ( $caption != '' || $caption_text != '' ) {
				$link_output .=			boldthemes_get_heading_html( '', $caption, $caption_text, 'small', 'bottom', '', '' );
			}
			$link_output .=		'</div>
							</div>
						</div>
					</div>';
					if ( $show_titles ) {
						$link_output .= '
						<div class="btShowTitle">
							<span class="btShowTitleCaptionTxt">'
									. boldthemes_get_heading_html( '', $caption, $caption_text, 'small', 'bottom', '', '' ) . 
								'</span>
						</div>';
					}
			$link_output .= '</div>';
			
			$output = $link_output;
		} else {
			$output = '<div class="bpgPhoto ' . $el_class . '" ' . $style_html . '>' . $output . '</div>';
		}
 		
		return $output;
	}

}
