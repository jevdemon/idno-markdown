<?php
// md.php: serves Idno posts, and the home page, as Markdown (for Markdown browsers such as BonBon, AI agents, etc.).
// Reached through .htaccess rewrites: /YYYY/slug.md, /index.md, ?format=markdown, or Accept: text/markdown.
// It reads the site's own RSS output (post URL + ?_t=rss, or the main feed), so it needs no changes to Idno itself.
// See README.md for installation. Edit the three settings below for your site.

$siteBase   = 'https://example.com/blog';   // your Idno site's address, no trailing slash
$siteHost   = 'https://example.com';        // scheme + host only; used to make /relative URLs absolute
$author     = 'Your Name';                  // used in the front matter
$noteLength = 160;                          // front-matter description length
$addH1      = true;                         // also put the title as "# Title" at the top of the body
$cacheDir   = __DIR__ . '/gfx/md-cache';
$cacheTtl   = 3600;
$indexFeed  = $siteBase . '/content/all?_t=rss';   // feed used for the home page (index) version
$notFoundTtl = 600;                         // remember "post not found" for 10 minutes
$maxBuilds   = 2;                           // at most this many posts converted at the same time
$perIpLimit  = 20;                          // conversions allowed per visitor IP per minute (cached hits don't count)

// ---------- HTML -> Markdown ----------

function md_esc($t) {
    return preg_replace('/([\\\\`*_\[\]<])/', '\\\\$1', $t);
}

function md_url($u) {
    global $siteHost;
    $u = trim($u);
    if ($u !== '' && $u[0] === '/' && substr($u, 0, 2) !== '//') $u = $siteHost . $u;
    return str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $u);
}

function md_wrap($inner, $mark) {
    if (trim($inner) === '') return $inner;
    preg_match('/^(\s*)(.*?)(\s*)$/s', $inner, $m);
    return $m[1] . $mark . $m[2] . $mark . $m[3];
}

function md_block($s) { return "\n\n" . trim($s) . "\n\n"; }

function md_oneline($s) { return trim(preg_replace('/\s+/u', ' ', str_replace("\x01", ' ', $s))); }

function md_tidy($s) {
    $s = preg_replace("/[ \t]+\n/", "\n", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    return trim($s, "\n");
}

function md_children(DOMNode $n, $pre = false) {
    $out = '';
    foreach ($n->childNodes as $c) $out .= md_node($c, $pre);
    return $out;
}

function md_list(DOMElement $list, $ordered) {
    $out = '';
    $i = 1;
    foreach ($list->childNodes as $li) {
        if (!($li instanceof DOMElement) || strtolower($li->nodeName) !== 'li') continue;
        $prefix = $ordered ? ($i++ . '. ') : '- ';
        $body = md_tidy(md_children($li));
        $pad = str_repeat(' ', strlen($prefix));
        $lines = explode("\n", $body);
        foreach ($lines as $k => $line) {
            $lines[$k] = ($k === 0 ? $prefix : ($line === '' ? '' : $pad)) . $line;
        }
        $out .= implode("\n", $lines) . "\n";
    }
    return md_block($out);
}

function md_table(DOMElement $t) {
    $rows = [];
    foreach ($t->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->childNodes as $c) {
            if ($c instanceof DOMElement && in_array(strtolower($c->nodeName), ['td', 'th'])) {
                $cells[] = str_replace('|', '\\|', md_oneline(md_children($c)));
            }
        }
        if ($cells) $rows[] = $cells;
    }
    if (!$rows) return '';
    $w = max(array_map('count', $rows));
    $out = '';
    foreach ($rows as $k => $r) {
        $r = array_pad($r, $w, '');
        $out .= '| ' . implode(' | ', $r) . " |\n";
        if ($k === 0) $out .= '|' . str_repeat(' --- |', $w) . "\n";
    }
    return md_block($out);
}

function md_node(DOMNode $n, $pre = false) {
    if ($n instanceof DOMText) {
        if ($pre) return $n->nodeValue;
        return md_esc(preg_replace('/\s+/u', ' ', $n->nodeValue));
    }
    if (!($n instanceof DOMElement)) return '';
    $tag = strtolower($n->nodeName);
    switch ($tag) {
        case 'script': case 'style': case 'noscript': case 'template':
            return '';
        case 'p': case 'div': case 'section': case 'article': case 'header':
        case 'footer': case 'figure': case 'main': case 'aside':
            return md_block(md_children($n));
        case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
            $text = md_oneline(md_children($n));
            return $text === '' ? '' : md_block(str_repeat('#', (int)$tag[1]) . ' ' . $text);
        case 'br':
            return "\x01";
        case 'hr':
            return md_block('---');
        case 'strong': case 'b':
            return md_wrap(md_children($n), '**');
        case 'em': case 'i': case 'cite':
            return md_wrap(md_children($n), '*');
        case 'del': case 's': case 'strike':
            return md_wrap(md_children($n), '~~');
        case 'code':
            $c = $n->textContent;
            $tick = strpos($c, '`') !== false ? '``' : '`';
            return $c === '' ? '' : $tick . $c . $tick;
        case 'pre':
            return md_block("```\n" . rtrim($n->textContent) . "\n```");
        case 'a':
            $inner = md_oneline(md_children($n));
            $href = $n->getAttribute('href');
            if ($href === '' || stripos($href, 'javascript:') === 0) return $inner;
            if ($inner === '') $inner = md_esc($href);
            return '[' . $inner . '](' . md_url($href) . ')';
        case 'img':
            $src = $n->getAttribute('src');
            if ($src === '') return '';
            $alt = str_replace(['[', ']'], ['(', ')'], md_oneline($n->getAttribute('alt')));
            return '![' . $alt . '](' . md_url($src) . ')';
        case 'iframe': case 'video': case 'audio': case 'embed':
            $src = $n->getAttribute('src');
            if ($src === '') {
                foreach ($n->getElementsByTagName('source') as $s) { $src = $s->getAttribute('src'); break; }
            }
            return $src === '' ? '' : md_block('[Embedded ' . $tag . '](' . md_url($src) . ')');
        case 'ul':
            return md_list($n, false);
        case 'ol':
            return md_list($n, true);
        case 'blockquote':
            $inner = md_tidy(md_children($n));
            $lines = array_map(function ($l) { return $l === '' ? '>' : '> ' . $l; }, explode("\n", $inner));
            return md_block(implode("\n", $lines));
        case 'figcaption':
            $c = md_oneline(md_children($n));
            return $c === '' ? '' : md_block('*' . $c . '*');
        case 'table':
            return md_table($n);
        default:
            return md_children($n, $pre);
    }
}

function html_to_markdown($html) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="md-root">' . $html . '</div></body></html>',
        LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    $root = $doc->getElementById('md-root');
    if (!$root) return '';
    $md = md_tidy(md_children($root));
    return str_replace("\x01", "  \n", $md);
}

// q-value of a media type in an Accept header, using the most specific matching range (RFC 9110).
function md_q($accept, $type) {
    $major = explode('/', $type)[0];
    $best = 0.0;
    $spec = -1;
    foreach (explode(',', $accept) as $part) {
        $bits = array_map('trim', explode(';', $part));
        $range = strtolower(array_shift($bits));
        $q = 1.0;
        foreach ($bits as $b) {
            if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $b, $m)) $q = (float)$m[1];
        }
        $s = $range === $type ? 2 : ($range === $major . '/*' ? 1 : ($range === '*/*' ? 0 : -1));
        if ($s > $spec) { $spec = $s; $best = $q; }
        elseif ($s === $spec && $s >= 0 && $q > $best) { $best = $q; }
    }
    return $spec < 0 ? 0.0 : $best;
}

function md_yaml($s) { return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"'; }

if (defined('MD_LIB_ONLY')) return;   // lets a test script use the converter without serving a page

// ---------- request handling ----------

function md_fail($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg . "\n";
    exit;
}

$path = isset($_GET['path']) ? $_GET['path'] : '';
$path = preg_replace('/\.md$/', '', $path);
$isIndex = ($path === '/' || $path === '');   // the home page: a list of recent posts
if ($isIndex) $path = '/';
elseif (!preg_match('#^/[A-Za-z0-9/_\-.%]+$#', $path) || strpos($path, '..') !== false) md_fail(400, 'Bad path.');

$pageUrl   = $siteBase . $path;
$cacheFile = $cacheDir . '/' . md5($path) . '.md';

// Reached through the Accept-header rule (via=accept): only serve Markdown if the client prefers it to HTML.
// The .md and ?format=markdown routes always serve Markdown.
$accept    = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
$serveHtml = isset($_GET['via']) && $_GET['via'] === 'accept'
             && md_q($accept, 'text/html') > md_q($accept, 'text/markdown');

function md_send($body, $pageUrl) {
    header('Content-Type: text/markdown; charset=utf-8');
    header('Vary: Accept');
    header('Link: <' . $pageUrl . '>; rel="canonical"');
    echo $body;
    exit;
}

if (!$serveHtml && is_file($cacheFile) && time() - filemtime($cacheFile) < $cacheTtl) md_send(file_get_contents($cacheFile), $pageUrl);

// A post we recently found doesn't exist: answer 404 without asking Idno again.
$missFile = $cacheDir . '/' . md5($path) . '.404';
if (is_file($missFile) && time() - filemtime($missFile) < $notFoundTtl) md_fail(404, 'Not found.');

if (!is_dir($cacheDir . '/rate')) @mkdir($cacheDir . '/rate', 0755, true);

// Per-visitor limit on conversions (requests answered from the cache never get here).
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$rateFile = $cacheDir . '/rate/' . md5($ip) . '.' . floor(time() / 60);
$count = 0;
if ($fh = @fopen($rateFile, 'c+')) {
    flock($fh, LOCK_EX);
    $count = (int)stream_get_contents($fh) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, (string)$count);
    flock($fh, LOCK_UN);
    fclose($fh);
}
if (mt_rand(1, 100) === 1) {   // now and then, delete old counters
    foreach (glob($cacheDir . '/rate/*') ?: [] as $f) {
        if (time() - @filemtime($f) > 120) @unlink($f);
    }
}
if ($count > $perIpLimit) {
    if (!$serveHtml && is_file($cacheFile)) md_send(file_get_contents($cacheFile), $pageUrl);
    header('Retry-After: 60');
    md_fail(429, 'Too many requests. Try again in a minute.');
}

// Limit how many conversions run at once, so md.php can never tie up all of the site's PHP workers.
$slot = null;
for ($i = 0; $i < $maxBuilds; $i++) {
    $fh = @fopen($cacheDir . '/build-' . $i . '.lock', 'c');
    if ($fh && flock($fh, LOCK_EX | LOCK_NB)) { $slot = $fh; break; }
    if ($fh) fclose($fh);
}
if (!$slot) {
    if (!$serveHtml && is_file($cacheFile)) md_send(file_get_contents($cacheFile), $pageUrl);
    header('Retry-After: 10');
    md_fail(503, 'Busy. Try again shortly.');
}
// The slot is released automatically when the script ends.

// The client prefers HTML: pass Idno's normal page through.
if ($serveHtml) {
    $ch = curl_init($pageUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'md.php passthrough', CURLOPT_HTTPHEADER => ['Accept: text/html']]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($html === false || !$code) md_fail(503, 'Temporarily unavailable.');
    http_response_code($code);
    header('Content-Type: ' . ($type ?: 'text/html; charset=utf-8'));
    header('Vary: Accept');
    echo $html;
    exit;
}

$ch = curl_init($isIndex ? $indexFeed : $pageUrl . '?_t=rss');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'md.php builder']);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$item = null;
$xml = null;
if ($raw !== false && $code === 200) {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw);
    if ($xml && isset($xml->channel->item[0])) $item = $xml->channel->item[0];
}

// Home page: the site's title and description, then each recent post with a link to its Markdown version
// and the day's entry headings (<h3>s) under it.
if ($isIndex && $item) {
    $siteTitle = trim(preg_replace('/\s+/u', ' ', (string)$xml->channel->title));
    $siteDesc  = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$xml->channel->description), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $out  = "---\n";
    $out .= 'title: ' . md_yaml($siteTitle) . "\n";
    $out .= 'author: ' . md_yaml($author) . "\n";
    if ($siteDesc !== '') $out .= 'description: ' . md_yaml($siteDesc) . "\n";
    $out .= 'link: ' . md_yaml($siteBase . '/') . "\n";
    $out .= "---\n\n";
    if ($siteTitle !== '') $out .= '# ' . md_esc($siteTitle) . "\n\n";
    if ($siteDesc !== '') $out .= '> ' . md_esc($siteDesc) . "\n\n";
    $out .= "## Recent posts\n\n";
    foreach ($xml->channel->item as $it) {
        $t = trim(preg_replace('/\s+/u', ' ', (string)$it->title));
        $l = trim((string)$it->link);
        if ($l === '') continue;
        if ($t === '') $t = $l;
        if (preg_match('#/[0-9]{4}/[^/?\#]+$#', $l)) $l .= '.md';   // posts get their Markdown version
        $d = strtotime((string)$it->pubDate);
        $out .= '- [' . md_esc($t) . '](' . md_url($l) . ')' . ($d ? ' (' . date('Y-m-d', $d) . ')' : '') . "\n";
        if (preg_match_all('#<h3\b[^>]*>(.*?)</h3>#is', (string)$it->description, $hm)) {
            foreach ($hm[1] as $h) {
                $h = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($h !== '') $out .= '  - ' . md_esc($h) . "\n";
            }
        }
    }
    @file_put_contents($cacheFile, $out, LOCK_EX);
    md_send($out, $pageUrl);
}

if (!$item) {
    if ($code === 404 && !$isIndex) {
        @touch($missFile);
        md_fail(404, 'Not found.');
    }
    if (is_file($cacheFile)) md_send(file_get_contents($cacheFile), $pageUrl);
    md_fail(503, 'Temporarily unavailable.');
}

$title = trim(preg_replace('/\s+/u', ' ', (string)$item->title));
$link  = trim((string)$item->link) ?: $pageUrl;
$date  = trim((string)$item->pubDate);
$body  = html_to_markdown((string)$item->description);

$plain = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', (string)$item->description);
$plain = preg_replace('#<\s*/?\s*(p|div|h[1-6]|br|li|ul|ol|blockquote|figure|figcaption|tr|td)\b[^>]*>#i', ' ', $plain);
$plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
$note = preg_match('/^(.+?[.!?])(\s|$)/u', $plain, $m) ? $m[1] : $plain;
if (mb_strlen($note) > $noteLength) $note = rtrim(mb_substr($note, 0, $noteLength - 1)) . '…';

$out  = "---\n";
$out .= 'title: ' . md_yaml($title) . "\n";
$out .= 'author: ' . md_yaml($author) . "\n";
if ($date !== '') $out .= 'pubDate: ' . md_yaml($date) . "\n";
if ($note !== '') $out .= 'description: ' . md_yaml($note) . "\n";
$out .= 'link: ' . md_yaml($link) . "\n";
$out .= "---\n\n";
if ($addH1 && $title !== '') $out .= '# ' . md_esc($title) . "\n\n";
$out .= $body . "\n";

@file_put_contents($cacheFile, $out, LOCK_EX);
md_send($out, $pageUrl);
