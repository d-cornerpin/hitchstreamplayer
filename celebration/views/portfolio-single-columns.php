<?php

	echo '<article class="' . esc_attr( implode( ' ', get_post_class( $class_array ) ) ) . ' btPortfolioSingleItemColumns">';
		echo '<div class="port">';
			echo '<div class="boldCell">';
				echo '<div class="boldRow bottomSmallSpaced">';
					$extra_class = 'col-sm-12';
					if ( $media_html != '' ) {
							$extra_class = 'col-sm-3';
							echo '<div class="rowItem col-sm-9 btTextCenter btGridGap5">' . $media_html . '</div><!-- /rowItem -->';
					}
					echo '<div class="rowItem btTextLeft ' . esc_attr( $extra_class ) . '">';
				
					echo '<div class="btClear btSeparator bottomSmallSpaced noBorder visible-ms visible-xs"><hr></div>';
					echo boldthemes_get_heading_html( '', get_the_title(), $subheading, 'large', $dash, 'wArticleMeta', '' ) ;
					
					echo '<div class="boldArticleBody btArticleBody">' . $content_html . '</div>';
					
					
					if ( ( $cf != '' && count( $cf ) > $data_items_split ) || $categories_html != '' || $cf_right_html != '' ) {
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
					}

					echo '<div class="btClear btSeparator bottomSmallSpaced noBorder"><hr></div>';
						
					echo '<div class="socialRow">' . ( function_exists( 'boldthemes_get_share_html' ) ? boldthemes_get_share_html( $permalink, 'pf', 'btIcoSmallSize' ) . '</div>' : '' );
										
					wp_link_pages( array( 
						'before'      => '<ul>' . esc_html__( 'Pages:', 'celebration' ),
						'separator'   => '<li>',
						'after'       => '</ul>'
					));					

					echo '</div><!-- /rowItem -->';
				echo '</div><!-- /boldRow -->';
			echo '</div><!-- boldCell -->';
		echo '</div><!-- /port -->';
	echo '</article>';

?>