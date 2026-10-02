<?php
declare(strict_types=1);
namespace EducacionalERP\Infrastructure\WordPress;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
final class Updates
{
    public const REPOSITORY = 'https://github.com/paulommaeda/erp-educacional/';
    public static function boot():void
    {
        $repository=defined('EDERP_GITHUB_REPOSITORY')?(string)EDERP_GITHUB_REPOSITORY:self::REPOSITORY;
        if($repository==='')return;
        if(!preg_match('~^https://github\.com/[A-Za-z0-9_-]+/[A-Za-z0-9_.-]+/?$~D',$repository))return;
        require_once dirname(EDERP_FILE).'/plugin-update-checker/plugin-update-checker.php';
        $checker=PucFactory::buildUpdateChecker($repository,EDERP_FILE,'erp-educacional');
        $checker->setBranch('main');
        add_filter($checker->getUniqueName('vcs_update_detection_strategies'),static fn(array $strategies):array=>array_intersect_key($strategies,['branch'=>true]));
        if(defined('EDERP_GITHUB_TOKEN')&&is_string(EDERP_GITHUB_TOKEN)&&EDERP_GITHUB_TOKEN!=='')$checker->setAuthentication(EDERP_GITHUB_TOKEN);
    }
}
