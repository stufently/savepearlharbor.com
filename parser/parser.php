<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru">
	<head>
		<meta http-equiv="content-type" content="text/html; charset=utf-8" />

<?php
include('./config.inc');
$connect = mysql_connect($old_base['host'], $old_base['user'], $old_base['pass']);
mysql_query("SET CHARSET UTF8;");
mysql_set_charset('utf8',$connect); 
mysql_select_db($old_base['name']);

 
 include 'simple_html_dom.php';
 $html = file_get_html('http://habrahabr.ru/posts/collective/all/');

 foreach($html->find('a.post_title') as $element)
 {
 
	$arr[] = $element->href;
    //$i++;
    //echo $element->href . '<br>';
 };
 
 foreach ($arr as $i => $value)

{
	$strLength = strlen($arr[$i]);
	//echo '<br>Количество символов<br>'.$strLength.'<br><br>';
	$idtxt = substr($arr[$i], -7, 6);
	//echo '<br>проверка ID<br>'.$idtxt.'<br><br>';
	$content = mysql_query('SELECT ID FROM `wp_posts` WHERE  ID='.$idtxt.';');
	$num_rows = mysql_num_rows($content);
	echo $num_rows;
	if ($num_rows<1)
			{
						
			echo '<br>проверка<br>'.$num_rows.'<br>' ;
			echo '<br>ID<br>'.$idtxt.'<br>' ;
 
			$html = file_get_html($arr[$i]);
			
			foreach($html->find('div.published') as $data) 
			//echo '<br>Дата<br>'.$data.'<br>' ;
			
			foreach($html->find('.post_title') as $name)
			//echo '<br>Навание<br>'.$name.'<br>'; 
			
			foreach($html->find('div.hubs') as $hubs) 
			//echo '<br>Хабы<br>'.$hubs->plaintext.'<br>' ;
			
			foreach($html->find('div[class=content html_format]') as $html_format) 
			//echo '<br>Текс<br>'.$html.'<br>' ;
			
			
			
			$original = '<br> ссылка на оригинал статьи <a href="'.$arr[$i].'"> '.$arr[$i].'</a><br>'; 
			
			$html_format = $html_format.$original;
			//echo $html_format;
			
			foreach($html->find('.tags') as $tags)
			//echo '<br>Тэги<br>'.$tags.'<br>'; 
			
			foreach($html->find('.author') as $tags)
			//echo '<br>Автор<br>'.$tags.'<br>'; 
			
			
			//echo '<br>URL<br>'.$arr[$i].'<br>';
			
			//echo '<br><br><br>'; 
			$html_format = mysql_escape_string($html_format);
			$name = mysql_escape_string($name);
			
			
			$result = mysql_query('INSERT INTO wp_posts (ID,post_date,post_content,post_title,post_author) VALUES('.$idtxt.',now(),"'.$html_format.'","'.$name.'",1);')
			or die("Invalid query: " . mysql_error());
			echo '<br><br><br>';
			//echo 'INSERT INTO wp_posts (ID,post_date,post_content,post_title,post_author) VALUES('.$idtxt.',now(),"'.$html_format.'","'.$name.'",1);';
	
			};
 
 

	
	
};



 ?>
 

 