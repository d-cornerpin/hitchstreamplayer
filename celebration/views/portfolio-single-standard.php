<?php

	$extra_class = 'col-sm-12';
	if ( ( $cf != '' && count( $cf ) > $data_items_split ) || $categories_html != '' || $cf_right_html != '' ) {
		$extra_class = ' col-sm-9';
	}

	echo '<article class="' . esc_attr( implode( ' ', get_post_class( $class_array ) ) ) . '">';
		echo '<div class="port">';
			echo '<div class="boldCell">';
			echo '<div class="boldRow btArticleHeader">';
				echo '<div class="rowItem ' . esc_attr( $extra_class ) . '">';
					
					echo boldthemes_get_heading_html( '', get_the_title(), $subheading, 'extralarge', $dash, 'wArticleMeta', '' ) ;
					
				echo '</div><!-- /rowItem -->';
			echo '</div><!-- /boldRow -->';
			if ( $media_html != '' ) {
				echo '<div class="boldRow boldRow topSmallSpaced bottomSmallSpaced">';
					echo '<div class="rowItem col-sm-12 btTextCenter btGridGap5">' . $media_html . '</div><!-- /rowItem -->';
				echo '</div><!-- /boldRow -->';
			}
			echo '<div class="boldRow">';
				echo '<div class="rowItem ' . esc_attr( $extra_class ) . '">';
					echo '<div class="boldArticleBody btArticleBody">' . $content_html . '</div>';
				echo '</div><!-- /rowItem -->';
				if ( ( $cf != '' && count( $cf ) > $data_items_split ) || $categories_html != '' || $cf_right_html != '' ) {
				echo '<div class="rowItem col-sm-3 btTextRight">';
					echo '<dl class="btArticleMeta onBottom">';
					if ( $categories_html != '' ) {
						echo '<dt>' . esc_html__( 'Category', 'celebration' ) . '</dt>';
						echo '<dd>' . $categories_html . '</dd>';
					}
					echo wp_kses_post( $cf_right_html );
					for ( $i = $data_items_split; $i < count( $cf ); $i++ ) {
						$item = $cf[ $i ];
						$item_key = substr( $item, 0, strpos( $item, ':' ) );
						$item_value = substr( $item, strpos( $item, ':' ) + 1 );
						echo '<dt>' . $item_key . '</dt>';
						echo '<dd>' . $item_value . '</dd>';
					}
					echo '</dl>';
				echo '</div><!-- /rowItem -->';
				}
			echo '</div><!-- /boldRow -->';
			echo '<div class="boldRow bottomSmallSpaced">';
				echo '<div class="rowItem col-sm-12 btTextRight btArticleShare">';
					echo '<div class="socialRow">' . ( function_exists( 'boldthemes_get_share_html' ) ? boldthemes_get_share_html( $permalink, 'pf', 'btIcoSmallSize' ) . '</div>' : '' );
				echo '</div><!-- /rowItem -->';
			echo '</div><!-- /boldRow >';
			echo '<div class="boldRow">';
				echo '<div class="rowItem col-sm-12 btLinkPages">';
					
				wp_link_pages( array( 
					'before'      => '<ul>' . esc_html__( 'Pages:', 'celebration' ),
					'separator'   => '<li>',
					'after'       => '</ul>'
				));
				
				echo '</div><!-- /rowItem -->';
			echo '</div><!-- /boldRow -->';
		echo '</div><!-- /port -->';
	echo '</article>';

?>