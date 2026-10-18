# Favorite Categories

> **Companion: [Hidden Categories](https://github.com/dilroopg/xExtension-HiddenCategories)** — the
> opposite job: put categories *away* rather than filtering down to a few. The two work together
> fine and neither needs the other — Favorite removes rows it filters out but never selects a row
> another extension has hidden, and in Strict it never writes to a feed inside a hidden category.
> Installed together, their buttons share one row above the sidebar.

**Filter your sidebar down to the categories you actually read.** Mark a few categories as
favorites, then flip one star button to see only those — or everything except those.

FreshRSS can group multiple categories into a folder, but that doesn't help when the categories you
care about are scattered across several folders: the main stream is still everything, and the
sidebar is still all of it. This adds a favorites filter that works across folders.

Nothing is deleted, renamed or moved, and no subscription is ever touched. **Nothing is written to a
feed in Light mode** — favoriting is a filter in your browser, not a change to your subscriptions.
The one thing it does write is a favorited category's display position, so favorites sort to the
top; that value is recorded first and restored when you unfavorite.

## Install

**Option 1 — git clone** (updates with `git pull`):

```sh
cd /path/to/FreshRSS/extensions
git clone https://github.com/dilroopg/xExtension-FavoriteCategories.git
```

**Option 2 — the release zip** (no git needed): download
`xExtension-FavoriteCategories.zip` from the
[latest release](https://github.com/dilroopg/xExtension-FavoriteCategories/releases/latest) and
unpack it in `FreshRSS/extensions/`. Unpacking a newer zip over the top updates it and leaves your
settings alone.

Then open **Settings → Extensions → Favorite Categories → enable**.

**Requirements:** FreshRSS 1.28.0 or newer. Nothing else — no build step, no config file, no other
extension. Tested on FreshRSS 1.30.1 / PHP 8.4.

## Quick start

1. Press **Favorite** next to a category in the extension's settings, or the **☆** on a category
   box under **Subscription management**.
2. The favorited category moves to the top of the sidebar and gains a small **favorite** tag.
3. A star button appears above the sidebar. Click it to cycle through the three views.
4. To stop filtering, click until the star is hollow again.

## The star

The star *is* the button, and its icon shows which view you're in. Clicking cycles:

| star | view | sidebar | stream |
|---|---|---|---|
| ☆ hollow | **everything** | none of your categories are filtered by this extension | nothing filtered |
| ★ filled | **favorites only** | only favorited categories are listed | only articles from favorited categories |
| ★̸ slashed | **favorites filtered out** | favorited categories are removed | only articles from the rest |

The filtered views **remove** the rows they filter out rather than dimming them, so the sidebar shows
exactly what the stream is showing. One more click on the star brings everything back.

Favorited categories carry a small **favorite** tag in every view, so you can tell at a glance which
rows the filter is acting on — including in *everything*, where nothing is filtered at all.

## Pick a mode

One question decides it: **should your phone and reader apps see the filter too?**

| | **Light** (default) | **Strict** |
|---|---|---|
| sidebar and stream in your browser | filtered | filtered |
| phone, reader app, API | still show everything | **filtered** |
| what it writes | nothing at all | FreshRSS's per-feed visibility, old value saved and restored |
| speed when switching | instant | a sweep (see below) |

In Strict, favorites are promoted to *Show in important feeds* and everything else is pushed down.
**Where they go** is a second setting, shown only in Strict:

- **Out of the main stream** (−5) — off the main stream everywhere, still readable in its own
  category and in reader apps.
- **Gone entirely** (−10) — not returned to any client at all, exactly like Hidden Categories'
  Strict mode. While this is in force, a non-favorite category's own page is empty too. That is the
  cost of it also being gone from your phone.

Switching back to Light restores every feed. So does unfavoriting, changing the demote setting, or
disabling the extension.

## What "favorite" does and doesn't do

**Does:** filter the sidebar and the main stream — in this browser in Light, on every client in
Strict.

**Doesn't:**

- **Change your subscriptions.** A category is favorited or not; its feeds, name, and folder stay
  exactly as they were.
- **Ever touch a feed in a hidden category.** If a category is hidden by a hiding extension, this
  one leaves its feeds alone entirely — so a favorited category that is also hidden stays hidden.
- **Touch read state.** Nothing is marked read, nothing is deleted.

## Works with other extensions

This extension is deliberately built to coexist. Two things make that true:

- **Every filter rule excludes rows another extension has hidden.** A category that is both
  favorited and hidden stays hidden in every favorites view — the hiding extension's row is never
  selected by a favorites rule, so its visibility is left entirely to that extension.
- **Only the rows this extension is allowed to touch are hidden.** Because of that exclusion, a
  favorites view never removes a row somebody else put away, and never brings one back.

**Neither extension needs the other installed.** Alone, this is a complete favorites filter. With a
hiding extension also installed, the two buttons share one row above the sidebar: one control takes
the full width on its own, two split it evenly with a gap between them. Each side sizes itself from
its own row, so neither has to detect the other.

**On the feeds themselves (Strict):** this extension never writes to a feed inside a hidden
category. Hiding owns those rows; favorites only reorders the rest. So the two can never write the
same feed, and no favorites click can bring back something you hid.

## Three ways to label the sidebar row

The favorites row at the bottom of the sidebar labels itself one of three ways, set under
**Sidebar** in the settings:

| state | the row shows |
|---|---|
| **Nothing but the caret** | no text at all — just the disclosure arrow |
| **The number only** | `3` |
| **Label and number** | `Favorites 3` (any text; default label is `Favorites`) |

The label under **Label text** is kept when you switch to another state, so moving to caret-only
and back never loses it. All three save with **one Save button**, with a live **After saving**
preview and an "Unsaved changes" line until you commit.

## What it writes

**Always:** one attribute on a favorited category — its `position`, which is how core orders the
sidebar. The previous value is recorded first and put back on unfavorite, so the original order
returns exactly (including the case where it had no position at all). This is what pins favorites
to the top; nothing else about a category is changed.

**Light:** nothing else. **Strict:** additionally one column on every feed — visibility — with the
previous value recorded first and put back afterwards. Nothing else about a feed is touched.

Its state lives in its own SQLite file —
`data/users/<your user>/extensions/FavoriteCategories/state.sqlite` — holding which categories are
favorites, the current view, and the two display options. Deleting the extension folder leaves that
file alone; delete the file itself to forget everything.

Disabling restores every feed it changed, then disables. If the restore cannot finish the disable is
**refused** and the extension stays enabled so you can fix it from its settings page. Your favorites
and display options are kept either way, and come back when you re-enable it.

## What it costs

All cost is at the moment you click — page loads stay at normal speed, and nothing runs in the
background (no cron, no external service, no network calls). Light mode costs nothing at all; every
number below is Strict.

- **Favoriting or unfavoriting** re-runs the sweep, because it changes what the feeds should look
  like: favorites move to *Show in important feeds*, everything else to the demote target.
- **Switching view or changing the demote setting** sweeps too. Entering *only favorites* one way
  and leaving it the other means two passes.
- **Cost scales with your feed count, not with how many categories you favorite** — every feed has
  to be set to one of the two values. Expect a couple of seconds at ~1000 feeds, on a local
  instance; a slow host or a remote database will take longer.
- **Hidden feeds keep downloading.** None of this changes what is fetched, so unread counts can
  climb for categories you have filtered out.

While in Strict, the feeds really are set to those visibility values for every client, phone
included. That is what makes the filter true off this machine, and it is exactly why leaving Strict
restores them.

## Troubleshooting

**The star isn't there.** It appears once at least one category is a favorite.

**Clicking the star changes the sidebar but not the stream.** The stream filter matches articles by
their category id. A theme that strips `data-category` from article rows will filter the sidebar but
leave the stream alone.

**Settings won't save / the page says the store isn't writable.** The extension repairs its own
directory and database permissions on every open, including the case where a previous unpack or a
root command-line run left them owned by another user — so this should now be self-healing. If the
message persists, the filesystem is refusing the repair outright (for example a read-only mount, or
group ownership the process cannot change). The message names the exact path, owner and mode to
check.

**It won't load at all.** You're on FreshRSS below 1.28.0, which doesn't have a feature it needs.

## How this was built

Written with AI assistance, as a sibling of
[Hidden Categories](https://github.com/dilroopg/xExtension-HiddenCategories) and sharing its
architecture — the same store pattern, the same page-payload hook, the same single-button view
cycle — but with no dependency on it in either direction. Tested against a real FreshRSS 1.30.1
install: favoriting and unfavoriting, the three views, the display options, disable/enable round
trips, and running both extensions at once.

That's disclosed so you know what you're installing. **Forking and reading the code is
encouraged.** The parts worth reading are `store.php` (the whole persistent state),
`extension.php` (`injectJsVars()`, the page payload) and `static/favorite.js` (the filtering, and
the comment at the top explaining the non-conflict contract).

## Changelog

**1.2.3** — the view button says *Show* / *Hide* / *Show all*.

The three labels named each state at length ("Show favorites", "Hide favorites", "Show all").
They are now one or two words each — *Show all* rather than a bare *All*, so the label says what
the click does — in the sidebar star and the settings page. Behaviour is unchanged.

**1.2.1** — README corrections.

- The README described the old dimming behaviour in five places, contradicting 1.2.0's real
  hiding. Corrected, and the favorite tag and top-of-sidebar pinning are now documented in the
  feature description instead of only in the changelog.
- The troubleshooting section no longer tells you to `chown` the state directory by hand — that
  step is gone, because the extension repairs it itself.

**1.2.0** — favorites are pinned, tagged, and the filters actually filter.

- **Favorites sort to the top.** Favoriting writes the category's `position` (the attribute core
  orders the sidebar by), snapshotting the old value and restoring it exactly on unfavorite. A
  reconcile on load pins anything that was favorited before this existed, and releases a pin whose
  category is no longer a favorite — it writes nothing in steady state.
- **A "favorite" tag** on every favorited category, in every view, styled like the tag a
  category-hiding extension puts on its own rows.
- **The filtered views now hide instead of dimming.** *Only favorites* removes non-favorites and
  *Hide favorites* removes the favorites — `display: none`, not `opacity`. Rows another extension
  hid are never selected, so a category that is both hidden and favorited stays as that extension
  left it.
- **The state directory repairs itself.** A directory created by a different user than the web
  server (root, from an unpack or a CLI run) previously left the store unopenable, so favoriting did
  nothing and logged a SQLSTATE error. Ownership and mode are now repaired on every open, including
  SQLite's `-wal`/`-shm` files, and the settings page says plainly if the filesystem still refuses.
  Unpack and enable is the whole install again.

**1.1.0** — Strict mode.

- Favorites now filter for **every** client, not just the browser: favorites are promoted to *Show
  in important feeds* and everything else is pushed down, with every previous value snapshotted and
  restored on unfavorite, mode switch, demote change, or disable.
- **Where non-favorites go** is a setting, shown only in Strict: *out of the main stream* (−5, still
  readable in their category) or *gone entirely* (−10, not returned to any client at all — the same
  as Hidden Categories' Strict).
- Feeds in a category hidden by another extension are never touched, so a hidden favorite stays
  hidden and the two extensions can't write the same feed.
- Light remains the default and still writes nothing.

**1.0.1** — right-click favoriting, and a button content option.

- Right-clicking a category offers *Add to favorites* / *Remove from favorites*. This was missing
  from 1.0.0. **Requires [RightClickActions](https://github.com/printfuck/xExtension-RightClickActions)**
  — that menu isn't ours, and FreshRSS has no right-click menu without it. The settings page now
  says so.
- **Star button shows**: symbol and text, symbol only, or text only.
- Button text wraps instead of clipping when two extensions share the row.

**1.0.0** — first release. Three-state star (everything / only favorites / favorites filtered out),
favoriting from the settings list, the subscription page star, and a right-click item; Light-only.

## Licence

GPL-3.0 — see [LICENSE](LICENSE).

FreshRSS itself is AGPL-3.0. This is an independent work that talks to FreshRSS through its public
extension API; check the AGPL's terms if you plan to redistribute a combined bundle.
