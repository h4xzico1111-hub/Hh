<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '128M');

$url = '';
if (isset($_POST['url'])) {
    $t = trim((string)$_POST['url']);
    if ($t !== '' && filter_var($t, FILTER_VALIDATE_URL)) $url = $t;
}

$error = '';
$videoId = '';
$videos = array();
$steps = array();
$meta = array();
$sourceUsed = '';
$success = false;

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function curlGet($target, $timeout = 25) {
    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 12);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://rumble.com/',
        'Origin: https://rumble.com',
        'Sec-Fetch-Dest: empty',
        'Sec-Fetch-Mode: cors',
        'Sec-Fetch-Site: same-origin',
        'sec-ch-ua: "Chromium";v="120", "Not(A:Brand";v="24"',
        'sec-ch-ua-mobile: ?0',
        'sec-ch-ua-platform: "Windows"',
        'DNT: 1',
    ));
    curl_setopt($ch, CURLOPT_USERAGENT,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $body = curl_exec($ch);
    $info = array(
        'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'errno'  => curl_errno($ch),
        'errmsg' => curl_error($ch),
        'size'   => is_string($body) ? strlen($body) : 0,
    );
    curl_close($ch);
    return array($body, $info);
}

function parseEmbedJson($body, &$videos) {
    $data = json_decode((string)$body, true);
    if (!is_array($data) || empty($data['ua']) || !is_array($data['ua'])) return false;
    $ua = $data['ua'];
    if (isset($ua['hls']) && is_array($ua['hls'])) {
        foreach ($ua['hls'] as $q => $item) {
            if (is_array($item) && !empty($item['url']))
                $videos['HLS / m3u8 (' . $q . ')'] = $item['url'];
        }
    }
    if (isset($ua['mp4']) && is_array($ua['mp4'])) {
        $mp4 = $ua['mp4']; krsort($mp4, SORT_NUMERIC);
        foreach ($mp4 as $q => $item) {
            if (is_array($item) && !empty($item['url'])) {
                $m = isset($item['meta']) ? $item['meta'] : array();
                $w = isset($m['w']) ? $m['w'] : '?';
                $hh = isset($m['h']) ? $m['h'] : '?';
                $sz = isset($m['size']) ? ' · ' . round($m['size']/1024/1024, 1) . ' MB' : '';
                $videos['MP4 ' . $q . 'p · ' . $w . '×' . $hh . $sz] = $item['url'];
            }
        }
    }
    if (isset($ua['webm']) && is_array($ua['webm'])) {
        $wm = $ua['webm']; krsort($wm, SORT_NUMERIC);
        foreach ($wm as $q => $item) {
            if (is_array($item) && !empty($item['url']))
                $videos['WebM ' . $q . 'p'] = $item['url'];
        }
    }
    if (!empty($data['title'])) $GLOBALS['meta']['title'] = $data['title'];
    if (!empty($data['duration'])) $GLOBALS['meta']['duration'] = $data['duration'];
    return count($videos) > 0;
}

function parseHtmlForUrls($body, &$videos) {
    if (!is_string($body) || strlen($body) < 50) return false;
    $found = false;
    if (preg_match_all('#https?://[^\s"\'<>\\\\]+\.(?:mp4|m3u8)[^\s"\'<>\\\\]*#i', $body, $mm)) {
        $uniq = array_unique($mm[0]);
        $i = 0;
        foreach ($uniq as $u) {
            if (stripos($u, '.m3u8') !== false) {
                $videos['HLS / m3u8 (' . (++$i) . ')'] = $u;
                $found = true;
            } elseif (preg_match('#/([a-z]{3})\.mp4#i', $u, $qm)) {
                $label = 'MP4 (' . $qm[1] . ')';
                $videos[$label] = $u;
                $found = true;
            }
        }
    }
    return $found;
}

/* ============ MAIN EXTRACTOR ============ */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {

    /* STEP 1: video ID from oEmbed */
    $oembedUrl = 'https://rumble.com/api/Media/oembed.json?url=' . urlencode($url);
    list($oBody, $oInfo) = curlGet($oembedUrl);
    $steps[] = 'oEmbed · HTTP ' . $oInfo['status'] . ' · ' . $oInfo['size'] . ' bytes';

    if ($oInfo['status'] === 200 && is_string($oBody) && preg_match('#rumble\.com/embed/([a-zA-Z0-9]+)#', $oBody, $m)) {
        $videoId = $m[1];
        $steps[] = '✓ Video ID: ' . $videoId;
        $oData = json_decode($oBody, true);
        if (is_array($oData)) {
            if (!empty($oData['title'])) $meta['title'] = $oData['title'];
        }
    }

    /* STEP 2: try sources in order */
    if ($videoId !== '') {
        $encId = urlencode($videoId);
        $rawApi = 'https://rumble.com/embedJS/u3/?ifr=0&dref=&request=video&ver=2&v=' . $encId . '&ad_wt=0';

        $sources = array(
            'embedJS direct'    => array('type' => 'json', 'url' => $rawApi),
            'embedJS www'       => array('type' => 'json', 'url' => 'https://www.rumble.com/embedJS/u3/?ifr=0&dref=&request=video&ver=2&v=' . $encId . '&ad_wt=0'),
            'embed page'        => array('type' => 'html', 'url' => 'https://rumble.com/embed/' . $encId . '/'),
            'proxy allorigins'  => array('type' => 'json', 'url' => 'https://api.allorigins.win/raw?url=' . urlencode($rawApi)),
            'proxy codetabs'    => array('type' => 'json', 'url' => 'https://api.codetabs.com/v1/proxy?quest=' . urlencode($rawApi)),
            'proxy corsproxy'   => array('type' => 'json', 'url' => 'https://corsproxy.io/?' . urlencode($rawApi)),
            'proxy thingproxy'  => array('type' => 'json', 'url' => 'https://thingproxy.freeboard.io/fetch/' . $rawApi),
            'page full HTML'    => array('type' => 'html', 'url' => $url),
        );

        foreach ($sources as $name => $src) {
            list($body, $info) = curlGet($src['url'], 25);
            $blocked = is_string($body) && stripos($body, 'Just a moment') !== false;
            $steps[] = $name . ' · HTTP ' . $info['status'] . ' · ' . $info['size'] . ' bytes'
                     . ($blocked ? ' · blocked' : '');

            if ($info['errno'] !== 0 || $info['status'] !== 200 || $blocked) continue;

            if ($src['type'] === 'json') {
                if (parseEmbedJson($body, $videos) && count($videos) > 0) {
                    $sourceUsed = $name;
                    $success = true;
                    $steps[] = '✓ Success: ' . $name;
                    break;
                }
            } else {
                if (parseHtmlForUrls($body, $videos) && count($videos) > 0) {
                    $sourceUsed = $name;
                    $success = true;
                    $steps[] = '✓ Success: ' . $name;
                    break;
                }
            }
            unset($body);
        }

        if (!$success) {
            $error = 'वीडियो लिंक नहीं मिले। थोड़ी देर बाद फिर कोशिश करें।';
        }
    } else {
        $error = 'Video ID नहीं मिला।';
    }
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rumble Video Extractor</title>
<style>
:root{
    --bg:#f7ecee;
    --surface:#fff6f7;
    --inset:#f3e1e4;
    --red:#d62839;
    --red-dark:#a81526;
    --text:#4a1119;
    --muted:#9a6a71;
    --shadow-dark:#d8b9be;
    --shadow-light:#ffffff;
}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;padding:24px 16px;font-family:system-ui,sans-serif;
    color:var(--text);background:var(--bg);font-size:14px}
.wrap{max-width:820px;margin:auto}

/* Claymorphism */
.card{background:var(--surface);border-radius:30px;padding:24px;margin-bottom:22px;
    overflow-wrap:anywhere;
    box-shadow:12px 12px 26px var(--shadow-dark),
               -10px -10px 22px var(--shadow-light),
               inset 4px 4px 8px rgba(255,255,255,.9),
               inset -6px -6px 12px rgba(214,40,57,.08)}
h1{font-size:22px;margin:0 0 6px;text-align:center;color:var(--red-dark)}
.sub{text-align:center;color:var(--muted);font-size:13px;margin-bottom:20px}

input[type=url]{width:100%;padding:15px 18px;border-radius:20px;border:0;outline:0;
    background:var(--inset);color:var(--text);font-size:14px;
    box-shadow:inset 6px 6px 12px var(--shadow-dark),
               inset -5px -5px 10px var(--shadow-light)}
input[type=url]::placeholder{color:var(--muted)}
button.submit{margin-top:16px;padding:15px;border:0;border-radius:22px;cursor:pointer;
    font-weight:700;font-size:15px;width:100%;color:#fff;
    background:linear-gradient(145deg,#ec3b4d,var(--red-dark));
    box-shadow:8px 8px 18px var(--shadow-dark),
               -6px -6px 14px var(--shadow-light),
               inset 3px 3px 6px rgba(255,255,255,.35),
               inset -4px -4px 8px rgba(0,0,0,.2)}
button.submit:active{box-shadow:inset 5px 5px 10px rgba(0,0,0,.3),
               inset -3px -3px 8px rgba(255,255,255,.2)}

.err{background:var(--inset);color:var(--red-dark);padding:16px;border-radius:22px;text-align:center;
    font-weight:600;
    box-shadow:inset 5px 5px 10px var(--shadow-dark),inset -4px -4px 8px var(--shadow-light)}
.ok{background:var(--inset);padding:14px 16px;border-radius:22px;margin-bottom:6px;font-size:13px;
    box-shadow:inset 5px 5px 10px var(--shadow-dark),inset -4px -4px 8px var(--shadow-light)}
.pill{display:inline-block;background:var(--red);color:#fff;padding:3px 12px;
    border-radius:20px;font-size:12px;font-weight:600;
    box-shadow:2px 2px 5px var(--shadow-dark),inset 1px 1px 2px rgba(255,255,255,.4)}
h3{font-size:16px;margin:18px 0 6px;color:var(--red-dark)}

.vid{background:var(--surface);border-radius:24px;padding:16px;margin-top:18px;
    box-shadow:8px 8px 18px var(--shadow-dark),
               -6px -6px 16px var(--shadow-light),
               inset 3px 3px 6px rgba(255,255,255,.9),
               inset -4px -4px 8px rgba(214,40,57,.06)}
.vid .lbl{color:var(--red);font-weight:700;font-size:13px;margin-bottom:10px}
.vid .u{font-size:11px;word-break:break-all;color:var(--text);line-height:1.6;margin-bottom:14px;
    font-family:ui-monospace,monospace;background:var(--inset);padding:12px;border-radius:16px;
    box-shadow:inset 4px 4px 8px var(--shadow-dark),inset -3px -3px 6px var(--shadow-light)}
.vid .actions{display:flex;gap:12px;flex-wrap:wrap}
.vid .actions button,.vid .actions a{font-size:13px;padding:10px 18px;border-radius:16px;cursor:pointer;
    text-decoration:none;font-weight:700;border:0;color:#fff;
    background:linear-gradient(145deg,#ec3b4d,var(--red-dark));
    box-shadow:5px 5px 11px var(--shadow-dark),
               -4px -4px 9px var(--shadow-light),
               inset 2px 2px 4px rgba(255,255,255,.35),
               inset -3px -3px 6px rgba(0,0,0,.2)}
.vid .actions .open{background:var(--surface);color:var(--red-dark);
    box-shadow:5px 5px 11px var(--shadow-dark),
               -4px -4px 9px var(--shadow-light),
               inset 2px 2px 4px rgba(255,255,255,.9),
               inset -3px -3px 6px rgba(214,40,57,.1)}

details{margin-top:4px}
summary{cursor:pointer;color:var(--muted);padding:12px 16px;border-radius:18px;
    font-size:13px;font-weight:600;list-style:none;background:var(--surface);
    box-shadow:5px 5px 11px var(--shadow-dark),-4px -4px 10px var(--shadow-light)}
summary::-webkit-details-marker{display:none}
.step{font-family:ui-monospace,monospace;font-size:12px;color:var(--muted);background:var(--inset);
    padding:9px 12px;border-radius:14px;margin-top:8px;word-break:break-all;
    box-shadow:inset 3px 3px 6px var(--shadow-dark),inset -2px -2px 5px var(--shadow-light)}
</style>
</head>
<body>
<div class="wrap">

<div class="card">
    <h1>🎬 Rumble Video Extractor</h1>
    <div class="sub">वीडियो लिंक डालें और स्ट्रीम URLs पाएँ</div>
    <form method="post">
        <input type="url" name="url" placeholder="Rumble video का लिंक यहाँ पेस्ट करें" required>
        <button class="submit" type="submit">🔍 Extract Video URLs</button>
    </form>
</div>

<?php if ($error !== ''): ?>
<div class="card"><div class="err">⚠️ <?php echo h($error); ?></div></div>
<?php endif; ?>

<?php if ($videoId !== ''): ?>
<div class="card">
    <div class="ok">
        ✅ Video ID: <span class="pill"><?php echo h($videoId); ?></span>
        <?php if (!empty($meta['title'])): ?>
        <span style="font-size:13px;margin-top:8px;display:block"><?php echo h($meta['title']); ?></span>
        <?php endif; ?>
    </div>

    <?php if (count($videos) > 0): ?>
    <h3>🎯 Stream URLs</h3>
    <?php foreach ($videos as $label => $vurl): ?>
    <div class="vid">
        <div class="lbl"><?php echo h($label); ?></div>
        <div class="u"><?php echo h($vurl); ?></div>
        <div class="actions">
            <button class="copy" onclick="cp(this, <?php echo h(json_encode($vurl, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP)); ?>)">📋 कॉपी</button>
            <a class="open" href="<?php echo h($vurl); ?>" target="_blank" rel="noopener">▶ ओपन</a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (count($steps) > 0): ?>
<div class="card">
    <details>
        <summary>🛠️ Request Log</summary>
        <?php foreach ($steps as $s): ?>
        <div class="step"><?php echo h($s); ?></div>
        <?php endforeach; ?>
    </details>
</div>
<?php endif; ?>

</div>
<script>
function cp(btn, t) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(t).then(function(){
            btn.textContent = '✓ हो गया';
            setTimeout(function(){ btn.textContent = '📋 कॉपी'; }, 1500);
        }).catch(function(){ fb(btn, t); });
    } else { fb(btn, t); }
}
function fb(btn, t) {
    var i = document.createElement('textarea');
    i.value = t; i.style.position = 'fixed'; i.style.opacity = '0';
    document.body.appendChild(i); i.select();
    try { document.execCommand('copy'); btn.textContent = '✓ हो गया';
        setTimeout(function(){ btn.textContent = '📋 कॉपी'; }, 1500);
    } catch (e) { alert('कॉपी नहीं हुआ'); }
    i.remove();
}
</script>
</body>
</html>
