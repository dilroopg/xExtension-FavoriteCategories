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
	 * There is nothing to undo: this extension never writes to a feed. The
	 * state (favorites, view, display options) is kept so re-enabling comes back
	 * exactly as it was left. Returning true here is therefore honest rather
	 * than a shortcut — the restore is provably a no-op.
	 *
	 * @return string|true
	 */
	public function uninstall()
	{
		try {
			$this->store()->assertSchema();
			$this->store()->checkpoint('disabled');
		} catch (Throwable $e) {
			// Nothing was written to a feed, so a broken store cannot leave the
			// instance inconsistent. Say so in the log and let the disable go
			// through; refusing would trap the user with no way back.
			Minz_Log::warning('FavoriteCategories: store check on disable failed: ' . $e->getMessage());
		}
		return true;
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
				Minz_Request::good($result['message'], $this->selfUrl());
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
				$now = [
					'caret' => 'the caret only',
					'number' => 'the number on its own',
					'both' => '"' . $this->store()->rowLabel() . '" and the number',
				][$this->store()->rowMode()] ?? 'nothing';
				Minz_Request::good('Saved: the sidebar row shows ' . $now . '.', $this->selfUrl());
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
