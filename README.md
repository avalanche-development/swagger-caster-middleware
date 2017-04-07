swagger-caster-middleware
==============

PHP middleware that casts parameters into their respective types based on their [swagger](http://swagger.io/) definition. Information is stored in a AvalancheDevelopment\ParsedSwaggerInterface attached to a Request object.

[![Build Status](https://travis-ci.org/avalanche-development/swagger-caster-middleware.svg?branch=master)](https://travis-ci.org/avalanche-development/swagger-caster-middleware)
[![Code Climate](https://codeclimate.com/github/avalanche-development/swagger-caster-middleware/badges/gpa.svg)](https://codeclimate.com/github/avalanche-development/swagger-caster-middleware)
[![Test Coverage](https://codeclimate.com/github/avalanche-development/swagger-caster-middleware/badges/coverage.svg)](https://codeclimate.com/github/avalanche-development/swagger-caster-middleware/coverage)

## Installation

It's recommended that you use [Composer](https://getcomposer.org/) to install swagger-caster-middleware.

```bash
$ composer require avalanche-development/swagger-caster-middleware
```

swagger-caster-middleware requires PHP 5.6 or newer.

## Usage

This middleware expects a AvalancheDevelopment\ParsedSwaggerInterface attribute already filled out and attached to the Request object. Without it, all of logic will be skipped. Otherwise, the middleware will walk through the params array and cast each value, overwriting the original 'value' attribute with the casted one.

```php
$caster = new AvalancheDevelopment\SwaggerCasterMiddleware\Caster();
$result = $caster($request, $response, $next); // middleware signature
```

It is recommended that this middleware occurs after AvalancheDevelopment\SwaggerRouterMiddleware, which will parse out and attach the AvalancheDevelopment\ParsedSwaggerInterface. Without it you'll need to reinvent the swagger/request parser.

### Interface

Once everything passes through successfully, each param in the AvalancheDevelopment\ParsedSwaggerInterface will have the following attributes.

```php
'swagger' => [
    ...,
    'params' => [
        'originalValue' => 'true',
        'type' => 'boolean',
        'value' => true,
    ],
]
```

### Invalid Requests

If there is an error with parameter casting that appears to be an issue with request, a peel BadRequest is thrown. An error handler can listen for these HttpErrorInterface exceptions and respond appropriately. This is most relevant with date and date-time properties.

## Development

This library is still being developed and some bugs may be experienced. Feel free to add issues or submit pull requests when road bumps are noticed.

### Tests

To execute the test suite, you'll need phpunit (and to install package with dev dependencies).

```bash
$ phpunit
```

## License

swagger-caster-middleware is licensed under the MIT license. See [License File](LICENSE) for more information.
