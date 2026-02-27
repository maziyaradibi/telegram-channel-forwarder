<?php

// ==========================================
// IMPORTANT:
// قبل از اجرا، مقادیر زیر را تنظیم کنید.
// ==========================================

define('BOT_TOKEN', 'YOUR_TELEGRAM_BOT_TOKEN'); // <-- این مقدار را تغییر دهید
define('SOURCE_CHANNEL', 'SOURCE_CHANNEL_USERNAME'); // بدون @
define('DEST_CHANNEL', '@DESTINATION_CHANNEL'); // با @ یا chat_id
define('STATE_FILE', __DIR__ . '/state.json');
define('LOCK_FILE', __DIR__ . '/bot.lock');

// --- لیست سیاه کلمات کلیدی (قابل شخصی‌سازی) ---
$adKeywords = [
    'vpn',
    'خرید سرویس',
    'سیگنال',
    'درآمد دلاری',
    'ویزای تضمینی'
];

ini_set('display_errors', 0);
error_reporting(E_ALL);

// ==========================================
// Lock Mechanism
// ==========================================
$lockHandle = fopen(LOCK_FILE, 'c');
if (!$lockHandle) {
    die("Error: Could not create lock file.\n");
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Script is already running.\n";
    exit;
}

// ==========================================

function isAdvertisement($text, $keywords) {
    if (empty($text)) return false;

    $normalizedText = str_replace([' ', "\n", "\r", '‌'], '', $text);

    foreach ($keywords as $word) {
        $normalizedWord = str_replace([' ', '‌'], '', $word);
        if (mb_stripos($normalizedText, $normalizedWord) !== false) {
            return true;
        }
    }
    return false;
}

function telegram($method, $data) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $res = curl_exec($ch);
    curl_close($ch);

    return json_decode($res, true);
}

function downloadFile($url) {
    if (empty($url)) return null;

    $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    $tempName = tempnam(sys_get_temp_dir(), 'tg_') . '.' . $ext;

    $ch = curl_init($url);
    $fp = fopen($tempName, 'wb');

    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);

    curl_close($ch);
    fclose($fp);

    return $tempName;
}

function cleanText($text) {
    if (!$text) return "";
    return trim($text);
}

function getPosts($channel) {

    $url = "https://t.me/s/" . $channel;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);

    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return [];

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);

    $nodes = $xpath->query('//div[contains(@class, "tgme_widget_message ")]');

    $posts = [];

    foreach ($nodes as $node) {

        $postIdAttr = $node->getAttribute('data-post');
        if (!$postIdAttr) continue;

        $id = (int) explode('/', $postIdAttr)[1];

        $textNode = $xpath->query('.//div[contains(@class, "js-message_text")]', $node)->item(0);
        $rawContent = ($textNode) ? $textNode->nodeValue : "";

        $mainText = "";
        if ($textNode) {
            $mainText = strip_tags($textNode->nodeValue);
            $mainText = cleanText($mainText);
        }

        $mediaUrls = [];

        $photoNode = $xpath->query('.//a[contains(@class, "tgme_widget_message_photo_wrap")]', $node)->item(0);
        if ($photoNode) {
            $style = $photoNode->getAttribute('style');
            if (preg_match("/url\(['\"]?(.*?)['\"]?\)/", $style, $m)) {
                $mediaUrls[] = ['type' => 'photo', 'url' => $m[1]];
            }
        }

        $videoNode = $xpath->query('.//video', $node)->item(0);
        if ($videoNode) {
            $mediaUrls[] = ['type' => 'video', 'url' => $videoNode->getAttribute('src')];
        }

        $posts[] = [
            'id' => $id,
            'raw_text' => $rawContent,
            'caption' => $mainText,
            'media' => $mediaUrls
        ];
    }

    return $posts;
}

// ==========================================
// MAIN
// ==========================================

$state = file_exists(STATE_FILE)
    ? json_decode(file_get_contents(STATE_FILE), true)
    : [];

$lastId = $state[SOURCE_CHANNEL] ?? 0;

$allPosts = getPosts(SOURCE_CHANNEL);

$newPosts = array_filter($allPosts, function ($p) use ($lastId) {
    return $p['id'] > $lastId;
});

usort($newPosts, fn($a, $b) => $a['id'] <=> $b['id']);

foreach ($newPosts as $post) {

    if (isAdvertisement($post['raw_text'], $adKeywords)) {
        continue;
    }

    $success = false;

    if (!empty($post['media'])) {

        $m = $post['media'][0];
        $filePath = downloadFile($m['url']);

        if ($filePath) {

            $method = ($m['type'] === 'video') ? 'sendVideo' : 'sendPhoto';

            $res = telegram($method, [
                'chat_id' => DEST_CHANNEL,
                $m['type'] => new CURLFile($filePath),
                'caption' => $post['caption'],
                'parse_mode' => 'HTML'
            ]);

            unlink($filePath);

            if ($res && $res['ok']) $success = true;
        }

    } elseif (!empty($post['caption'])) {

        $res = telegram('sendMessage', [
            'chat_id' => DEST_CHANNEL,
            'text' => $post['caption'],
            'parse_mode' => 'HTML'
        ]);

        if ($res && $res['ok']) $success = true;
    }

    if ($success) {
        $lastId = $post['id'];
        $state[SOURCE_CHANNEL] = $lastId;
        file_put_contents(STATE_FILE, json_encode($state));
    }

    sleep(2);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
