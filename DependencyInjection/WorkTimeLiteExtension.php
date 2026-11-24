<?php

namespace KimaiPlugin\WorkTimeLiteBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class WorkTimeLiteExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->registerBundleConfiguration($container, $config);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function getAlias(): string
    {
        return 'work_time_lite';
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_USER' => ['wtl_close_own_month', 'wtl_absence_own'],
                    'ROLE_TEAMLEAD' => ['wtl_close_own_month', 'wtl_absence_own'],
                    'ROLE_ADMIN' => ['wtl_close_own_month', 'wtl_close_other_month', 'wtl_unlock_month', 'wtl_absence_own', 'wtl_absence_other'],
                    'ROLE_SUPER_ADMIN' => ['wtl_close_own_month', 'wtl_close_other_month', 'wtl_unlock_month', 'wtl_absence_own', 'wtl_absence_other'],
                ],
            ],
        ]);
    }
}
