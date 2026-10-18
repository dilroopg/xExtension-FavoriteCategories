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
 * Deliberately absent, unlike its sibling extension:
 *   - no feed snapshot table: this extension never writes to a feed.
 *     Favoriting is a browser-side filter, so there is nothing to snapshot or
 *     restore and no way for it to fight another extension over feed.priority.
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
	 * Make sure the state directory exists and is usable BOTH by the web server
	 * (uid www-data, which does the real reads/writes) and by a root CLI script.
	 *
	 * A directory created by a root CLI run is root-owned 0750, which the web
	 * server cannot even stat — and then the extension silently reports "not
	 * writable" on every page while the CLI thinks it is fine.
	 */
	private function ensureDir(): bool
	{
		if (!is_dir($this->dir)) {
			if (!@mkdir($this->dir, 0770, true) && !is_dir($this->dir)) {
				return false;
			}
			@chmod($this->dir, 0770);   // mkdir is subject to umask
			if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
				@chgrp($this->dir, 'www-data');
			}
		}
		$mode = @fileperms($this->dir);
		if ($mode !== false && ($mode & 0007) !== 0007) {
			@chmod($this->dir, 0770);
		}
		return is_dir($this->dir) && is_writable($this->dir);
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
			$this->error = 'state directory is not usable: ' . $this->dir
				. ' (needs to be readable and writable by the web server)';
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
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			foreach (glob($this->path . '*') ?: [] as $f) {
				@chgrp($f, 'www-data');
				@chmod($f, 0664);
			}
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
	}

	private function seedMeta(): void
	{
		$this->setMetaIfAbsent('schema', (string)self::SCHEMA);
		// 'all' is the safe starting view: nothing is filtered until asked
		$this->setMetaIfAbsent('view', 'all');
		// display options for the sidebar row, same three-state model as the
		// sibling extension so the two rows look and behave alike
		$this->setMetaIfAbsent('rowmode', 'both');
		$this->setMetaIfAbsent('row_label', self::DEFAULT_LABEL);
		$this->setMetaIfAbsent('show_count', '1');
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
		return $problems;
	}
}
