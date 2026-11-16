/**
 * Certificates → Signers: the account search behind Add signer (#2622).
 *
 * A combobox in place of a select of every account with an RF, which could
 * neither scale to a site with thousands of users nor reach an account past
 * the list's cap. Typing asks `ffc_search_signer_candidates`; the arrows move
 * through the results, Enter or a click picks one into the hidden
 * `ffc_signer_user` field, Escape closes the list. Submitting without a
 * choice is stopped with an inline message; the server refuses user 0 too.
 *
 * @since 6.45.0
 */
(function (window, document) {
	'use strict';

	var DELAY = 250;

	function config() {
		return window.ffcSignerUserSearch || {};
	}

	function text(key, fallback) {
		var strings = config().strings || {};
		return strings[key] || fallback;
	}

	/**
	 * Wire one search box.
	 *
	 * @param {HTMLElement} root The `[data-ffc-signer-user-search]` wrapper.
	 */
	function SignerUserSearch(root) {
		this.root = root;
		this.input = root.querySelector('[role="combobox"]');
		this.value = root.querySelector('input[type="hidden"]');
		this.list = root.querySelector('[role="listbox"]');
		this.status = root.querySelector('[role="status"]');
		this.users = [];
		this.active = -1;
		this.timer = null;
		this.sequence = 0;
		this.bind();
	}

	SignerUserSearch.prototype.bind = function () {
		var self = this;
		this.input.addEventListener('input', function () {
			self.value.value = '';
			self.input.removeAttribute('aria-invalid');
			window.clearTimeout(self.timer);
			self.timer = window.setTimeout(function () {
				self.search(self.input.value.trim());
			}, DELAY);
		});
		this.input.addEventListener('keydown', function (event) {
			self.onKey(event);
		});
		this.input.addEventListener('blur', function () {
			// A click on a result lands after the blur; let it pick first.
			window.setTimeout(function () {
				self.close();
			}, 150);
		});
		this.list.addEventListener('mousedown', function (event) {
			var option = event.target.closest('[role="option"]');
			if (option) {
				event.preventDefault();
				self.pick(Number(option.getAttribute('data-index')));
			}
		});
		var form = this.root.closest('form');
		if (form) {
			form.addEventListener('submit', function (event) {
				if (!self.value.value) {
					event.preventDefault();
					self.input.setAttribute('aria-invalid', 'true');
					self.say(text('choose', 'Choose a WordPress user.'));
					self.input.focus();
				}
			});
		}
	};

	SignerUserSearch.prototype.onKey = function (event) {
		var open = !this.list.hidden && this.users.length > 0;
		if (event.key === 'ArrowDown' && open) {
			event.preventDefault();
			this.highlight(Math.min(this.active + 1, this.users.length - 1));
		} else if (event.key === 'ArrowUp' && open) {
			event.preventDefault();
			this.highlight(Math.max(this.active - 1, 0));
		} else if (event.key === 'Enter' && open && this.active >= 0) {
			event.preventDefault();
			this.pick(this.active);
		} else if (event.key === 'Escape' && open) {
			event.preventDefault();
			this.close();
		}
	};

	SignerUserSearch.prototype.search = function (term) {
		var self = this;
		var cfg = config();
		var sequence = ++this.sequence;
		if (term.length < (cfg.minChars || 2)) {
			this.render([]);
			this.say('');
			return;
		}
		if (!window.FFC || typeof window.FFC.request !== 'function') {
			return;
		}
		this.say(text('searching', 'Searching…'));
		window.FFC.request(cfg.action, { term: term }, { nonce: cfg.nonce }).then(function (data) {
			if (sequence !== self.sequence) {
				return;
			}
			var users = data && Array.isArray(data.users) ? data.users : [];
			self.render(users);
			self.say(users.length
				? text('found', '%d accounts found.').replace('%d', String(users.length))
				: text('none', 'No account with an RF matches.'));
		}, function () {
			if (sequence === self.sequence) {
				self.render([]);
				self.say(text('failed', 'The search failed. Try again.'));
			}
		});
	};

	SignerUserSearch.prototype.render = function (users) {
		this.users = users;
		this.active = -1;
		this.list.textContent = '';
		var self = this;
		users.forEach(function (user, index) {
			var item = document.createElement('li');
			item.id = self.list.id + '-' + index;
			item.className = 'ffc-signer-user-option';
			item.setAttribute('role', 'option');
			item.setAttribute('aria-selected', 'false');
			item.setAttribute('data-index', String(index));
			item.textContent = String(user.label);
			self.list.appendChild(item);
		});
		this.list.hidden = users.length === 0;
		this.input.setAttribute('aria-expanded', users.length ? 'true' : 'false');
		this.input.removeAttribute('aria-activedescendant');
	};

	SignerUserSearch.prototype.highlight = function (index) {
		var options = this.list.querySelectorAll('[role="option"]');
		for (var i = 0; i < options.length; i++) {
			options[i].setAttribute('aria-selected', i === index ? 'true' : 'false');
			options[i].classList.toggle('is-active', i === index);
		}
		this.active = index;
		if (options[index]) {
			this.input.setAttribute('aria-activedescendant', options[index].id);
		}
	};

	SignerUserSearch.prototype.pick = function (index) {
		var user = this.users[index];
		if (!user) {
			return;
		}
		this.value.value = String(user.id);
		this.input.value = String(user.label);
		this.input.removeAttribute('aria-invalid');
		this.close();
		this.say('');
	};

	SignerUserSearch.prototype.close = function () {
		this.list.hidden = true;
		this.input.setAttribute('aria-expanded', 'false');
		this.input.removeAttribute('aria-activedescendant');
		this.active = -1;
	};

	SignerUserSearch.prototype.say = function (message) {
		if (this.status) {
			this.status.textContent = message;
		}
	};

	function boot() {
		var roots = document.querySelectorAll('[data-ffc-signer-user-search]');
		for (var i = 0; i < roots.length; i++) {
			if (!roots[i].ffcSignerUserSearch) {
				roots[i].ffcSignerUserSearch = new SignerUserSearch(roots[i]);
			}
		}
	}

	window.FFCSignerUserSearch = { boot: boot };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})(window, document);
