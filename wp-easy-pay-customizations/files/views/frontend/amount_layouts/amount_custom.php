<?php

$wpep_square_payment_box_1 = get_post_meta( $wpep_current_form_id, 'wpep_square_payment_box_1', true );
$wpep_square_payment_box_2 = get_post_meta( $wpep_current_form_id, 'wpep_square_payment_box_2', true );
$wpep_square_payment_box_3 = get_post_meta( $wpep_current_form_id, 'wpep_square_payment_box_3', true );
$wpep_square_payment_box_4 = get_post_meta( $wpep_current_form_id, 'wpep_square_payment_box_4', true );
$defaultPriceSelected      = ! empty( get_post_meta( $wpep_current_form_id, 'defaultPriceSelected', true ) ) ? get_post_meta( $wpep_current_form_id, 'defaultPriceSelected', true ) : '';

$wpep_square_user_defined_amount = get_post_meta( $wpep_current_form_id, 'wpep_square_user_defined_amount', true );
$wpep_square_payment_min         = get_post_meta( $wpep_current_form_id, 'wpep_square_payment_min', true );
$wpep_square_payment_max         = get_post_meta( $wpep_current_form_id, 'wpep_square_payment_max', true );


$form_payment_global = get_post_meta( $wpep_current_form_id, 'wpep_individual_form_global', true );

if ( $form_payment_global == 'on' ) {

	$global_payment_mode = get_option( 'wpep_square_payment_mode_global', true );

	if ( $global_payment_mode == 'on' ) {

		/* If Global Form Live Mode */


		$wpep_square_currency = get_option( 'wpep_square_currency_new' );

	}

	if ( $global_payment_mode !== 'on' ) {

		/* If Global Form Test Mode */


		$wpep_square_currency = get_option( 'wpep_square_currency_test' );

	}
}

if ( $form_payment_global !== 'on' ) {


	$individual_payment_mode = get_post_meta( $wpep_current_form_id, 'wpep_payment_mode', true );

	if ( $individual_payment_mode == 'on' ) {

		/* If Individual Form Live Mode */


		$square_currency = get_post_meta( $wpep_current_form_id, 'wpep_post_square_currency_new', true );

	}

	if ( $individual_payment_mode !== 'on' ) {


		/* If Individual Form Test Mode */


		$square_currency = get_post_meta( $wpep_current_form_id, 'wpep_post_square_currency_test', true );


	}
}


$currencySymbolType = ! empty( get_post_meta( $wpep_current_form_id, 'currencySymbolType', true ) ) ? get_post_meta( $wpep_current_form_id, 'currencySymbolType', true ) : 'code';

if ( $currencySymbolType == 'symbol' ) {

	if ( $square_currency == 'USD' ) :
		$square_currency = '$';
	endif;

	if ( $square_currency == 'CAD' ) :
		$square_currency = 'C$';
	endif;

	if ( $square_currency == 'AUD' ) :
		$square_currency = 'A$';
	endif;

	if ( $square_currency == 'JPY' ) :
		$square_currency = '¥';
	endif;

	if ( $square_currency == 'GBP' ) :
		$square_currency = '£';
	endif;

	if ( $square_currency == 'EUR' ) :
		$square_currency = '€';
	endif;

}

?>

<div class="form-group cusPaymentSec">

	<?php

	$show_other = false;
	if ( $wpep_square_user_defined_amount !== 'on' && '' == $wpep_square_payment_box_1 && '' == $wpep_square_payment_box_2 && '' == $wpep_square_payment_box_3 && '' == $wpep_square_payment_box_4 ) {
		$show_other = true;

		if ( '' == $wpep_square_payment_min && '' == $wpep_square_payment_min ) {

			$wpep_square_payment_min = 1;
			$wpep_square_payment_max = 50000;

		}
	} else {

//		echo '<label class="selectAmount">Enter Payment Amount:</label>';
	}

	?>


	<div class="paymentSelect">
		<?php if ( '' != $wpep_square_payment_box_1 ) { ?>
			<div class="selection">
				<input id="doller1_<?php echo $wpep_current_form_id; ?>" name="doller"
					   type="radio" 
					   <?php
						if ( $defaultPriceSelected == 'dollar1' || $defaultPriceSelected == '' ) :
							echo 'data-default="true" checked';
                        endif;
						?>
						 />
				<label for="doller1_<?php echo $wpep_current_form_id; ?>" class="paynow">
					<?php if ( $currencySymbolType == 'symbol' ) { ?>
						<?php echo $square_currency . $wpep_square_payment_box_1; ?>
					<?php } else { ?>
						<?php echo $wpep_square_payment_box_1 . ' ' . $square_currency; ?>
					<?php } ?>
				</label>
			</div>
		<?php } ?>
		<?php if ( '' != $wpep_square_payment_box_2 ) { ?>
			<div class="selection">
				<input id="doller2_<?php echo $wpep_current_form_id; ?>" name="doller"
					   type="radio" 
					   <?php
						if ( $defaultPriceSelected == 'dollar2' ) :
							echo 'data-default="true" checked';
                        endif;
						?>
						 />
				<label for="doller2_<?php echo $wpep_current_form_id; ?>" class="paynow">
					<?php if ( $currencySymbolType == 'symbol' ) { ?>
						<?php echo $square_currency . $wpep_square_payment_box_2; ?>
					<?php } else { ?>
						<?php echo $wpep_square_payment_box_2 . ' ' . $square_currency; ?>
					<?php } ?>
				</label>
			</div>
		<?php } ?>
		<?php if ( '' != $wpep_square_payment_box_3 ) { ?>
			<div class="selection">
				<input id="doller5_<?php echo $wpep_current_form_id; ?>" name="doller"
					   type="radio" 
					   <?php
						if ( $defaultPriceSelected == 'dollar3' ) :
							echo 'data-default="true" checked';
endif;
						?>
						 />
				<label for="doller5_<?php echo $wpep_current_form_id; ?>" class="paynow">
					<?php if ( $currencySymbolType == 'symbol' ) { ?>
						<?php echo $square_currency . $wpep_square_payment_box_3; ?>
					<?php } else { ?>
						<?php echo $wpep_square_payment_box_3 . ' ' . $square_currency; ?>
					<?php } ?>
				</label>
			</div>
		<?php } ?>
		<?php if ( '' != $wpep_square_payment_box_4 ) { ?>
			<div class="selection">
				<input id="doller10_<?php echo $wpep_current_form_id; ?>" name="doller"
					   type="radio" 
					   <?php
						if ( $defaultPriceSelected == 'dollar4' ) :
							echo 'data-default="true" checked';
endif;
						?>
						 />
				<label for="doller10_<?php echo $wpep_current_form_id; ?>" class="paynow">
					<?php if ( $currencySymbolType == 'symbol' ) { ?>
						<?php echo $square_currency . $wpep_square_payment_box_4; ?>
					<?php } else { ?>
						<?php echo $wpep_square_payment_box_4 . ' ' . $square_currency; ?>
					<?php } ?>
				</label>
			</div>
		<?php } ?>

		<?php
		if ( $wpep_square_user_defined_amount == 'on' ) {


			?>

			<div class="selection" style="display:none;">
				<input id="doller3_<?php echo $wpep_current_form_id; ?>" name="doller"
					   min="<?php echo $wpep_square_payment_min; ?>" max="<?php echo $wpep_square_payment_max; ?>"
					   class="otherpayment" type="radio"/>
				<label for="doller3_<?php echo $wpep_current_form_id; ?>">Other</label>
			</div>

		<?php } ?>


	</div>

	<div class="selection showPayment">
		<div class="otherpInput">

			<input class="form-control text-center customPayment otherPayment"
				   Placeholder="Enter your amount"
				   name="somename" min="<?php echo $wpep_square_payment_min; ?>"
				   max="<?php echo $wpep_square_payment_max; ?>" type="text"/>


		</div>
	</div>
</div>
