<!DOCTYPE html>
<html <?php language_attributes(); ?> <?php boldthemes_theme_data(); ?>>
<head>
	
	<?php
	
	boldthemes_set_override();
	boldthemes_header_init();
	boldthemes_header_meta();

	$body_style = '';
	
	$page_background = boldthemes_get_option( 'page_background' );
	if ( $page_background ) {
		if ( is_numeric( $page_background ) ) {
			$page_background = wp_get_attachment_image_src( $page_background, 'full' );
			$page_background = $page_background[0];
		}
		$body_style = ' style="background-image:url(' . $page_background . ')"';
	}

	$header_extra_class = ''; 

	if ( boldthemes_get_option( 'boxed_menu' ) ) {
		$header_extra_class .= 'gutter ';
	}

	wp_head(); ?>
    
    <meta name="msvalidate.01" content="9B85D4248E53AED27855187A4CF59A1D" />
    <meta name="google-site-verification" content="fyRD1tOvQNcS_hcAV58UWlIwuFIWV2fd8iSdt277ij4" />
<!--
	<meta name="keywords" content="Wedding streaming,Live wedding streaming,Denver wedding streaming,Seattle wedding streaming,Wedding video streaming,Weddings live,Live weddings,Denver weddings,Seattle weddings,Wedding live broadcast, denver wedding live streaming, seattle wedding live streaming" />
    <meta name="description" content="HitchStream provides wedding streaming services for the happiest day of your life. We provide affordable, dependable, and stress-free streaming for any wedding ceremony—letting you share your special day with friends and family near and far!">
    <meta property="og:title" content="HitchStream - We Are Gathered Here">
    <meta property="og:image" content="https://hitchstream.com/wp-content/uploads/2022/12/fb_002.png">
    <meta property="og:description" content="HitchStream provides wedding streaming services for the happiest day of your life. We provide affordable, dependable, and stress-free streaming for any wedding ceremony—letting you share your special day with friends and family near and far!">
-->
    
</head>

<body <?php body_class(); ?> data-autoplay="<?php echo intval( boldthemes_get_option( 'autoplay_interval' ) ); ?>" <?php echo wp_kses_post( $body_style ); ?>>

<?php echo boldthemes_preloader_html(); ?>

<div class="btPageWrap" id="top">
	
    <header class="mainHeader btClear <?php echo esc_attr( $header_extra_class ); ?>">
        <div class="port">
			<?php if ( ! boldthemes_get_option( 'top_tools_in_menu' ) ) echo boldthemes_top_bar_html( 'top' ); ?>
			<div class="btLogoArea menuHolder btClear">
				<?php if ( has_nav_menu( 'primary' ) ) { ?>
					<span class="btVerticalMenuTrigger">&nbsp;<?php echo boldthemes_get_icon_html( 'fa_f0c9', '#', '', 'btIcoSmallSize btIcoDefaultColor btIcoDefaultType' ); ?></span>
					<span class="btHorizontalMenuTrigger">&nbsp;<?php echo boldthemes_get_icon_html( 'fa_f0c9', '#', '', 'btIcoSmallSize btIcoDefaultColor btIcoDefaultType' ); ?></span>
				<?php } ?>
				<div class="logo">
					<span>
						<?php boldthemes_logo( 'header' ); ?>
					</span>
				</div><!-- /logo -->
				<?php 
					if ( boldthemes_get_option( 'menu_type' ) == 'hLeftBelow' || boldthemes_get_option( 'menu_type' ) == 'hRightBelow' ) {
						echo boldthemes_top_bar_html( 'menu-half' );
						echo '</div><!-- /menuHolder -->';
						echo '<div class="btBelowLogoArea btClear">';
					}
				?>
				<div class="menuPort">
						<?php if ( boldthemes_get_option( 'top_tools_in_menu' ) ) echo boldthemes_top_bar_html( 'menu' ); ?>
					<nav>
						<?php boldthemes_nav_menu(); ?>
					</nav>
				</div><!-- .menuPort -->
			</div><!-- /menuHolder / btBelowLogoArea -->
		</div><!-- /port -->
    </header><!-- /.mainHeader -->
	<div class="btContentWrap btClear">
		<?php if ( CelebrationTheme::$boldthemes_page_for_header_id != '' && ! is_search() ) { ?>
			<?php
				$content = get_post( CelebrationTheme::$boldthemes_page_for_header_id );
				$top_content = $content->post_content;
				if ( $top_content != '' ) {
					$top_content = do_shortcode( $top_content );
				}
				echo '<div class = "btBlogHeaderContent">' . $top_content . '</div>';
			?>
		<?php } ?>
		<div class="btContentHolder">

			<div class="btContent">
			<?php boldthemes_header_headline() ?>