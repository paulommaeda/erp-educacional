<?php
declare(strict_types=1);
namespace EducacionalERP\Presentation\Portal;

use EducacionalERP\Infrastructure\WordPress\MenuPolicy;

final class HelpWiki
{
    public static function assets():void
    {
        wp_enqueue_style('ederp-help',plugins_url('assets/help.css',EDERP_FILE),['journey-ui'],EDERP_VERSION);
    }

    public static function render():void
    {
        $articles=require dirname(__DIR__,3).'/templates/help-secretaria.php';
        $articles=array_filter($articles,static fn($article)=>MenuPolicy::any($article['menus']));
        $slug=sanitize_key(self::input('erp_artigo'));
        $url=Portal::url('ajuda');

        if($slug!==''&&!isset($articles[$slug])){
            echo '<section class="ederp-card erp-wiki"><h1>Artigo indisponível</h1><p>Escolha um dos artigos disponíveis para o seu perfil.</p><a href="'.esc_url($url).'">Voltar à Ajuda</a></section>';
            return;
        }

        echo '<section class="erp-wiki">';
        if($slug===''){
            self::index($url,$articles);
        }else{
            self::article($url,$articles,$slug);
        }
        echo '</section>';
    }

    private static function input(string $key):string
    {
        $value=$_GET[$key]??'';
        return is_string($value)?sanitize_text_field(wp_unslash($value)):'';
    }

    private static function searchText(string $text):string
    {
        return strtolower(remove_accents($text));
    }

    private static function index(string $url,array $articles):void
    {
        $query=wp_html_excerpt(self::input('erp_busca_ajuda'),100,'');
        echo '<header class="erp-wiki-heading"><h1>Ajuda</h1><p>Rotinas da secretaria</p></header>';
        self::searchForm($url,$query);
        if($query!==''){
            $words=preg_split('/\s+/u',self::searchText($query),-1,PREG_SPLIT_NO_EMPTY);
            $articles=array_filter($articles,static function(array $article)use($words):bool{
                $parts=[$article['title'],$article['intro'],$article['topic'],$article['keywords']??'',$article['note']??''];
                foreach($article['sections'] as $section=>$steps)array_push($parts,$section,...$steps);
                $text=self::searchText(implode(' ',$parts));
                foreach($words as $word)if(!str_contains($text,$word))return false;
                return true;
            });
        }
        if(!$articles){
            echo '<p class="ederp-card">Nenhum artigo encontrado. Tente buscar pelo nome de uma tela, como Matrículas.</p>';
            return;
        }

        $topics=[];
        foreach($articles as $key=>$article)$topics[$article['topic']][$key]=$article;
        foreach($topics as $title=>$group){
            echo '<section class="erp-wiki-topic"><h2>'.esc_html($title).'</h2><ul class="erp-wiki-grid">';
            foreach($group as $key=>$article){
                echo '<li><a href="'.esc_url(add_query_arg('erp_artigo',$key,$url)).'"><strong>'.esc_html($article['title']).'</strong>'
                    .'<span>'.esc_html($article['intro']).'</span></a></li>';
            }
            echo '</ul></section>';
        }
    }

    private static function searchForm(string $url,string $query):void
    {
        parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$args);
        echo '<form method="get" action="'.esc_url(explode('?',$url,2)[0]).'" class="erp-wiki-search" role="search" aria-label="Ajuda do ERP">';
        foreach($args as $key=>$value)if(is_string($value))echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
        echo '<label for="erp-help-query">Buscar na Ajuda</label><div><input id="erp-help-query" name="erp_busca_ajuda" type="search" value="'.esc_attr($query).'" maxlength="100" placeholder="Ex.: matrícula ou RG da mãe"><button type="submit">Buscar</button>';
        if($query!=='')echo '<a href="'.esc_url($url).'">Limpar busca</a>';
        echo '</div></form>';
    }

    private static function article(string $url,array $articles,string $slug):void
    {
        $article=$articles[$slug];
        $related=array_intersect_key($articles,array_flip($article['related']??[]));
        echo '<nav class="erp-wiki-path" aria-label="Localização na Ajuda"><a href="'.esc_url($url).'">Ajuda</a><span>'.esc_html($article['topic']).'</span></nav>';
        echo '<div class="erp-wiki-layout"><article class="ederp-card erp-wiki-article"><header><h1>'.esc_html($article['title']).'</h1><p>'.esc_html($article['intro']).'</p></header>';
        if(MenuPolicy::can($article['screen'])){
            echo '<a class="erp-wiki-open" href="'.esc_url(Portal::url($article['screen'])).'">Abrir '.esc_html(MenuPolicy::MENUS[$article['screen']]).'</a>';
        }
        $index=0;
        foreach($article['sections'] as $heading=>$steps){
            echo '<section id="ajuda-secao-'.++$index.'"><h2>'.esc_html($heading).'</h2><ol>';
            foreach($steps as $step)echo '<li>'.esc_html($step).'</li>';
            echo '</ol></section>';
        }
        if(isset($article['note']))echo '<p class="erp-wiki-note">'.esc_html($article['note']).'</p>';
        echo '</article><aside class="erp-wiki-aside"><nav aria-label="Seções do artigo"><h2>Neste artigo</h2><ul>';
        $index=0;
        foreach(array_keys($article['sections']) as $heading){
            echo '<li><a href="#ajuda-secao-'.++$index.'">'.esc_html($heading).'</a></li>';
        }
        echo '</ul></nav>';
        if($related){
            echo '<nav aria-label="Artigos relacionados"><h2>Leia também</h2><ul>';
            foreach($related as $key=>$item){
                echo '<li><a href="'.esc_url(add_query_arg('erp_artigo',$key,$url)).'">'.esc_html($item['title']).'</a></li>';
            }
            echo '</ul></nav>';
        }
        echo '</aside></div>';
    }
}
