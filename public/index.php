<?php

/** @var ContainerInterface $container */
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Interfaces\CallableResolverInterface;
use Slim\Interfaces\RouteCollectorInterface;
use Slim\Interfaces\RouteResolverInterface;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\RoutingMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Factory\AppFactory;

$container = require dirname(__DIR__) . '/bootstrap.php';
$request = ServerRequestFactory::createFromGlobals();

AppFactory::setContainer($container);

$app = AppFactory::create(
    $container->get(ResponseFactoryInterface::class),
    $container,
    $container->get(CallableResolverInterface::class),
    $container->get(RouteCollectorInterface::class),
    $container->get(RouteResolverInterface::class)
);

if($env == 'dev') $app->addErrorMiddleware(true, false, false);

$app->add($container->get(RoutingMiddleware::class));
$app->add($container->get(ErrorMiddleware::class));

$app->run($request);
