<?php

namespace KimaiPlugin\WorkTimeLiteBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('work_time_lite');
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->addDefaultsIfNotSet()
            ->children()
                // Eingabe
                ->booleanNode('input_time_normalize')->defaultTrue()->end()
                ->booleanNode('input_duration_dot_minutes')->defaultFalse()->end()
                // Abwesenheiten
                ->scalarNode('absence_start_time')->defaultValue('07:00')->end()
                ->scalarNode('absence_duration_source')->defaultValue('value')->end()
                ->booleanNode('absence_account_uses_value')->defaultTrue()->end()
                ->scalarNode('absence_vacation_project')->defaultNull()->end()
                ->scalarNode('absence_vacation_activity')->defaultNull()->end()
                ->scalarNode('absence_sick_project')->defaultNull()->end()
                ->scalarNode('absence_sick_activity')->defaultNull()->end()
                ->scalarNode('absence_overtime_project')->defaultNull()->end()
                ->scalarNode('absence_overtime_activity')->defaultNull()->end()
                ->scalarNode('absence_other_project')->defaultNull()->end()
                ->scalarNode('absence_other_activity')->defaultNull()->end()
                ->scalarNode('holiday_mode')->defaultValue('zero')->end()
                // Monatsabschluss
                ->scalarNode('close_template')->defaultValue('')->end()
                ->scalarNode('close_filename')->defaultValue('Stundenzettel {monat} {jahr}')->end()
                ->booleanNode('close_download')->defaultTrue()->end()
                ->booleanNode('close_lock')->defaultTrue()->end()
                ->booleanNode('close_mail')->defaultTrue()->end()
                ->scalarNode('smtp_host')->defaultValue('')->end()
                ->scalarNode('smtp_port')->defaultValue('465')->end()
                ->scalarNode('smtp_user')->defaultValue('')->end()
                ->scalarNode('smtp_password')->defaultValue('')->end()
                ->scalarNode('smtp_from')->defaultValue('')->end()
                ->scalarNode('mail_to')->defaultValue('')->end()
                ->scalarNode('mail_cc')->defaultValue('')->end()
                ->scalarNode('mail_subject')->defaultValue('Stundenzettel {monat} {jahr} – {name}')->end()
                ->scalarNode('mail_body')->defaultValue('')->end()
                ->scalarNode('storage_type')->defaultValue('none')->end()
                ->scalarNode('storage_folder')->defaultValue('Arbeit/Stundenzettel/{jahr}')->end()
                ->scalarNode('storage_server_path')->defaultValue('/opt/kimai/var/data/stundenzettel')->end()
                ->scalarNode('storage_webdav_url')->defaultValue('')->end()
                ->scalarNode('storage_webdav_user')->defaultValue('')->end()
                ->scalarNode('storage_webdav_password')->defaultValue('')->end()
                // Teil 2
                ->booleanNode('quick_create')->defaultTrue()->end()
                ->booleanNode('quick_create_global_activity')->defaultTrue()->end()
                ->booleanNode('auto_team')->defaultTrue()->end()
                ->booleanNode('day_buttons')->defaultTrue()->end()
                ->booleanNode('site_today')->defaultTrue()->end()
                ->booleanNode('mobile_nav')->defaultTrue()->end()
                ->booleanNode('site_favorites')->defaultTrue()->end()
                ->booleanNode('site_templates')->defaultTrue()->end()
                ->booleanNode('site_status')->defaultTrue()->end()
                ->booleanNode('site_access')->defaultTrue()->end()
                ->booleanNode('site_circuits')->defaultTrue()->end()
                ->booleanNode('site_meters')->defaultTrue()->end()
                ->booleanNode('site_feierabend')->defaultTrue()->end()
                ->booleanNode('site_packlist')->defaultTrue()->end()
                ->booleanNode('site_ampel')->defaultTrue()->end()
                ->booleanNode('site_tools')->defaultTrue()->end()
                ->booleanNode('site_stock')->defaultTrue()->end()
                ->booleanNode('site_tax')->defaultTrue()->end()
                ->booleanNode('dictate')->defaultTrue()->end()
                ->scalarNode('tax_meal_rate')->defaultValue('14')->end()
                ->scalarNode('tax_km_rate')->defaultValue('0.38')->end()
                ->scalarNode('tax_km')->defaultValue('0')->end()
                ->scalarNode('tax_travel_minutes')->defaultValue('0')->end()
                ->scalarNode('ampel_yellow')->defaultValue('20')->end()
                ->scalarNode('ampel_red')->defaultValue('40')->end()
                ->booleanNode('site_todos')->defaultTrue()->end()
                ->booleanNode('site_photos')->defaultTrue()->end()
                ->scalarNode('custom_fields')->defaultValue('')->end()
                ->scalarNode('zoll_employer')->defaultValue('')->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
