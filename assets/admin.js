/* CoWeb Outgoing Mail Archive admin controls. GPL-2.0-or-later. */
document.addEventListener(
	'change',
	function (event) {
		if (event.target.matches( '[data-mailbox-select-all]' )) {
			event.target.form.querySelectorAll( 'input[name="mail_ids[]"]' ).forEach(
				function (box) {
					box.checked = event.target.checked;
				}
			);
		}
	}
);
document.addEventListener(
	'submit',
	function (event) {
		var message = event.submitter && event.submitter.dataset.confirm;
		message     = message || event.target.dataset.confirm;
		if (message && ! window.confirm( message )) {
			event.preventDefault(); }
	}
);
