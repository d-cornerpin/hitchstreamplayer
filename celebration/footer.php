		</div><!-- /boldthemes_content -->
		<?php

if ( CelebrationTheme::$boldthemes_has_sidebar ) {
	echo '<aside class="btSidebar">';
		dynamic_sidebar( 'primary_widget_area' );
	echo '</aside>';					
}

?>
		</div><!-- /contentHolder -->
		</div><!-- /contentWrap -->

		<?php

if ( boldthemes_get_option( 'footer_dark_skin' ) ) {
	echo '<footer class="btDarkSkin">';
} else {
	echo '<footer>';
}

$custom_text_html = '';
$custom_text = boldthemes_get_option( 'custom_text' );
if ( $custom_text != '' ) {
	$custom_text_html = '<p class="copyLine">' . $custom_text . '</p>';
}

$footer_supertitle_text = '';
$footer_supertitle_text = boldthemes_get_option( 'footer_supertitle_text' );

$footer_title_text = '';
$footer_title_text = boldthemes_get_option( 'footer_title_text' );

if ( is_active_sidebar( 'footer_widgets' ) ) {
	echo '
	<section class="boldSection btSiteFooterWidgets gutter">
		<div class="port">
			<div class="boldRow" id="boldSiteFooterWidgetsRow">';
			dynamic_sidebar( 'footer_widgets' );
	echo '	
			</div>
		</div>
	</section>';
}

?>
		<?php if ( $footer_supertitle_text != '' || $footer_title_text != '' || $custom_text_html != '' || has_nav_menu( 'footer' )) { ?>
		<section class="boldSection gutter btSiteFooter btGutter">
		    <div class="port">
		        <div class="boldRow">
		            <div class="rowItem btFooterMenu col-md-4 col-sm-12 btTextLeft">

		            </div>
		            <div class="rowItem btFooterTitle col-md-4 col-sm-12 btTextCenter">
		                <div class="btBrideNGroom">
		                    <img src="https://hitchstream.com/wp-content/uploads/2022/11/1x_horz_pink.png" width=90%>
		                </div><!-- /btBrideNGroom -->
		            </div><!-- /copy -->
		            <div class="rowItem btFooterCopy col-md-4 col-sm-12 btTextRight">
		                <?php 
						if ( is_active_sidebar( 'footer_right_widgets' ) ) dynamic_sidebar( 'footer_right_widgets' );
						echo wp_kses_post( $custom_text_html ); 
					?>
		            </div><!-- /copy -->

		        </div><!-- /boldRow -->
		    </div><!-- /port -->
		</section>
		<?php } ?>

		</footer>

		</div><!-- /pageWrap -->

		<?php

wp_footer();

?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        function getQueryParameter(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }
        const packageValue = getQueryParameter('package');
        const serviceLocValue = getQueryParameter('serviceloc');
        const orValue = getQueryParameter('OR');
        const addendum1Value = getQueryParameter('addendum1');

        const packageTermsField = document.querySelector('input[name="package_terms"]');
        const orField = document.querySelector('input[name="OR"]');
        const TPD_termsEl = document.querySelector('input[name="TPD_terms"]');
        const IDO_termsEl = document.querySelector('input[name="IDO_terms"]');
        const KISS_termsEl = document.querySelector('input[name="KISS_terms"]');

        const packagePrices = {
            'The Perfect Day': 3500,
            'The I Do': 999,
            'The Kiss': 2150
        };

        if (packageTermsField && TPD_termsEl && IDO_termsEl && KISS_termsEl) {
            if (packageValue === 'The Perfect Day') {
                packageTermsField.value = TPD_termsEl.value;
            } else if (packageValue === 'The I Do') {
                packageTermsField.value = IDO_termsEl.value;
            } else if (packageValue === 'The Kiss') {
                packageTermsField.value = KISS_termsEl.value;
            } else {
                packageTermsField.value = '';
            }
        }

        if (orField) {
            if (orValue) {
                orField.value = orValue;
            } else if (packageValue && packagePrices[packageValue]) {
                orField.value = packagePrices[packageValue];
            } else {
                orField.value = '';
            }
        }

        const locationSelectField = document.querySelector('input[name="location_select"]');
        const CO_locationEl = document.querySelector('input[name="CO_location"]');
        const WA_locationEl = document.querySelector('input[name="WA_location"]');

        if (locationSelectField && CO_locationEl && WA_locationEl) {
            if (serviceLocValue === 'Denver') {
                locationSelectField.value = CO_locationEl.value;
            } else if (serviceLocValue === 'Seattle') {
                locationSelectField.value = WA_locationEl.value;
            } else {
                locationSelectField.value = '';
            }
        }

        const addendum1TextField = document.querySelector('input[name="addendum1_text"]');

        if (addendum1TextField) {
            if (addendum1Value === 'ProOptOut') {
                addendum1TextField.value = 'Addendum: Promotional Materials Opt-Out. This addendum confirms that the Client has elected to restrict the Company\'s use of footage, audio, and any other content captured during the Service Date for promotional purposes. The Client has either paid the one-time promotional opt-out fee of $200, reflected in the total price stated in Section 4 of this Agreement, or the fee has been expressly waived by the Company in writing prior to the signing of this Agreement. In consideration of this payment or written waiver, the Company agrees that it will not use the Client\'s footage, audio, likeness, or any identifiable details from their event in any promotional materials, including but not limited to portfolio reels, social media, advertising, or website content. All other terms of Section 12 and Section 13 of this Agreement remain in full effect. This addendum is incorporated into and made part of the Agreement as of the date first above written.';
            } else {
                addendum1TextField.value = '';
            }
        }
    });
</script>

		</body>

		</html>
