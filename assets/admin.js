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
document.querySelectorAll( 'iframe[data-mailbox-preview]' ).forEach(
	function (frame) {
		function fit() {
			var doc;
			try {
				doc = frame.contentDocument;
			} catch (e) {
				return;
			}
			if ( ! doc || ! doc.documentElement) {
				return;
			}
			frame.style.height = '0';
			frame.style.height = doc.documentElement.scrollHeight + 'px';
		}
		frame.addEventListener( 'load', fit );
		window.addEventListener( 'resize', fit );
		if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
			fit();
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
