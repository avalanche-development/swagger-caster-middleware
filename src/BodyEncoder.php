<?php

namespace AvalancheDevelopment\SwaggerCasterMiddleware;

use DateTime;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class BodyEncoder
{

    /**
     * @param Request $request
     * @param Response $response
     * @return Response $response
     */
    public function __invoke(Request $request, Response $response)
    {
        $body = (string) $response->getBody();
        $responseSchema = $this->getResponseSchema($request, $response);
        $produces = $request->getAttribute('swagger')->getProduces();

        $encodedBody = $this->encodeBody($body, $responseSchema, $produces);

        $response->getBody()->attach('php://memory', 'wb+');
        $response->getBody()->write($encodedBody);

        return $response;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return array
     */
    protected function getResponseSchema(Request $request, Response $response)
    {
        $responseCode = $response->getStatusCode();
        $responseSchemas = $request->getAttribute('swagger')->getResponses();
        if (array_key_exists($responseCode, $responseSchemas)) {
            return $responseSchemas[$responseCode];
        }
        if (array_key_exists('default', $responseSchemas)) {
            return $responseSchemas['default'];
        }

        throw new Exception('Could not detect proper response schema');
    }

    /**
     * @param string $body
     * @param array $schema
     * @param array $produces
     * @return string
     */
    protected function encodeBody($body, array $schema, array $produces)
    {
        $produces = $request->getAttribute('swagger')->getProduces();
        $producesJson = array_filter($produces, [ $this, 'checkJsonType' ]);
        if (count($producesJson) > 0) {
            return $this->encodeJson($body, $schema);
        }

        return $body;
    }

    /**
     * @param string $type
     */
    protected function checkJsonType($type)
    {
        return preg_match('/application\/json/i', $type) > 0;
    }

    /**
     * @param string $body
     * @param array $schema
     * @return string
     */
    protected function formatJson($body, array $schema)
    {
        $body = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Could not decode json response body');
        }

        $body = $this->formatObject($body, $schema);

        $body = json_encode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Could not encode json response body');
        }

        return $body;
    }

    /**
     * @param array $value
     * @param array $parameter
     * @return object
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
     * @return mixed
     */
    protected function formatString($value, array $parameter)
    {
        if (!array_key_exists('format', $parameter)) {
            return $value;
        }

        // todo flip-flop datetime processing
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
}
