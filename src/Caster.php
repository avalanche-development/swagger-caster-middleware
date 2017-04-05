<?php

namespace AvalancheDevelopment\SwaggerCasterMiddleware;

use AvalancheDevelopment\Peel\HttpError\BadRequest;
use AvalancheDevelopment\SwaggerRouterMiddleware\ParsedSwaggerInterface;
use DateTime;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;

class Caster implements LoggerAwareInterface
{

    use LoggerAwareTrait;

    public function __construct()
    {
        $this->logger = new NullLogger;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param callable $next
     * @return ResponseInterface $response
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next)
    {
        if (!$request->getAttribute('swagger')) {
            $this->log('no swagger information found in request, skipping');
            return $next($request, $response);
        }

        $updatedSwagger = $this->updateSwaggerParams($request->getAttribute('swagger'));
        $request = $request->withAttribute('swagger', $updatedSwagger);

        return $next($request, $response);
    }

    /**
     * @param ParsedSwaggerInterface $swagger
     * @return ParsedSwaggerInterface
     */
    protected function updateSwaggerParams(ParsedSwaggerInterface $swagger)
    {
        $updatedParams = [];
        foreach ($swagger->getParams() as $param) {
            // todo this will replace the params with the casted values
            // really, we should be updating the value key of the param
            // check to see how array/objects will bubble up their values first
            array_push($updatedParams, $this->castType($param['value'], $param));
        }
        $swagger->setParams($updatedParams);
        return $swagger;
    }

    /**
     * @param mixed $value
     * @param array $parameter
     * @return mixed
     */
    protected function castType($value, array $parameter)
    {
        $type = $this->getParameterType($parameter);

        switch ($type) {
            case 'array':
                foreach ($value as $key => $row) {
                    $value[$key] = $this->castType($row, $parameter['items']);
                }
                break;
            case 'boolean':
                $value = (boolean) $value;
                break;
            case 'file':
                break;
            case 'integer':
                $value = (int) $value;
                break;
            case 'number':
                $value = (float) $value;
                break;
            case 'object':
                $value = $this->formatObject($value, $parameter);
                break;
            case 'string':
                $value = (string) $value;
                $value = $this->formatString($value, $parameter);
                break;
            default:
                throw new Exception('Invalid parameter type value defined in swagger');
                break;
        }

        return $value;
    }

    /**
     * @param array $parameter
     * @return string
     */
    protected function getParameterType(array $parameter)
    {
        $type = '';

        if (isset($parameter['type'])) {
            $type = $parameter['type'];
        }
        if (isset($parameter['in']) && $parameter['in'] === 'body') {
            $type = $parameter['schema']['type'];
        }

        if (empty($type)) {
            throw new Exception('Parameter type is not defined in swagger');
        }
        return $type;
    }

    /**
     * @param string $value
     * @param array $parameter
     * @return object
     */
    protected function formatObject($value, array $parameter)
    {
        $object = $value;
        if (!is_object($object)) {
            $object = (string) $object;
            $object = json_decode($object);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new BadRequest('Bad json object passed in as parameter');
            }
        }

        $schema = array_key_exists('schema', $parameter) ? $parameter['schema'] : $parameter;
        if (empty($schema['properties'])) {
            return $object;
        }
        $properties = $schema['properties'];

        foreach ($object as $key => $attribute) {
            $object->{$key} = $this->castType($attribute, $properties[$key]);
        }

        return $object;
    }

    /**
     * @param string $value
     * @param array $parameter
     * @return mixed
     */
    protected function formatString($value, array $parameter)
    {
        if (!array_key_exists('format', $parameter)) {
            return $value;
        }

        switch ($parameter['format']) {
            case 'date':
                $value = DateTime::createFromFormat('Y-m-d', $value);
                if (!$value) {
                    throw new BadRequest('Invalid date parameter passed in');
                }
                break;
            case 'date-time':
                try {
                    $value = new DateTime($value);
                } catch (Exception $e) {
                    throw new BadRequest('Invalid date parameter passed in');
                }
                break;
            default:
                // this is an open-type property
                break;
        }

        return $value;
    }

    /**
     * @param string $message
     */
    protected function log($message)
    {
        $this->logger->debug("swagger-caster-middleware: {$message}");
    }
}
