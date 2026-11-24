# Markdown output for Known (Idno)

Serve every public Known post as Markdown, alongside the normal HTML page.

Any post can be fetched as Markdown three ways:

| Request | Example |
|---|---|
| Add `.md` to the post URL | `https://example.com/blog/2026/my-post.md` |
| Add `?format=markdown` | `https://example.com/blog/2026/my-post?format=markdown` |
| Send `Accept: text/markdown` | `curl -H "Accept: text/markdown" https://example.com/blog/2026/my-post` |

The home page works the same way (`/index.md`, `/?format=markdown`, or `Accept: text/markdown` on `/`) and returns a list of recent posts, each linking to its Markdown version.

Browsers and other clients that ask for HTML keep getting the normal page.

## Why

- **Markdown browsers.** Dave Winer's [Markdown web](https://github.com/scripting/markdownWeb) and its BonBon browser read Markdown pages directly.
- **AI tools.** Some AI agents and coding tools request `Accept: text/markdown` to get a page's text without the HTML around it.

## What the output looks like

```markdown
---
title: "November 11, 2026"
author: "Your Name"
pubDate: "Wed, 11 Nov 2026 18:04:00 +0000"
description: "First sentence of the post."
link: "https://example.com/blog/2026/november-11-2026"
---

# November 11, 2026

### First entry of the day

Text with a [link](https://example.com/) and **bold** words.

![photo.jpg](https://example.com/blog/gfx/post-photos/photo.jpg)
```

The home page version lists recent posts instead, with each day's `<h3>` entry headings underneath:

```markdown
---
title: "Your Blog"
author: "Your Name"
description: "Your blog's description from its feed."
link: "https://example.com/blog/"
---

# Your Blog

> Your blog's description from its feed.

## Recent posts

- [November 11, 2026](https://example.com/blog/2026/november-11-2026.md) (2026-11-11)
  - First entry of the day
  - Second entry of the day
```

The front matter uses field names from RSS 2.0, following the Markdown web conventions. Responses are sent as `text/markdown; charset=utf-8` with `Vary: Accept` and a `Link: <post URL>; rel="canonical"` header pointing to the HTML page.

## How it works

1. Rewrite rules in `.htaccess` send Markdown requests to `md.php` instead of Idno.
2. `md.php` fetches the post's own RSS output (`<post URL>?_t=rss`), which contains the full post HTML. For the home page it fetches the main feed (`/content/all?_t=rss`) instead.
3. It converts the HTML to Markdown element by element: headings, paragraphs, links, bold, italic, strikethrough, images, lists (including nested and numbered), blockquotes, inline code, code blocks, tables and line breaks. Relative image and link URLs are made absolute.
4. It adds the front matter and returns the result.
5. The result is cached per page. A small Idno plugin, **MarkdownCache**, clears the cache whenever a post is created, edited or deleted, so edits show up immediately.

Idno itself is not modified. Posts are still written and stored as HTML.

### Content negotiation

On the `Accept` header route, `md.php` honors q-values (RFC 9110). For example, `Accept: text/html, text/markdown;q=0.5` gets the HTML page, while `Accept: text/markdown, text/html;q=0.9` gets Markdown. A tie goes to Markdown. The `.md` and `?format=markdown` routes always return Markdown, since asking for them is an explicit choice.

Requests whose `Accept` header lists only types the site can't serve get the normal HTML page, not a `406`. RFC 9110 allows either.

### Load protection

Each uncached conversion makes one extra request to the site, so `md.php` limits how much work it can be made to do:

- **"Not found" is remembered.** A missing post is answered with `404` from a marker file for 10 minutes, without asking Idno again.
- **Per-visitor limit.** Each client IP can trigger at most 20 conversions per minute. Requests answered from the cache don't count. Over the limit, clients get the cached copy if there is one, otherwise `429` with `Retry-After`.
- **Concurrency limit.** At most 2 conversions run at the same time. Others get the cached copy if there is one, otherwise `503` with `Retry-After`. This keeps `md.php` from tying up all of the site's PHP workers.

## Requirements

- Idno, on Apache with `mod_rewrite` (and `mod_headers` for the `Vary` header)
- PHP with the `curl`, `dom`, `simplexml` and `mbstring` extensions
- Post URLs in Idno's usual `/YYYY/post-slug` form

Tested with Known 1.6.4 (build 2026022801), PHP 8.3 and Apache 2.4 (cPanel).

## Installation

The examples assume Idno is installed at `https://example.com/blog/`. If it's at your domain root, see the note in step 2.

### 1. Add md.php

Copy `md.php` into your Idno folder, next to Idno's `index.php`. Edit the three settings at the top:

```php
$siteBase   = 'https://example.com/blog';   // your Idno site's address, no trailing slash
$siteHost   = 'https://example.com';        // scheme + host only
$author     = 'Your Name';                  // used in the front matter
```

`md.php` caches into `gfx/md-cache/` inside your Idno folder and creates that folder itself. The web server needs write access to `gfx/`.

### 2. Add the rewrite rules

Open the `.htaccess` file in your Idno folder. Paste the rules from `htaccess-rules.txt` inside the `<IfModule mod_rewrite.c>` block, right after `RewriteEngine on`. They must come before Idno's own catch-all rule, which sends everything to `index.php`. Then paste the `mod_headers` block after that `</IfModule>`.

If Idno is at your domain root, change `/blog/md.php` to `/md.php` in all of the rules.

The two rules under `Accept` conditions end with `&via=accept`. Keep it: it tells `md.php` the request came in through the `Accept` header, so it should check q-values.

### 3. Install the MarkdownCache plugin

Copy the `IdnoPlugins/MarkdownCache` folder into your Idno `IdnoPlugins` folder, then enable **MarkdownCache** on the Plugins page of your site administration (`/admin/plugins/`).

The plugin listens for Idno's `saved`, `updated` and `deleted` events and deletes the cached Markdown files. Check that your Idno version fires these events:

```
grep -n "triggerEvent('updated'" Idno/Common/Entity.php
```

If that prints a line, the plugin will work.

### 4. Test

Replace the URL with one of your own posts.

```
# Each should print: 200 text/markdown; charset=utf-8
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" https://example.com/blog/2026/my-post.md
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" "https://example.com/blog/2026/my-post?format=markdown"
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" -H "Accept: text/markdown" https://example.com/blog/2026/my-post

# Should print text/html (the client prefers HTML)
curl -s -o /dev/null -w "%{content_type}\n" -H "Accept: text/html, text/markdown;q=0.5" https://example.com/blog/2026/my-post

# Should print 404
curl -s -o /dev/null -w "%{http_code}\n" https://example.com/blog/2026/no-such-post.md

# Home page: the first should show the list of recent posts, the second should print text/markdown
curl -s https://example.com/blog/index.md | head -15
curl -s -o /dev/null -w "%{content_type}\n" -H "Accept: text/markdown" https://example.com/blog/
```

Then edit a post and reload its `.md` URL. The change should appear immediately.

On Windows PowerShell, type `curl.exe` instead of `curl`.

## Settings

All settings are at the top of `md.php`.

| Setting | Default | Meaning |
|---|---|---|
| `$siteBase` | — | Your Idno site's address, no trailing slash |
| `$siteHost` | — | Scheme and host, for making relative URLs absolute |
| `$author` | — | Author name for the front matter |
| `$noteLength` | `160` | Maximum length of the front matter `description` |
| `$addH1` | `true` | Also put the title as `# Title` at the top of the body. Set to `false` if your reader shows the title twice |
| `$indexFeed` | `$siteBase/content/all?_t=rss` | Feed used to build the home page version |
| `$cacheDir` | `gfx/md-cache` | Where cached files go |
| `$cacheTtl` | `3600` | Seconds a cached copy is used. With the plugin enabled, edits clear the cache anyway, so this can be raised (for example `86400`) |
| `$notFoundTtl` | `600` | Seconds a "not found" result is remembered |
| `$maxBuilds` | `2` | Conversions allowed to run at the same time |
| `$perIpLimit` | `20` | Conversions per client IP per minute |

## Limitations

- **Embeds become links.** Iframes, video and audio come out as a link to their source, since Markdown can't embed them.
- **Layout is dropped.** Inline styles, classes, image sizes and floats have no Markdown equivalent.
- **It's a conversion.** Posts are stored as HTML, so the Markdown is generated from that HTML, not from an original Markdown source.
- **The home page lists posts only.** Sidebars, widgets and other theme content aren't in the feed, so they don't appear in the Markdown version.
- **No previous/next links.** The front matter doesn't include `urlPrev` / `urlNext` yet.
- **Special characters are escaped.** `*`, `_`, `[`, `]`, `` ` `` and `<` in normal text appear with a backslash in the raw file. They render correctly.

## Optional: llms.txt

The MarkdownCache plugin also deletes `gfx/llms-cache.txt`. If you generate an `llms.txt` with a script that caches to that file, it is refreshed on every post change too. If you don't, that line does nothing.

## Troubleshooting: slow saves

Not caused by this code, but you may notice it while testing. When you save a public post, Idno sends webmentions to every link in the post, one after another, while you wait. On posts with many links, saving can take minutes, and your other pages wait behind it because the session is locked.

Idno has a built-in switch to turn this off. Add this line at the start of a plugin's `registerEventHooks()` method, for example in MarkdownCache:

```php
if (!defined('IDNO_NOMENTION')) define('IDNO_NOMENTION', true);
```

The trade-off: sites you link to are no longer notified. Receiving webmentions is not affected.
Ideally Known could send webmentions in the background instead of real-time while you wait. 
Note: I have an [async implementation of webmentions for Known here](https://github.com/jevdemon/idno-async-webmentions) if anyone is interested. It works so well that I've re-enabled webmentions on my blog.

## Files

| File | Goes in |
|---|---|
| `md.php` | Your Idno folder, next to `index.php` |
| `htaccess-rules.txt` | Pasted into your Idno `.htaccess` |
| `IdnoPlugins/MarkdownCache/Main.php` | `IdnoPlugins/MarkdownCache/` |
| `IdnoPlugins/MarkdownCache/plugin.ini` | `IdnoPlugins/MarkdownCache/` |

## License

Apache License 2.0, the same license as Idno. See [LICENSE](LICENSE)
