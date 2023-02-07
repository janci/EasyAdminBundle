<?php

namespace EasyCorp\Bundle\EasyAdminBundle\DependencyInjection;

use Exception;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ContainerWrapper extends Container
{
    /** @var Container */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct();
    }

    public function getOrPrivate($serviceName)
    {
        $service = $this->container->get($serviceName, 0);
        if (!isset($service)) {
            if (isset($this->container->privates[$serviceName])) {
                return $this->container->privates[$serviceName];
            }

            throw new ServiceNotFoundException($serviceName);
        } else {
            return $service;
        }
    }
}
