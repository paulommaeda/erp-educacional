<?php
if(!defined('ABSPATH'))exit;
add_action('after_setup_theme',static function(){add_theme_support('title-tag');add_theme_support('post-thumbnails');add_theme_support('html5',['search-form','comment-form','gallery','caption','style','script']);});
add_action('wp_enqueue_scripts',static function(){
    wp_enqueue_style('erp-portal-fonts','https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',[],'1');
    wp_enqueue_style('erp-portal-theme',get_template_directory_uri().'/style.css',['erp-portal-fonts'],'1.0.0');
});
add_filter('wp_resource_hints',static function($urls,$relation){if($relation==='preconnect'){$urls[]='https://fonts.googleapis.com';$urls[]=['href'=>'https://fonts.gstatic.com','crossorigin'=>'anonymous'];}return $urls;},10,2);
