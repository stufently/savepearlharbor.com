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

    $MODE = 1;
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

    echo "Start parse\n";
    include('./config.inc.php');
    if ($MODE == 1) {
        $connect = mysqli_connect($old_base['host'], $old_base['user'], $old_base['pass'], $old_base['name']);
        mysqli_set_charset($connect, 'utf8mb4');
        mysqli_query($connect, 'SET NAMES utf8mb4;');
    }


    $SITES = array(
        'habrahabr' => 1,
        'megamozg' => 2,
        'geektimes' => 3
    );

    $startID = 325809;

    $cursorID = 0;

    while (true) {
        $post_stmt = $connect->prepare('SELECT ID,post_content FROM `wp_posts` WHERE origID is not null and id > ? order by id LIMIT 50');
        $post_stmt->attr_set(MYSQLI_STMT_ATTR_CURSOR_TYPE, MYSQLI_CURSOR_TYPE_READ_ONLY);
        //    $post_stmt->attr_set(MYSQLI_STMT_ATTR_PREFETCH_ROWS, 100);
        $post_stmt->bind_param('i', $cursorID);

        $update_stmt = $connect->prepare("UPDATE `wp_posts` set post_excerpt = ? where id = ?");

        if ($post_stmt->execute()) {
            $post_stmt->bind_result($post_id, $post_content);

            $processed = 0;
            while ($post_stmt->fetch()) {
                $processed++;
                $habracut_pos = mb_strpos($post_content, '<a name="habracut"');
                $post_excerpt = $habracut_pos == false ? truncate($post_content, 10000) : mb_substr($post_content, 0, min($habracut_pos, 10000));
                #$post_excerpt = truncate($post_content, 1000);

                $cursorID = $post_id;

                $update_stmt->bind_param('si', $post_excerpt, $post_id);
                try {
                    if ($update_stmt->execute()) {
                        printf('updated %d', $post_id);
                    }
                } catch (\Exception $e) {

                    #  var_dump($post_excerpt);
                    var_dump($e);

                }
            }
            if ($processed ==0){
                break;
            }
        } else {
            printf("Post statement error: %s\n", $post_stmt->error);
        }
    }
    $post_stmt->close();
    $update_stmt->close();


    ?>





