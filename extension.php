<?php
declare(strict_types=1);

/**
 * Favorite Categories
 *
 * Pick a few categories as favorites, then filter the sidebar and stream down
 * to just those — or down to everything except those — from one button.
 *
 * One mode only: nothing here is ever written to a feed. Every change is a
 * browser-side filter, so this extension has nothing to snapshot, restore, or
 * fight over with any other extension. That is also why it composes safely with
 * a hiding extension: it never writes `display` on a row it does not own.
 *
 * STANDALONE BY DESIGN
 *   Nothing in this file reads another extension's tables, files, classes or
 *   payload. If no other extension is installed this is a complete, working
 *   favorites filter. If one is, the only shared surface is the class names in
 *   the page markup (see static/favorite.css), and those are read-only.
 */
class FavoriteCategoriesExtension extends Minz_Extension
{
	/** @var FavoriteCategoriesStore|null */
	private $store = null;

	/**
	 * Registers the one global hook plus the stylesheet.
	 *
	 * Like its sibling, this extension is inert outside its own render path:
	 * init() registers no writes and touches no feed. Everything it does is a
	 * page attribute plus CSS.
	 */
	public function init(): void
	{
		require_once __DIR__ . '/store.php';

		try {
			$this->store()->assertSchema();
		} catch (Throwable $e) {
			Minz_Log::warning('FavoriteCategories: state store unavailable: ' . $e->getMessage());
		}

		// Self-heal the pinned order. A favorite made before this feature existed
		// (or by any path that skipped the pin) is corrected here, and a pin whose
		// category is no longer a favorite is released. In steady state this reads
		// two small tables and writes nothing, so it is safe on a page load.
		if ($this->store()->available()) {
			try {
				$r = $this->store()->reconcilePositions();
				if (!$r['ok']) {
					Minz_Log::warning('FavoriteCategories: ' . $r['message']);
				}
			} catch (Throwable $e) {
				Minz_Log::warning('FavoriteCategories: order reconcile failed: ' . $e->getMessage());
			}
		}

		// JsVars fires exactly once per page from the page head. The payload is
		// category ids only, so it cannot grow with subscription count.
		$this->registerHook(Minz_HookType::JsVars, [$this, 'injectJsVars']);

		Minz_View::appendStyle($this->getFileUrl('favorite.css'));
		Minz_View::appendScript($this->getFileUrl('favorite.js', 'js', true));
	}

	/**
	 * The page payload, under its OWN key: `favoriteCategories`.
	 *
	 * Deliberately not merged into another extension's key or derived from one.
	 * Two extensions writing the same key would be the definition of a conflict;
	 * separate keys mean either can be installed alone.
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	public function injectJsVars($vars = [])
	{
		if (!is_array($vars)) {
			$vars = [];
		}
		$cats = [];
		$meta = [];
		try {
			$cats = $this->store()->favIds();
			foreach ($this->categories() as $c) {
				if (in_array($c['id'], $cats, true)) {
					$meta[$c['id']] = ['name' => $c['name'], 'feeds' => $c['feeds']];
				}
			}
		} catch (Throwable $e) {
			Minz_Log::warning('FavoriteCategories: could not build page payload: ' . $e->getMessage());
		}
		$vars['favoriteCategories'] = [
			'cats' => $cats,
			'meta' => $meta,
			'view' => $this->store()->view(),
			'next' => $this->store()->nextView(),
			'rowMode' => $this->store()->rowMode(),
			'rowLabel' => $this->store()->rowLabel(),
			'button' => $this->store()->buttonContent(),
			// One field for the UI: 'pinned' or 'favorites'. The store keeps
			// vocab and icon separately, but they only ever move together.
			'look' => $this->store()->vocabulary() === 'pinned' && $this->store()->icon() === 'pin'
				? 'pinned' : 'favorites',
			'mode' => $this->store()->mode(),
			// _url() returns an HTML-escaped string ("&amp;"), which is correct
			// in markup and WRONG in a JS string: fetch() would send a parameter
			// literally named "amp;a" and every click would silently do nothing.
			'api' => html_entity_decode((string)_url('extension', 'configure', 'e', urlencode($this->getName())), ENT_QUOTES),
		];
		return $vars;
	}

	/**
	 * Runs on every render of the settings page, before the page is built.
	 *
	 * Not in init(): that runs on every page of the instance, and this
	 * extension stays inert outside its own settings. Not in
	 * getConfigureView() either — that method is final in Minz_Extension.
	 */
	public function handleConfigureAction(): void
	{
		Minz_View::appendScript($this->getFileUrl('config.js', 'js', true));

		try {
			$this->store()->assertSchema();
			$this->store()->checkpoint('enabled');
		} catch (Throwable $e) {
			Minz_Log::warning('FavoriteCategories: store check failed: ' . $e->getMessage());
		}

		$this->handleConfigurePost();
	}

	/**
	 * FreshRSS calls this from disableAction — before it writes
	 * extensions_enabled — and a non-true return BLOCKS the disable.
	 *
	 * Same contract as the sibling extension: every feed this extension changed
	 * is restored from its snapshot before the disable goes through, and if the
	 * restore cannot finish the disable is REFUSED so the user still has the
	 * settings page to fix it from. The state (favorites, view, display
	 * options) is kept so re-enabling comes back exactly as it was left.
	 *
	 * @return string|true
	 */
	public function uninstall()
	{
		try {
			$this->store()->assertSchema();
		} catch (Throwable $e) {
			return 'Favorite Categories: could not open its state store, so nothing was restored. '
				. 'The extension was left enabled. ' . $e->getMessage();
		}
		if (!$this->store()->available()) {
			return 'Favorite Categories: state store is unusable (' . $this->store()->error() . '), '
				. 'so nothing was restored. The extension was left enabled.';
		}

		// restore everything: temporarily switch the stored mode off so the
		// sweep is restore-only, then put it back so re-enabling returns in the
		// mode the user left it in
		$mode = $this->store()->mode();
		if ($mode === 'strict') {
			$this->store()->setMode('light');
		}
		$sweep = $this->sweepFeeds();
		if ($mode === 'strict') {
			$this->store()->setMode($mode);
		}

		if (!$sweep['ok']) {
			return 'Favorite Categories: ' . $sweep['message'] . '. Nothing was changed and the '
				. 'extension was left enabled so it can be fixed from its settings page.';
		}

		// Ordering is restored last, and a failure here refuses the disable: a
		// category left pinned at the top with no snapshot would be stuck there
		// with nothing left to restore it from.
		if (!$this->store()->restoreAllPositions()) {
			return 'Favorite Categories: the original category order could not be restored, '
				. 'so the extension was left enabled so it can be fixed from its settings page.';
		}
		$this->store()->checkpoint('disabled');
		return true;
	}

	/**
	 * Converge the feed visibility to the favorites set, and prove it.
	 *
	 * A direct port of the sibling extension's sweep, with one rule added:
	 * feeds in a category that another extension has hidden are NEVER touched.
	 * That is the whole non-conflict guarantee on the feed side — favorites can
	 * reorder feeds, but it cannot resurrect one a hiding extension has removed,
	 * and the two extensions therefore never write the same row.
	 *
	 * In Light mode it only RESTORES (leftovers from a previous Strict session);
	 * it never writes a new value.
	 *
	 * $apply = false performs the same walk without writing, for diagnostics.
	 *
	 * @return array{ok:bool,enforced:int,restored:int,skipped:int,failed:int,message:string}
	 */
	public function sweepFeeds(bool $apply = true): array
	{
		$store = $this->store();
		$strict = $store->mode() === 'strict';
		$favIds = $store->favIds();
		$skipCats = $this->externallyHiddenIds();
		$demoteTo = $store->demotePriority();
		$enforced = 0;
		$restored = 0;
		$skipped = 0;
		$failed = 0;

		// feed id -> current priority, feed id -> category, read once
		$current = [];
		$categoryOf = [];
		try {
			foreach (FreshRSS_Factory::createFeedDao()->selectAll() as $feed) {
				$id = (int)$feed['id'];
				$current[$id] = (int)$feed['priority'];
				$categoryOf[$id] = (int)$feed['category'];
			}
		} catch (Throwable $e) {
			return ['ok' => false, 'enforced' => 0, 'restored' => 0, 'skipped' => 0, 'failed' => 0,
				'message' => 'could not read feeds: ' . $e->getMessage()];
		}

		// ---- 1. restore anything that should no longer be enforced ---------
		// Covers: unfavorited, moved category, deleted feed, Light mode, or a
		// feed that has since been claimed by a hiding extension.
		foreach ($store->snapshots() as $snap) {
			$feedId = $snap['feed_id'];
			$cat = $categoryOf[$feedId] ?? null;
			if ($cat === null) {
				if ($apply) { $store->forgetSnapshot($feedId); }
				$skipped++;
				continue;
			}
			// a feed now inside an externally hidden category: hand it back
			$claimed = in_array($cat, $skipCats, true);
			$stillOurs = $strict && !$claimed;
			if ($stillOurs) {
				continue;                     // stays enforced, handled in pass 2
			}
			if (!$apply) { $restored++; continue; }
			if (!$this->writePriority($feedId, $snap['orig_priority'])) {
				$failed++;
				continue;
			}
			$store->forgetSnapshot($feedId);
			$restored++;
		}

		// ---- 2. enforce ---------------------------------------------------
		if ($strict) {
			foreach ($categoryOf as $feedId => $cat) {
				if (in_array($cat, $skipCats, true)) {
					continue;                 // someone else owns this row
				}
				$prio = $current[$feedId] ?? null;
				if ($prio === null) { continue; }
				$target = in_array($cat, $favIds, true)
					? FavoriteCategoriesStore::PRIORITY_FAV
					: $demoteTo;
				if ($prio === $target) {
					$skipped++;
					continue;
				}
				$known = $store->origFor($feedId);
				if ($known === null) {
					$r = $store->enforceFeed($feedId, $prio);
					if ($r['state'] === 'error') { $failed++; continue; }
				}
				if (!$apply) { $enforced++; continue; }
				if (!$this->writePriority($feedId, $target)) {
					$failed++;
					// the snapshot is wrong if nothing changed; drop it so a later
					// run re-snapshots cleanly
					$store->forgetSnapshot($feedId);
					continue;
				}
				$enforced++;
			}
		}

		if ($failed > 0) {
			return ['ok' => false, 'enforced' => $enforced, 'restored' => $restored,
				'skipped' => $skipped, 'failed' => $failed,
				'message' => $failed . ' feed(s) could not be updated'];
		}
		return ['ok' => true, 'enforced' => $enforced, 'restored' => $restored,
			'skipped' => $skipped, 'failed' => 0, 'message' => ''];
	}

	/**
	 * Category ids another extension has hidden.
	 *
	 * Read as a plain SQLite query against that extension's own state file, if
	 * it exists, because the page payload is not available on every code path
	 * (the sweep also runs from the disable hook and from a settings-page POST).
	 *
	 * This is deliberately read-only and completely optional: no file, no table,
	 * or no PDO SQLite means "nothing is hidden", which is exactly the
	 * standalone case. Nothing here is required for this extension to work, and
	 * nothing is written to that file. The path is derived from the user data
	 * directory, so it never names another extension's code, only a convention
	 * for where its state lives.
	 *
	 * @return list<int>
	 */
	private function externallyHiddenIds(): array
	{
		try {
			$file = $this->getExtensionUserPath();
			// .../extensions/FavoriteCategories -> .../extensions
			$base = dirname(rtrim($file, '/'));
			$candidates = glob($base . '/*/state.sqlite');
			if (!$candidates) {
				return [];
			}
			$ids = [];
			foreach ($candidates as $path) {
				// only files that actually have a hidden_cat table are considered
				try {
					$pdo = new PDO('sqlite:' . $path, null, null, [
						PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
						PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_COLUMN,
					]);
					$pdo->exec('PRAGMA busy_timeout=1000');
					$has = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='hidden_cat'")->fetch();
					if ($has === false) {
						continue;
					}
					foreach ($pdo->query('SELECT cat_id FROM hidden_cat') as $id) {
						$ids[] = (int)$id;
					}
				} catch (Throwable $e) {
					// unreadable or not ours: ignore it
					continue;
				}
			}
			return array_values(array_unique($ids));
		} catch (Throwable $e) {
			Minz_Log::warning('FavoriteCategories: could not read external hidden set: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Write one feed's priority and confirm it landed.
	 *
	 * Uses FreshRSS's own DAO rather than raw SQL so the write goes through the
	 * same path the UI uses. The value is re-read afterwards: a write that
	 * returns without an error but did not change the row is treated as a
	 * failure, because a silent no-op means the feed stays visible while the UI
	 * claims it was filtered.
	 */
	private function writePriority(int $feedId, int $priority): bool
	{
		try {
			FreshRSS_Factory::createFeedDao()->updateFeed($feedId, ['priority' => $priority]);
		} catch (Throwable $e) {
			Minz_Log::warning('FavoriteCategories: priority write failed for feed ' . $feedId . ': ' . $e->getMessage());
			return false;
		}
		try {
			$feed = FreshRSS_Factory::createFeedDao()->searchById($feedId);
			return $feed !== null && (int)$feed->priority() === $priority;
		} catch (Throwable $e) {
			return false;
		}
	}

	/** Lazily built store, rooted in this user's own extension data directory. */
	public function store(): FavoriteCategoriesStore
	{
		if ($this->store === null) {
			require_once __DIR__ . '/store.php';
			$this->store = new FavoriteCategoriesStore($this->getExtensionUserPath());
		}
		return $this->store;
	}

	/**
	 * Every category with its feed count, for the config table.
	 *
	 * @return list<array{id:int,name:string,feeds:int}>
	 */
	public function categories(): array
	{
		$out = [];
		try {
			$counts = [];
			foreach (FreshRSS_Factory::createFeedDao()->selectAll() as $feed) {
				$cid = (int)$feed['category'];
				$counts[$cid] = ($counts[$cid] ?? 0) + 1;
			}
			foreach (FreshRSS_Factory::createCategoryDao()->selectAll() as $row) {
				$id = (int)$row['id'];
				$out[] = [
					'id' => $id,
					'name' => (string)$row['name'],
					'feeds' => (int)($counts[$id] ?? 0),
				];
			}
		} catch (Throwable $e) {
			Minz_Log::warning('FavoriteCategories: could not list categories: ' . $e->getMessage());
		}
		return $out;
	}

	/**
	 * One category's display name, read from FreshRSS itself. Returns null when
	 * the category no longer exists, which is how a stale page revives a deleted
	 * category without silently favoriting a different one that reused the id.
	 */
	public function categoryName(int $catId): ?string
	{
		foreach ($this->categories() as $c) {
			if ($c['id'] === $catId) {
				return $c['name'];
			}
		}
		return null;
	}

	/** POST handling only. Everything that must happen on ANY settings-page
	 *  render lives in handleConfigureAction() above. */
	private function handleConfigurePost(): void
	{
		if (!Minz_Request::isPost()) {
			return;
		}

		// paramStringNull distinguishes "field absent" from "field present but
		// empty". An absent action field is a malformed request, not an
		// instruction to change something — refuse it and change nothing.
		$act = Minz_Request::paramStringNull('act', true);
		if ($act === null || trim($act) === '') {
			Minz_Request::bad('Save failed: the form did not say what to change, so nothing was changed.', $this->selfUrl());
			return;
		}
		$act = trim($act);

		switch ($act) {
			case 'fav':
			case 'unfav':
				$catId = Minz_Request::paramInt('cat');
				if ($catId <= 0) {
					Minz_Request::bad('No category was named, so nothing changed.', $this->selfUrl());
					return;
				}
				// Name comes from the database, never from the request: a posted
				// name could label one category while favoriting another.
				$name = $this->categoryName($catId);
				if ($name === null) {
					Minz_Request::bad('Category ' . $catId . ' no longer exists — nothing changed.', $this->selfUrl());
					return;
				}
				$result = $act === 'fav'
					? $this->store()->favCategory($catId, $name)
					: $this->store()->unfavCategory($catId, $name);
				if (!$result['ok']) {
					Minz_Request::bad($result['message'], $this->selfUrl());
					return;
				}
				// Strict: favoriting changes what the feeds should look like, so
				// converge now rather than on the next page load.
				$sweep = $this->sweepFeeds();
				$extra = $sweep['ok'] ? '' : ' But ' . $sweep['message'] . '.';
				Minz_Request::good($result['message'] . $extra, $this->selfUrl());
				return;

			case 'view':
				// One button, three states. Accepts an explicit target so the
				// button can send the state it displays; refuses anything else
				// rather than guessing.
				$to = Minz_Request::paramStringNull('to', true);
				if ($to === null || trim($to) === '') {
					$to = $this->store()->nextView();
				}
				$to = trim($to);
				if (!in_array($to, FavoriteCategoriesStore::VIEWS, true)) {
					Minz_Request::bad('Unknown view "' . $to . '": nothing was changed.', $this->selfUrl());
					return;
				}
				if (!$this->store()->setView($to)) {
					Minz_Request::bad('Could not save the view: nothing was changed.', $this->selfUrl());
					return;
				}
				// Strict: the view is what the feeds should now look like.
				// 'all' restores every feed; 'only' promotes favorites and
				// demotes the rest; 'without' does the inverse. Light writes
				// nothing, so the sweep is a no-op restore pass.
				$sweep = $this->sweepFeeds();
				if (!$sweep['ok']) {
					Minz_Request::bad('View saved, but ' . $sweep['message'] . '.', $this->selfUrl());
					return;
				}
				Minz_Request::good(
					$to === 'only' ? 'Showing only your favorites.'
						: ($to === 'without' ? 'Favorites are filtered out.'
							: 'Favorites no longer filter anything — showing everything.'),
					$this->selfUrl()
				);
				return;

			case 'display':
				// The sidebar row's display state, saved from one form with one
				// Save button: a rowmode (caret / number / both) plus the label
				// text. Both are posted every time, so this reads the full
				// desired state instead of guessing which control moved.
				$mode = Minz_Request::paramStringNull('rowmode', true);
				$label = Minz_Request::paramStringNull('label', true);
				if ($mode === null || $label === null) {
					Minz_Request::bad('Save failed: the form was incomplete, so nothing was changed.', $this->selfUrl());
					return;
				}
				// The label is stored even in caret mode, so switching back to
				// "Label and number" does not lose what was typed.
				$rl = $this->store()->setRowLabel($label);
				if (!$rl['ok']) {
					Minz_Request::bad($rl['message'], $this->selfUrl());
					return;
				}
				$rm = $this->store()->setRowMode(trim($mode));
				if (!$rm['ok']) {
					Minz_Request::bad($rm['message'], $this->selfUrl());
					return;
				}
				// The button content rides on the same form and the same Save,
				// so the two halves of "what the sidebar shows" can never be
				// saved out of step with each other.
				$btn = Minz_Request::paramStringNull('button', true);
				if ($btn !== null && trim($btn) !== '') {
					$rb = $this->store()->setButtonContent(trim($btn));
					if (!$rb['ok']) {
						Minz_Request::bad($rb['message'], $this->selfUrl());
						return;
					}
				}
				// One setting, two stored halves: the wording (vocab) and the
				// glyph (icon) always move together.
				$look = Minz_Request::paramStringNull('look', true);
				if ($look !== null && trim($look) !== '') {
					$look = trim($look);
					if (!in_array($look, ['pinned', 'favorites'], true)) {
						Minz_Request::bad('Refused: "' . $look . '" is not a symbol option.', $this->selfUrl());
						return;
					}
					$rv = $this->store()->setVocabulary($look);
					$ri = $this->store()->setIcon($look === 'pinned' ? 'pin' : 'star');
					if (!$rv['ok'] || !$ri['ok']) {
						Minz_Request::bad($rv['ok'] ? $ri['message'] : $rv['message'], $this->selfUrl());
						return;
					}
				}
				$now = [
					'caret' => 'the caret only',
					'number' => 'the number on its own',
					'both' => '"' . $this->store()->rowLabel() . '" and the number',
				][$this->store()->rowMode()] ?? 'nothing';
				Minz_Request::good('Saved: the sidebar row shows ' . $now . '.', $this->selfUrl());
				return;

			case 'mode':
				$mode = Minz_Request::paramStringNull('mode', true);
				if ($mode === null || trim($mode) === '') {
					Minz_Request::bad('Save failed: no mode was sent, so nothing was changed.', $this->selfUrl());
					return;
				}
				$r = $this->store()->setMode(trim($mode));
				if (!$r['ok']) {
					Minz_Request::bad($r['message'], $this->selfUrl());
					return;
				}
				// Light -> Strict enforces; Strict -> Light restores. One sweep.
				$sweep = $this->sweepFeeds();
				if (!$sweep['ok']) {
					Minz_Request::bad($r['message'] . ' But ' . $sweep['message'] . '.', $this->selfUrl());
					return;
				}
				Minz_Request::good($r['message'], $this->selfUrl());
				return;

			case 'demote':
				$what = Minz_Request::paramStringNull('demote', true);
				if ($what === null || trim($what) === '') {
					Minz_Request::bad('Save failed: no option was sent, so nothing was changed.', $this->selfUrl());
					return;
				}
				$r = $this->store()->setDemote(trim($what));
				if (!$r['ok']) {
					Minz_Request::bad($r['message'], $this->selfUrl());
					return;
				}
				// changing how far non-favorites fall back means re-applying
				$sweep = $this->sweepFeeds();
				if (!$sweep['ok']) {
					Minz_Request::bad($r['message'] . ' But ' . $sweep['message'] . '.', $this->selfUrl());
					return;
				}
				Minz_Request::good($r['message'], $this->selfUrl());
				return;

			default:
				Minz_Request::bad('Unknown action "' . $act . '": nothing was changed.', $this->selfUrl());
				return;
		}
	}

	/**
	 * Redirect target for feedback messages.
	 *
	 * The extension name MUST sit under 'params': Minz_Request::forward() only
	 * carries 'params', so a top-level 'e' is dropped and the redirect lands on
	 * a nameless configure URL (404).
	 *
	 * @return array<string,mixed>
	 */
	private function selfUrl(): array
	{
		return ['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]];
	}
}
