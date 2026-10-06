<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru">
<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8" />

<?php
    ini_set('display_errors', 1); ini_set('display_startup_errors', 1); error_reporting(E_ALL);
    
    /*
     * $MODE
     * SET 1 to store to DB
     * SET 2 to var_dump content title
     */

    $MODE = 1;
    echo "Start parse\n";
    include('./config.inc');
    if ($MODE == 1) {
        $connect = mysql_connect($old_base['host'], $old_base['user'], $old_base['pass']);
        mysql_query("SET CHARSET UTF8;");
        mysql_set_charset('utf8', $connect);
        mysql_select_db($old_base['name']);
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
    $listUrl = 'https://habr.com/all/';
    $baseUrl = 'https://habr.com';

    while($listUrl != null){
	print("loading $listUrl\n");
	$listHtml = file_get_html($listUrl);
	$listUrl = null;
	$listUrl = @$listHtml->find('a#next_page',0)->href;
	print("Nextpage $listUrl\n");
	if ($listUrl){
	    $listUrl = $baseUrl.$listUrl;
	}
	$postItems = $listHtml->find('li.content-list__item_post');
	print("Got ".count($postItems)."\n");
	
    foreach($postItems as $post){
	if (!$post->id){
	    continue;
	}
	
	$idarr =explode('_',$post->id);
	if (!isset($idarr[1])){
	    var_dump($post->id);
	    continue;
	}
	
	$id = $idarr[1];
        $siteID = 1;
        if (!$id)
            continue;
        if ($MODE == 1) {
            $query = mysql_query('SELECT ID FROM `wp_posts` WHERE origID = ' . $id . ' AND origFrom =' . $siteID);
            $rows_count = mysql_num_rows($query);
            if ($rows_count > 0){
        	print ("Skip $id\n");
                continue;
	    }
        }
        
        $linkEl = $post->find('a.post__title_link',0);
        
        $url = $linkEl->href;

        $title = $linkEl->plaintext;
	
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
#        $data = $value['time_published'];

        $original = '<br> ссылка на оригинал статьи <a href="'.$url.'"> '.$url.'</a><br>';
        $content.=$original;
        $tags = @$html->find('.post__tags-list',0)->plaintext;
        $author = @$html->find('.post__user-info',0)->plaintext;
        if ($MODE == 1) {
            $content = mysql_real_escape_string($content);
            $title = mysql_real_escape_string($title);
            $result = mysql_query('INSERT INTO wp_posts (post_date,post_date_gmt,post_content,post_title,post_author,origID,origFrom) VALUES(now(),'."'".gmdate("Y-m-d H:i:s", time())."'".',"' . $content . '","' . $title . '",1,' . $id . ',' . $siteID . ');')
            or die("Invalid query: " . mysql_error());
	    $post_id = mysql_insert_id ();
            $result = mysql_query("UPDATE  `wp_posts` SET  `guid` = CONCAT('http://savepearlharbor.com/?p=', ID ) WHERE  `post_status` =  'publish' AND  `post_type` =  'post' AND ID = ".$post_id);
        } else {
            print($content);
            var_dump($title);
        }
        echo '<br><br><br>';
        }
    }
?>





