<?php
/**
 * Single Product tabs
 *
 * @author  WooThemes
 * @package WooCommerce/Templates
 * @version 5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filter tabs and allow third parties to add their own
 *
 * Each tab is an array containing title, callback and priority.
 * @see woocommerce_default_product_tabs()
 */
$tabs = apply_filters( 'woocommerce_product_tabs', array() );

global $post, $product;

$product_cat_arr	= get_the_terms( $post->ID, 'product_cat' );
$cat_count			= is_array( $product_cat_arr ) ? sizeof( $product_cat_arr ) : 0;
$product_tag_arr	= get_the_terms( $post->ID, 'product_tag' );
$tag_count			= is_array( $product_tag_arr ) ? sizeof( $product_tag_arr ) : 0;

if ( ! empty( $tabs ) ) { ?>

	<div class="btClear"></div>
	<div class="btTabs tabsHorizontal">
		<ul class="tabsHeader">
			<?php foreach ( $tabs as $key => $tab ) : ?>
				<li class="<?php echo esc_attr( $key ); ?>_tab">
					<a href="#tab-<?php echo esc_attr( $key ); ?>"><?php echo apply_filters( 'woocommerce_product_' . $key . '_tab_title', esc_html( $tab['title'] ), $key ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="tabPanes tabPanesTabs">
		<?php foreach ( $tabs as $key => $tab ) : ?>
			<div class="tabPane" id="tab-<?php echo esc_attr( $key ); ?>">
				<?php call_user_func( $tab['callback'], $key, $tab ); ?>
			</div>
		<?php endforeach; ?>
		</div>
	</div>

<?php } ?>

<div class="boldRow topSmallSpaced bottomSmallSpaced">
	<div class="product_meta rowItem col-sm-6 tagsRowItem btTextLeft">
		<?php do_action( 'woocommerce_product_meta_start' ); ?>
		<?php
			if ( boldthemes_woocommerce_is_new_version() ) {
				echo '<div class="btTags"><ul>' . wc_get_product_tag_list( $product->get_id(), '</li><li> ',  '<li>', '</li>' )  . '</ul></div>';
			}else{
				echo '<div class="btTags"><ul>' . wp_kses_post( $product->get_tags( '</li><li> ', '<li>', '</li>' ) ) . '</ul></div>';
			}
		?>

		<?php do_action( 'woocommerce_product_meta_end' ); ?>
	</div>
	<div class="rowItem col-sm-6 cellRight shareRowItem btTextRight">
		<div class="socialRow"><?php echo ( function_exists( 'boldthemes_get_share_html' ) ? boldthemes_get_share_html( get_permalink(), 'shop', 'btIcoSmallSize' ) : '' ); ?></div>
	</div>
</div>