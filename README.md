# Favorite Categories

**Filter your sidebar down to the categories you actually read.** Mark a few categories as
favorites, then flip one star button to see only those — or everything except those.

FreshRSS can group multiple categories into a folder, but that doesn't help when the categories you
care about are scattered across several folders: the main stream is still everything, and the
sidebar is still all of it. This adds a favorites filter that works across folders.

Nothing is deleted, renamed or moved, and no subscription is ever touched. **Nothing is written to
a feed at all** — favoriting is a filter in your browser, not a change to your subscriptions.

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
2. A star button appears above the sidebar. Click it to cycle through the three views.
3. To stop filtering, click until the star is hollow again.

## The star

The star *is* the button, and its icon shows which view you're in. Clicking cycles:

| star | view | sidebar | stream |
|---|---|---|---|
| ☆ hollow | **everything** | none of your categories are filtered by this extension | nothing filtered |
| ★ filled | **favorites only** | non-favorites fade back | only articles from favorited categories |
| ★̸ slashed | **favorites filtered out** | favorites fade back | only articles from the rest |

The filtered views dim the other rows rather than removing them, so a stray click can never leave
you with an apparently empty sidebar — and one more click gets you back.

The filled views also tint favorited category names, so you can tell at a glance which rows the
filter is acting on.

## What "favorite" does and doesn't do

**Does:** filter the sidebar and the main stream in this browser.

**Doesn't:**

- **Change your subscriptions.** A category is favorited or not; its feeds, name, and folder stay
  exactly as they were.
- **Hide anything from other clients.** This is a browser-side filter. Your phone and any synced
  reader app still see every category and feed. (If you want feeds gone from the phone too, that's
  a different job — see *Works with other extensions* below.)
- **Touch read state.** Nothing is marked read, nothing is deleted.

## Works with other extensions

This extension is deliberately built to coexist. Two things make that true:

- **It never writes `display` on a category row.** It dims with `opacity`, so another extension
  hiding a row still works — it just stays hidden.
- **Every filter rule excludes rows another extension has hidden.** A category that is both
  favorited and hidden stays hidden in every favorites view.

**Neither extension needs the other installed.** Alone, this is a complete favorites filter. With a
hiding extension also installed, the two buttons share one row above the sidebar: one control takes
the full width on its own, two split it evenly with a gap between them. Each side sizes itself from
its own row, so neither has to detect the other.

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

Nothing in your feeds. Its state lives in its own SQLite file —
`data/users/<your user>/extensions/FavoriteCategories/state.sqlite` — holding which categories are
favorites, the current view, and the two display options. Deleting the extension folder leaves that
file alone; delete the file itself to forget everything.

Disabling the extension has nothing to undo, because nothing was ever written to a feed. Your
favorites and display options are kept and come back when you re-enable it.

## Troubleshooting

**The star isn't there.** It appears once at least one category is a favorite.

**Clicking the star dims rows but my stream doesn't change.** The stream filter matches articles by
their category id. A theme that strips `data-category` from article rows will dim the sidebar but
leave the stream alone.

**Settings won't save / the page says the store isn't writable.** The web server can't write
`data/users/<your user>/extensions/FavoriteCategories/`. This normally only happens if FreshRSS has
been run as root from a command line, leaving root-owned files behind. Fix ownership with
`chown -R www-data:www-data data/users/<your user>/extensions` (use your own web-server user).

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

## Licence

GPL-3.0 — see [LICENSE](LICENSE).

FreshRSS itself is AGPL-3.0. This is an independent work that talks to FreshRSS through its public
extension API; check the AGPL's terms if you plan to redistribute a combined bundle.
