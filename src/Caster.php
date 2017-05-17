<?php

namespace AvalancheDevelopment\SwaggerCasterMiddleware;

use AvalancheDevelopment\Peel\HttpError\BadRequest;
use AvalancheDevelopment\SwaggerRouterMiddleware\ParsedSwaggerInterface as Swagger;
use DateTime;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
     * @param Request $request
     * @param Response $response
     * @param callable $next
     * @return ResponseInterface $response
     */
    public function __invoke(Request $request, Response $response, callable $next)
    {
        if (!$request->getAttribute('swagger')) {
            $this->log('no swagger information found in request, skipping');
            return $next($request, $response);
        }

        $updatedSwagger = $this->updateSwaggerParams($request->getAttribute('swagger'));
        $request = $request->withAttribute('swagger', $updatedSwagger);

        $result = $next($request, $response);
        $result = $this->castResponseBody($response, $request->getAttribute('swagger'));

        $this->log('finished');
        return $result;
    }

    /**
     * @param Swagger $swagger
     * @return Swagger
     */
    protected function updateSwaggerParams(Swagger $swagger)
    {
        $updatedParams = [];
        foreach ($swagger->getParams() as $key => $param) {
            $updatedParam = array_merge($param, [
                'originalValue' => $param['value'],
                'value' => $this->castType($param['value'], $param),
            ]);
            $updatedParams[$key] = $updatedParam;
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
     * @param array $value
     * @param array $parameter
     * @return array
     */
    protected function formatObject(array $value, array $parameter)
    {
        $object = $value;

        $schema = array_key_exists('schema', $parameter) ? $parameter['schema'] : $parameter;
        if (empty($schema['properties'])) {
            return $object;
        }
        $properties = $schema['properties'];

        foreach ($object as $key => $attribute) {
            $object[$key] = $this->castType($attribute, $properties[$key]);
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
     * @param Response $response
     * @param Swagger $swagger
     * @return Response
     */
    protected function castResponseBody(Response $response, Swagger $swagger)
    {
        $hasJsonProduce = $this->hasJsonProduce($swagger);
        if (!$hasJsonProduce) {
            return $response;
        }

        $schema = $this->getResponseSchema($response->getStatusCode(), $swagger);

        $body = (string) $response->getBody();
        $body = json_decode($body, true);
        $body = $this->castType($body, $schema);
        $body = $this->serializeType($body, $schema);
        $body = json_encode($body);

        $response->getBody()->attach('php://memory', 'wb+');
        $response->getBody()->write($body);

        return $response;
    }

    /**
     * @param Swagger $swagger
     * @return boolean
     */
    protected function hasJsonProduce(Swagger $swagger)
    {
        $jsonProduceHeaders = array_filter(
            $swagger->getProduces(),
            function ($produceHeader) {
                return preg_match('/application\/json/i', $produceHeader) > 0;
            }
        );
        return count($jsonProduceHeaders) > 0;
    }

    /**
     * @param string $statusCode
     * @param Swagger $swagger
     * @return array
     */
    protected function getResponseSchema($statusCode, Swagger $swagger)
    {
        $responseSchemas = $swagger->getResponses();

        if (array_key_exists($statusCode, $responseSchemas)) {
            return $responseSchemas[$statusCode]['schema'];
        }
        if (array_key_exists('default', $responseSchemas)) {
            return $responseSchemas['default']['schema'];
        }

        throw new Exception('Could not detect proper response schema');
    }

    /**
     * @param mixed $value
     * @param array $parameter
     * @return mixed
     */
    protected function serializeType($value, array $parameter)
    {
        $type = $this->getParameterType($parameter);

        switch ($type) {
            case 'array':
                foreach ($value as $key => $row) {
                    $value[$key] = $this->serializeType($row, $parameter['items']);
                }
                break;
            case 'object':
                $value = $this->serializeObject($value, $parameter);
                break;
            case 'string':
                $value = $this->serializeString($value, $parameter);
                break;
            default:
                break;
        }

        return $value;
    }

    /**
     * @param array $value
     * @param array $parameter
     * @return object
     */
    protected function serializeObject(array $value, array $parameter)
    {
        $object = $value;

        $schema = array_key_exists('schema', $parameter) ? $parameter['schema'] : $parameter;
        if (empty($schema['properties'])) {
            return $object;
        }
        $properties = $schema['properties'];

        foreach ($object as $key => $attribute) {
            $object[$key] = $this->serializeType($attribute, $properties[$key]);
        }

        return $object;
    }

    /**
     * @param mixed $value
     * @param array $parameter
     * @return string
     */
    protected function serializeString($value, array $parameter)
    {
        if (!array_key_exists('format', $parameter)) {
            return $value;
        }

        switch ($parameter['format']) {
            case 'date':
                $value = $value->format('Y-m-d');
                break;
            case 'date-time':
                $value = $value->format('c');
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
