<?php
/**
 * Export WordPress posts to Hugo markdown files
 * Run: docker exec savepearlharborcom-php-1 php /var/www/html/parser/export_to_hugo.php
 */

error_reporting(E_ALL);
ini_set("display_errors", "0");
ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/export.log");

include(__DIR__ . "/config.inc.php");

$connect = mysqli_connect($old_base["host"], $old_base["user"], $old_base["pass"], $old_base["name"]);
if (!$connect) { die("DB connection failed\n"); }
mysqli_set_charset($connect, "utf8mb4");

$outputDir = "/tmp/hugo-export";
if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
if (!is_dir("$outputDir/content/posts")) mkdir("$outputDir/content/posts", 0755, true);

$tr = ["а"=>"a","б"=>"b","в"=>"v","г"=>"g","д"=>"d","е"=>"e","ё"=>"yo","ж"=>"zh","з"=>"z","и"=>"i","й"=>"y","к"=>"k","л"=>"l","м"=>"m","н"=>"n","о"=>"o","п"=>"p","р"=>"r","с"=>"s","т"=>"t","у"=>"u","ф"=>"f","х"=>"h","ц"=>"ts","ч"=>"ch","ш"=>"sh","щ"=>"sch","ъ"=>"","ы"=>"y","ь"=>"","э"=>"e","ю"=>"yu","я"=>"ya"];

$res = mysqli_query($connect, "SELECT COUNT(*) as cnt FROM wp_posts WHERE post_status = 'publish' AND post_type = 'post'");
$total = (int)mysqli_fetch_assoc($res)["cnt"];
echo "Total posts: $total\n";

$batchSize = 1000;
$exported = 0;
$offset = 0;

while ($offset < $total) {
    $sql = "SELECT ID, post_title, post_content, post_date, post_date_gmt, origID
            FROM wp_posts
            WHERE post_status = 'publish' AND post_type = 'post'
            ORDER BY ID ASC
            LIMIT $batchSize OFFSET $offset";

    $result = mysqli_query($connect, $sql);
    if (!$result) {
        echo "Query error at offset $offset: " . mysqli_error($connect) . "\n";
        break;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $id = $row["ID"];
        $origID = $row["origID"] ? $row["origID"] : $id;
        $title = $row["post_title"];
        $date = $row["post_date_gmt"] ? $row["post_date_gmt"] : $row["post_date"];
        $content = $row["post_content"];

        $slug = mb_strtolower(strip_tags($title));
        $slug = strtr($slug, $tr);
        $slug = preg_replace("/[^a-z0-9]+/", "-", $slug);
        $slug = trim($slug, "-");
        $slug = mb_substr($slug, 0, 150);
        if (!$slug) $slug = "post-$origID";

        $year = substr($date, 0, 4);
        $dir = "$outputDir/content/posts/$year";
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $yamlTitle = str_replace('"', '\\"', strip_tags($title));
        $yamlTitle = str_replace("\n", " ", $yamlTitle);

        $md = "---\n";
        $md .= "title: \"$yamlTitle\"\n";
        $md .= "date: $date\n";
        $md .= "draft: false\n";
        $md .= "orig_id: $origID\n";
        $md .= "slug: \"$slug\"\n";
        $md .= "---\n\n";
        $md .= $content . "\n";

        $filename = "$dir/$origID.md";
        file_put_contents($filename, $md);
        $exported++;
    }

    $offset += $batchSize;
    echo "Exported: $exported / $total (" . round($exported / max($total, 1) * 100, 1) . "%)\n";
}

echo "\nDone! Exported $exported posts to $outputDir/content/posts/\n";
system("du -sh $outputDir");
system("find $outputDir/content/posts -type d | wc -l");
system("find $outputDir/content/posts -name '*.md' | wc -l");

mysqli_close($connect);
