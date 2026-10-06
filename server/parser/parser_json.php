<?php
/**
 * Habr Parser v3.2 — JSON API based (reviewed by Codex+Cursor+Gemini)
 */

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/parser.log");

include(__DIR__ . "/config.inc.php");

$connect = mysqli_connect($old_base["host"], $old_base["user"], $old_base["pass"], $old_base["name"]);
if (!$connect) { die("DB connection failed: " . mysqli_connect_error() . "\n"); }
mysqli_set_charset($connect, "utf8mb4");

function postExists($connect, $id) {
    $stmt = mysqli_prepare($connect, "SELECT ID FROM wp_posts WHERE origID = ? AND origFrom = 1 LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function fetchArticle($id) {
    $id = (int)$id;
    $url = "https://habr.com/kek/v2/articles/{$id}/";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => "Mozilla/5.0 (compatible; SavePearlHarbor/3.1)",
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Curl error for article $id: $curlError");
        return null;
    }
    if ($httpCode === 429) { error_log("Rate limited (429) at article $id"); return "RATE_LIMITED"; }
    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error for article $id: " . json_last_error_msg());
        return null;
    }
    if (!isset($data["titleHtml"]) || !isset($data["textHtml"])) return null;

    return $data;
}

function generateSlug($title) {
    // Transliterate Russian to Latin
    $tr = ["а"=>"a","б"=>"b","в"=>"v","г"=>"g","д"=>"d","е"=>"e","ё"=>"yo","ж"=>"zh","з"=>"z","и"=>"i","й"=>"y","к"=>"k","л"=>"l","м"=>"m","н"=>"n","о"=>"o","п"=>"p","р"=>"r","с"=>"s","т"=>"t","у"=>"u","ф"=>"f","х"=>"h","ц"=>"ts","ч"=>"ch","ш"=>"sh","щ"=>"sch","ъ"=>"","ы"=>"y","ь"=>"","э"=>"e","ю"=>"yu","я"=>"ya"];
    $text = mb_strtolower(strip_tags($title));
    $text = strtr($text, $tr);
    $text = preg_replace("/[^a-z0-9]+/", "-", $text);
    $text = trim($text, "-");
    return mb_substr($text, 0, 200);
}

function truncateHtml($html, $maxLength = 10000) {
    // Always strip tags for excerpt consistency
    $text = strip_tags(preg_replace("/<img[^>]+>/i", "", $html));
    if (mb_strlen($text) > $maxLength) {
        $text = mb_substr($text, 0, $maxLength);
        $text = preg_replace("/\s+\S+$/", "", $text) . "...";
    }
    return $text;
}

function processArticle($connect, $id) {
    if (postExists($connect, $id)) return "exists";

    $article = fetchArticle($id);
    if ($article === "RATE_LIMITED") return "rate_limited";
    if (!$article) return "not_found";

    if (($article["status"] ?? "") !== "published") return "not_published";

    $title = $article["titleHtml"] ?? "";
    $content = $article["textHtml"] ?? "";
    $content = preg_replace("/[\x00-\x1F\x7F]/u", "", $content);

    $origUrl = "https://habr.com/ru/articles/{$id}/";
    $content .= "<br><br>ссылка на оригинал статьи <a href=\"{$origUrl}\">{$origUrl}</a>";

    $post_excerpt = truncateHtml($content);

    // Parse date with UTC for post_date_gmt
    $ts = strtotime($article["timePublished"] ?? "");
    if (!$ts) $ts = time();
    $post_date = date("Y-m-d H:i:s", $ts);
    $post_date_gmt = gmdate("Y-m-d H:i:s", $ts);
    $post_name = generateSlug($title);

    $stmt = mysqli_prepare($connect,
        "INSERT IGNORE INTO wp_posts (post_date, post_date_gmt, post_content, post_excerpt, post_title, post_author, origID, origFrom, post_status, post_type, comment_status, ping_status, to_ping, pinged, post_content_filtered)
         VALUES (?, ?, ?, ?, ?, 1, ?, 1, 'publish', 'post', 'closed', 'closed', '', '', '')");
    mysqli_stmt_bind_param($stmt, "sssssi", $post_date, $post_date_gmt, $content, $post_excerpt, $title, $id);
    $ok = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    $post_id = mysqli_insert_id($connect);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        error_log("Insert error for $id: " . mysqli_error($connect));
        return "error";
    }
    if ($affected === 0) return "exists"; // INSERT IGNORE hit duplicate

    mysqli_query($connect, "UPDATE wp_posts SET guid = CONCAT('https://savepearlharbor.com/?p=', ID) WHERE ID = " . (int)$post_id);

    echo "Added: $id - $title\n";
    return "added";
}

function getLatestHabrId($lastKnownID) {
    // Use main page to find latest IDs
    $ch = curl_init("https://habr.com/ru/articles/");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => "Mozilla/5.0 (compatible; SavePearlHarbor/3.1)",
    ]);
    $mainPage = curl_exec($ch);
    curl_close($ch);

    $latestID = $lastKnownID;
    if ($mainPage && preg_match_all("/\/articles\/(\d+)\//", $mainPage, $matches)) {
        $latestID = max($latestID, max(array_map("intval", $matches[1])));
    }

    // Probe if main page failed
    if ($latestID <= $lastKnownID) {
        for ($probe = $lastKnownID + 1000; $probe > $lastKnownID; $probe -= 100) {
            usleep(200000);
            $article = fetchArticle($probe);
            if ($article) {
                $latestID = max($latestID, $probe);
                break;
            }
        }
    }
    return $latestID;
}

// Main
echo "Habr Parser v3.2 (JSON API)\n";
echo "===========================\n";
echo date("Y-m-d H:i:s") . "\n\n";

$query = mysqli_query($connect, "SELECT MAX(origID) as maxID FROM wp_posts WHERE origFrom = 1");
$row = mysqli_fetch_assoc($query);
$lastKnownID = (int)$row["maxID"];
echo "Last known article ID: $lastKnownID\n";

$latestID = getLatestHabrId($lastKnownID);
echo "Latest article ID: $latestID\n";
echo "Gap: " . ($latestID - $lastKnownID) . " articles\n\n";

if ($latestID <= $lastKnownID) {
    echo "No new articles.\n";
    mysqli_close($connect);
    exit(0);
}

// Process NEWEST first: walk from $latestID downward, skip already-known via postExists()
$stats = ["added" => 0, "exists" => 0, "not_found" => 0, "error" => 0, "not_published" => 0, "rate_limited" => 0];
$batchSize = 50;
$maxBatches = 3;  // up to 150 latest IDs per run
$lowerBound = max(1, $latestID - ($maxBatches * $batchSize) + 1);

echo "Processing newest first: $latestID down to $lowerBound\n\n";

for ($batch = 0; $batch < $maxBatches; $batch++) {
    $batchStart = $latestID - ($batch * $batchSize);             // newest end of batch
    $batchEnd = max($batchStart - $batchSize + 1, $lowerBound);   // oldest end of batch

    echo "Batch " . ($batch + 1) . ": $batchStart - $batchEnd\n";

    for ($id = $batchStart; $id >= $batchEnd; $id--) {
        $result = processArticle($connect, $id);
        $stats[$result]++;
        if ($result === "rate_limited") { echo "Rate limited! Stopping.\n"; break 2; }

        // Rate limit ALL requests to Habr, not just added
        if ($result === "added") {
            usleep(500000); // 0.5s after insert
        } else {
            usleep(100000); // 0.1s between checks
        }
    }

    if ($batchEnd <= $lowerBound) break;
}

echo "\nDone! " . date("Y-m-d H:i:s") . "\n";
echo "Added: {$stats['added']}, Exists: {$stats['exists']}, Not found: {$stats['not_found']}, Errors: {$stats['error']}\n";

mysqli_close($connect);
