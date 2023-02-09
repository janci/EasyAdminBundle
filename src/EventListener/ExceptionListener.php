<?php

/*
 * This file is part of the EasyAdminBundle.
 *
 * (c) Javier Eguiluz <javier.eguiluz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\EventListener;

use EasyCorp\Bundle\EasyAdminBundle\Exception\BaseException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\FlattenException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener as BaseExceptionListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

/**
 * This listener allows to display customized error pages in the production
 * environment.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class ExceptionListener extends BaseExceptionListener
{
    /** @var \Twig_Environment */
    private $twig;

    /** @var array */
    private $easyAdminConfig;

    private $currentEntityName;

    public function __construct(\Twig_Environment $twig, array $easyAdminConfig, $controller, LoggerInterface $logger = null)
    {
        $this->twig = $twig;
        $this->easyAdminConfig = $easyAdminConfig;

        parent::__construct($controller, $logger);
    }

    /**
     * @param ExceptionEvent $event
     */
    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        $this->currentEntityName = $event->getRequest()->query->get('entity', null);

        if (!$exception instanceof BaseException) {
            return;
        }

        if (!$this->isLegacySymfony()) {
            parent::onKernelException($event);
        } else {
            $response = $this->legacyOnKernelException($event);
            $event->setResponse($response);
        }
    }

    /**
     * @param FlattenException $exception
     *
     * @return Response
     */
    public function showExceptionPageAction(FlattenException $exception)
    {
        $entityConfig = isset($this->easyAdminConfig['entities'][$this->currentEntityName])
            ? $this->easyAdminConfig['entities'][$this->currentEntityName] : null;
        $exceptionTemplatePath = isset($entityConfig['templates']['exception'])
            ? $entityConfig['templates']['exception']
            : isset($this->easyAdminConfig['design']['templates']['exception'])
                ? $this->easyAdminConfig['design']['templates']['exception']
                : '@EasyAdmin/default/exception.html.twig';
        $exceptionLayoutTemplatePath = isset($entityConfig['templates']['layout'])
            ? $entityConfig['templates']['layout']
            : isset($this->easyAdminConfig['design']['templates']['layout'])
                ? $this->easyAdminConfig['design']['templates']['layout']
                : '@EasyAdmin/default/layout.html.twig';

        return Response::create($this->twig->render($exceptionTemplatePath, array(
            'exception' => $exception,
            'layout_template_path' => $exceptionLayoutTemplatePath,
        )), $exception->getStatusCode());
    }

    /**
     * {@inheritdoc}
     */
    protected function logException(\Throwable $exception, string $message, string $logLevel = null): void
    {
        if (!$exception instanceof BaseException) {
            parent::logException($exception, $message, $logLevel);

            return;
        }

        if (null !== $this->logger) {
            if ($exception->getStatusCode() >= 500) {
                $this->logger->critical($message, array('exception' => $exception));
            } else {
                $this->logger->error($message, array('exception' => $exception));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function duplicateRequest(\Throwable $exception, Request $request): Request
    {
        if (!$this->isLegacySymfony()) {
            $request = parent::duplicateRequest($exception, $request);
        } else {
            $request = $this->legacyDuplicateRequest($request);
        }

        if ($exception instanceof \Exception) {
            $request->attributes->set('exception', FlattenException::create($exception));
        } else {
            $e = new \RuntimeException($exception->getMessage(), $exception->getCode(), $exception);
            $request->attributes->set('exception', FlattenException::create($e));
        }

        return $request;
    }

    /**
     * Utility method needed for BC reasons with Symfony 2.3
     * Code copied from Symfony\Component\HttpKernel\EventListener\ExceptionListener
     *
     * @param ExceptionEvent $event
     *
     * @return Response
     *
     * @throws \Exception
     */
    private function legacyOnKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();

        $this->logException($exception, sprintf('Uncaught PHP Exception %s: "%s" at %s line %s', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));

        $request = $this->duplicateRequest($exception, $event->getRequest());

        try {
            return $event->getKernel()->handle($request, HttpKernelInterface::SUB_REQUEST, false);
        } catch (\Exception $e) {
            $this->logException($e, sprintf('Exception thrown when handling an exception (%s: %s at %s line %s)', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

            $wrapper = $e;

            while ($prev = $wrapper->getPrevious()) {
                if ($exception === $wrapper = $prev) {
                    throw $e;
                }
            }

            $prev = new \ReflectionProperty('Exception', 'previous');
            $prev->setAccessible(true);
            $prev->setValue($wrapper, $exception);

            throw $e;
        }
    }

    /**
     * Utility method needed for BC reasons with Symfony 2.3
     * Code copied from Symfony\Component\HttpKernel\EventListener\ExceptionListener.
     *
     * @param Request $request
     *
     * @return Request
     */
    private function legacyDuplicateRequest(Request $request)
    {
        $attributes = array(
            '_controller' => $this->controller,
            'logger' => $this->logger instanceof DebugLoggerInterface ? $this->logger : null,
            'format' => $request->getRequestFormat(),
        );
        $request = $request->duplicate(null, null, $attributes);
        $request->setMethod('GET');

        return $request;
    }

    /**
     * Returns true if Symfony version is considered legacy (e.g. 2.3)
     *
     * @return bool
     */
    private function isLegacySymfony()
    {
        return 2 === Kernel::MAJOR_VERSION && 3 === Kernel::MINOR_VERSION;
    }
}

class_alias('EasyCorp\Bundle\EasyAdminBundle\EventListener\ExceptionListener', 'JavierEguiluz\Bundle\EasyAdminBundle\EventListener\ExceptionListener', false);
