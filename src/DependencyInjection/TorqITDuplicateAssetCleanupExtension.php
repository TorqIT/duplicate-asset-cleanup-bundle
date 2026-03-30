<?php declare(strict_types=1);

namespace TorqIT\DuplicateAssetCleanupBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class TorqITDuplicateAssetCleanupExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configFile = __DIR__ . '/../Resources/config/custom_reports/DuplicateAssets.yaml';
        $config = \Symfony\Component\Yaml\Yaml::parseFile($configFile);

        if (isset($config['pimcore_custom_reports'])) {
            $container->prependExtensionConfig('pimcore_custom_reports', $config['pimcore_custom_reports']);
        }
    }
}
