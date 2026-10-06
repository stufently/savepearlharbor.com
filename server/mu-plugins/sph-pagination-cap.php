<?php
/**
 * Plugin Name: SPH — ограничение глубины пагинации
 * Description: Обрезает max_num_pages основного запроса до реально доступного
 *              диапазона, чтобы пагинатор темы не печатал ссылок на страницы,
 *              которые nginx отдаёт как 410.
 * Version:     1.0
 * Author:      агент Claude, по задаче Романа
 *
 * ЗАЧЕМ (11.08.2026).
 * На сайте 271 174 записи при posts_per_page = 10, поэтому WP_Query::max_num_pages
 * = 27118. Блочная тема twentytwentyfour печатает core-блоки query-pagination-*,
 * которые берут число страниц ровно оттуда: с главной вела ссылка на ?paged=27118,
 * со страницы 99 — на 100 и 101. Все они с 11.08.2026 отдают 410 (nginx режет
 * глубокую пагинацию — она клала MySQL: страница 27000 считалась ~280 секунд).
 * То есть сайт сам приглашал людей и краулеров на битые URL.
 *
 * ПОЧЕМУ mu-plugin, а не правка темы: twentytwentyfour — стоковая тема, её
 * обновление затёрло бы правку. Откат этой правки = удалить один этот файл.
 *
 * ЧТО ДЕЛАЕТ: на фильтре the_posts (срабатывает и на кэшированной ветке
 * WP_Query::get_posts, в отличие от одного лишь found_posts) обрезает
 * max_num_pages основного запроса. Страницы 2..99 работают как работали,
 * пагинатор на месте — он просто больше не рисует «27 118» и «→» за границей.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Первая страница, которую nginx отдаёт как 410.
 *
 * ЕДИНСТВЕННОЕ место, где записана граница. Она обязана совпадать с порогом в
 * /opt/services/savepearlharbor.com/nginx.conf (map $sph_deep_raw, регексп
 * `0*[1-9][0-9]{2,}` = 100 и выше). Меняешь порог там — меняй здесь.
 */
if ( ! defined( 'SPH_PAGED_GONE_FROM' ) ) {
	define( 'SPH_PAGED_GONE_FROM', 100 );
}

add_filter(
	'the_posts',
	static function ( $posts, $query ) {
		if ( ! $query instanceof WP_Query || is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}

		$max_allowed = SPH_PAGED_GONE_FROM - 1;
		if ( $query->max_num_pages > $max_allowed ) {
			$query->max_num_pages = $max_allowed;
		}

		return $posts;
	},
	10,
	2
);
