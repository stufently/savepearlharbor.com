<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8" />

<?php
    ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);


    function limit_text($text, $limit) {
      if (str_word_count($text, 0) > $limit) {
          $words = str_word_count($text, 2);
          $pos = array_keys($words);
          $text = substr($text, 0, $pos[$limit]) . '...';
      }
      return $text;
    }
    

    /*
     * $MODE
     * SET 1 to store to DB
     * SET 2 to var_dump content title
     */

    $MODE = 1;
    echo "Start parse\n";
    include('./config.inc');
    if ($MODE == 1) {
	$connect = mysqli_connect($old_base['host'], $old_base['user'], $old_base['pass'],$old_base['name']);
        mysqli_set_charset($connect,'utf8');
    }
    include 'simple_html_dom.php';

    $SITES = array(
        'habrahabr'=>1,
        'megamozg' =>2,
        'geektimes'=>3
    );

//    $html = file_get_html('http://habrahabr.ru/posts/collective/all/');
/*
    foreach($html->find('a.post_title') as $element)
    {

        $arr[] = $element->href;
        //$i++;
        //echo $element->href . '<br>';
    };
*/
#    $url = "http://tmfeed.ru/posts/habrahabr-megamozg-geektimes_all_alltime.json";
    $url = "https://tmfeed.ru/api/v1/habrahabr-geektimes_all_alltime.json";
#    $url = 'https://habr.com/ru/rss/all/all/?fl=ru';
    $data = file_get_contents($url);

    echo "get feed\n";
    if ($data ==null)
        return;
   $html = file_get_html('https://habr.com/ru/all/');

    $data_decoded = json_decode($data,true);
    if ($data_decoded == null)
        return;
        
    echo "Decoding data\n";


    foreach ($data_decoded['posts'] as $i => $value){

        $id = (int)$value['id'];
    
        $siteID = 0;
        $siteID = isset($values['site']) && isset($SITES[$value['site']]) ? $SITES[$value['site']] :1 ;
        if (!$id)
            continue;
        if ($MODE == 1) {
	    $query = mysqli_query($connect,'SELECT ID FROM `wp_posts` WHERE origID = ' . $id . ' AND origFrom =' . $siteID);
            $rows_count = mysqli_num_rows($query);
            if ($rows_count > 0)
                continue;
        }
        $url = $value['url'];

        $data = $value['time_published'];
        $title = $value['title'];
	
	print ("Fetch $url\n");

	$html  = file_get_html($url);
        if (!$html){
    	    print ("Fetch failed\n");
            continue;
	}	
        $hubs = @$html->find('.post__hubs',0)->plaintext;
        $content = @$html->find('.post__body',0)->innertext;
        if (!$content) {
            print("No Content: skipping\n");
            continue;
        }
        $original = '<br> ссылка на оригинал статьи <a href="'.$url.'"> '.$url.'</a><br>';
        $content.=$original;
        $tags = @$html->find('.post__tags-list',0)->plaintext;
        $author = @$html->find('.post__user-info',0)->plaintext;

	$habracut_pos = mb_strpos($content,'<a name="habracut"');
	
	$post_excerpt = $habracut_pos == false ? limit_text($content,50) : mb_substr($content,0,$habracut_pos);


        if ($MODE == 1) {
   $content = mysqli_real_escape_string($connect,$content);

   $post_excerpt = mysqli_real_escape_string($connect,$post_excerpt);

            $title = mysqli_real_escape_string($connect,$title);
            $result = mysqli_query($connect,'INSERT INTO wp_posts (post_date,post_date_gmt,post_content,post_excerpt,post_title,post_author,origID,origFrom,to_ping,pinged,post_content_filtered)
 VALUES(now(),'."'".gmdate("Y-m-d H:i:s", time())."'".',"' . $content . '","' . $post_excerpt . '","' . $title . '",1,' . $id . ',' . $siteID . ',"","","");')
            or die("Invalid query: " . mysqli_error($connect));
            $post_id = mysqli_insert_id($connect);
            $result = mysqli_query($connect,"UPDATE  `wp_posts` SET  `guid` = CONCAT('http://savepearlharbor.com/?p=', ID ) WHERE  `post_status` =  'publish' AND  `post_type` =  'post' AND ID = ".$post_id);        } else {
            print($content);
            var_dump($title);
        }
        echo '<br><br><br>';
    }
?>





