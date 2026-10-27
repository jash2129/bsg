/* My Account: Sign in / Create account tabs and mobile number input. */
(function () {
	'use strict';

	var head = document.querySelector('.bsg-auth-head');
	var cols = document.querySelector('.bsg-auth-form .u-columns');

	if (head && cols) {
		var login = cols.querySelector('.u-column1');
		var register = cols.querySelector('.u-column2');
		var tabs = head.querySelectorAll('[role="tab"]');

		if (login && register) {
			login.id = 'bsg-pane-login';
			register.id = 'bsg-pane-register';
			login.setAttribute('role', 'tabpanel');
			register.setAttribute('role', 'tabpanel');
			// The headings inside each pane repeat the tab label.
			[login, register].forEach(function (pane) {
				var h = pane.querySelector('h2');
				if (h) h.classList.add('screen-reader-text');
			});
			cols.classList.add('bsg-auth-js');

			var show = function (mode, focus) {
				var isReg = mode === 'register';
				cols.classList.toggle('is-register', isReg);
				tabs.forEach(function (t) {
					t.setAttribute('aria-selected', t.dataset.mode === mode ? 'true' : 'false');
				});
				if (focus) {
					var f = (isReg ? register : login).querySelector('input:not([type=hidden])');
					if (f) f.focus({ preventScroll: true });
				}
			};

			tabs.forEach(function (t) {
				t.addEventListener('click', function () {
					show(t.dataset.mode, true);
					if (history.replaceState) history.replaceState(null, '', t.dataset.mode === 'register' ? '#register' : location.pathname + location.search);
				});
			});

			show(location.hash === '#register' ? 'register' : head.dataset.mode, false);
		}
	}

	// Mobile number: digits, spaces and +91 only.
	document.querySelectorAll('#reg_mobile, #bsg_mobile, #billing_phone').forEach(function (el) {
		el.addEventListener('input', function () {
			var v = el.value.replace(/[^\d+\s]/g, '');
			if (v !== el.value) el.value = v;
		});
	});
})();
