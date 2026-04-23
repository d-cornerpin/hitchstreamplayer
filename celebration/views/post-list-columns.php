<?php

	$share_html = '<div class="btIconRow">' . ( function_exists( 'boldthemes_get_share_html' ) ? boldthemes_get_share_html( $permalink, 'blog', 'btIcoExtraSmallSize', 'btIcoDefaultType btIcoDefaultColor' ) : '' ) . '</div>';

	$blog_date_side = date_i18n( "d", strtotime( ( $blog_date ) ) ) . "/". date_i18n( "M", strtotime( ( $blog_date ) ) ). "/". date_i18n( "Y", strtotime( ( $blog_date ) ) );

	$dash = $blog_use_dash ? "bottom" : "";
	
	if ( is_search() ) {
		 $blog_side_info = false;
	}

	$meta_html = '';
	if ( $blog_author || $blog_date || $show_comments_number ) {
		$meta_html .= '';
		if ( $blog_date && ! $blog_side_info ) $meta_html .= '<span class="btArticleDate">' . esc_html( date_i18n( CelebrationTheme::$boldthemes_date_format, strtotime( get_the_time( 'Y-m-d' ) ) ) ) . ' </span>'; 
		if ( $show_comments_number ) $meta_html .= '<a href="' . esc_url_raw( $permalink ) . '#comments" class="btArticleComments">' . $comments_number . '</a>';
		
	}

	if ( $blog_author && ! $blog_side_info ) $categories_html .= $author_html . '</p>';

	if( boldthemes_get_option( 'sidebar' ) ) {
		$left_col_class = ' col-md-6 ';
		$right_col_class = ' col-md-6 ';
	} else {
		$left_col_class = ' col-md-6 ';
		$right_col_class = ' col-md-6 ';

	}
	
	echo '<article class="' . implode( ' ', get_post_class( $class_array ) ) . ' btBlogColumnView">';
		echo '<div class="port">';
			echo '<div class="boldCell">';
				echo '<div class = "boldRow bottomExtraSmallSpaced">';
				if($media_html != "") {
					echo '<div class="rowItem ' . esc_attr( $left_col_class ) . ' btTextCenter">' . $media_html . '</div>';
				}

					echo '<div class="rowItem btTextLeft ' . esc_attr( $right_col_class ) . '">';
						echo '<div class="rowItemContent">';
							echo '<div class="btClear btSeparator bottomSmallSpaced noBorder visible-md visible-ms visible-xs"><hr></div>';
							echo boldthemes_get_heading_html( $categories_html, '<a href="' . esc_url_raw( $permalink ) . '">' . get_the_title() . '</a>', $meta_html , 'medium', $dash, '', '' );
							echo '<div class="btArticleListBodyContent">' . $content_final_html . '</div>';
							echo ' ' . $share_html;
						echo '</div>' ;
					echo '</div>';

				if ( $blog_side_info ) {
					echo '<div class="articleSideGutter btTextRight">';
					$avatar_html = get_avatar( get_the_author_meta( 'ID' ), 144 ); 					
					if ( $avatar_html != '' ) {
						echo '<div class="asgItem avatar"><a href="' . esc_url_raw( $author_url ) . '">' . $avatar_html . '</a></div>';
					}
					if ( $blog_author ) {
						echo '<div class="asgItem title"><span>' . $author_html . '</span></div>';
					}
					if ( $blog_date ) {
						echo '<div class="asgItem date"><small>' . $blog_date_side . '</small></div>';
					}
					
					echo '</div>';
				}
				echo '</div><!-- /boldRow -->';
			echo '</div><!-- boldCell -->';			
		echo '</div><!-- port -->';
	echo '</article><!-- /articles -->';

?>