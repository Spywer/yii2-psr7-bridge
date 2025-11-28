# Yii2 PSR-7 Bridge

A PSR-7 bridge and PSR-15 adapter for Yii2 web applications that enables Yii2 to work with modern PHP application servers and middleware.

## Overview

This bridge allows Yii2 applications to be utilized with PSR-7 and PSR-15 middlewares and task runners such as RoadRunner and PHP-PM, with **minimal** code changes to your application. You can continue using `Yii::$app->request` and `Yii::$app->response` throughout your application without modifications.

## Requirements

- **PHP 8.5+** (required)
- Yii2 2.0.15+
- PSR-7 compatible HTTP message implementation
- PSR-15 compatible middleware dispatcher (optional)

## Installation

Install the package via Composer:

```bash
composer require charlesportwoodii/yii2-psr7-bridge
```

## Quick Start

### 1. Update Application Configuration

Modify your `request` and `response` components in your web application configuration:

```php
return [
    'components' => [
        'request' => [
            'class' => \yii\Psr7\web\Request::class,
        ],
        'response' => [
            'class' => \yii\Psr7\web\Response::class,
        ],
        // ... other components
    ]
];
```

> **Note:** If you're using a custom `Request` class, extend `yii\Psr7\web\Request` to inherit the base functionality.

### 2. Configure Environment Variables

Set the following environment variables in your task runner configuration. For RoadRunner, your `rr.yaml` might look like:

```yaml
env:
  YII_ALIAS_WEBROOT: /path/to/webroot
  YII_ALIAS_WEB: '127.0.0.1:8080'
```

> **Important:** All environment variables must be defined.

### 3. Create Worker Script

Create a worker script for your application server. Example for RoadRunner:

```php
#!/usr/bin/env php
<?php

ini_set('display_errors', 'stderr');

// Set Yii constants
defined('YII_DEBUG') or define('YII_DEBUG', getenv('YII_DEBUG') ?: true);
defined('YII_ENV') or define('YII_ENV', getenv('YII_ENV') ?: 'dev');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$worker = Spiral\RoadRunner\Worker::create();
$psrServerFactory = new Laminas\Diactoros\ServerRequestFactory();
$psrStreamFactory = new Laminas\Diactoros\StreamFactory();
$psrUploadFileFactory = new Laminas\Diactoros\UploadedFileFactory();
$psr7 = new Spiral\RoadRunner\Http\PSR7Worker(
    $worker,
    $psrServerFactory,
    $psrStreamFactory,
    $psrUploadFileFactory
);

$config = require __DIR__ . '/../config/web.php';
$application = new \yii\Psr7\web\Application($config);

// Handle requests in a loop
try {
    while ($request = $psr7->waitRequest()) {
        if ($request instanceof Psr\Http\Message\ServerRequestInterface) {
            try {
                $response = $application->handle($request);
                $psr7->respond($response);
            } catch (\Throwable $e) {
                $psr7->getWorker()->error((string)$e);
            }

            // Check if worker should be restarted due to memory usage
            if ($application->clean()) {
                $psr7->getWorker()->stop();
                return;
            }
        }
    }
} catch (\Throwable $e) {
    $psr7->getWorker()->error((string)$e);
}
```

## Features

### Worker Memory Management

With each request, PHP's memory usage may gradually increase. This is unavoidable due to Yii2 not being designed for persistent application servers. The `$application->clean()` method checks if the current script usage is within 90% of your `memory_limit` ini setting. If so, it returns `true`, allowing you to gracefully restart the worker to avoid out-of-memory errors.

### Session Support

This library is fully compatible with `yii\web\Session` and classes that extend it. Important notes:

1. The application component automatically sets the following session ini settings at runtime:
   ```php
   ini_set('use_cookies', 'false');
   ini_set('use_only_cookies', 'true');
   ```
   Do not overwrite these settings as they are necessary for proper session handling.

2. Don't access `$application->getSession()` within your worker initialization code.

### Request Handling

`yii\Psr7\web\Request` is a drop-in replacement for `yii\web\Request`. To access the raw PSR-7 request object:

```php
$psr7Request = Yii::$app->request->getPsr7Request();
```

### Response Handling

`yii\Psr7\web\Response` directly extends `yii\web\Response`. All PSR-7 functionality is implemented via the `yii\Psr7\web\traits\Psr7ResponseTrait` trait. You can:

- Use `yii\Psr7\web\Response` directly
- Add `Psr7ResponseTrait` to your custom response classes that extend `yii\web\Response`

### Error Handling

`yii\Psr7\web\ErrorHandler` implements custom error handling compatible with `yii\web\ErrorHandler`. The `yii\Psr7\web\Application` automatically uses this error handler. If you have a custom error handler, extend `yii\Psr7\web\ErrorHandler`.

Normal functionality via `errorAction` is supported. Yii2's standard error and exception pages work out of the box.

## PSR-7 and PSR-15 Compatibility

`\yii\Psr7\web\Application` extends `\yii\web\Application` and implements PSR-15's `\Psr\Http\Server\RequestHandlerInterface`, providing full PSR-15 compatibility.

### Using PSR-7 Only

If your application doesn't require PSR-15 middlewares, you can simply return a PSR-7 response:

```php
$response = $application->handle($request);
$psr7->respond($response);
```

No dispatcher is necessary in this configuration.

### Using PSR-15 Middlewares

Since `\yii\Psr7\web\Application` is PSR-15 middleware compatible, you can use it with any PSR-15 dispatcher.

> This library does not implement its own dispatcher, allowing you the freedom to use any PSR-15 compatible dispatcher of your choice.

Example with `middlewares/utils`:

```php
$response = \Middlewares\Utils\Dispatcher::run([
    // new Middleware,
    // new NextMiddleware,
    function($request, $next) use ($application) {
        return $application->handle($request);
    }
], $request);

$psr7->respond($response);
```

## PSR-15 Middleware Filters

This package provides the ability to process PSR-15 compatible middlewares on a per-route basis via `yii\base\ActionFilter` extensions.

> **Note:** Middlewares run on a per-route basis via these methods aren't 100% PSR-15 compliant as they are executed in their own sandbox independent of the middlewares declared in any dispatcher. These middlewares operate solely within the context of the action filter itself. Middlewares such as `middlewares/request-time` will only measure the time it takes to run the action filter rather than the entire request. If you need these middlewares to function at a higher level, chain them in your primary dispatcher, or consider using a native Yii2 ActionFilter.

### Authentication Middleware

If your application requires a PSR-15 authentication middleware not provided by an existing `yii\filters\auth\AuthInterface` class, you can use `yii\Psr7\filters\auth\MiddlewareAuth`:

```php
public function behaviors()
{
    return array_merge(parent::behaviors(), [
        [
            'class' => \yii\Psr7\filters\auth\MiddlewareAuth::class,
            'attribute' => 'username',
            'middleware' => (new \Middlewares\BasicAuthentication([
                'username1' => 'password1',
                'username2' => 'password2',
            ]))->attribute('username')
        ]
    ]);
}
```

> **Note:** Your `yii\web\User` and `IdentityInterface` should be configured to handle the request attribute you provide. Most authentication middlewares export an attribute with user information, which should be used to interface back to Yii2's `IdentityInterface`.

### Other Middlewares

`yii\Psr7\filters\MiddlewareActionFilter` can be used to process other PSR-15 compatible middlewares. Each middleware listed will be executed sequentially:

```php
public function behaviors()
{
    return array_merge(parent::behaviors(), [
        [
            'class' => \yii\Psr7\filters\MiddlewareActionFilter::class,
            'middlewares' => [
                // Yii::$app->request->getAttribute('client-ip') will return the client IP
                new \Middlewares\ClientIp,
                // Yii::$app->response->headers['X-Uuid'] will be set
                new \Middlewares\Uuid,
            ]
        ]
    ]);
}
```

The middleware handler also supports PSR-15 compatible closures:

```php
public function behaviors()
{
    return array_merge(parent::behaviors(), [
        [
            'class' => \yii\Psr7\filters\MiddlewareActionFilter::class,
            'middlewares' => [
                function ($request, $next) {
                    // Yii::$app->request->getAttribute('foo') will be set to `bar`
                    // Yii::$app->response->headers['hello'] will be set to `world`
                    return $next->handle(
                        $request->withAttribute('foo', 'bar')
                    )->withHeader('hello', 'world');
                }
            ]
        ]
    ]);
}
```

Middlewares are processed sequentially until a response is returned (such as an HTTP redirect) or all middlewares have been processed.

If a response is returned by any middleware executed, the before action filter will return `false`, and the resulting response will be sent to the client.

## Why This Package Exists

### Performance Benefits

The performance benefits of task runners such as RoadRunner and PHP-PM are significant.

While PHP has had incremental speed improvements since PHP 7.0, the performance of web-based PHP applications is limited by the need to rebootstrap every single file with each HTTP request. Even with opcache, every file has to be read back into memory on each HTTP request.

PSR-7 servers enable us to keep almost all classes and code in memory between requests, which mostly eliminates the biggest performance bottleneck.

Be sure to check out the [Performance Comparisons](https://github.com/charlesportwoodii/yii2-psr7-bridge/wiki/Performance-Comparisons) wiki page for more information on the actual performance impact.

PHP 8.5 with preloading provides additional performance improvements.

### PSR-7 and PSR-15 Compatibility

While not strictly the goal of this project, it's becoming increasingly difficult to ignore PSR-7 and PSR-15 middlewares. As the Yii2 team has deferred PSR-7 compatibility to Yii 2.1 or Yii 3, existing Yii2 projects cannot take advantage of a standardized request/response pattern or chained middlewares.

Developers conforming to PSR-7 and PSR-15 consequently need to re-implement custom middlewares for Yii2, which runs contrary to Yii2's "fast, secure, efficient" mantra. This library helps to alleviate some of that pain.

## How It Works

This package provides three main classes within the `yii\Psr7\web` namespace:

- **`Application`** - A PSR-15 compatible application component
- **`Request`** - A PSR-7 compatible request component
- **`Response`** - A PSR-7 compatible response component
- **`Psr7ResponseTrait`** - A trait for adding PSR-7 response capabilities to custom response classes

The `yii\Psr7\web\Application` component acts as a drop-in replacement for `yii\web\Application` for use in your task runner. Its constructor takes the standard Yii2 configuration array. The `Application` component then instantiates a `yii\Psr7\web\Request` object using the `ServerRequestInterface` provided by your application server.

> Since `yii\web\Application::bootstrap` uses the `request` component, the request component needs to be properly constructed during the application constructor, as opposed to simply calling `$app->handleRequest($psr7Request)`.

`yii\Psr7\web\Request` is a drop-in replacement for `yii\web\Request`. Its purpose is to provide an interface between `ServerRequestInterface` and the standard `yii\web\Request` API.

Within your modules, controllers, and actions, `Yii::$app->request` and `Yii::$app->response` may be used normally without any changes.

Before the application exits, it calls `getPsr7Response()` on your `response` component. If you're using `yii\web\Response`, simply change your `response` component class in your application configuration to `yii\Psr7\web\Response`. If you're using a custom `Response` object, add the `yii\Psr7\web\traits\Psr7ResponseTrait` trait to your `Response` object that extends `yii\web\Response` to gain the necessary behaviors.

## Limitations

- The Yii2 debug toolbar `yii2-debug` may show incorrect request time and memory usage due to the persistent application server architecture.
- Some advanced cookie features may have limitations.

## Testing

Run tests with PHPUnit:

```bash
./vendor/bin/phpunit
```

## Current Status

### Implemented Features

- [x] Custom `Application` component
- [x] Convert PSR-7 Request into `yii\web\Request` object
- [x] Return PSR-7 responses
- [x] Routing
- [x] Handle `yii\web\Response::$format`
- [x] Work with standard Yii2 formatters
- [x] Handle `HeaderCollection`
- [x] Handle `CookieCollection`
- [x] Handle `yii\web\Response::$stream` and `yii\web\Response::$content`
- [x] Implement `yii\web\Response::redirect`
- [x] Implement `yii\web\Response::refresh`
- [x] GET query parameters `yii\web\Request::get()`
- [x] POST parameters `yii\web\Request::post()`
- [x] `yii\web\Request::getAuthCredentials()`
- [x] `yii\web\Request::loadCookies()`
- [x] Per-action middleware authentication handling
- [x] Per-action middleware chains
- [x] Reuse `Application` component instead of re-instantiating in each loop
- [x] `yii\web\ErrorHandler` implementation
- [x] Run `yii-app-basic`
- [x] Bootstrap with `yii\log\Target`
- [x] Session handling
- [x] `yii2-debug` compatibility
- [x] `yii2-gii` compatibility
- [x] Fix fatal memory leak under load
- [x] Implement `sendFile`, `sendStreamAsFile`

### Pending Features

- [ ] `yii\filters\auth\CompositeAuth` compatibility
- [ ] `yii\web\Request::$methodParam` support (not applicable to `ServerRequestInterface`)
- [ ] Improved test coverage

## Contributing

Contributors are welcome! Check out the pending features list for things that still need to be implemented, help add tests, or add new features!

## License

This project is licensed under the BSD-3-Clause license. See [LICENSE](LICENSE) for more details.
