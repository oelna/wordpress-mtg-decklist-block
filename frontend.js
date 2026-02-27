(function(){
	function parsePreload(wrapper){
		if (wrapper._mtgdlPreloadParsed) {
			return wrapper._mtgdlPreload || {};
		}
		wrapper._mtgdlPreloadParsed = true;

		var node = wrapper.querySelector('.mtgdl-preload');
		if (!node) {
			wrapper._mtgdlPreload = {};
			return wrapper._mtgdlPreload;
		}

		try {
			wrapper._mtgdlPreload = JSON.parse(node.textContent || '{}') || {};
		} catch (e) {
			wrapper._mtgdlPreload = {};
		}
		return wrapper._mtgdlPreload;
	}

	function getCardImageUrl(card){
		if (card && card.image_uris && card.image_uris.normal) {
			return card.image_uris.normal;
		}
		if (card && Array.isArray(card.card_faces) && card.card_faces[0] && card.card_faces[0].image_uris && card.card_faces[0].image_uris.normal) {
			return card.card_faces[0].image_uris.normal;
		}
		return null;
	}

	function supportsAnchorPositioning(){
		if (!window.CSS || !CSS.supports) {
			return false;
		}
		return CSS.supports('position-anchor: --a') && CSS.supports('left: anchor(right)');
	}

	var cache = new Map();
	var tooltip = null;
	var activeLink = null;

	function ensureTooltip(){
		if (tooltip) {
			return tooltip;
		}
		tooltip = document.createElement('div');
		tooltip.className = 'mtgdl-tooltip';
		tooltip.innerHTML = '<div class="mtgdl-tooltip-inner"><img alt="" loading="lazy"></div>';
		document.body.appendChild(tooltip);
		return tooltip;
	}

	function setAnchorFor(link, tip){
		var anchorName = link.dataset.mtgdlAnchor;
		if (!anchorName) {
			anchorName = '--mtgdl-' + Math.random().toString(36).slice(2);
			link.dataset.mtgdlAnchor = anchorName;
		}

		try {
			link.style.anchorName = anchorName;
			tip.style.positionAnchor = anchorName;
			tip.classList.add('mtgdl-anchor-supported');
		} catch (e) {}
	}

	function positionTooltip(link){
		var tip = ensureTooltip();

		if (supportsAnchorPositioning()) {
			setAnchorFor(link, tip);
			return;
		}

		var rect = link.getBoundingClientRect();
		var left = window.scrollX + rect.right + 12;
		var top = window.scrollY + rect.top;

		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
	}

	function show(link){
		var tip = ensureTooltip();
		activeLink = link;

		tip.classList.add('is-visible');
		positionTooltip(link);
	}

	function hide(){
		if (!tooltip) {
			return;
		}
		tooltip.classList.remove('is-visible');
		activeLink = null;
	}

	function setImage(cardName, imgUrl){
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

	function fetchCardImageFromScryfall(cardName){
		if (cache.has(cardName)) {
			return Promise.resolve(cache.get(cardName));
		}

		var url = 'https://api.scryfall.com/cards/named?exact=' + encodeURIComponent(cardName);

		return fetch(url, { method: 'GET', mode: 'cors' })
			.then(function(resp){
				if (!resp.ok) {
					throw new Error('Scryfall request failed');
				}
				return resp.json();
			})
			.then(function(json){
				var img = getCardImageUrl(json);
				cache.set(cardName, img);
				return img;
			})
			.catch(function(){
				cache.set(cardName, null);
				return null;
			});
	}

	function resolveImageUrl(link){
		var cardName = link.getAttribute('data-card-name') || '';
		if (!cardName) {
			return Promise.resolve(null);
		}

		var wrapper = link.closest ? link.closest('.mtgdl[data-mtgdl="1"]') : null;
		if (wrapper) {
			var preload = parsePreload(wrapper);
			var key = (cardName || '').trim().toLowerCase();
			var card = preload && preload[key] ? preload[key] : null;
			var img = getCardImageUrl(card);
			if (img) {
				cache.set(cardName, img);
				return Promise.resolve(img);
			}
		}

		return fetchCardImageFromScryfall(cardName);
	}

	function onMouseOver(e){
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

		resolveImageUrl(link).then(function(imgUrl){
			if (!activeLink || activeLink !== link) {
				return;
			}
			if (!imgUrl) {
				hide();
				return;
			}
			setImage(cardName, imgUrl);
			tip.classList.remove('is-loading');
			positionTooltip(link);
		});
	}

	function onMouseOut(e){
		var link = e.target.closest ? e.target.closest('a.mtgdl-card-link') : null;
		if (!link) {
			return;
		}
		if (e.relatedTarget && (e.relatedTarget === link || (e.relatedTarget.closest && e.relatedTarget.closest('a.mtgdl-card-link') === link))) {
			return;
		}
		hide();
	}

	function onScrollOrResize(){
		if (!activeLink || !tooltip || !tooltip.classList.contains('is-visible')) {
			return;
		}
		positionTooltip(activeLink);
	}

	function setCopyStatus(wrapper, msg){
		var status = wrapper.querySelector('.mtgdl-copy-status');
		if (!status) {
			return;
		}
		status.textContent = msg;
		window.clearTimeout(status._mtgdlT);
		status._mtgdlT = window.setTimeout(function(){
			status.textContent = '';
		}, 1600);
	}

	function getSourceText(wrapper){
		var ta = wrapper.querySelector('.mtgdl-source');
		if (!ta) {
			return '';
		}
		return ta.value || ta.textContent || '';
	}

	function copyText(text){
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function(resolve, reject){
			try {
				var tmp = document.createElement('textarea');
				tmp.value = text;
				tmp.setAttribute('readonly', '');
				tmp.style.position = 'fixed';
				tmp.style.left = '-9999px';
				document.body.appendChild(tmp);
				tmp.select();
				var ok = document.execCommand('copy');
				document.body.removeChild(tmp);
				if (ok) {
					resolve();
				} else {
					reject(new Error('copy failed'));
				}
			} catch (e) {
				reject(e);
			}
		});
	}

	function onClick(e){
		var btn = e.target.closest ? e.target.closest('button.mtgdl-copy[data-mtgdl-copy="1"]') : null;
		if (!btn) {
			return;
		}
		var wrapper = btn.closest ? btn.closest('.mtgdl[data-mtgdl="1"]') : null;
		if (!wrapper) {
			return;
		}

		var text = getSourceText(wrapper);
		if (!text) {
			setCopyStatus(wrapper, 'Nothing to copy');
			return;
		}

		copyText(text).then(function(){
			setCopyStatus(wrapper, 'Copied');
		}).catch(function(){
			setCopyStatus(wrapper, 'Copy failed');
		});
	}

	document.addEventListener('mouseover', onMouseOver, true);
	document.addEventListener('mouseout', onMouseOut, true);
	document.addEventListener('click', onClick, true);

	window.addEventListener('scroll', onScrollOrResize, { passive: true });
	window.addEventListener('resize', onScrollOrResize);

	document.addEventListener('touchstart', function(){
		hide();
	}, { passive: true });
})();
