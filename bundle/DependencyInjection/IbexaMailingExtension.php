<?php

declare(strict_types=1);

namespace CodeRhapsodie\IbexaMailingBundle\DependencyInjection;

use Ibexa\Bundle\Core\DependencyInjection\Configuration\SiteAccessAware\ConfigurationProcessor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

class IbexaMailingExtension extends Extension implements PrependExtensionInterface
{
    public function getAlias(): string
    {
        return 'ibexamailing';
    }

    /**
     * {@inheritdoc}
     *
     * @param array<mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('ezadminui.yml');
        $loader->load('default_settings.yml');
        $loader->load('services.yml');

        $processor = new ConfigurationProcessor($container, $this->getAlias());
        $processor->mapSetting('simple_mailer', $config);
        $processor->mapSetting('mailing_mailer', $config);
        $processor->mapSetting('email_subject_prefix', $config);
        $processor->mapSetting('email_from_address', $config);
        $processor->mapSetting('email_from_name', $config);
        $processor->mapSetting('email_return_path', $config);
        $processor->mapSetting('default_mailinglist_id', $config);
        $processor->mapSetting('unsubscribe_all', $config);
        $processor->mapSetting('delete_user', $config);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configFile = __DIR__.'/../Resources/config/views.yml';
        $config = Yaml::parse(file_get_contents($configFile));
        $container->prependExtensionConfig('ibexa', $config);
        $container->addResource(new FileResource($configFile));

        $loggerFile = __DIR__.'/../Resources/config/logger.yml';
        $config = Yaml::parse(file_get_contents($loggerFile));
        $container->prependExtensionConfig('monolog', $config);
        $container->addResource(new FileResource($loggerFile));

        $workflowFile = __DIR__.'/../Resources/config/workflow.yml';
        $config = Yaml::parse(file_get_contents($workflowFile));
        $container->prependExtensionConfig('framework', $config);
        $container->addResource(new FileResource($workflowFile));
    }
}
