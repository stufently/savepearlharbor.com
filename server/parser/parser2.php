<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8"/>
    <?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    /*
     * $MODE
     * SET 1 to store to DB
     * SET 2 to var_dump content title
     */

    $postsDir = './posts';
    
    if (!is_dir($postsDir)) {
        mkdir($postsDir, 0755, true);
    }

    function clean_old_files($postsDir){
        // Get current time
        $currentTime = time();
        
        // Define the time period (7 days in seconds)
        $timePeriod = 1 * 24 * 60 * 60;
        
        // Use glob() to get all files in the folder
        $files = glob($postsDir . '/*');
        
        foreach ($files as $file) {
            // Check if the file is a regular file (not a directory)
            if (is_file($file)) {
                // Get the file's modification time
                $fileModificationTime = filemtime($file);
                
                // Calculate the file's age
                $fileAge = $currentTime - $fileModificationTime;
                
                // Check if the file is older than 7 days
                if ($fileAge > $timePeriod) {
                    // Delete the file
                    if (unlink($file)) {
                        echo "Deleted: $file\n";
                    } else {
                        echo "Error deleting: $file\n";
                    }
                }
            }
        }

    }
    
    clean_old_files($postsDir);
    
    $MODE = 1;

    function limit_text($text, $limit)
    {
        if (str_word_count($text, 0) > $limit) {
            $words = str_word_count($text, 2);
            $pos = array_keys($words);
            $text = substr($text, 0, $pos[$limit]) . '...';
        }
        return $text;
    }

    function truncate($html, $maxLength = 100)
    {
        mb_internal_encoding("UTF-8");
        $printedLength = 0;
        $position = 0;
        $tags = array();
        $newContent = '';

        $html = $content = preg_replace("/<img[^>]+\>/i", "", $html);

        while ($printedLength < $maxLength && preg_match('{</?([a-z]+)[^>]*>|&#?[a-zA-Z0-9]+;}', $html, $match, PREG_OFFSET_CAPTURE, $position)) {
            list($tag, $tagPosition) = $match[0];
            // Print text leading up to the tag.
            $str = mb_strcut($html, $position, $tagPosition - $position);
            if ($printedLength + mb_strlen($str) > $maxLength) {
                $newstr = mb_strcut($str, 0, $maxLength - $printedLength);
                $newstr = preg_replace('~\s+\S+$~', '', $newstr);
                $newContent .= $newstr;
                $printedLength = $maxLength;
                break;
            }
            $newContent .= $str;
            $printedLength += mb_strlen($str);
            if ($tag[0] == '&') {
                // Handle the entity.
                $newContent .= $tag;
                $printedLength++;
            } else {
                // Handle the tag.
                $tagName = $match[1][0];
                if ($tag[1] == '/') {
                    // This is a closing tag.
                    $openingTag = array_pop($tags);
                    assert($openingTag == $tagName); // check that tags are properly nested.
                    $newContent .= $tag;
                } else if ($tag[mb_strlen($tag) - 2] == '/') {
                    // Self-closing tag.
                    $newContent .= $tag;
                } else {
                    // Opening tag.
                    $newContent .= $tag;
                    $tags[] = $tagName;
                }
            }

            // Continue after the tag.
            $position = $tagPosition + mb_strlen($tag);
        }

        // Print any remaining text.
        if ($printedLength < $maxLength && $position < mb_strlen($html)) {
            $newstr = mb_strcut($html, $position, $maxLength - $printedLength);
            $newstr = preg_replace('~\s+\S+$~', '', $newstr);
            $newContent .= $newstr;
        }

        // Close any open tags.
        while (!empty($tags)) {
            $newContent .= sprintf('</%s>', array_pop($tags));
        }

        return $newContent;
    }


    function postExists($id, $siteID = 1)
    {
        global $connect;
        $query = mysqli_query($connect, 'SELECT ID FROM `wp_posts` WHERE origID = ' . $id . ' AND origFrom =' . $siteID);
        $rows_count = mysqli_num_rows($query);
        if ($rows_count > 0)
            return true;
        return false;
    }

    function processPost($id, $url, $siteID = 1)
    {
        global $MODE;
        global $connect;
        global $postsDir;


        print ("Fetch $url\n");
        $filename = "${postsDir}/${id}";

        if (file_exists($filename)) {
            $html = file_get_html($filename);
        } else {
            $html = file_get_html($url);
            file_put_contents($filename, $html);
        }


        if (!$html) {
            if (file_exists($filename)) {
                unlink($filename);
            }
            print ("Fetch failed\n");
            return false;
        }

        $data = @$html->find('.tm-article-datetime-published time', 0)->attr['datetime'];
        $title = @$html->find('.tm-title', 0)->innertext;

        $hubs = @$html->find('tm-article-snippet__hubs', 0)->plaintext;
        $content = @$html->find('.tm-article-body', 0)->innertext;
        if (!$content) {
            print("No Content: skipping\n");
            return;
        }
        $content = preg_replace('/[\x00-\x1F\x7F]/u', '', $content);
        $original = '<br> ссылка на оригинал статьи <a href="' . $url . '"> ' . $url . '</a><br>';
        $content .= $original;
        $tags = @$html->find('.tm-article-presenter__meta-list', 0)->plaintext;
        $author = @$html->find('.tm-article-author', 0)->plaintext;

        $habracut_pos = mb_strpos($content, '<a name="habracut"');
        
        
        $post_excerpt = $habracut_pos == false ? truncate($content, 10000) : mb_substr($content, 0,  min($habracut_pos,10000));


        if ($MODE == 1) {
            $content = mysqli_real_escape_string($connect, $content);

            $post_excerpt = mysqli_real_escape_string($connect, $post_excerpt);

            $title = mysqli_real_escape_string($connect, $title);
            $result = mysqli_query($connect, 'INSERT INTO wp_posts (post_date,post_date_gmt,post_content,post_excerpt,post_title,post_author,origID,origFrom,to_ping,pinged,post_content_filtered)
     VALUES(now(),' . "'" . gmdate("Y-m-d H:i:s", time()) . "'" . ',"' . $content . '","' . $post_excerpt . '","' . $title . '",1,' . $id . ',' . $siteID . ',"","","");')
            or die("Invalid query: " . mysqli_error($connect));
            $post_id = mysqli_insert_id($connect);
            $result = mysqli_query($connect, "UPDATE  `wp_posts` SET  `guid` = CONCAT('http://savepearlharbor.com/?p=', ID ) WHERE  `post_status` =  'publish' AND  `post_type` =  'post' AND ID = " . $post_id);
        } else {
            print($content);
            var_dump($title);
            var_dump($tags);
            var_dump($data);
        }
        echo '<br><br><br>';
    }


    echo "Start parse\n";
    include('./config.inc.php');
    #  if ($MODE == 1) {
    $connect = mysqli_connect($old_base['host'], $old_base['user'], $old_base['pass'], $old_base['name']);
    mysqli_set_charset($connect, 'utf8mb4');
    mysqli_query($connect,'SET sql_mode = "STRICT_TRANS_TABLES,STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO"; ');
    #  }

    include 'simple_html_dom.php';

    $SITES = array(
        'habrahabr' => 1,
        'megamozg' => 2,
        'geektimes' => 3
    );

    //    $html = file_get_html('http://habrahabr.ru/posts/collective/all/');

    $siteID = 1;


    $html = file_get_html('https://habr.com/ru/articles/');
    $articleID = (int)@$html->find('article', 0)->id;

    if (!$articleID) {
        return;
    }
    $query = mysqli_query($connect, 'SELECT max(origID) as maxID FROM `wp_posts` WHERE origFrom =' . $siteID);
    $origID = mysqli_fetch_assoc($query);

    $origID = $origID['maxID']-10;
    if (!$origID) {
        return;
    }
    
    for ($counter=1; $counter < 10; $counter++) {

        $maxID = min($origID + 50, $articleID);

        $items = range($origID, $maxID);

        shuffle($items);
        $MODE = 1;

        if (!count($items)){
            exit();
        }
        
        foreach ($items as $id) {
            $url = "https://habr.com/ru/articles/${id}/";
            if (postExists($id)) {
                #            $skipped++;
                continue;
            }
            $link = "https://habr.com/ru/articles/${id}/";
            processPost($id, $link);
        }
        $origID=$maxID;
    }

    //
    //$page=1; 
    //$skipped = 0;
    //while (true){
    //    if ($page ==1){
    //        $linkEx ='';
    //    } else {
    //        $linkEx ='page'.$page;
    //    }
    //    $html = file_get_html('https://habr.com/ru/all/'.$linkEx);
    //
    //    foreach($html->find('a.tm-title__link') as $element)
    //    {
    //        $link =   $element->href;
    //
    //        if (strpos($link,'https')!==0){
    //            $link = 'https://habr.com/'.ltrim($link,'/');
    //        }
    //        print($link."\n");
    //        #exit();
    //
    //        $tmpAr = explode('/',trim($link,'/'));
    //        $id = end($tmpAr);
    //        if (!$id)
    //           continue;
    //        if ($skipped >10 ){
    //            return;
    //        }
    //        if (postExists($id)){
    //            $skipped++;
    //            continue;
    //        }
    //       
    //        processPost($id,$link);
    //    };
    //    $page++;
    //}

    ?>





