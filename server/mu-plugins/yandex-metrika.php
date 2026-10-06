<?php
/**
 * Plugin Name: Yandex Metrika
 * Description: Injects Yandex Metrika counter 17835511 into wp_head.
 */

add_action('wp_head', function () {
	if (is_admin()) {
		return;
	}
?>
<!-- Yandex.Metrika counter -->
<script type="text/javascript">(function(e,t,n,s,o,i,a){e[o]=e[o]||function(){(e[o].a=e[o].a||[]).push(arguments)},e[o].l=1*new Date;for(var r=0;r<document.scripts.length;r++)if(document.scripts[r].src===s)return;i=t.createElement(n),a=t.getElementsByTagName(n)[0],i.async=1,i.src=s,a.parentNode.insertBefore(i,a)})(window,document,"script","https://mc.yandex.ru/metrika/tag.js","ym"),ym(17835511,"init",{clickmap:!0,trackLinks:!0,accurateTrackBounce:!0,webvisor:!0})</script>
<noscript><div><img src="https://mc.yandex.ru/watch/17835511" style="position:absolute; left:-9999px;" alt="" /></div></noscript>
<!-- /Yandex.Metrika counter -->
<?php
}, 2);
