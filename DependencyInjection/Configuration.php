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
                ->scalarNode('mail_to')->defaultValue('')->end()
                ->scalarNode('mail_cc')->defaultValue('')->end()
                ->scalarNode('mail_subject')->defaultValue('Stundenzettel {monat} {jahr} – {name}')->end()
                ->scalarNode('mail_body')->defaultValue("Hallo,\n\nanbei mein Stundenzettel für {monat} {jahr}.\n\nViele Grüße\n{name}")->end()
                ->scalarNode('storage_type')->defaultValue('none')->end()
                ->scalarNode('storage_folder')->defaultValue('Arbeit/Stundenzettel/{jahr}')->end()
                ->scalarNode('storage_server_path')->defaultValue('/opt/kimai/var/data/stundenzettel')->end()
                ->scalarNode('storage_webdav_url')->defaultValue('')->end()
                ->scalarNode('storage_webdav_user')->defaultValue('')->end()
                ->scalarNode('storage_webdav_password')->defaultValue('')->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
