# Favorite Categories

> **Companion: [Hidden Categories](https://github.com/dilroopg/xExtension-HiddenCategories)** — the
> opposite job: put categories *away* rather than filtering down to a few. The two work together
> fine and neither needs the other — Favorite only dims rows and skips any row already hidden, and
> in Strict it never writes to a feed inside a hidden category. Installed together, their buttons
> share one row above the sidebar.

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

- **It never writes `display` on a category row.** It dims with `opacity`, so another extension
  hiding a row still works — it just stays hidden.
- **Every filter rule excludes rows another extension has hidden.** A category that is both
  favorited and hidden stays hidden in every favorites view.

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

**Light:** nothing. **Strict:** one column on every feed — visibility — with the previous value
recorded first and put back afterwards. Nothing else about a feed is touched.

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

## Changelog

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
