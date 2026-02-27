(function() {
	var cache = new Map();
	var tooltip = null;
	var activeLink = null;

	function ensureTooltip() {
		if (tooltip) {
			return tooltip;
		}
		tooltip = document.createElement('div');
		tooltip.className = 'mtgdl-tooltip';
		tooltip.innerHTML = '<div class="mtgdl-tooltip-inner"><img alt="" loading="lazy"></div>';
		document.body.appendChild(tooltip);
		return tooltip;
	}

	function getCardImageUrl(json) {
		if (json && json.image_uris && json.image_uris.normal) {
			return json.image_uris.normal;
		}
		if (json && Array.isArray(json.card_faces) && json.card_faces[0] && json.card_faces[0].image_uris && json.card_faces[0].image_uris.normal) {
			return json.card_faces[0].image_uris.normal;
		}
		return null;
	}

	function fetchCardImage(cardName) {
		if (cache.has(cardName)) {
			return Promise.resolve(cache.get(cardName));
		}

		var url = 'https://api.scryfall.com/cards/named?exact=' + encodeURIComponent(cardName);

		return fetch(url, { method: 'GET', mode: 'cors' })
			.then(function(resp) {
				if (!resp.ok) {
					throw new Error('Scryfall request failed');
				}
				return resp.json();
			})
			.then(function(json) {
				var img = getCardImageUrl(json);
				cache.set(cardName, img);
				return img;
			})
			.catch(function() {
				cache.set(cardName, null);
				return null;
			});
	}

	function supportsAnchorPositioning() {
		if (!window.CSS || !CSS.supports) {
			return false;
		}
		return CSS.supports('position-anchor: --a') && CSS.supports('left: anchor(right)');
	}

	function setAnchorFor(link, tip) {
		var anchorName = link.dataset.mtgdlAnchor;
		if (!anchorName) {
			anchorName = '--mtgdl-' + Math.random().toString(36).slice(2);
			link.dataset.mtgdlAnchor = anchorName;
		}

		try {
			link.style.anchorName = anchorName;
			tip.style.positionAnchor = anchorName;
			tip.classList.add('mtgdl-anchor-supported');
		} catch (e) {
			// If the browser partially supports the syntax but not DOM style bindings.
		}
	}

	function positionTooltip(link) {
		var tip = ensureTooltip();

		if (supportsAnchorPositioning()) {
			setAnchorFor(link, tip);
			// With anchor positioning, CSS handles placement via anchor().
			return;
		}

		var rect = link.getBoundingClientRect();
		var left = window.scrollX + rect.right + 12;
		var top = window.scrollY + rect.top;

		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
	}

	function show(link) {
		var tip = ensureTooltip();
		activeLink = link;

		tip.classList.add('is-visible');
		positionTooltip(link);
	}

	function hide() {
		if (!tooltip) {
			return;
		}
		tooltip.classList.remove('is-visible');
		activeLink = null;
	}

	function setImage(cardName, imgUrl) {
		var tip = ensureTooltip();
		var img = tip.querySelector('img');
		if (!img) {
			return;
		}
		if (imgUrl) {
			img.src = imgUrl;
			img.alt = cardName;
		}
	}

	function onMouseOver(e) {
		var link = e.target.closest ? e.target.closest('a.mtgdl-card-link') : null;
		if (!link) {
			return;
		}
		var cardName = link.getAttribute('data-card-name');
		if (!cardName) {
			return;
		}

		show(link);

		var tip = ensureTooltip();
		tip.classList.add('is-loading');

		fetchCardImage(cardName).then(function(imgUrl) {
			if (!activeLink || activeLink !== link) {
				return;
			}
			setImage(cardName, imgUrl);
			tip.classList.remove('is-loading');
			positionTooltip(link);
			if (!imgUrl) {
				// No image found; keep tooltip hidden.
				hide();
			}
		});
	}

	function onMouseOut(e) {
		var link = e.target.closest ? e.target.closest('a.mtgdl-card-link') : null;
		if (!link) {
			return;
		}
		// If moving within the same link, ignore.
		if (e.relatedTarget && (e.relatedTarget === link || (e.relatedTarget.closest && e.relatedTarget.closest('a.mtgdl-card-link') === link))) {
			return;
		}
		hide();
	}

	function onScrollOrResize() {
		if (!activeLink || !tooltip || !tooltip.classList.contains('is-visible')) {
			return;
		}
		positionTooltip(activeLink);
	}

	document.addEventListener('mouseover', onMouseOver, true);
	document.addEventListener('mouseout', onMouseOut, true);
	window.addEventListener('scroll', onScrollOrResize, { passive: true });
	window.addEventListener('resize', onScrollOrResize);

	// Touch devices: prevent permanent tooltip on tap
	document.addEventListener('touchstart', function() {
		hide();
	}, { passive: true });
})();
