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

	// One word and one glyph: a category is pinned. The star and the word
	// "favorite" were settings until 1.4; nothing reads FC.look any more.
	// Declared here, at the top, because the badge (set below) needs NOUN_CAP
	// before the button code runs.
	var NOUN = 'pinned';
	var NOUN_PL = 'pinned';
	var NOUN_CAP = 'Pinned';
	// 'hide' (default) removes filtered rows; 'dim' leaves them at 32% opacity.
	var DIM = FC.filterStyle === 'dim';

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
	root.setAttribute('data-fc-filter', DIM ? 'dim' : 'hide');
	root.setAttribute('data-fc-favs', favs.join(','));
	// The badge word, from the vocabulary setting. A CSS custom property, not
	// an attribute: attributes do not inherit down the tree, so a rule on the
	// row could not read one set on <html>, but a custom property inherits and
	// favorite.css can consume it from any row.
	root.style.setProperty('--fc-badge', '"' + NOUN_CAP + '"');

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
			// 'only': everything that is NOT a favorite is removed outright.
			// The NOT_HIDDEN guard means a row hidden by another extension is never
			// matched, so its display stays whatever that extension decided.
			rules.push('html[data-fc-view="only"] li.tree-folder.category' + NOT_HIDDEN
				+ ':not([data-fc-fav="1"]) { display: none !important; }');
			rules.push('html[data-fc-view="without"] li.tree-folder.category[data-fc-fav="1"]'
				+ NOT_HIDDEN + ' { display: none !important; }');
			// Dim mode. These MUST be regenerated alongside the two rules above:
			// they are per-id and injected after the stylesheet, so a CSS-only
			// override would lose to them and dim mode would silently do nothing.
			if (DIM) {
				rules.push('html[data-fc-filter="dim"][data-fc-view="only"] li.tree-folder.category' + NOT_HIDDEN
					+ ':not([data-fc-fav="1"]) { display: list-item !important; opacity: 0.32; }');
				rules.push('html[data-fc-filter="dim"][data-fc-view="without"] li.tree-folder.category[data-fc-fav="1"]'
					+ NOT_HIDDEN + ' { display: list-item !important; opacity: 0.32; }');
			}
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

	// NOTE: internals keep the original favorite-based names (favCategory,
	// data-fc-fav, favorite.js, view states all/only/without) on purpose —
	// data-fc-fav is a contract with the Hidden Categories sibling. The user
	// -facing words are "pinned" and the glyph is a pushpin. Do not rename
	// these identifiers as a "cleanup".

	// ---- 5. the one button: all -> only -> without -> all ------------------
	//
	// One control, one place, three states. Drawn inline with currentColor so
	// they take the theme's text colour and size, with no icon-font or image
	// dependency.
	//
	//   hollow      -> all:      pins filter nothing
	//   filled      -> only:     just the pinned categories are shown
	//   slashed     -> without:  pinned categories are filtered out
	// One set of glyphs: a pushpin, at the same viewBox, stroke weight and
	// currentColor treatment as the sibling eye, so it sits in the shared
	// sidebar row at the same size.
	// A pushpin: head, shaft, and a point. Filled it reads as "pinned" at
	// sidebar size; the hollow variant is the unpinned state.
	var PIN = '<path d="M9 3h6l-.6 4.2 3.1 3.1v2.2h-4.9V20l-.6 1.2L11.4 20v-7.5H6.5v-2.2l3.1-3.1z"/>';

	var PIN_ICONS = {
		all:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
			+ ' stroke-linejoin="round" aria-hidden="true">' + PIN + '</svg>',
		only:
			'<svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.7"'
			+ ' stroke-linejoin="round" aria-hidden="true">' + PIN + '</svg>',
		without:
			'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
			+ ' stroke-linejoin="round" aria-hidden="true">' + PIN
			+ '<path d="M4 20L20 4" stroke-width="1.9" stroke-linecap="round"/></svg>'
	};

	var GLYPHS = PIN_ICONS;

	var LABELS = {
		// label names the NEXT click does; the icon shows the CURRENT state
		all: { text: 'Mixed', title: 'Everything is showing \u2014 ' + NOUN_PL + ' are not filtering anything' },
		only: { text: 'Just ' + NOUN_CAP, title: 'Only your ' + NOUN + ' categories are showing' },
		without: { text: 'Hide ' + NOUN_CAP, title: NOUN_CAP + ' are filtered out \u2014 everything else is showing' }
	};

	// ---- the button's own content ----------------------------------------
	//
	// Three settings, from the select in the settings page:
	//
	//   both  the current state's symbol AND the next action's text
	//   icon  the symbol alone
	//   text  the text alone
	//
	// There is deliberately no "neither": a button with no content is not a
	// button, and the row would read as an empty gap. Validated server-side,
	// and anything unexpected degrades to 'both'.
	var content = FC.button === 'icon' || FC.button === 'text' ? FC.button : 'both';

	function buttonInner(state) {
		var l = LABELS[state] || LABELS.all;
		var icon = '<span class="fc-ico">' + (GLYPHS[state] || GLYPHS.all) + '</span>';
		// the text is split on the first space so it can wrap onto two lines
		// inside a narrow shared row: "Show" / "favorites". CSS handles the
		// wrap; the split only marks where the break belongs.
		var words = l.text.split(' ');
		var txt = '<span class="fc-txt">'
			+ '<span class="fc-w">' + words[0] + '</span>'
			+ (words.length > 1 ? ' <span class="fc-w">' + words.slice(1).join(' ') + '</span>' : '')
			+ '</span>';
		if (content === 'icon') { return icon; }
		if (content === 'text') { return txt; }
		return icon + txt;
	}

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
		b.setAttribute('data-fc-content', content);
		var l = LABELS[view] || LABELS.all;
		b.innerHTML = buttonInner(view);
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
		var label = typeof FC.rowLabel === 'string' ? FC.rowLabel : NOUN_CAP;
		var drawLabel = rowMode === 'both' && label !== '';
		var drawNum = rowMode !== 'caret';

		var title = document.createElement('a');
		title.className = 'tree-folder-title';
		title.href = '#';
		title.setAttribute('role', 'button');
		title.setAttribute('aria-expanded', view === 'all' ? 'false' : 'true');
		title.title = favs.length + ' ' + NOUN + ' ' + (favs.length === 1 ? 'category' : 'categories');
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
			btn.title = 'Remove from ' + NOUN_PL + ' (the category itself is untouched)';
			btn.setAttribute('aria-label', 'Un' + NOUN + ' ' + (info.name || ('category ' + id)));
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
			// Same glyph as the sidebar button: the filled shape when this
			// category is already in the set, hollow when it is not.
			var shape = PIN;
			btn.innerHTML = '<svg viewBox="0 0 24 24" width="1em" height="1em"'
				+ ' fill="' + (fav ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="1.7"'
				+ ' stroke-linejoin="round" aria-hidden="true">' + shape + '</svg>';
			btn.title = fav
				? 'Remove from ' + NOUN_PL + ' (the category itself is untouched)'
				: 'Add to ' + NOUN_PL;
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

	// ---- 8. right-click a category ---------------------------------------
	//
	// This item is CONTRIBUTED TO RightClickActions, not provided by us. With
	// RCA installed its context menu is the one that opens; we append our item
	// to it. Without RCA there is no right-click menu on the page at all, so
	// there is nothing to append to and nothing is missing — every other way of
	// favoriting (sidebar list, subscription box, settings list) is unaffected.
	//
	// Deliberately NO fallback menu: growing our own would duplicate RCA's UI,
	// drift from its look, and be one more surface to break. If #frss-ctx-menu
	// is absent when the event fires, we do nothing.
	//
	// Guarded so it cannot collide with another extension doing the same: our
	// item carries .fc-ctx-item and we skip appending if one is already there,
	// and we only ever READ the menu element that someone else built.
	function catIdFromRow(el) {
		var row = el && el.closest ? el.closest('li.tree-folder.category') : null;
		if (!row || !row.id || row.id.indexOf('c_') !== 0) { return 0; }
		var id = parseInt(row.id.slice(2), 10);
		return id > 0 ? id : 0;
	}

	function attachRightClick() {
		// capture phase: RCA builds its menu synchronously in its own handler,
		// so by the time ours runs (on the same event, after theirs) the menu
		// exists and we can append into it.
		document.addEventListener('contextmenu', function (ev) {
			var row = ev.target && ev.target.closest ? ev.target.closest('.tree-folder-title') : null;
			if (!row) { return; }
			var id = catIdFromRow(row);
			if (!id) { return; }

			// RCA builds its menu in the same tick; give it one microtask so our
			// append cannot land on a menu it is about to replace.
			window.setTimeout(function () {
				var menu = document.getElementById('frss-ctx-menu');
				if (!menu || menu.querySelector('.fc-ctx-item')) { return; }

				var fav = !!isFav[id];
				var info = meta[id] || {};

				var sep = document.createElement('div');
				sep.className = 'fc-ctx-sep';
				sep.style.cssText = 'height:1px;margin:4px 0;background:rgba(128,128,128,.3)';

				var item = document.createElement('div');
				item.className = 'fc-ctx-item';
				item.setAttribute('role', 'menuitem');
				item.style.cssText = 'padding:6px 14px;cursor:pointer;display:flex;gap:8px;align-items:center';
				item.innerHTML = '<span>' + (fav ? 'Remove from ' + NOUN_PL : 'Add to ' + NOUN_PL) + '</span>'
					+ (info.feeds != null ? '<span style="opacity:.5;font-size:.85em;margin-left:auto">'
						+ info.feeds + '</span>' : '');
				item.addEventListener('mouseenter', function () {
					item.style.background = 'rgba(128,128,128,.2)';
				});
				item.addEventListener('mouseleave', function () {
					item.style.background = '';
				});
				item.addEventListener('click', function (ev2) {
					ev2.preventDefault();
					ev2.stopPropagation();
					if (menu && menu.parentNode) { menu.style.display = 'none'; }
					post({ act: fav ? 'unfav' : 'fav', cat: id });
				});

				menu.appendChild(sep);
				menu.appendChild(item);
			}, 0);
		}, true);
	}

	// ---- 9. keep markers correct as the page changes ----------------------
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
		attachRightClick();
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
