<?php
/**
 * Plugin Name: Analytics (GA4 + Yandex Metrika)
 * Description: Injects GA4 and Yandex Metrika tracking codes
 */

add_action("wp_head", function() {
?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-16LDCQT4SG"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","G-16LDCQT4SG");</script>
<?php
}, 1);
