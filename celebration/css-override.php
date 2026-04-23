<?php
if ( class_exists( 'BoldThemesFramework' ) && isset( BoldThemesFramework::$crush_vars ) ) {
	$boldthemes_crush_vars = BoldThemesFramework::$crush_vars;
}
if ( class_exists( 'BoldThemesFramework' ) && isset( BoldThemesFramework::$crush_vars_def ) ) {
	$boldthemes_crush_vars_def = BoldThemesFramework::$crush_vars_def;
}
if ( isset( $boldthemes_crush_vars['headingFont'] ) ) {
	$headingFont = $boldthemes_crush_vars['headingFont'];
} else {
	$headingFont = "Great Vibes,Arial,sans-serif";
}
if ( isset( $boldthemes_crush_vars['headingSuperTitleFont'] ) ) {
	$headingSuperTitleFont = $boldthemes_crush_vars['headingSuperTitleFont'];
} else {
	$headingSuperTitleFont = "Josefin Slab,Arial,sans-serif";
}
if ( isset( $boldthemes_crush_vars['headingSubTitleFont'] ) ) {
	$headingSubTitleFont = $boldthemes_crush_vars['headingSubTitleFont'];
} else {
	$headingSubTitleFont = "Josefin Sans,Arial,sans-serif";
}
if ( isset( $boldthemes_crush_vars['menuFont'] ) ) {
	$menuFont = $boldthemes_crush_vars['menuFont'];
} else {
	$menuFont = "Josefin Sans,Arial,sans-serif";
}
if ( isset( $boldthemes_crush_vars['bodyFont'] ) ) {
	$bodyFont = $boldthemes_crush_vars['bodyFont'];
} else {
	$bodyFont = "Josefin Sans,Arial,sans-serif";
}
if ( isset( $boldthemes_crush_vars['accentColor'] ) ) {
	$accentColor = $boldthemes_crush_vars['accentColor'];
} else {
	$accentColor = "#c8ba7b";
}
$accentColorPaled = CssCrush\fn__l_adjust( $accentColor." 5" );$css_override = sanitize_text_field("a:hover{
    color: {$accentColor};}
select,
input{font-family: {$bodyFont};}
body{font-family: {$bodyFont};}
.header h1,
.header h2,
.header h3,
.header h4,
.header h5,
.header h6{font-family: {$headingFont};}
.btArticleListItem .header h1,
.btArticleListItem .header h2,
.btArticleListItem .header h3,
.btArticleListItem .header h4,
.btArticleListItem .header h5,
.btArticleListItem .header h6{font-family: {$bodyFont};}
.btLightSkin .btText h4,
.btDarkSkin .btLightSkin .btText h4,
.btDarkSkin .btText h4,
.btLightSkin .btDarkSkin .btText h4{color: {$accentColor};}
a:hover{color: {$accentColor};}
.btAccentColorBackground{background-color: {$accentColor} !important;}
.menuPort{
    font-family: {$menuFont};}
.menuPort nav ul li a:hover{color: {$accentColor} !important;}
.btMenuHorizontal .menuPort nav > ul > li.current-menu-ancestor > a,
.btMenuHorizontal .menuPort nav > ul > li.current-menu-item > a{border-bottom: 2px solid {$accentColor};}
.btMenuHorizontal .menuPort nav > ul > li > ul li.current-menu-ancestor > a,
.btMenuHorizontal .menuPort nav > ul > li > ul li.current-menu-item > a{color: {$accentColor} !important;}
.logo.boldthemes_logo_text{
    color: {$accentColor};}
.btLightSkin .logo.boldthemes_logo_text a,
.btDarkSkin .btLightSkin .logo.boldthemes_logo_text a,
.btDarkSkin .logo.boldthemes_logo_text a,
.btLightSkin .btDarkSkin .logo.boldthemes_logo_text a{color: {$accentColor};}
.btMenuVertical nav li.current-menu-ancestor > a,
.btMenuVertical nav li.current-menu-item > a{color: {$accentColor} !important;}
.subToggler:before{
    color: {$accentColor};}
body.btMenuHorizontal .menuPort ul ul:before{
    background-color: {$accentColor};}
body.btMenuHorizontal .menuPort > nav > ul > li.btMenuWideDropdown > ul:first-child li{border-top: 2px solid {$accentColor};}
body.btMenuHorizontal .menuPort > nav > ul > li.btMenuWideDropdown > ul > li:before{
    background-color: {$accentColor};}
body.btMenuHorizontal .menuPort > nav > ul > li.btMenuWideDropdown > ul > li ul li:first-child a,
body.btMenuHorizontal .menuPort > nav > ul > li.btMenuWideDropdown > ul > li:last-child ul li:first-child a{
    border-top: 2px solid {$accentColor} !important;}
body.btMenuVertical .subToggler:before{
    color: {$accentColor};}
body.btMenuVertical > .menuPort .btCloseVertical:before:hover{color: {$accentColor};}
@media (min-width: 1200px){.btMenuVerticalOn .btVerticalMenuTrigger .btIco a:before{color: {$accentColor} !important;}
}.topBar .widget_search button,
.topBarInMenu .widget_search button{
    background: {$accentColor};}
.btSearchInner.btFromTopBox{
    background: {$accentColor};}
.btSearchInner.btFromTopBox button:hover:before{color: {$accentColor};}
.btSiteFooter .menu li:before{
    color: {$accentColor};}
.btSiteFooter .menu a:hover{color: {$accentColor};}
.btBrideNGroom{
    color: {$accentColor};}
.btBrideNGroom p{
    font-family: {$headingSubTitleFont};}
.btBrideNGroom h4{font-family: {$headingFont};
    color: {$accentColor};}
.sticky .headline{color: {$accentColor};}
.headline a{color: {$accentColor};}
.btPortfolioSingleItemColumns dt{color: {$accentColor};}
.commentTxt p.edit-link a:hover,
.commentTxt p.reply a:hover{color: {$accentColor};}
.widget_shopping_cart .total{border-top: 2px solid {$accentColor};}
.widget_shopping_cart .widget_shopping_cart_content .mini_cart_item .ppRemove a.remove:hover:before{background-color: {$accentColor};}
.widget_price_filter .ui-slider .ui-slider-handle{
    background-color: {$accentColor};}
.widget_layered_nav ul li.chosen a:hover:before,
.widget_layered_nav ul li a:hover:before,
.widget_layered_nav_filters ul li.chosen a:hover:before,
.widget_layered_nav_filters ul li a:hover:before{background-color: {$accentColor};}
.btBox > h4:after,
.btCustomMenu > h4:after{
    border-bottom: 1px solid {$accentColor};}
.btBox ul li a:hover,
.btCustomMenu ul li a:hover{color: {$accentColor};}
.btBox.widget_calendar table caption{background: {$accentColorPaled};
    font-family: {$bodyFont};}
.btBox.widget_archive ul li a:hover,
.btBox.widget_categories ul li a:hover,
.btCustomMenu ul li a:hover{border-bottom: 1px solid {$accentColor};}
.btDarkSkin .btBox.widget_archive ul li a:hover,
.btLightSkin .btDarkSkin .btBox.widget_archive ul li a:hover,
.btDarkSkin .btBox.widget_categories ul li a:hover,
.btLightSkin .btDarkSkin .btBox.widget_categories ul li a:hover{border-bottom: 1px solid {$accentColor};}
.btBox.widget_rss li a.rsswidget{font-family: {$bodyFont};}
.btBox.widget_rss li cite:before{
    color: {$accentColor};}
.btBox .btSearch button,
.btBox .btSearch input[type=submit],
form.woocommerce-product-search button,
form.woocommerce-product-search input[type=submit]{
    background: {$accentColor};}
input:not([type=\"radio\"]):not([type=\"checkbox\"]):not([type=\"submit\"]):focus,
textarea:focus,
.fancy-select .trigger.open{-webkit-box-shadow: 0 0 4px 0 {$accentColor};
    box-shadow: 0 0 4px 0 {$accentColor};}
.btDarkSkin input:not([type=\"radio\"]):not([type=\"checkbox\"]):not([type=\"submit\"]):focus,
.btLightSkin .btDarkSkin input:not([type=\"radio\"]):not([type=\"checkbox\"]):not([type=\"submit\"]):focus{-webkit-box-shadow: 0 0 4px 0 {$accentColor};
    box-shadow: 0 0 4px 0 {$accentColor};}
form.wpcf7-form .wpcf7-submit{
    color: {$accentColor};
    border: 2px solid {$accentColor};}
form.wpcf7-form .wpcf7-submit:hover,
form.wpcf7-form .wpcf7-submit:focus{
    background-color: {$accentColor};}
form.wpcf7-form span.wpcf7-radio span label:before{
    border: 1px solid {$accentColor};}
form.wpcf7-form span.wpcf7-radio span label:after{
    background-color: {$accentColor};}
.fancy-select .trigger.open{color: {$accentColor};}
.fancy-select ul.options > li:hover{color: {$accentColor};}
.btBox .tagcloud a,
.btTags ul a{
    background: {$accentColor};}
.btBox .tagcloud a:hover,
.btTags ul a:hover{background: {$accentColorPaled};}
.recentTweets small:before{
    color: {$accentColor};}
.header .btSubTitle .btArticleCategories a:not(:first-child):before,
.header .btSuperTitle .btArticleCategories a:not(:first-child):before{
    background-color: {$accentColor};}
.btContentHolder table tr th,
.btContentHolder table thead tr th{background: {$accentColor};}
.post-password-form input[type=\"submit\"]{
    background: {$accentColor};
    font-family: {$headingFont};}
.btPagination .paging a:hover:after{background: {$accentColor};}
.comment-respond .btnOutline button[type=\"submit\"]{font-family: {$headingFont};}
a#cancel-comment-reply-link:hover{color: {$accentColor};}
span.btHighlight{
    background-color: {$accentColor};}
a.btContinueReading{
    color: {$accentColor};
    -webkit-box-shadow: 0 0 0 1px {$accentColor} inset;
    box-shadow: 0 0 0 1px {$accentColor} inset;}
a.btContinueReading:hover{
    -webkit-box-shadow: 0 0 0 2em {$accentColor} inset;
    box-shadow: 0 0 0 2em {$accentColor} inset;}
.asgItem.title a{color: {$accentColor};}
.btIco .btIcoHolder:before{color: {$accentColor};}
.btIco.btIcoWhiteType .btIcoHolder:before{
    color: {$accentColor};}
.btIco.btIcoFilledType.btIcoAccentColor .btIcoHolder:before,
.btIco.btIcoOutlineType.btIcoAccentColor:hover .btIcoHolder:before{-webkit-box-shadow: 0 0 0 1em {$accentColor} inset;
    box-shadow: 0 0 0 1em {$accentColor} inset;}
.btIco.btIcoFilledType.btIcoAccentColor:hover .btIcoHolder:before,
.btIco.btIcoOutlineType.btIcoAccentColor .btIcoHolder:before{-webkit-box-shadow: 0 0 0 1px {$accentColor} inset;
    box-shadow: 0 0 0 1px {$accentColor} inset;
    color: {$accentColor};}
.btLightSkin .btIco.btIcoDefaultType.btIcoAccentColor .btIcoHolder:before,
.btLightSkin .btIco.btIcoDefaultType.btIcoDefaultColor:hover .btIcoHolder:before,
.btDarkSkin .btLightSkin .btIco.btIcoDefaultType.btIcoAccentColor .btIcoHolder:before,
.btDarkSkin .btLightSkin .btIco.btIcoDefaultType.btIcoDefaultColor:hover .btIcoHolder:before,
.btDarkSkin .btIco.btIcoDefaultType.btIcoAccentColor .btIcoHolder:before,
.btDarkSkin .btIco.btIcoDefaultType.btIcoDefaultColor:hover .btIcoHolder:before,
.btLightSkin .btDarkSkin .btIco.btIcoDefaultType.btIcoAccentColor .btIcoHolder:before,
.btLightSkin .btDarkSkin .btIco.btIcoDefaultType.btIcoDefaultColor:hover .btIcoHolder:before{color: {$accentColor};}
.btIcoAccentColor span{color: {$accentColor};}
.btIcoDefaultColor:hover span{color: {$accentColor};}
.btLightSkin .menuPort .btIco.btSpecialHeaderIcon .btIcoHolder:before,
.btDarkSkin .btLightSkin .menuPort .btIco.btSpecialHeaderIcon .btIcoHolder:before,
.btDarkSkin .menuPort .btIco.btSpecialHeaderIcon .btIcoHolder:before,
.btLightSkin .btDarkSkin .menuPort .btIco.btSpecialHeaderIcon .btIcoHolder:before{color: {$accentColor};}
.btnFilledStyle.btnAccentColor,
.btnOutlineStyle.btnAccentColor:hover{background-color: {$accentColor};
    border: 2px solid {$accentColor};}
.btnOutlineStyle.btnAccentColor,
.btnFilledStyle.btnAccentColor:hover{
    border: 2px solid {$accentColor};
    color: {$accentColor} !important;}
.btnOutlineStyle.btnAccentColor span,
.btnFilledStyle.btnAccentColor:hover span,
.btnOutlineStyle.btnAccentColor span:before,
.btnFilledStyle.btnAccentColor:hover span:before,
.btnOutlineStyle.btnAccentColor a,
.btnFilledStyle.btnAccentColor:hover a,
.btnOutlineStyle.btnAccentColor .btIco a:before,
.btnFilledStyle.btnAccentColor:hover .btIco a:before,
.btnOutlineStyle.btnAccentColor button,
.btnFilledStyle.btnAccentColor:hover button{color: {$accentColor} !important;}
.btnBorderlessStyle.btnAccentColor span,
.btnBorderlessStyle.btnNormalColor:hover span,
.btnBorderlessStyle.btnAccentColor span:before,
.btnBorderlessStyle.btnNormalColor:hover span:before,
.btnBorderlessStyle.btnAccentColor a,
.btnBorderlessStyle.btnNormalColor:hover a,
.btnBorderlessStyle.btnAccentColor .btIco a:before,
.btnBorderlessStyle.btnNormalColor:hover .btIco a:before,
.btnBorderlessStyle.btnAccentColor button,
.btnBorderlessStyle.btnNormalColor:hover button{color: {$accentColor};}
.btCounterHolder{font-family: {$headingSuperTitleFont};}
.btCounterHolder div[class$=\"_text\"] > span{font-family: {$headingFont};}
.btCounterHolder div[class$=\"_text\"]:before{background-color: {$accentColor};}
.btProgressContent .btProgressAnim{background-color: {$accentColor};}
.btProgressBarLineStyle .btProgressContent .btProgressAnim{
    color: {$accentColor};
    border-bottom: 4px solid {$accentColor};}
.btImageSimpleHover .captionPane .captionTxt:before{color: {$accentColor};}
.bpgPhoto .captionTxt h4{font-family: {$bodyFont};}
.btPriceTable .btPriceTableHeader{background: {$accentColor};}
.btPriceTable .btPriceTableHeader .header.extralarge h2{font-family: {$bodyFont};}
.header .btSuperTitle{font-family: {$headingSuperTitleFont};}
.header .btSubTitle{font-family: {$headingSubTitleFont};}
.btDash.bottomDash .dash:after,
.btDash.topDash .dash:before{
    border-bottom: 1px solid {$accentColor};}
.header.small h3,
.header.small h4{
    font-family: {$bodyFont};}
.header.medium h2,
.header.medium h3{
    font-family: {$bodyFont};}
.header.medium .dash:after,
.header.medium .dash:before{border-color: {$accentColor};}
.header.large .dash:after,
.header.large .dash:before{border-color: {$accentColor};}
.header.extralarge .dash:after,
.header.extralarge .dash:before{border-color: {$accentColor};}
.header.huge .dash:after,
.header.huge .dash:before{border-color: {$accentColor};}
.header.huge h1{color: {$accentColor};}
.btGridContent .header .btSuperTitle a:hover{color: {$accentColor};}
.btCatFilter .btCatFilterItem:hover{color: {$accentColor};}
.btCatFilter .btCatFilterItem.active{color: {$accentColor};}
.btMediaBox.btQuote,
.btMediaBox.btLink{
    background-color: {$accentColor};}
.btLightSkin .boldPhotoSlide h4.nbs.nsPrev a:hover:before,
.btLightSkin .boldPhotoSlide h4.nbs.nsNext a:hover:after,
.btDarkSkin .btLightSkin .boldPhotoSlide h4.nbs.nsPrev a:hover:before,
.btDarkSkin .btLightSkin .boldPhotoSlide h4.nbs.nsNext a:hover:after,
.btDarkSkin .boldPhotoSlide h4.nbs.nsPrev a:hover:before,
.btDarkSkin .boldPhotoSlide h4.nbs.nsNext a:hover:after,
.btLightSkin .btDarkSkin .boldPhotoSlide h4.nbs.nsPrev a:hover:before,
.btLightSkin .btDarkSkin .boldPhotoSlide h4.nbs.nsNext a:hover:after{background-color: {$accentColor};}
h4.nbs.nsPrev a:hover:before,
h4.nbs.nsNext a:hover:after{background-color: {$accentColor};}
.btGetInfo{
    border: 1px solid {$accentColor};}
.btInfoBarMeta p strong{color: {$accentColor};}
.tabAccordionTitle.on{background: {$accentColor};}
.demos span{
    background-color: {$accentColor};}
.btWhishTxt p:first-of-type:before{
    color: {$accentColor};}
.btWhishAuthor h4{font-family: {$headingSuperTitleFont};}
.btWishAuthorMeta p{
    color: {$accentColor};}
.btLightSkin .btWhishes .slick-dots li button:hover,
.btDarkSkin .btLightSkin .btWhishes .slick-dots li button:hover,
.btDarkSkin .btWhishes .slick-dots li button:hover,
.btLightSkin .btDarkSkin .btWhishes .slick-dots li button:hover,
.btLightSkin .btWhishes .slick-dots li.slick-active button,
.btDarkSkin .btLightSkin .btWhishes .slick-dots li.slick-active button,
.btDarkSkin .btWhishes .slick-dots li.slick-active button,
.btLightSkin .btDarkSkin .btWhishes .slick-dots li.slick-active button{background-color: {$accentColor};}
.btAnimNav li.btAnimNavDot:hover{background-color: {$accentColor};}
.btAnimNav li.btAnimNavNext:hover,
.btAnimNav li.btAnimNavPrev:hover{color: {$accentColor};}
.headline b.animate.animated{color: {$accentColor};}
p.demo_store{
    background-color: {$accentColor};}
.woocommerce .woocommerce-error,
.woocommerce .woocommerce-info,
.woocommerce .woocommerce-message,
.woocommerce-page .woocommerce-error,
.woocommerce-page .woocommerce-info,
.woocommerce-page .woocommerce-message{
    border-top: 2px solid {$accentColor};}
.woocommerce .woocommerce-info a: not(.button),
.woocommerce .woocommerce-message a: not(.button),
.woocommerce-page .woocommerce-info a: not(.button),
.woocommerce-page .woocommerce-message a: not(.button){color: {$accentColor};}
.woocommerce .woocommerce-info,
.woocommerce .woocommerce-message,
.woocommerce-page .woocommerce-info,
.woocommerce-page .woocommerce-message{border-top-color: {$accentColor};}
.woocommerce .woocommerce-message:before,
.woocommerce .woocommerce-info:before,
.woocommerce-page .woocommerce-message:before,
.woocommerce-page .woocommerce-info:before{
    color: {$accentColor};}
.woocommerce a.button,
.woocommerce input[type=\"submit\"],
.woocommerce button[type=\"submit\"],
.woocommerce input.button,
.woocommerce input.alt:hover,
.woocommerce a.button.alt:hover,
.woocommerce .button.alt:hover,
.woocommerce button.alt:hover,
.woocommerce-page a.button,
.woocommerce-page input[type=\"submit\"],
.woocommerce-page button[type=\"submit\"],
.woocommerce-page input.button,
.woocommerce-page input.alt:hover,
.woocommerce-page a.button.alt:hover,
.woocommerce-page .button.alt:hover,
.woocommerce-page button.alt:hover{
    border: 2px solid {$accentColor};
    color: {$accentColor};}
.woocommerce a.button:hover,
.woocommerce input[type=\"submit\"]:hover,
.woocommerce .button:hover,
.woocommerce button:hover,
.woocommerce input.alt,
.woocommerce a.button.alt,
.woocommerce .button.alt,
.woocommerce button.alt,
.woocommerce-page a.button:hover,
.woocommerce-page input[type=\"submit\"]:hover,
.woocommerce-page .button:hover,
.woocommerce-page button:hover,
.woocommerce-page input.alt,
.woocommerce-page a.button.alt,
.woocommerce-page .button.alt,
.woocommerce-page button.alt{background-color: {$accentColor};}
.woocommerce p.lost_password:before,
.woocommerce-page p.lost_password:before{
    color: {$accentColor};}
.woocommerce form.login p.lost_password a:hover,
.woocommerce-page form.login p.lost_password a:hover{color: {$accentColor};}
.woocommerce div.product .stock,
.woocommerce-page div.product .stock{color: {$accentColor};}
.woocommerce div.product div.images .woocommerce-product-gallery__trigger:after,
.woocommerce-page div.product div.images .woocommerce-product-gallery__trigger:after{
    -webkit-box-shadow: 0 0 0 2em {$accentColor} inset,0 0 0 2em rgba(255,255,255,.5) inset;
    box-shadow: 0 0 0 2em {$accentColor} inset,0 0 0 2em rgba(255,255,255,.5) inset;}
.woocommerce div.product div.images .woocommerce-product-gallery__trigger:hover:after,
.woocommerce-page div.product div.images .woocommerce-product-gallery__trigger:hover:after{-webkit-box-shadow: 0 0 0 1px {$accentColor} inset,0 0 0 2em rgba(255,255,255,.5) inset;
    box-shadow: 0 0 0 1px {$accentColor} inset,0 0 0 2em rgba(255,255,255,.5) inset;
    color: {$accentColor};}
.woocommerce div.product a.reset_variations:hover,
.woocommerce-page div.product a.reset_variations:hover{color: {$accentColor};}
.woocommerce .products ul li.product .btPriceTableSticker,
.woocommerce ul.products li.product .btPriceTableSticker,
.woocommerce-page .products ul li.product .btPriceTableSticker,
.woocommerce-page ul.products li.product .btPriceTableSticker{
    background: {$accentColor};}
.woocommerce nav.woocommerce-pagination ul li a:focus,
.woocommerce nav.woocommerce-pagination ul li a:hover,
.woocommerce nav.woocommerce-pagination ul li span.current,
.woocommerce-page nav.woocommerce-pagination ul li a:focus,
.woocommerce-page nav.woocommerce-pagination ul li a:hover,
.woocommerce-page nav.woocommerce-pagination ul li span.current{background: {$accentColor};}
.woocommerce .star-rating span:before,
.woocommerce-page .star-rating span:before{
    color: {$accentColor};}
.woocommerce p.stars a[class^=\"star-\"].active:after,
.woocommerce p.stars a[class^=\"star-\"]:hover:after,
.woocommerce-page p.stars a[class^=\"star-\"].active:after,
.woocommerce-page p.stars a[class^=\"star-\"]:hover:after{color: {$accentColor};}
.woocommerce-cart table.cart td.product-remove a.remove{
    color: {$accentColor};
    border: 1px solid {$accentColor};}
.woocommerce-cart table.cart td.product-remove a.remove:hover{background-color: {$accentColor};}
.woocommerce-cart .cart_totals .discount td{color: {$accentColor};}
.woocommerce-account header.title .edit{
    color: {$accentColor};}
.woocommerce-account header.title .edit:before{
    color: {$accentColor};}
.btLightSkin.woocommerce-page .product .headline a:hover,
.btDarkSkin .btLightSkin.woocommerce-page .product .headline a:hover,
.btDarkSkin.woocommerce-page .product .headline a:hover,
.btLightSkin .btDarkSkin.woocommerce-page .product .headline a:hover{color: {$accentColor};}
.btQuoteBooking .btContactNext{
    border: {$accentColor} 2px solid;
    color: {$accentColor};}
.btQuoteBooking .btContactNext:hover,
.btQuoteBooking .btContactNext:active{background-color: {$accentColor} !important;}
.btQuoteBooking .btQuoteSwitch:hover{-webkit-box-shadow: 0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);
    box-shadow: 0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);}
.btQuoteBooking .btQuoteSwitch.on .btQuoteSwitchInner{
    background: {$accentColor};}
.btQuoteBooking .dd.ddcommon.borderRadiusTp .ddTitleText,
.btQuoteBooking .dd.ddcommon.borderRadiusBtm .ddTitleText{
    -webkit-box-shadow: 5px 0 0 {$accentColor} inset,0 2px 10px rgba(0,0,0,.2);
    box-shadow: 5px 0 0 {$accentColor} inset,0 2px 10px rgba(0,0,0,.2);}
.btQuoteBooking .ui-slider .ui-slider-handle{
    background: {$accentColor};}
.btQuoteBooking .btQuoteBookingForm .btQuoteTotal{
    background: {$accentColor};}
.btQuoteBooking .btContactFieldMandatory.btContactFieldError input,
.btQuoteBooking .btContactFieldMandatory.btContactFieldError textarea{border: 1px solid {$accentColor};
    -webkit-box-shadow: 0 0 0 1px {$accentColor} inset;
    box-shadow: 0 0 0 1px {$accentColor} inset;}
.btQuoteBooking .btContactFieldMandatory.btContactFieldError .dd.ddcommon.borderRadius .ddTitleText{border: 1px solid {$accentColor};
    -webkit-box-shadow: 0 0 0 1px {$accentColor} inset;
    box-shadow: 0 0 0 1px {$accentColor} inset;}
.btQuoteBooking .btContactFieldMandatory.btContactFieldError input:hover,
.btQuoteBooking .btContactFieldMandatory.btContactFieldError textarea:hover{-webkit-box-shadow: 0 0 0 1px {$accentColor} inset,0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);
    box-shadow: 0 0 0 1px {$accentColor} inset,0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);}
.btQuoteBooking .btContactFieldMandatory.btContactFieldError .dd.ddcommon.borderRadius:hover .ddTitleText{-webkit-box-shadow: 0 0 0 1px {$accentColor} inset,0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);
    box-shadow: 0 0 0 1px {$accentColor} inset,0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);}
.btQuoteBooking .btContactFieldMandatory.btContactFieldError input:focus,
.btQuoteBooking .btContactFieldMandatory.btContactFieldError textarea:focus{-webkit-box-shadow: 0 0 0 1px {$accentColor} inset,5px 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);
    box-shadow: 0 0 0 1px {$accentColor} inset,5px 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);}
.btQuoteBooking .btContactFieldMandatory.btContactFieldError .dd.ddcommon.borderRadiusTp .ddTitleText{-webkit-box-shadow: 0 0 0 1px {$accentColor} inset,5px 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);
    box-shadow: 0 0 0 1px {$accentColor} inset,5px 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);}
.btQuoteBooking .btSubmitMessage{color: {$accentColor};}
.btDatePicker .ui-datepicker-header{
    background-color: {$accentColor};}
.btQuoteBooking .btContactSubmit{
    background-color: {$accentColor};
    border: 2px solid {$accentColor};}
.btQuoteBooking .btContactSubmit:hover{
    color: {$accentColor};}
.btPayPalButton:hover{-webkit-box-shadow: 0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);
    box-shadow: 0 0 0 {$accentColor} inset,0 1px 5px rgba(0,0,0,.2);}
@media (max-width: 1199px){.btAnimNav li.btAnimNavNext,
.btAnimNav li.btAnimNavPrev{
    border: 1px solid {$accentColor};}
}@media (max-width: 767px){.btArticleListItem .btArticleFooter .btShareArticle:before{
    background-color: {$accentColor};}
}.wp-block-button__link:hover{color: {$accentColor} !important;}
", array() );