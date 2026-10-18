/**
 * Favorite Categories — page behaviour.
 *
 * Split of responsibility:
 *   favorite.css   does the filtering, from attributes this file sets
 *   this file      sets those attributes, and adds the conveniences
 *
 * THE NON-CONFLICT CONTRACT (read this before changing anything)
 * -------------------------------------------------------------
 * Another extension may also be managing sidebar rows. To make both work at
 * once without either knowing about the other:
 *
 *   1. This file NEVER sets `display` on a sidebar row. It writes one
 *      attribute, `data-fc-fav`, and lets CSS decide.
 *   2. This file NEVER removes a row from the DOM.
 *   3. Every filtering rule in favorite.css is gated on the row NOT being
 *      marked hidden by someone else — `[data-fc-hidden="0"]`, or the absence
 *      of that attribute when no hiding extension is installed.
 *
 * So if a category is favorited AND hidden, the hiding extension keeps it
 * gone in every favorites view: the favorites rules simply do not match it.
 * Neither extension needs to detect, name, or coordinate with the other.
 *
 * Nothing here is defensive for its own sake: every lookup is guarded so a
 * theme rename or a FreshRSS upgrade degrades to "filtering does nothing"
 * rather than a broken page, and the attribute setting is independent of any
 * DOM surgery below.
 */
(function () {
	'use strict';

	function payload() {
		var ctx = window.context || {};
		var ext = ctx.extensions || {};
		return ext.favoriteCategories || null;
	}

	// window.context is built by FreshRSS's own main.js, which is loaded
	// `defer async` — so it can execute AFTER this file. Reading the payload
	// once at load and bailing out when context is not there yet would make
	// every click and every filter silently do nothing. Wait for it (event,
	// poll, or a short deadline) and only then start.
	function whenContext(fn) {
		var tries = 0;
		var done = function (FC) {
			if (FC && typeof FC === 'object') { fn(FC); }
		};
		var check = function () {
			var FC = payload();
			if (FC) { done(FC); return true; }
			return false;
		};
		if (check()) { return; }
		document.addEventListener('freshrss:globalContextLoaded', function () {
			if (check()) { return; }
			var t = setInterval(function () {
				if (check() || ++tries > 40) { clearInterval(t); }
			}, 50);
		}, { once: true });
		var t0 = setInterval(function () {
			if (check() || ++tries > 40) { clearInterval(t0); }
		}, 50);
	}

	whenContext(start);

	function start(FC) {

	var favs = Array.isArray(FC.cats) ? FC.cats.map(Number) : [];
	var meta = FC.meta && typeof FC.meta === 'object' ? FC.meta : {};
	var view = FC.view === 'only' || FC.view === 'without' ? FC.view : 'all';
	var next = FC.next || 'all';
	var isFav = {};
	for (var i = 0; i < favs.length; i++) {
		isFav[favs[i]] = true;
	}

	var root = document.documentElement;

	// ---- 1. the attributes the stylesheet keys off -------------------------
	//
	// Set immediately, before any DOM walk, so the CSS can filter at first
	// paint. This is the only part the filtering depends on.
	root.setAttribute('data-fc-view', view);
	root.setAttribute('data-fc-favs', favs.join(','));

	// ---- 2. per-id rules, injected once ------------------------------------
	//
	// The filtering needs one line per favorited category. Generating them here
	// keeps favorite.css static and cacheable.
	//
	// EVERY selector below is qualified by [data-fc-hidden="0"] OR the absence
	// of that attribute. That is the whole coordination mechanism: a row another
	// extension has hidden is not a row this extension may filter, so hiding
	// always wins and the two never disagree about the same node.
	var NOT_HIDDEN = ':not([data-fc-hidden="1"])';

	function markStyles() {
		if (favs.length === 0) { return; }
		if (document.getElementById('fc-rules')) { return; }
		var rules = [];
		for (var n = 0; n < favs.length; n++) {
			var id = favs[n];
			// 'only': everything that is NOT a favorite recedes
			// 'without': the favorites themselves recede
			// both are reachable only on rows we are allowed to touch
			rules.push('html[data-fc-view="only"] li.tree-folder.category' + NOT_HIDDEN
				+ ':not([data-fc-fav="1"]) { opacity: .32; }');
			rules.push('html[data-fc-view="without"] li.tree-folder.category[data-fc-fav="1"]'
				+ NOT_HIDDEN + ' { opacity: .32; }');
			rules.push('li.tree-folder.category#c_' + id + NOT_HIDDEN
				+ '[data-fc-fav="1"] > .tree-folder-title { color: #d9a13b; }');
		}
		var st = document.createElement('style');
		st.id = 'fc-rules';
		st.textContent = rules.join('\n');
		(document.head || document.documentElement).appendChild(st);
	}

	// Markers for anything the generated rules cannot reach by id (a theme that
	// renames the tree classes). Purely additive, and it only ever writes
	// `data-fc-fav` — never `display`, never `hidden`.
	function markTree() {
		var nodes = document.querySelectorAll('li.tree-folder.category');
		for (var n = 0; n < nodes.length; n++) {
			var el = nodes[n];
			var id = parseInt(String(el.id || '').replace(/^c_/, ''), 10);
			if (id > 0) {
				el.setAttribute('data-fc-cat', String(id));
				el.setAttribute('data-fc-fav', isFav[id] ? '1' : '0');
			} else {
				// Main stream / Important / Favourites / Recently Read and other
				// pseudo-categories have no id of ours. They are never favorites,
				// so they must be marked 0 explicitly or the 'only' rule (which
				// selects [data-fc-fav="0"]) would skip them.
				el.setAttribute('data-fc-fav', '0');
			}
		}
	}

	function markStream() {
		var nodes = document.querySelectorAll('.flux[data-category]');
		for (var n = 0; n < nodes.length; n++) {
			var el = nodes[n];
			var id = parseInt(el.getAttribute('data-category'), 10);
			if (!id) { continue; }
			var fav = !!isFav[id];
			el.setAttribute('data-fc-fav', fav ? '1' : '0');

			// Three states, three stream behaviours:
			//   all      -> nothing filtered by this extension
			//   only     -> hide articles that are NOT from a favorite
			//   without  -> hide articles that ARE from a favorite
			var out;
			if (view === 'only') {
				out = !fav;
			} else if (view === 'without') {
				out = fav;
			} else {
				out = false;
			}
			el.classList.toggle('fc-stream-out', out);
		}
	}

	function markAll() {
		markStyles();
		markTree();
		markStream();
	}

	// ---- 3. post a change -------------------------------------------------
	//
	// Every control funnels through here, and every one of them hits the same
	// server action. One write path means the sidebar, the settings page and
	// the subscription page cannot drift apart.
	function csrfToken() {
		var el = document.querySelector('input[name="_csrf"]');
		return (window.context && window.context.csrf) || (el ? el.value : '');
	}

	function post(extra, done) {
		if (!FC.api) { return; }
		var body = new URLSearchParams();
		body.append('_csrf', csrfToken());
		Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });

		fetch(FC.api, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		}).then(function (r) {
			if (r.ok || r.redirected) {
				if (done) { done(); }
				window.location.reload();
			} else {
				toast('Could not save (HTTP ' + r.status + ')', true);
			}
		}).catch(function () {
			toast('Could not reach the server', true);
		});
	}

	// ---- 4. toast ---------------------------------------------------------
	function toast(msg, bad) {
		var n = document.createElement('div');
		n.textContent = msg;
		n.className = 'fc-toast';
		n.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);'
			+ 'z-index:99999;padding:10px 18px;border-radius:7px;font-size:13px;'
			+ 'background:' + (bad ? '#a83232' : '#333') + ';color:#fff;opacity:0;'
			+ 'transition:opacity .18s ease;box-shadow:0 4px 14px rgba(0,0,0,.35)';
		document.body.appendChild(n);
		requestAnimationFrame(function () { n.style.opacity = '1'; });
		setTimeout(function () {
			n.style.opacity = '0';
			setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 220);
		}, bad ? 4000 : 2200);
	}

	// ---- 5. the one button: all -> only -> without -> all ------------------
	//
	// One control, one place, three states. Drawn inline with currentColor so
	// they take the theme's text colour and size, with no icon-font or image
	// dependency.
	//
	//   hollow      -> all:      favorites filter nothing
	//   filled      -> only:     just the favorites are shown
	//   slashed     -> without:  favorites are filtered out
	var STAR = '<path d="M12 3.6l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.8l5.9-.9z"/>';
	var ICONS = {
		all:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
			+ ' stroke-linejoin="round" aria-hidden="true">' + STAR + '</svg>',
		only:
			'<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.7"'
			+ ' stroke-linejoin="round" aria-hidden="true">' + STAR + '</svg>',
		without:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
			+ ' stroke-linejoin="round" aria-hidden="true">' + STAR
			+ '<path d="M4 20L20 4" stroke-width="1.9" stroke-linecap="round"/></svg>'
	};

	var LABELS = {
		// label names what the NEXT click does; the icon shows the CURRENT state
		all: { text: 'Show favorites', title: 'Favorites filter nothing right now \u2014 click to show only favorites' },
		only: { text: 'Hide favorites', title: 'You are seeing only favorites \u2014 click to filter them out instead' },
		without: { text: 'Show all', title: 'Favorites are filtered out \u2014 click to show everything again' }
	};

	function buildButton() {
		// THE SHARED ROW. Any extension that wants a control above the sidebar
		// tree appends into this one row; the row sizes its children generically
		// (see .rs-bar-row in favorite.css). So: get-or-create, then append.
		// Alone that leaves one button at full width; with another extension
		// present the row splits, and neither side knows the other exists.
		var bar = document.getElementById('rs-bar');
		if (!bar) {
			var tree = document.querySelector('nav#sidebar .tree') || document.querySelector('.tree');
			if (!tree) { return; }
			bar = document.createElement('div');
			bar.id = 'rs-bar';
			bar.className = 'rs-bar';
			// sits directly above the tree, so it is the first thing in reach
			tree.parentNode.insertBefore(bar, tree);
			var wrap = document.createElement('div');
			wrap.className = 'rs-bar-row';
			bar.appendChild(wrap);
		}
		var btnWrap = bar.querySelector('.rs-bar-row');
		if (!btnWrap) { return; }
		bar.style.display = '';

		// Idempotent: if this page was already rendered by us, leave it alone
		// rather than stacking a second star.
		if (document.getElementById('fc-view-btn')) { return; }

		var b = document.createElement('button');
		b.type = 'button';
		b.id = 'fc-view-btn';
		b.className = 'fc-view-btn';
		b.setAttribute('data-fc-state', view);
		var l = LABELS[view] || LABELS.all;
		// icon = current state, label = next action, kept in two spans so the
		// icon never has to be re-parsed on every render
		b.innerHTML = '<span class="fc-ico">' + (ICONS[view] || ICONS.all) + '</span>'
			+ '<span class="fc-txt">' + l.text + '</span>';
		b.title = l.title;
		b.setAttribute('aria-label', l.text + ' \u2014 ' + l.title);
		b.addEventListener('click', function (ev) {
			ev.preventDefault();
			post({ act: 'view', to: next });
		});
		btnWrap.appendChild(b);
	}

	// ---- 6. the sidebar "Favorites (N)" section ---------------------------
	function buildFavSection() {
		if (favs.length === 0) { return; }
		var tree = document.querySelector('nav#sidebar .tree') || document.querySelector('.tree');
		if (!tree) { return; }
		if (document.getElementById('fc-fav-tree')) { return; }

		var li = document.createElement('li');
		li.id = 'fc-fav-tree';
		li.className = 'tree-folder category fc-tree';

		// The display state is stored as `rowmode`, exactly as the sibling
		// extension stores it, so the two rows look and behave alike:
		//   caret   nothing but the disclosure caret
		//   number  the count on its own
		//   both    rowLabel text, then the count
		var rowMode = FC.rowMode === 'number' || FC.rowMode === 'both' ? FC.rowMode : 'caret';
		var label = typeof FC.rowLabel === 'string' ? FC.rowLabel : 'Favorites';
		var drawLabel = rowMode === 'both' && label !== '';
		var drawNum = rowMode !== 'caret';

		var title = document.createElement('a');
		title.className = 'tree-folder-title';
		title.href = '#';
		title.setAttribute('role', 'button');
		title.setAttribute('aria-expanded', view === 'all' ? 'false' : 'true');
		title.title = favs.length + ' favorite ' + (favs.length === 1 ? 'category' : 'categories');
		title.innerHTML = '<button class="dropdown-toggle" type="button" title="Expand">'
			+ '<span class="fc-caret">&#9662;</span></button>'
			+ (!drawLabel && !drawNum ? ''
				: '<span class="title">'
					+ (drawLabel ? '<span class="fc-row-label"></span>' : '')
					+ (drawNum ? '<span class="fc-row-num">' + favs.length + '</span>' : '')
					+ '</span>');
		// the label goes in as TEXT, never as markup: it is user-typed and
		// must not be able to inject anything into the page
		var labelEl = title.querySelector('.fc-row-label');
		if (labelEl) { labelEl.textContent = label; }

		var ul = document.createElement('ul');
		ul.className = 'tree-folder-items' + (view === 'all' ? '' : ' fc-open');
		ul.style.display = view === 'all' ? 'none' : '';

		favs.forEach(function (id) {
			var info = meta[id] || {};
			var item = document.createElement('li');
			item.className = 'item fc-item';

			var a = document.createElement('a');
			a.className = 'fc-name';
			a.href = './?get=c_' + id;
			a.textContent = info.name || ('Category ' + id);

			var count = document.createElement('span');
			count.className = 'fc-count';
			count.textContent = info.feeds != null ? info.feeds + ' feeds' : '';

			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'fc-unfav';
			btn.title = 'Remove from favorites (the category itself is untouched)';
			btn.setAttribute('aria-label', 'Unfavorite ' + (info.name || ('category ' + id)));
			btn.textContent = '\u00D7';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				post({ act: 'unfav', cat: id });
			});

			item.appendChild(a);
			item.appendChild(count);
			item.appendChild(btn);
			ul.appendChild(item);
		});

		// The row is an <a href="#">; themes and core scripts also bind click and
		// preventDefault, and an inline `display` can be overridden by a CSS
		// `.active` class the theme toggles. So: own the click in CAPTURE phase,
		// stop it reaching anything else, and toggle a class instead of an inline
		// style.
		var toggle = function (ev) {
			ev.preventDefault();
			ev.stopPropagation();
			var open = !ul.classList.contains('fc-open');
			ul.classList.toggle('fc-open', open);
			title.setAttribute('aria-expanded', open ? 'true' : 'false');
		};
		title.addEventListener('click', toggle, true);
		var caret = title.querySelector('.dropdown-toggle');
		if (caret) { caret.addEventListener('click', toggle, true); }

		li.appendChild(title);
		li.appendChild(ul);
		tree.appendChild(li);
	}

	// ---- 7. subscription management: a Favorite button per category box ---
	//
	// subscription/index.phtml renders one div.box per category, and its
	// ul.box-content carries data-cat-id — a stable hook, so the button is a
	// plain injection with no template patch. If that markup ever changes the
	// button simply does not appear; the settings page still works.
	function buildSubscriptionButtons() {
		if (!/c=subscription/.test(window.location.search)) { return; }
		var boxes = document.querySelectorAll('div.box');
		for (var n = 0; n < boxes.length; n++) {
			var box = boxes[n];
			if (box.getAttribute('data-fc-done') === '1') { continue; }
			var list = box.querySelector('ul.box-content[data-cat-id]');
			var title = box.querySelector('.box-title');
			if (!list || !title) { continue; }
			var id = parseInt(list.getAttribute('data-cat-id'), 10);
			if (!id) { continue; }
			box.setAttribute('data-fc-done', '1');

			var fav = !!isFav[id];
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'btn fc-sub-btn' + (fav ? ' fc-sub-on' : '');
			btn.textContent = fav ? '\u2605' : '\u2606';
			btn.title = fav
				? 'Remove from favorites (the category itself is untouched)'
				: 'Add to favorites';
			btn.addEventListener('click', function (ev) {
				ev.preventDefault();
				ev.stopPropagation();
				post({ act: this.fcWasFav ? 'unfav' : 'fav', cat: this.fcCatId });
			});
			btn.fcCatId = id;
			btn.fcWasFav = fav;
			title.appendChild(btn);
		}
	}

	// ---- 8. keep markers correct as the page changes ----------------------
	//
	// Infinite scroll and "load more" append new .flux nodes. One observer that
	// only marks newly added items is cheaper than a polling loop.
	function watch() {
		if (typeof MutationObserver !== 'function') { return; }
		var target = document.getElementById('stream');
		if (!target) { return; }
		var pending = false;
		var obs = new MutationObserver(function () {
			if (pending) { return; }
			pending = true;
			requestAnimationFrame(function () {
				pending = false;
				markStream();
			});
		});
		obs.observe(target, { childList: true, subtree: true });
	}

	function boot() {
		markAll();
		buildButton();
		buildFavSection();
		buildSubscriptionButtons();
		watch();
	}

	if (document.readyState === 'loading') {
		// attributes are already set above, before first paint; the DOM work
		// waits for the tree to exist
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
	}   // end start(FC)
}());
