/**
 * Profitly settings: grey out the per-zone shipping cost fields when the
 * selected shipping cost model is not the per-zone carrier estimate. The
 * fields stay editable so switching models never loses configuration.
 */
( function () {
	var group = document.getElementById( 'profitly-shipping-model' );
	var card = document.getElementById( 'profitly-zone-costs' );

	if ( ! group || ! card ) {
		return;
	}

	function sync() {
		var checked = group.querySelector( 'input[type="radio"]:checked' );
		var on = checked && 'carrier_estimate' === checked.value;
		card.classList.toggle( 'is-disabled', ! on );
	}

	group.addEventListener( 'change', sync );
	sync();
}() );
