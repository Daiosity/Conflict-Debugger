document.addEventListener('DOMContentLoaded', function () {
	const scanButton = document.querySelector('[data-pcd-scan-button="true"]');
	const scanStatusWrap = document.querySelector('[data-pcd-scan-status]');
	const scanStatusText = document.querySelector('[data-pcd-scan-message]');
	const scanProgress = document.querySelector('[data-pcd-scan-progress]');
	const scanProgressLabel = document.querySelector('[data-pcd-scan-progress-label]');
	const tabTriggers = Array.from(document.querySelectorAll('[data-pcd-tab-trigger]'));
	const tabPanels = Array.from(document.querySelectorAll('[data-pcd-tab-panel]'));
	const pluginSelect = document.querySelector('[data-pcd-plugin-select="true"]');
	const pluginCards = Array.from(document.querySelectorAll('[data-pcd-plugin-card]'));
	const tabLinks = Array.from(document.querySelectorAll('[data-pcd-open-tab]'));
	const sessionContextSelect = document.querySelector('[data-pcd-session-context="true"]');
	const sessionStartButton = document.querySelector('[data-pcd-session-start="true"]');
	const sessionEndButton = document.querySelector('[data-pcd-session-end="true"]');
	let pollHandle = null;
	let localBusy = false;
	const defaultLabel = scanButton ? (scanButton.dataset.pcdDefaultLabel || pcdAdmin.i18n.runScan || 'Run Scan') : 'Run Scan';
	const reloadedTokenKey = 'pcdScanReloadedToken';
	const activeTabKey = 'pcdActiveTab';
	const activePluginKey = 'pcdActivePlugin';

	if (!scanButton || typeof pcdAdmin === 'undefined') {
		return;
	}

	function activateTab(tabName) {
		if (!tabName || !tabTriggers.length || !tabPanels.length) {
			return;
		}

		tabTriggers.forEach(function (trigger) {
			const isActive = trigger.dataset.pcdTabTrigger === tabName;
			trigger.classList.toggle('nav-tab-active', isActive);
			trigger.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		tabPanels.forEach(function (panel) {
			const isActive = panel.dataset.pcdTabPanel === tabName;
			panel.classList.toggle('is-active', isActive);
			panel.hidden = !isActive;
		});

		window.sessionStorage.setItem(activeTabKey, tabName);

		if (tabName === 'plugins' && pluginCards.length) {
			activatePluginCard((pluginSelect && pluginSelect.value) || pluginCards[0].dataset.pcdPluginCard);
		}
	}

	function activatePluginCard(pluginSlug) {
		if (!pluginSlug || !pluginCards.length) {
			return;
		}

		let matched = false;

		pluginCards.forEach(function (card) {
			const isActive = card.dataset.pcdPluginCard === pluginSlug;
			card.hidden = !isActive;
			card.classList.toggle('is-active', isActive);
			if (isActive) {
				matched = true;
			}
		});

		if (!matched && pluginCards[0]) {
			activatePluginCard(pluginCards[0].dataset.pcdPluginCard);
			return;
		}

		if (pluginSelect && pluginSelect.value !== pluginSlug) {
			pluginSelect.value = pluginSlug;
		}

		window.sessionStorage.setItem(activePluginKey, pluginSlug);
	}

	tabTriggers.forEach(function (trigger) {
		trigger.addEventListener('click', function () {
			activateTab(trigger.dataset.pcdTabTrigger);
		});
	});

	if (tabTriggers.length && tabPanels.length) {
		activateTab(window.sessionStorage.getItem(activeTabKey) || tabTriggers[0].dataset.pcdTabTrigger);
	}

	if (pluginSelect && pluginCards.length) {
		pluginSelect.addEventListener('change', function () {
			activatePluginCard(pluginSelect.value);
		});

		activatePluginCard(window.sessionStorage.getItem(activePluginKey) || pluginSelect.value || pluginCards[0].dataset.pcdPluginCard);
	}

	tabLinks.forEach(function (link) {
		link.addEventListener('click', function (event) {
			const targetTab = link.dataset.pcdOpenTab;
			const targetSelector = link.dataset.pcdScrollTarget;

			if (!targetTab) {
				return;
			}

			event.preventDefault();
			activateTab(targetTab);

			if (!targetSelector) {
				return;
			}

			window.setTimeout(function () {
				const target = document.querySelector(targetSelector);
				if (!target) {
					return;
				}

				document.querySelectorAll('.pcd-runtime-event-card.is-highlighted').forEach(function (card) {
					card.classList.remove('is-highlighted');
				});

				target.classList.add('is-highlighted');
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });

				window.setTimeout(function () {
					target.classList.remove('is-highlighted');
				}, 2200);
			}, 120);
		});
	});

	function updateSessionButtons(isActive) {
		if (sessionStartButton) {
			sessionStartButton.disabled = !!isActive;
		}
		if (sessionEndButton) {
			sessionEndButton.disabled = !isActive;
		}
		if (sessionContextSelect) {
			sessionContextSelect.disabled = !!isActive;
		}
	}

	function sessionRequest(action, extraFields) {
		const formData = new FormData();
		formData.append('action', action);
		formData.append('nonce', pcdAdmin.nonce);

		Object.keys(extraFields || {}).forEach(function (key) {
			formData.append(key, extraFields[key]);
		});

		return fetch(pcdAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error((payload && payload.data && payload.data.message) || pcdAdmin.i18n.error);
			}

			return payload.data || {};
		});
	}

	if (sessionStartButton) {
		sessionStartButton.addEventListener('click', function () {
			const targetContext = sessionContextSelect ? sessionContextSelect.value : 'all';
			updateSessionButtons(true);

			sessionRequest('pcd_start_diagnostic_session', {
				target_context: targetContext,
			}).then(function () {
				window.sessionStorage.setItem(activeTabKey, 'diagnostics');
				window.location.reload();
			}).catch(function () {
				updateSessionButtons(false);
			});
		});
	}

	if (sessionEndButton) {
		sessionEndButton.addEventListener('click', function () {
			updateSessionButtons(false);

			sessionRequest('pcd_end_diagnostic_session', {}).then(function () {
				window.sessionStorage.setItem(activeTabKey, 'diagnostics');
				window.location.reload();
			}).catch(function () {
				updateSessionButtons(true);
			});
		});
	}

	if (sessionStartButton || sessionEndButton) {
		updateSessionButtons(sessionEndButton ? !sessionEndButton.disabled : false);
	}

	function updateProgress(state) {
		if (!scanStatusWrap || !scanStatusText || !scanProgress || !scanProgressLabel) {
			return;
		}

		const status = state.status || 'idle';
		const progress = Number(state.progress || 0);
		scanStatusWrap.hidden = status === 'idle';
		scanStatusWrap.dataset.pcdScanState = status;
		scanStatusText.textContent = state.message || pcdAdmin.i18n.running;
		scanProgress.style.width = `${Math.max(0, Math.min(progress, 100))}%`;
		scanProgressLabel.textContent = `${progress}%`;

		if (status === 'complete') {
			scanStatusText.textContent = pcdAdmin.i18n.completed;
			scanButton.disabled = false;
			scanButton.dataset.pcdBusy = 'false';
			scanButton.textContent = defaultLabel;
			localBusy = false;

			const token = state.token || '';
			const reloadedToken = window.sessionStorage.getItem(reloadedTokenKey);
			if (token && reloadedToken !== token) {
				window.sessionStorage.setItem(reloadedTokenKey, token);
				setTimeout(function () {
					window.location.reload();
				}, 400);
			}
		}

		if (status === 'failed') {
			scanButton.disabled = false;
			scanButton.dataset.pcdBusy = 'false';
			scanButton.textContent = defaultLabel;
			localBusy = false;
		}
	}

	function getStatus() {
		const formData = new FormData();
		formData.append('action', 'pcd_get_scan_status');
		formData.append('nonce', pcdAdmin.nonce);

		return fetch(pcdAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error(pcdAdmin.i18n.error);
				}

				updateProgress(payload.data || {});
				return payload.data || {};
			});
	}

	function startPolling() {
		if (pollHandle) {
			window.clearInterval(pollHandle);
		}

		pollHandle = window.setInterval(function () {
			getStatus().then(function (state) {
				if (state.status === 'complete' || state.status === 'failed' || state.status === 'idle') {
					window.clearInterval(pollHandle);
					pollHandle = null;
				}
			}).catch(function () {
				window.clearInterval(pollHandle);
				pollHandle = null;
				localBusy = false;
				scanButton.disabled = false;
				scanButton.dataset.pcdBusy = 'false';
				scanButton.textContent = defaultLabel;
				if (scanStatusText) {
					scanStatusText.textContent = pcdAdmin.i18n.error;
				}
			});
		}, 1500);
	}

	function startScan() {
		const formData = new FormData();
		formData.append('action', 'pcd_start_scan');
		formData.append('nonce', pcdAdmin.nonce);

		return fetch(pcdAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then(function (response) {
				return response.json();
		})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error(pcdAdmin.i18n.error);
				}

				if (window.sessionStorage) {
					window.sessionStorage.removeItem(reloadedTokenKey);
				}

				updateProgress(payload.data || {});
				startPolling();
			});
	}

	getStatus().then(function (state) {
		if (state.status === 'queued' || state.status === 'running') {
			scanButton.disabled = true;
			scanButton.dataset.pcdBusy = 'true';
			scanButton.textContent = pcdAdmin.scanningLabel;
			localBusy = true;
			startPolling();
		}
	}).catch(function () {
		// Ignore initial status failures.
	});

	scanButton.addEventListener('click', function (event) {
		event.preventDefault();

		if (scanButton.dataset.pcdBusy === 'true' || localBusy) {
			return;
		}

		localBusy = true;
		scanButton.dataset.pcdBusy = 'true';
		scanButton.textContent = pcdAdmin.scanningLabel;
		scanButton.disabled = true;
		if (scanStatusWrap) {
			scanStatusWrap.hidden = false;
		}
		if (scanStatusText) {
			scanStatusText.textContent = pcdAdmin.i18n.starting;
		}

		startScan().catch(function () {
			localBusy = false;
			scanButton.dataset.pcdBusy = 'false';
			scanButton.textContent = defaultLabel;
			scanButton.disabled = false;
			if (scanStatusText) {
				scanStatusText.textContent = pcdAdmin.i18n.error;
			}
		});
	});
});
