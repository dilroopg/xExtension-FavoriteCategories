<?php
declare(strict_types=1);

/**
 * Favorite Categories — own state store.
 *
 * Lives in the user's extension data directory
 * (data/users/<user>/extensions/FavoriteCategories/state.sqlite), NOT in the
 * extension directory: a re-install replaces the directory and the state must
 * survive that. One SQLite file, two tables:
 *
 *   meta     user-level key/value (schema version, view, row display)
 *   fav_cat  the DEFINITION — which categories are favorited
 *
 * Two modes, exactly like its sibling extension:
 *
 *   Light   nothing is written to the feeds. The sidebar and article list are
 *           filtered in the browser.
 *   Strict  feed visibility (FreshRSS's own `priority` column) is changed so
 *           the filter is real for every client, with the previous value
 *           snapshotted per feed and restored exactly.
 *
 * Deliberately absent:
 *   - no passcode/lock: the lock is a self-contained feature of the sibling.
 *
 * Nothing here writes to FreshRSS's own databases, config or feeds, and
 * nothing here reads another extension's files. It works the same whether or
 * not any other extension is installed.
 */
final class FavoriteCategoriesStore
{
	public const SCHEMA = 1;
	/** What the sidebar row is labelled out of the box. */
	public const DEFAULT_LABEL = 'Favorites';

	private const DB_FILE = 'state.sqlite';

	/** Base position for a pinned favorite: below any value a user sets by hand. */
	private const TOP_POSITION = -1000;

	/** @var PDO|null */
	private $pdo = null;

	private string $dir;
	private string $path;
	private string $error = '';
	private string $notice = '';

	public function __construct(string $baseDir)
	{
		$baseDir = rtrim($baseDir, '/');
		$this->dir = $baseDir;
		$this->path = $baseDir . '/' . self::DB_FILE;
	}

	public function path(): string { return $this->path; }
	public function error(): string { return $this->error; }
	public function notice(): string { return $this->notice; }
	public function available(): bool { return $this->error === ''; }

	/**
	 * Make the state directory usable by whoever is serving the request.
	 *
	 * This must hold for an extension that was simply unpacked and enabled: no
	 * step outside this file may be needed. The hard case is a directory that
	 * already exists and was created by a DIFFERENT uid than the web server —
	 * a root-owned `0770` directory is unreadable to www-data, and a root-owned
	 * `0644` database is readable but never writable.
	 *
	 * So ownership and mode are repaired on EVERY call, not only at creation.
	 * chgrp() to a group we do not own simply fails (harmless: that is the
	 * already-correct case), so this needs no knowledge of the current uid —
	 * and must not use posix_*(), which the stock image does not ship.
	 */
	private function ensureDir(): bool
	{
		if (!is_dir($this->dir)) {
			if (!@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
				return false;
			}
		}
		$this->repairState();
		if (is_dir($this->dir) && is_writable($this->dir)) {
			return true;
		}
		// Last resort: widen permissions so an install whose group we cannot
		// determine still works, rather than failing with no way out.
		@chmod($this->dir, 0777);
		return is_dir($this->dir) && is_writable($this->dir);
	}

	/**
	 * Repair the directory, the database and SQLite's sidecar files.
	 *
	 * Called from ensureDir() and again after the connection is open, because
	 * SQLite creates -wal/-shm lazily on the first write and they inherit the
	 * umask, not our intent.
	 */
	private function repairState(): void
	{
		$gid = $this->webGroupId();
		$dirMode = 0770;

		foreach ([$this->dir] as $path) {
			if (!file_exists($path)) {
				continue;
			}
			if ($gid !== null && @filegroup($path) !== $gid) {
				@chgrp($path, $gid);
			}
			// Only touch the mode when it is actually wrong: a needless chmod
			// on every page load is a write on a read path.
			$m = @fileperms($path);
			if ($m !== false && ($m & 0777) !== $dirMode) {
				@chmod($path, $dirMode);
			}
		}

		$files = glob($this->path . '*') ?: [];
		foreach ($files as $path) {
			if (!is_file($path)) {
				continue;
			}
			if ($gid !== null && @filegroup($path) !== $gid) {
				@chgrp($path, $gid);
			}
			$m = @fileperms($path);
			if ($m !== false && ($m & 0777) !== 0660) {
				@chmod($path, 0660);
			}
		}
	}

	/**
	 * The group that owns the user's data directory — i.e. the account the web
	 * server runs as. Null when it cannot be determined.
	 */
	private function webGroupId(): ?int
	{
		// data/users/<user>/extensions/<Entrypoint>  ->  three levels up
		$probe = $this->dir;
		for ($i = 0; $i < 3; $i++) {
			$probe = dirname($probe);
		}
		foreach ([$probe, $this->dir] as $candidate) {
			$gid = @filegroup($candidate);
			if (is_int($gid) && $gid > 0) {
				return $gid;
			}
		}
		return null;
	}

	/** Owner/mode as a human-readable string, for the error message. */
	private function describePath(string $path): string
	{
		if (!file_exists($path)) {
			return $path . ' (missing)';
		}
		$uid = @fileowner($path);
		$gid = @filegroup($path);
		$mode = @fileperms($path);
		$user = $uid !== false && function_exists('posix_getpwuid') ? (@posix_getpwuid($uid)['name'] ?? (string)$uid) : (string)$uid;
		$group = $gid !== false && function_exists('posix_getgrgid') ? (@posix_getgrgid($gid)['name'] ?? (string)$gid) : (string)$gid;
		return $path . ' (owner ' . $user . ':' . $group . ', mode ' . ($mode === false ? '?' : substr(sprintf('%o', $mode), -4)) . ')';
	}

	/**
	 * Open the database, create the schema, and repair a corrupt file.
	 * Never throws: an unusable store reports through error() so the config
	 * page can say so instead of taking the whole instance down.
	 */
	public function assertSchema(): bool
	{
		$this->error = '';

		if (!$this->ensureDir()) {
			$this->error = 'the state directory is not writable by the web server: '
				. $this->describePath($this->dir)
				. '. This is a filesystem permission problem, not a setting.';
			return false;
		}

		try {
			$this->pdo();
		} catch (Throwable $e) {
			$aside = $this->path . '.corrupt-' . date('Ymd-His');
			if (@rename($this->path, $aside)) {
				$this->notice = 'The state database was unreadable and has been set aside as '
					. basename($aside) . '. A fresh one was created.';
			} else {
				$this->error = 'state database is unreadable and could not be moved aside: ' . $e->getMessage();
				return false;
			}
			try {
				$this->pdo(true);
			} catch (Throwable $e2) {
				$this->error = 'state database could not be created: ' . $e2->getMessage();
				return false;
			}
		}

		try {
			$this->createSchema();
			$this->seedMeta();
		} catch (Throwable $e) {
			$this->error = 'state schema failed: ' . $e->getMessage();
			return false;
		}
		// The connection is open now, so SQLite's -wal/-shm exist. Repair them
		// too: they are created with the umask, not with the mode we want, and
		// a journal the web user cannot write breaks the next save.
		$this->repairState();

		if (!is_writable($this->path)) {
			$this->error = 'the state database is not writable by the web server: '
				. $this->describePath($this->path)
				. '. This is a filesystem permission problem, not a setting.';
			return false;
		}
		return true;
	}

	private function pdo(bool $fresh = false): PDO
	{
		if ($this->pdo !== null && !$fresh) {
			return $this->pdo;
		}
		$pdo = new PDO('sqlite:' . $this->path, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$pdo->exec('PRAGMA journal_mode=WAL');
		$pdo->exec('PRAGMA busy_timeout=3000');
		$pdo->query('SELECT count(*) FROM sqlite_master')->fetchColumn();
		$this->pdo = $pdo;
		return $pdo;
	}

	private function createSchema(): void
	{
		$pdo = $this->pdo();
		$pdo->exec('CREATE TABLE IF NOT EXISTS meta (
			k TEXT PRIMARY KEY,
			v TEXT NOT NULL
		)');
		$pdo->exec('CREATE TABLE IF NOT EXISTS fav_cat (
			cat_id INTEGER PRIMARY KEY,
			cat_name TEXT NOT NULL,
			fav_at TEXT NOT NULL
		)');
		// the EFFECTS, not the definition: one row per feed this extension has
		// changed, holding the value it had before. Same shape as the sibling
		// extension's table, in this extension's own file.
		// Sorting: a favorited category is pushed to the top by writing its
		// position attribute, which core sorts by in listSortedCategories().
		// The prior value is kept here so unfavoriting restores it exactly.
		// had_position distinguishes "was absent" from "was 0".
		$pdo->exec('CREATE TABLE IF NOT EXISTS cat_snap (
			cat_id INTEGER PRIMARY KEY,
			had_position INTEGER NOT NULL,
			orig_position INTEGER,
			applied_at TEXT NOT NULL
		)');
		$pdo->exec('CREATE TABLE IF NOT EXISTS feed_snap (
			feed_id INTEGER PRIMARY KEY,
			orig_priority INTEGER NOT NULL,
			applied_at TEXT NOT NULL,
			mode TEXT NOT NULL,
			note TEXT NOT NULL
		)');
	}

	private function seedMeta(): void
	{
		$this->setMetaIfAbsent('schema', (string)self::SCHEMA);
		$this->setMetaIfAbsent('mode', 'light');
		// Strict only: how far a non-favorite falls back. -5 keeps it readable
		// in its own category and in reader apps; -10 removes it everywhere.
		$this->setMetaIfAbsent('demote', 'stream');
		// 'all' is the safe starting view: nothing is filtered until asked
		$this->setMetaIfAbsent('view', 'all');
		// display options for the sidebar row, same three-state model as the
		// sibling extension so the two rows look and behave alike
		$this->setMetaIfAbsent('rowmode', 'both');
		$this->setMetaIfAbsent('row_label', self::DEFAULT_LABEL);
		$this->setMetaIfAbsent('show_count', '1');
		// what the star button itself draws: both | icon | text
		$this->setMetaIfAbsent('button', 'both');
	}

	// ------------------------------------------------------------ favorites

	/**
	 * Favorite one category. Idempotent: favoriting an already-favorited
	 * category changes nothing and says so.
	 *
	 * This is the ONLY write path for favoriting. Every control in the UI posts
	 * to the same action that calls this, so they cannot diverge.
	 *
	 * @return array{ok:bool,message:string,changed:bool}
	 */
	public function favCategory(int $catId, string $catName): array
	{
		if ($catId <= 0) {
			return ['ok' => false, 'changed' => false, 'message' => 'No category was named, so nothing changed.'];
		}
		try {
			$st = $this->pdo()->prepare('INSERT OR IGNORE INTO fav_cat (cat_id, cat_name, fav_at) VALUES (:id, :n, :t)');
			$st->execute([':id' => $catId, ':n' => $catName, ':t' => date('c')]);
			$changed = $st->rowCount() > 0;
		} catch (Throwable $e) {
			return ['ok' => false, 'changed' => false, 'message' => 'Could not save the favorite: ' . $e->getMessage()];
		}
		if ($changed) {
			// Pin it above the rest. A failure here must not undo the favorite,
			// so the message reports it but the favorite stands.
			$r = $this->pushToTop($catId);
			if (!$r['ok']) {
				return [
					'ok' => true,
					'changed' => true,
					'message' => '"' . $catName . '" is now a favorite, but it could not be moved to the top: ' . $r['message'],
				];
			}
		}
		return [
			'ok' => true,
			'changed' => $changed,
			'message' => $changed
				? '"' . $catName . '" is now a favorite.'
				: '"' . $catName . '" was already a favorite.',
		];
	}

	/**
	 * Unfavorite one category. Idempotent, same reasoning as favCategory().
	 *
	 * @return array{ok:bool,message:string,changed:bool}
	 */
	public function unfavCategory(int $catId, string $catName): array
	{
		if ($catId <= 0) {
			return ['ok' => false, 'changed' => false, 'message' => 'No category was named, so nothing changed.'];
		}
		try {
			$st = $this->pdo()->prepare('DELETE FROM fav_cat WHERE cat_id = :id');
			$st->execute([':id' => $catId]);
			$changed = $st->rowCount() > 0;
			if ($changed) {
				// Put the original order back before anything else can read it.
				$this->restorePosition($catId);
			}
		} catch (Throwable $e) {
			return ['ok' => false, 'changed' => false, 'message' => 'Could not clear the favorite: ' . $e->getMessage()];
		}
		return [
			'ok' => true,
			'changed' => $changed,
			'message' => $changed
				? '"' . $catName . '" is no longer a favorite.'
				: '"' . $catName . '" was not a favorite.',
		];
	}


	// ------------------------------------------------------------- ordering

	/**
	 * Push a favorited category to the top by writing its position attribute.
	 *
	 * Core sorts categories by position ascending (CategoryDAO::listSortedCategories),
	 * so a negative value beats every manually set position. The prior value is
	 * snapshotted first (including "absent"), so unfavoriting restores exactly what
	 * was there rather than a guessed default.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function pushToTop(int $catId, int $rank = 0): array
	{
		if ($catId <= 0) {
			return ['ok' => false, 'message' => 'No category was named.'];
		}
		try {
			$cat = $this->category($catId);
			if ($cat === null) {
				return ['ok' => false, 'message' => 'That category no longer exists.'];
			}
			$current = $cat->attributeInt('position');
			$st = $this->pdo()->prepare(
				'INSERT OR IGNORE INTO cat_snap (cat_id, had_position, orig_position, applied_at)
				 VALUES (:id, :had, :orig, :t)'
			);
			$st->execute([
				':id' => $catId,
				':had' => $current === null ? 0 : 1,
				':orig' => $current,
				':t' => date('c'),
			]);
			$this->writePosition($cat, self::TOP_POSITION + $rank);
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => 'Could not move the category: ' . $e->getMessage()];
		}
		return ['ok' => true, 'message' => ''];
	}

	/**
	 * Restore a category's position from its snapshot and drop the snapshot.
	 * Called on unfavorite, and on disable for every remaining snapshot.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function restorePosition(int $catId): array
	{
		try {
			$st = $this->pdo()->prepare('SELECT had_position, orig_position FROM cat_snap WHERE cat_id = :id');
			$st->execute([':id' => $catId]);
			$row = $st->fetch();
			if (!is_array($row)) {
				return ['ok' => true, 'message' => ''];
			}
			$cat = $this->category($catId);
			if ($cat !== null) {
				$had = ((int)$row['had_position']) === 1;
				$orig = $row['orig_position'] === null ? null : (int)$row['orig_position'];
				$this->writePosition($cat, $had ? $orig : null);
			}
			$this->pdo()->prepare('DELETE FROM cat_snap WHERE cat_id = :id')->execute([':id' => $catId]);
		} catch (Throwable $e) {
			return ['ok' => false, 'message' => 'Could not restore the category order: ' . $e->getMessage()];
		}
		return ['ok' => true, 'message' => ''];
	}


	/**
	 * Converge the pinned order: restore first, then enforce.
	 *
	 * Favoriting pins a category at the moment it is favorited, but that alone
	 * leaves two cases wrong — something favorited before this feature existed
	 * was never pinned, and a category unfavorited by another path (or with no
	 * snapshot) can still be sitting at the top. This walks both sides:
	 *
	 *   1. every snapshot whose category is no longer a favorite is restored
	 *      and its snapshot dropped;
	 *   2. every favorite with no snapshot is pinned.
	 *
	 * Idempotent: in steady state it reads two small tables and writes nothing,
	 * which is what makes it safe to call from a page load.
	 *
	 * @return array{ok:bool,pinned:int,restored:int,message:string}
	 */
	public function reconcilePositions(): array
	{
		$pinned = 0;
		$restored = 0;
		try {
			$favs = $this->favIds();
			$favSet = array_flip($favs);

			// 1. restore anything pinned that is no longer a favorite
			$snaps = $this->pdo()->query('SELECT cat_id FROM cat_snap')->fetchAll(PDO::FETCH_COLUMN) ?: [];
			foreach ($snaps as $id) {
				if (!isset($favSet[(int)$id])) {
					$this->restorePosition((int)$id);
					$restored++;
				}
			}

			// 2. pin favorites that carry no snapshot yet
			foreach ($favs as $id) {
				$st = $this->pdo()->prepare('SELECT 1 FROM cat_snap WHERE cat_id = :id');
				$st->execute([':id' => $id]);
				if ($st->fetchColumn() === false) {
					$r = $this->pushToTop((int)$id);
					if ($r['ok']) {
						$pinned++;
					}
				}
			}
		} catch (Throwable $e) {
			return ['ok' => false, 'pinned' => $pinned, 'restored' => $restored,
				'message' => 'could not reconcile the category order: ' . $e->getMessage()];
		}
		return ['ok' => true, 'pinned' => $pinned, 'restored' => $restored, 'message' => ''];
	}

	/** Restore every snapshot this extension holds. Used on disable. */
	public function restoreAllPositions(): bool
	{
		try {
			$ids = $this->pdo()->query('SELECT cat_id FROM cat_snap')->fetchAll(PDO::FETCH_COLUMN) ?: [];
		} catch (Throwable $e) {
			return false;
		}
		$ok = true;
		foreach ($ids as $id) {
			$r = $this->restorePosition((int)$id);
			if (!$r['ok']) {
				$ok = false;
			}
		}
		return $ok;
	}

	private function category(int $catId): ?FreshRSS_Category
	{
		$dao = FreshRSS_Factory::createCategoryDao();
		return $dao->searchById($catId);
	}

	private function writePosition(FreshRSS_Category $cat, ?int $position): void
	{
		$cat->_attribute('position', $position);
		// updateCategory() writes name, kind and attributes in one statement, so
		// all three are passed: sending only attributes would blank the name.
		FreshRSS_Factory::createCategoryDao()->updateCategory($cat->id(), [
			'name' => $cat->name(),
			'kind' => $cat->kind(),
			'attributes' => $cat->attributes(),
		]);
	}

	/** @return list<int> category ids, ascending — what the page filter uses. */
	public function favIds(): array
	{
		try {
			return array_map('intval', $this->pdo()->query('SELECT cat_id FROM fav_cat ORDER BY cat_id')->fetchAll(PDO::FETCH_COLUMN));
		} catch (Throwable $e) {
			return [];
		}
	}

	/** @return list<array{cat_id:int,cat_name:string,fav_at:string}> */
	public function favCategories(): array
	{
		try {
			$rows = $this->pdo()->query('SELECT cat_id, cat_name, fav_at FROM fav_cat ORDER BY cat_id')->fetchAll();
		} catch (Throwable $e) {
			return [];
		}
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'cat_id' => (int)$r['cat_id'],
				'cat_name' => (string)$r['cat_name'],
				'fav_at' => (string)$r['fav_at'],
			];
		}
		return $out;
	}

	public function favCount(): int
	{
		try {
			return (int)$this->pdo()->query('SELECT count(*) FROM fav_cat')->fetchColumn();
		} catch (Throwable $e) {
			return 0;
		}
	}

	// ----------------------------------------------------------- view state
	//
	// Three values, cycling in this order:
	//
	//   all      normal: nothing is filtered by this extension
	//   only     only favorited categories are shown
	//   without  favorites are filtered out; everything else is shown
	//
	// Stored as text so a fourth state could be added later without a
	// migration, and so an unexpected value degrades to 'all' — the state in
	// which this extension does nothing at all. That is the safe failure: a
	// bad value can never hide a category.

	public const VIEWS = ['all', 'only', 'without'];

	public function view(): string
	{
		$v = $this->getMeta('view', 'all');
		return in_array($v, self::VIEWS, true) ? $v : 'all';
	}

	/** The state the button moves to on the next click. */
	public function nextView(): string
	{
		$i = array_search($this->view(), self::VIEWS, true);
		return self::VIEWS[($i === false ? 0 : $i + 1) % count(self::VIEWS)];
	}

	public function setView(string $view): bool
	{
		if (!in_array($view, self::VIEWS, true)) {
			return false;
		}
		return $this->setMeta('view', $view);
	}

	// --------------------------------------------------------------- strict
	//
	// Strict makes favoriting real for every client by writing FreshRSS's own
	// per-feed Visibility (the `priority` column), exactly as the sibling
	// extension does for hiding:
	//
	//   20  Show in important feeds   <- what a favorite is promoted to
	//   10  Show in main stream       <- what almost every feed is
	//    0  Show in its category      <- "gone" demote target
	//   -5  Show in its feed          <- "stream" demote target
	//  -10  Do not show               <- never returned by the API at all
	//
	// The demote target is the user's choice, because it is the difference
	// between "not in my main stream" and "not anywhere". Every value is
	// snapshotted before it is changed and restored afterwards, and nothing
	// else about the feed is touched.

	public const PRIORITY_FAV = 20;
	public const PRIORITY_GONE = -10;
	public const DEMOTE_TARGETS = ['stream', 'gone'];

	/** 'light' | 'strict' — anything unexpected falls back to the safe mode. */
	public function mode(): string
	{
		$m = $this->getMeta('mode', 'light');
		return in_array($m, ['light', 'strict'], true) ? $m : 'light';
	}

	/** @return array{ok:bool,message:string} */
	public function setMode(string $mode): array
	{
		if (!in_array($mode, ['light', 'strict'], true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $mode . '" is not a mode. Nothing was changed.'];
		}
		// written last of any multi-step switch: a crash before this leaves the
		// previous mode, which is the state the feed data is consistent with
		if (!$this->setMeta('mode', $mode)) {
			return ['ok' => false, 'message' => 'Save failed: could not store the mode.'];
		}
		return ['ok' => true, 'message' => 'Mode saved: ' . $mode . '.'];
	}

	/** 'stream' (out of the main stream) | 'gone' (out of the category too). */
	public function demote(): string
	{
		$d = $this->getMeta('demote', 'stream');
		return in_array($d, self::DEMOTE_TARGETS, true) ? $d : 'stream';
	}

	/**
	 * The priority a non-favorite is pushed down to, per the current choice.
	 *
	 *   stream  -5   "Show in its feed": still in its category and in reader
	 *                apps, just not the main stream
	 *   gone   -10   "Do not show": never returned to any client, identical to
	 *                the sibling extension's Strict. The category page is empty
	 *                too while this is in force — that is the cost of it being
	 *                gone from the phone as well.
	 */
	public function demotePriority(): int
	{
		return $this->demote() === 'gone' ? self::PRIORITY_GONE : -5;
	}

	/** @return array{ok:bool,message:string} */
	public function setDemote(string $what): array
	{
		if (!in_array($what, self::DEMOTE_TARGETS, true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $what . '" is not a demote option.'];
		}
		if (!$this->setMeta('demote', $what)) {
			return ['ok' => false, 'message' => 'Could not save the demote option.'];
		}
		return ['ok' => true, 'message' => $what === 'gone'
			? 'Non-favorites will be hidden entirely, on every client.'
			: 'Non-favorites will leave the main stream but stay in their category.'];
	}

	/**
	 * Snapshot one feed's priority before this extension changes it.
	 *
	 * @return array{state:string,orig:int|null} state: snapped | recorded | error
	 */
	public function enforceFeed(int $feedId, int $currentPriority): array
	{
		try {
			$st = $this->pdo()->prepare('SELECT orig_priority FROM feed_snap WHERE feed_id = :id');
			$st->execute([':id' => $feedId]);
			$snapped = $st->fetchColumn();
		} catch (Throwable $e) {
			return ['state' => 'error', 'orig' => null];
		}
		if ($snapped !== false) {
			return ['state' => 'recorded', 'orig' => (int)$snapped];
		}
		try {
			$this->pdo()->beginTransaction();
			$this->pdo()->prepare(
				'INSERT INTO feed_snap (feed_id, orig_priority, applied_at, mode, note)
				 VALUES (:id, :p, :t, :m, :n)'
			)->execute([
				':id' => $feedId,
				':p' => $currentPriority,
				':t' => date('c'),
				':m' => 'strict',
				':n' => 'snapped',
			]);
			$this->pdo()->commit();
			return ['state' => 'snapped', 'orig' => $currentPriority];
		} catch (Throwable $e) {
			if ($this->pdo()->inTransaction()) {
				$this->pdo()->rollBack();
			}
			return ['state' => 'error', 'orig' => null];
		}
	}

	/** The recorded original priority for a feed, or null if none. */
	public function origFor(int $feedId): ?int
	{
		try {
			$st = $this->pdo()->prepare('SELECT orig_priority FROM feed_snap WHERE feed_id = :id');
			$st->execute([':id' => $feedId]);
			$v = $st->fetchColumn();
			return ($v === false || $v === null) ? null : (int)$v;
		} catch (Throwable $e) {
			return null;
		}
	}

	/** @return list<array{feed_id:int,orig_priority:int}> */
	public function snapshots(): array
	{
		try {
			$rows = $this->pdo()->query('SELECT feed_id, orig_priority FROM feed_snap ORDER BY feed_id')->fetchAll();
		} catch (Throwable $e) {
			return [];
		}
		$out = [];
		foreach ($rows as $r) {
			$out[] = ['feed_id' => (int)$r['feed_id'], 'orig_priority' => (int)$r['orig_priority']];
		}
		return $out;
	}

	public function snapshotCount(): int
	{
		try {
			return (int)$this->pdo()->query('SELECT count(*) FROM feed_snap')->fetchColumn();
		} catch (Throwable $e) {
			return 0;
		}
	}

	/** Drop one snapshot row after its value has been restored. */
	public function forgetSnapshot(int $feedId): void
	{
		try {
			$this->pdo()->prepare('DELETE FROM feed_snap WHERE feed_id = :id')->execute([':id' => $feedId]);
		} catch (Throwable $e) {
			// leave it: a stale row is retried on the next pass
		}
	}

	// -------------------------------------------------------- display options

	/** What the sidebar row draws: caret | number | both. */
	public const ROW_MODES = ['caret', 'number', 'both'];

	public function rowMode(): string
	{
		$m = $this->getMeta('rowmode', '');
		return in_array($m, self::ROW_MODES, true) ? $m : 'both';
	}

	/** @return array{ok:bool,message:string} */
	public function setRowMode(string $mode): array
	{
		if (!in_array($mode, self::ROW_MODES, true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $mode . '" is not a row display option.'];
		}
		if (!$this->setMeta('rowmode', $mode)) {
			return ['ok' => false, 'message' => 'Could not save the display option.'];
		}
		$this->setMeta('show_count', $mode === 'caret' ? '0' : '1');
		return ['ok' => true, 'message' => 'Display option saved.'];
	}

	public function rowLabel(): string
	{
		return $this->getMeta('row_label', self::DEFAULT_LABEL);
	}

	/** @return array{ok:bool,message:string} */
	public function setRowLabel(string $label): array
	{
		// Newlines and tabs would break the row; collapse whitespace runs and
		// trim the ends, then cap so one paste cannot fill the row.
		$label = trim((string)preg_replace('/\s+/u', ' ', $label));
		$label = function_exists('mb_substr') ? mb_substr($label, 0, 40) : substr($label, 0, 40);
		if (!$this->setMeta('row_label', $label)) {
			return ['ok' => false, 'message' => 'Could not save the label.'];
		}
		return ['ok' => true, 'message' => $label === ''
			? 'Saved: the sidebar row shows the number on its own.'
			: 'Saved: the sidebar row is labelled "' . $label . '".'];
	}

	// --------------------------------------------------------- button content
	//
	//   both  the current state's symbol and the next action's text
	//   icon  the symbol alone
	//   text  the text alone
	//
	// No "neither": a control with no content is an invisible click target and
	// an empty gap in the row. Each option is one or the other, never both.

	public const BUTTON_CONTENTS = ['both', 'icon', 'text'];

	// ------------------------------------------------------ vocabulary + icon
	//
	// Two more display choices, independent of button content:
	//
	//   vocabulary  pinned    the button and row say "pinned" / "Pinned"
	//               favorites the original wording
	//   icon        pin       a pushpin glyph
	//               star      the original star glyph
	//
	// Both are display-only. Neither changes what is stored, which feeds are
	// touched, or any identifier: the extension is still literally named
	// FavoriteCategories internally (favCategory(), data-fc-fav, the
	// `favorite` badge). That is deliberate — see the note at the top of
	// favorite.js before "fixing" the names.

	public const VOCABULARIES = ['pinned', 'favorites'];
	public const ICONS = ['pin', 'star'];

	// ------------------------------------------------------------ dim or hide
	//
	//   hide  filtered rows are removed (display:none) — the 1.2.x behaviour
	//   dim   filtered rows stay in place at reduced opacity
	//
	// Dimming is the gentler option: a stray click can never leave an apparently
	// empty sidebar. It is opt-in and defaults to hide.

	public const FILTER_STYLES = ['hide', 'dim'];

	public function filterStyle(): string
	{
		$v = $this->getMeta('filterstyle', 'hide');
		return in_array($v, self::FILTER_STYLES, true) ? $v : 'hide';
	}

	/** @return array{ok:bool,message:string} */
	public function setFilterStyle(string $what): array
	{
		if (!in_array($what, self::FILTER_STYLES, true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $what . '" is not a filter option.'];
		}
		if (!$this->setMeta('filterstyle', $what)) {
			return ['ok' => false, 'message' => 'Could not save the filter option.'];
		}
		return ['ok' => true, 'message' => $what === 'dim'
			? 'Saved: filtered categories are dimmed instead of hidden.'
			: 'Saved: filtered categories are hidden.'];
	}

	public function vocabulary(): string
	{
		$v = $this->getMeta('vocab', 'favorites');
		return in_array($v, self::VOCABULARIES, true) ? $v : 'favorites';
	}

	/** @return array{ok:bool,message:string} */
	public function setVocabulary(string $what): array
	{
		if (!in_array($what, self::VOCABULARIES, true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $what . '" is not a wording option.'];
		}
		if (!$this->setMeta('vocab', $what)) {
			return ['ok' => false, 'message' => 'Could not save the wording option.'];
		}
		return ['ok' => true, 'message' => $what === 'pinned'
			? 'Saved: the button and sidebar row say "pinned".'
			: 'Saved: the button and sidebar row say "favorites".'];
	}

	public function icon(): string
	{
		$i = $this->getMeta('icon', 'star');
		return in_array($i, self::ICONS, true) ? $i : 'star';
	}

	/** @return array{ok:bool,message:string} */
	public function setIcon(string $what): array
	{
		if (!in_array($what, self::ICONS, true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $what . '" is not an icon option.'];
		}
		if (!$this->setMeta('icon', $what)) {
			return ['ok' => false, 'message' => 'Could not save the icon option.'];
		}
		return ['ok' => true, 'message' => 'Saved: the button shows a ' . $what . '.'];
	}

	public function buttonContent(): string
	{
		$b = $this->getMeta('button', 'both');
		return in_array($b, self::BUTTON_CONTENTS, true) ? $b : 'both';
	}

	/** @return array{ok:bool,message:string} */
	public function setButtonContent(string $what): array
	{
		if (!in_array($what, self::BUTTON_CONTENTS, true)) {
			return ['ok' => false, 'message' => 'Refused: "' . $what . '" is not a button content option.'];
		}
		if (!$this->setMeta('button', $what)) {
			return ['ok' => false, 'message' => 'Could not save the button option.'];
		}
		$now = ['both' => 'the symbol and the text', 'icon' => 'the symbol only', 'text' => 'the text only'][$what];
		return ['ok' => true, 'message' => 'Saved: the star button shows ' . $now . '.'];
	}

	// ------------------------------------------------------------- meta I/O

	public function getMeta(string $key, string $default = ''): string
	{
		try {
			$st = $this->pdo()->prepare('SELECT v FROM meta WHERE k = :k');
			$st->execute([':k' => $key]);
			$v = $st->fetchColumn();
			return ($v === false || $v === null) ? $default : (string)$v;
		} catch (Throwable $e) {
			return $default;
		}
	}

	public function setMeta(string $key, string $value): bool
	{
		try {
			$this->pdo()->prepare(
				'INSERT INTO meta (k, v) VALUES (:k, :v)
				 ON CONFLICT(k) DO UPDATE SET v = excluded.v'
			)->execute([':k' => $key, ':v' => $value]);
			return true;
		} catch (Throwable $e) {
			$this->error = 'could not save ' . $key . ': ' . $e->getMessage();
			return false;
		}
	}

	private function setMetaIfAbsent(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT OR IGNORE INTO meta (k, v) VALUES (:k, :v)')
			->execute([':k' => $key, ':v' => $value]);
	}

	/** Record enable/disable times once, for the Details block. */
	public function checkpoint(string $what): void
	{
		$now = date('c');
		switch ($what) {
			case 'enabled':
				$this->setMetaIfAbsent('first_enabled_at', $now);
				$this->setMetaIfAbsent('last_enabled_at', $now);
				break;
			case 'disabled':
				$this->setMeta('last_disabled_at', $now);
				break;
		}
		$this->setMeta('last_seen_at', $now);
	}

	/** @return list<string> */
	public function invariantProblems(): array
	{
		$problems = [];
		if ($this->getMeta('schema', '') !== (string)self::SCHEMA) {
			$problems[] = 'schema version is "' . $this->getMeta('schema', '') . '", expected ' . self::SCHEMA;
		}
		$view = $this->getMeta('view', 'all');
		if (!in_array($view, self::VIEWS, true)) {
			$problems[] = 'view value "' . $view . '" is not one of ' . implode('/', self::VIEWS);
		}
		if (!in_array($this->getMeta('mode', 'light'), ['light', 'strict'], true)) {
			$problems[] = 'mode value is not light/strict';
		}
		// Light must never leave enforcement snapshots behind: if it does, a mode
		// switch was interrupted and the restore has to finish first.
		if ($this->mode() === 'light' && $this->snapshotCount() > 0) {
			$problems[] = 'light mode with ' . $this->snapshotCount()
				. ' enforced feed(s) recorded — the restore was interrupted';
		}
		return $problems;
	}
}
