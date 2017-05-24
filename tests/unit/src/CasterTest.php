<?php

namespace AvalancheDevelopment\SwaggerCasterMiddleware;

use AvalancheDevelopment\SwaggerRouterMiddleware\ParsedSwaggerInterface as Swagger;
use DateTime;
use Exception;
use PHPUnit_Framework_TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Zend\Diactoros\Response;
use Zend\Diactoros\Stream;

class CasterTest extends PHPUnit_Framework_TestCase
{

    public function testImplementsLoggerAwareInterface()
    {
        $caster = new Caster;

        $this->assertInstanceOf(LoggerAwareInterface::class, $caster);
    }

    public function testConstructSetsNullLogger()
    {
        $logger = new NullLogger;
        $caster = new Caster;

        $this->assertAttributeEquals($logger, 'logger', $caster);
    }

    public function testInvokeBailsIfNoSwaggerFound()
    {
        $mockRequest = $this->createMock(Request::class);
        $mockRequest->expects($this->once())
            ->method('getAttribute')
            ->with('swagger')
            ->willReturn(null);

        $mockResponse = $this->createMock(Response::class);
        $mockCallable = function ($request, $response) {
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castResponseBody',
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('castResponseBody');
        $caster->expects($this->once())
            ->method('log')
            ->with('no swagger information found in request, skipping');
        $caster->expects($this->never())
            ->method('updateSwaggerParams');

        $caster->__invoke($mockRequest, $mockResponse, $mockCallable);
    }

    /**
     * @expectedException Exception
     */
    public function testInvokeBailsIfUpdateSwaggerFails()
    {
        $mockException = $this->createMock(Exception::class);
        $mockSwagger = $this->createMock(Swagger::class);

        $mockRequest = $this->createMock(Request::class);
        $mockRequest->method('getAttribute')
            ->willReturn($mockSwagger);

        $mockResponse = $this->createMock(Response::class);
        $mockCallable = function ($request, $response) {
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castResponseBody',
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
        $caster->method('castResponseBody')
            ->will($this->returnArgument(0));
        $caster->expects($this->never())
            ->method('log');
        $caster->expects($this->once())
            ->method('updateSwaggerParams')
            ->with($mockSwagger)
            ->will($this->throwException($mockException));

        $caster->__invoke($mockRequest, $mockResponse, $mockCallable);
    }

    public function testInvokeUpdatesSwaggerAttribute()
    {
        $mockSwagger = $this->createMock(Swagger::class);

        $mockRequest = $this->createMock(Request::class);
        $mockRequest->method('getAttribute')
            ->willReturn($mockSwagger);
        $mockRequest->expects($this->once())
            ->method('withAttribute')
            ->with('swagger', $mockSwagger)
            ->willReturn($mockRequest);

        $mockResponse = $this->createMock(Response::class);
        $mockCallable = function ($request, $response) use ($mockRequest) {
            $this->assertSame($mockRequest, $request);
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castResponseBody',
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
        $caster->method('castResponseBody')
            ->will($this->returnArgument(0));
        $caster->expects($this->once())
            ->method('log')
            ->with('finished');
        $caster->method('updateSwaggerParams')
            ->willReturn($mockSwagger);

        $caster->__invoke($mockRequest, $mockResponse, $mockCallable);
    }

    public function testInvokePassesAlongResponseFromCallStack()
    {
        $mockSwagger = $this->createMock(Swagger::class);

        $mockRequest = $this->createMock(Request::class);
        $mockRequest->method('getAttribute')
            ->willReturn($mockSwagger);
        $mockRequest->method('withAttribute')
            ->willReturn($mockRequest);

        $mockResponse = $this->createMock(Response::class);
        $mockCallable = function ($request, $response) use ($mockRequest) {
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castResponseBody',
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
        $caster->method('castResponseBody')
            ->will($this->returnArgument(0));
        $caster->expects($this->once())
            ->method('log')
            ->with('finished');
        $caster->method('updateSwaggerParams')
            ->willReturn($mockSwagger);

        $result = $caster->__invoke($mockRequest, $mockResponse, $mockCallable);

        $this->assertSame($mockResponse, $result);
    }

    public function testInvokePassesResponseThroughBodyCaster()
    {
        $mockSwagger = $this->createMock(Swagger::class);

        $mockRequest = $this->createMock(Request::class);
        $mockRequest->method('getAttribute')
            ->willReturn($mockSwagger);
        $mockRequest->method('withAttribute')
            ->willReturn($mockRequest);

        $mockResponse = $this->createMock(Response::class);
        $mockResponseWithCastBody = $this->createMock(Response::class);

        $mockCallable = function ($request, $response) use ($mockRequest) {
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castResponseBody',
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
        $caster->expects($this->once())
            ->method('castResponseBody')
            ->with($mockResponse, $mockSwagger)
            ->willReturn($mockResponseWithCastBody);
        $caster->expects($this->once())
            ->method('log')
            ->with('finished');
        $caster->method('updateSwaggerParams')
            ->willReturn($mockSwagger);

        $result = $caster->__invoke($mockRequest, $mockResponse, $mockCallable);

        $this->assertSame($mockResponseWithCastBody, $result);
    }

    public function testUpdateSwaggerHandlesEmptySwaggerParams()
    {
        $mockParams = [];

        $mockSwagger = $this->createMock(Swagger::class);
        $mockSwagger->expects($this->once())
            ->method('getParams')
            ->willReturn($mockParams);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedUpdateSwagger = $reflectedCaster->getMethod('updateSwaggerParams');
        $reflectedUpdateSwagger->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('castType');

        $reflectedUpdateSwagger->invokeArgs($caster, [
            $mockSwagger,
        ]);
    }

    public function testUpdateSwaggerWalksSwaggerParamsThroughCast()
    {
        $mockParams = [
            [
                'value' => 'first value',
            ],
            [
                'value' => 'second value',
            ],
        ];
        $mockValues = array_column($mockParams, 'value');

        $mockSwagger = $this->createMock(Swagger::class);
        $mockSwagger->expects($this->once())
            ->method('getParams')
            ->willReturn($mockParams);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedUpdateSwagger = $reflectedCaster->getMethod('updateSwaggerParams');
        $reflectedUpdateSwagger->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castType',
            ])
            ->getMock();
        $caster->expects($this->exactly(count($mockParams)))
            ->method('castType')
            ->withConsecutive(
                [ $mockValues[0], $mockParams[0] ],
                [ $mockValues[1], $mockParams[1] ]
            );

        $reflectedUpdateSwagger->invokeArgs($caster, [
            $mockSwagger,
        ]);
    }

    /**
     * @expectedException Exception
     */
    public function testUpdateSwaggerBailsIfCastFails()
    {
        $mockException = $this->createMock(Exception::class);

        $mockParams = [
            [
                'value' => 'some value',
            ],
        ];

        $mockSwagger = $this->createMock(Swagger::class);
        $mockSwagger->method('getParams')
            ->willReturn($mockParams);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedUpdateSwagger = $reflectedCaster->getMethod('updateSwaggerParams');
        $reflectedUpdateSwagger->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castType',
            ])
            ->getMock();
        $caster->method('castType')
            ->will($this->throwException($mockException));

        $reflectedUpdateSwagger->invokeArgs($caster, [
            $mockSwagger,
        ]);
    }

    public function testUpdateSwaggerUpdatesParams()
    {
        $mockParams = [
            [
                'value' => 'some value',
            ],
        ];
        $updatedParams = [
            [
                'originalValue' => 'some value',
                'value' => 'some updated value',
            ],
        ];

        $mockSwagger = $this->createMock(Swagger::class);
        $mockSwagger->method('getParams')
            ->willReturn($mockParams);
        $mockSwagger->expects($this->once())
            ->method('setParams')
            ->with($updatedParams);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedUpdateSwagger = $reflectedCaster->getMethod('updateSwaggerParams');
        $reflectedUpdateSwagger->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castType',
            ])
            ->getMock();
        $caster->method('castType')
            ->with('some value')
            ->willReturn('some updated value');

        $reflectedUpdateSwagger->invokeArgs($caster, [
            $mockSwagger,
        ]);
    }

    public function testUpdateSwaggerReturnsModifiedSwagger()
    {
        $mockParams = [
            [
                'value' => 'some value',
            ],
        ];

        $mockSwagger = $this->createMock(Swagger::class);
        $mockSwagger->method('getParams')
            ->willReturn($mockParams);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedUpdateSwagger = $reflectedCaster->getMethod('updateSwaggerParams');
        $reflectedUpdateSwagger->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'castType',
            ])
            ->getMock();
        $caster->method('castType')
            ->will($this->returnArgument(0));

        $updatedSwagger = $reflectedUpdateSwagger->invokeArgs($caster, [
            $mockSwagger,
        ]);

        $this->assertSame($mockSwagger, $updatedSwagger);
    }

    public function testCastTypeHandlesArray()
    {
        $parameter = [
            'items' => [
                'type' => 'string',
            ],
        ];
        $value = [
            123,
            456,
        ];

        $expectedValue = array_map(function ($row) {
            return (string) $row;
        }, $value);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->method('formatString')
            ->will($this->returnArgument(0));
        $caster->expects($this->never())
            ->method('getObjectProperties');
        $caster->expects($this->exactly(3))
            ->method('getParameterType')
            ->with($this->isType('array'))
            ->will($this->onConsecutiveCalls('array', 'string', 'string'));

        $result = $reflectedCastType->invokeArgs($caster, [
            $value,
            $parameter,
        ]);

        $this->assertSame($expectedValue, $result);
    }

    public function testCastTypeHandlesBoolean()
    {
        $parameter = [
            'some value'
        ];
        $value = 'false';

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('formatString');
        $caster->expects($this->never())
            ->method('getObjectProperties');
        $caster->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('boolean');

        $result = $reflectedCastType->invokeArgs(
            $caster,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame((boolean) $value, $result);
    }

    public function testCastTypeHandlesFile()
    {
        $parameter = [
            'some value'
        ];
        $value = $this->createMock(UploadedFileInterface::class);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('formatString');
        $caster->expects($this->never())
            ->method('getObjectProperties');
        $caster->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('file');

        $result = $reflectedCastType->invokeArgs(
            $caster,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame($value, $result);
    }

    public function testCastTypeHandlesInteger()
    {
        $parameter = [
            'some value'
        ];
        $value = '245';

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('formatString');
        $caster->expects($this->never())
            ->method('getObjectProperties');
        $caster->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('integer');

        $result = $reflectedCastType->invokeArgs(
            $caster,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame((int) $value, $result);
    }

    public function testCastTypeHandlesNumber()
    {
        $parameter = [
            'some value',
        ];
        $value = '3.141592';

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('formatString');
        $caster->expects($this->never())
            ->method('getObjectProperties');
        $caster->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('number');

        $result = $reflectedCastType->invokeArgs(
            $caster,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame((float) $value, $result);
    }

    public function testCastTypeHandlesObject()
    {
        $parameter = [
            'some schema',
        ];
        $value = [
            'key1' => 1,
            'key2' => 2,
        ];

        $renderedSchema = [
            'key1' => [
                'type' => 'string',
            ],
            'key2' => [
                'type' => 'string',
            ],
        ];
        $expectedValue = array_map(function ($row) {
            return (string) $row;
        }, $value);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->method('formatString')
            ->will($this->returnArgument(0));
        $caster->expects($this->once())
            ->method('getObjectProperties')
            ->with($parameter)
            ->willReturn($renderedSchema);
        $caster->expects($this->exactly(3))
            ->method('getParameterType')
            ->with($this->isType('array'))
            ->will($this->onConsecutiveCalls('object', 'string', 'string'));

        $result = $reflectedCastType->invokeArgs(
            $caster,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame($expectedValue, $result);
    }

    public function testCastTypeHandlesString()
    {
        $parameter = [
            'some value',
        ];
        $value = 1337;

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->expects($this->once())
            ->method('formatString')
            ->with($value, $parameter)
            ->will($this->returnArgument(0));
        $caster->expects($this->never())
            ->method('getObjectProperties');
        $caster->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('string');

        $result = $reflectedCastType->invokeArgs(
            $caster,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame((string) $value, $result);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Invalid parameter type value defined in swagger
     */
    public function testCastTypeBailsOnUnknownType()
    {
        $parameter = [
            'some value',
        ];

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastType = $reflectedCaster->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'formatString',
                'getObjectProperties',
                'getParameterType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('formatString');
        $caster->expects($this->never())
            ->method('getObjectProperties');
        $caster->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('invalid');

        $reflectedCastType->invokeArgs(
            $caster,
            [
                '',
                $parameter,
            ]
        );
    }

    public function testGetParameterTypeDefaultsToType()
    {
        $parameter = [
            'in' => 'path',
            'type' => 'good type',
            'schema' => [
                'type' => 'bad type',
            ],
        ];

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedGetParameterType = $reflectedCaster->getMethod('getParameterType');
        $reflectedGetParameterType->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedGetParameterType->invokeArgs(
            $caster,
            [
                $parameter,
            ]
        );

        $this->assertEquals('good type', $result);
    }

    public function testGetParameterTypeBodyUsesSchemaType()
    {
        $parameter = [
            'in' => 'body',
            'type' => 'bad type',
            'schema' => [
                'type' => 'good type',
            ],
        ];

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedGetParameterType = $reflectedCaster->getMethod('getParameterType');
        $reflectedGetParameterType->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedGetParameterType->invokeArgs(
            $caster,
            [
                $parameter,
            ]
        );

        $this->assertEquals('good type', $result);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Parameter type is not defined in swagger
     */
    public function testGetParameterTypeBailsOnEmptyType()
    {
        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedGetParameterType = $reflectedCaster->getMethod('getParameterType');
        $reflectedGetParameterType->setAccessible(true);

        $caster = new Caster;
        $reflectedGetParameterType->invokeArgs(
            $caster,
            [[]]
        );
    }

    public function testGetObjectPropertiesUsesDefaultProperties()
    {
        $properties = [
            'some property',
            'some other property',
        ];

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedGetObjectProperties = $reflectedCaster->getMethod('getObjectProperties');
        $reflectedGetObjectProperties->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedGetObjectProperties->invokeArgs(
            $caster,
            [
                [
                    'properties' => $properties,
                ],
            ]
        );

        $this->assertEquals($properties, $result);
    }

    public function testGetObjectPropertiesUsesSchemaProperties()
    {
        $schemaProperties = [
            'some property',
            'some other property',
        ];
        $defaultProperties = [
            'bad property',
        ];

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedGetObjectProperties = $reflectedCaster->getMethod('getObjectProperties');
        $reflectedGetObjectProperties->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedGetObjectProperties->invokeArgs(
            $caster,
            [
                [
                    'properties' => $defaultProperties,
                    'schema' => [
                        'properties' => $schemaProperties,
                    ],
                ],
            ]
        );

        $this->assertEquals($schemaProperties, $result);
    }

    public function testGetObjectPropertiesHandlesEmptyProperties()
    {
        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedGetObjectProperties = $reflectedCaster->getMethod('getObjectProperties');
        $reflectedGetObjectProperties->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedGetObjectProperties->invokeArgs(
            $caster,
            [
                [
                    'properties' => [],
                ],
            ]
        );

        $this->assertSame([], $result);
    }

    public function testFormatStringIgnoresFormatlessParameter()
    {
        $value = 'some string';

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedFormatString = $reflectedCaster->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedFormatString->invokeArgs(
            $caster,
            [
                $value,
                []
            ]
        );

        $this->assertSame($value, $result);
    }

    public function testFormatStringHandlesDate()
    {
        $value = '2016-10-18';
        $expectedValue = DateTime::createFromFormat('Y-m-d', $value);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedFormatString = $reflectedCaster->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedFormatString->invokeArgs(
            $caster,
            [
                $value,
                [ 'format' => 'date' ],
            ]
        );

        $this->assertEquals($expectedValue, $result);
    }

    /**
     * @expectedException AvalancheDevelopment\Peel\HttpError\BadRequest
     * @expectedExceptionMessage Invalid date parameter passed in
     */
    public function testFormatStringHandlesDateFailures()
    {
        $value = 'invalid date';

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedFormatString = $reflectedCaster->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $caster = new Caster;
        $reflectedFormatString->invokeArgs(
            $caster,
            [
                $value,
                [ 'format' => 'date' ],
            ]
        );
    }

    public function testFormatStringHandlesDateTime()
    {
        $value = '2016-10-18T+07:00';
        $expectedValue = new DateTime($value);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedFormatString = $reflectedCaster->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedFormatString->invokeArgs(
            $caster,
            [
                $value,
                [ 'format' => 'date-time' ],
            ]
        );

        $this->assertEquals($expectedValue, $result);
    }

    /**
     * @expectedException AvalancheDevelopment\Peel\HttpError\BadRequest
     * @expectedExceptionMessage Invalid date parameter passed in
     */
    public function testFormatStringHandlesDateTimeFailures()
    {
        $value = 'invalid date';

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedFormatString = $reflectedCaster->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $caster = new Caster;
        $reflectedFormatString->invokeArgs(
            $caster,
            [
                $value,
                [ 'format' => 'date-time' ],
            ]
        );
    }

    public function testFormatStringIgnoresOnUnmatchedFormat()
    {
        $value = 'some value';

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedFormatString = $reflectedCaster->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $caster = new Caster;
        $result = $reflectedFormatString->invokeArgs(
            $caster,
            [
                $value,
                [ 'format' => 'random' ],
            ]
        );

        $this->assertSame($value, $result);
    }

    public function testCastResponseBodyBailsIfNotJson()
    {
        $mockResponse = $this->createMock(Response::class);
        $mockSwagger = $this->createMock(Swagger::class);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastResponseBody = $reflectedCaster->getMethod('castResponseBody');
        $reflectedCastResponseBody->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'castType',
                'getResponseSchema',
                'hasJsonProduce',
                'serializeType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('castType');
        $caster->expects($this->never())
            ->method('getResponseSchema');
        $caster->expects($this->once())
            ->method('hasJsonProduce')
            ->with($mockSwagger)
            ->willReturn(false);
        $caster->expects($this->never())
            ->method('serializeType');

        $result = $reflectedCastResponseBody->invokeArgs(
            $caster,
            [
                $mockResponse,
                $mockSwagger,
            ]
        );

        $this->assertSame($result, $mockResponse);
    }

    public function testCastResponseBodyPullsSchemaFromSwagger()
    {
        $mockBody = [
            'key' => 'some body',
        ];
        $mockSchema = [
            'some schema',
        ];
        $mockStatusCode = 200;

        $mockStream = $this->createMock(Stream::class);
        $mockStream->method('__toString')
            ->willReturn(json_encode($mockBody));

        $mockResponse = $this->createMock(Response::class);
        $mockResponse->expects($this->once())
            ->method('getStatusCode')
            ->willReturn($mockStatusCode);
        $mockResponse->method('getBody')
            ->willReturn($mockStream);

        $mockSwagger = $this->createMock(Swagger::class);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastResponseBody = $reflectedCaster->getMethod('castResponseBody');
        $reflectedCastResponseBody->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'castType',
                'getResponseSchema',
                'hasJsonProduce',
                'serializeType',
            ])
            ->getMock();
        $caster->expects($this->once())
            ->method('castType')
            ->with($mockBody, $mockSchema)
            ->will($this->returnArgument(0));
        $caster->expects($this->once())
            ->method('getResponseSchema')
            ->with($mockStatusCode, $mockSwagger)
            ->willReturn($mockSchema);
        $caster->method('hasJsonProduce')
            ->willReturn(true);
        $caster->expects($this->once())
            ->method('serializeType')
            ->with($mockBody, $mockSchema)
            ->will($this->returnArgument(0));

        $result = $reflectedCastResponseBody->invokeArgs(
            $caster,
            [
                $mockResponse,
                $mockSwagger,
            ]
        );

        $this->assertSame($result, $mockResponse);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Error encountered when trying to decode json body
     */
    public function testCastResponseBodyBailsIfJsonDecodeFails()
    {
        $mockBody = 'some body';
        $mockSchema = [
            'some schema',
        ];
        $mockStatusCode = 200;

        $mockStream = $this->createMock(Stream::class);
        $mockStream->method('__toString')
            ->willReturn($mockBody);

        $mockResponse = $this->createMock(Response::class);
        $mockResponse->expects($this->once())
            ->method('getStatusCode')
            ->willReturn($mockStatusCode);
        $mockResponse->method('getBody')
            ->willReturn($mockStream);

        $mockSwagger = $this->createMock(Swagger::class);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastResponseBody = $reflectedCaster->getMethod('castResponseBody');
        $reflectedCastResponseBody->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'castType',
                'getResponseSchema',
                'hasJsonProduce',
                'serializeType',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('castType');
        $caster->method('getResponseSchema')
            ->willReturn($mockSchema);
        $caster->method('hasJsonProduce')
            ->willReturn(true);
        $caster->expects($this->never())
            ->method('serializeType');

        $reflectedCastResponseBody->invokeArgs(
            $caster,
            [
                $mockResponse,
                $mockSwagger,
            ]
        );
    }

    public function testCastResponseBodyPassesBodyThroughCastType()
    {
        $mockBody = [
            'key' => 'some body',
        ];
        $mockCastBody = [
            'key' => 'some cast body',
        ];
        $mockSchema = [
            'some schema',
        ];
        $mockStatusCode = 200;

        $mockStream = $this->createMock(Stream::class);
        $mockStream->method('__toString')
            ->willReturn(json_encode($mockBody));

        $mockResponse = $this->createMock(Response::class);
        $mockResponse->expects($this->once())
            ->method('getStatusCode')
            ->willReturn($mockStatusCode);
        $mockResponse->method('getBody')
            ->willReturn($mockStream);

        $mockSwagger = $this->createMock(Swagger::class);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastResponseBody = $reflectedCaster->getMethod('castResponseBody');
        $reflectedCastResponseBody->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'castType',
                'getResponseSchema',
                'hasJsonProduce',
                'serializeType',
            ])
            ->getMock();
        $caster->method('castType')
            ->willReturn($mockCastBody);
        $caster->method('getResponseSchema')
            ->willReturn($mockSchema);
        $caster->method('hasJsonProduce')
            ->willReturn(true);
        $caster->expects($this->once())
            ->method('serializeType')
            ->with($mockCastBody, $mockSchema)
            ->will($this->returnArgument(0));

        $result = $reflectedCastResponseBody->invokeArgs(
            $caster,
            [
                $mockResponse,
                $mockSwagger,
            ]
        );

        $this->assertSame($result, $mockResponse);
    }

    public function testCastResponseBodyPassesBodyThroughSerializeType()
    {
        $mockBody = [
            'key' => 'some body',
        ];
        $mockSerializedBody = [
            'key' => 'some serialized body',
        ];
        $mockSchema = [
            'some schema',
        ];
        $mockStatusCode = 200;

        $mockStream = $this->createMock(Stream::class);
        $mockStream->method('__toString')
            ->willReturn(json_encode($mockBody));
        $mockStream->expects($this->once())
            ->method('write')
            ->with(json_encode($mockSerializedBody));

        $mockResponse = $this->createMock(Response::class);
        $mockResponse->expects($this->once())
            ->method('getStatusCode')
            ->willReturn($mockStatusCode);
        $mockResponse->method('getBody')
            ->willReturn($mockStream);

        $mockSwagger = $this->createMock(Swagger::class);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastResponseBody = $reflectedCaster->getMethod('castResponseBody');
        $reflectedCastResponseBody->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'castType',
                'getResponseSchema',
                'hasJsonProduce',
                'serializeType',
            ])
            ->getMock();
        $caster->method('castType')
            ->with($mockBody, $mockSchema)
            ->will($this->returnArgument(0));
        $caster->method('getResponseSchema')
            ->willReturn($mockSchema);
        $caster->method('hasJsonProduce')
            ->willReturn(true);
        $caster->expects($this->once())
            ->method('serializeType')
            ->with($mockBody, $mockSchema)
            ->willReturn($mockSerializedBody);

        $result = $reflectedCastResponseBody->invokeArgs(
            $caster,
            [
                $mockResponse,
                $mockSwagger,
            ]
        );

        $this->assertSame($result, $mockResponse);
    }

    /**
     * @expectedException Exception
     * @expectedExceptionMessage Error encountered when trying to encode json body
     */
    public function testCastResponseBodyBailsIfJsonEncodeFails()
    {
        $mockBody = [
            'key' => 'some body',
        ];
        $mockBadBody = "\xB1\x31";
        $mockSchema = [
            'some schema',
        ];
        $mockStatusCode = 200;

        $mockStream = $this->createMock(Stream::class);
        $mockStream->method('__toString')
            ->willReturn(json_encode($mockBody));

        $mockResponse = $this->createMock(Response::class);
        $mockResponse->expects($this->once())
            ->method('getStatusCode')
            ->willReturn($mockStatusCode);
        $mockResponse->method('getBody')
            ->willReturn($mockStream);

        $mockSwagger = $this->createMock(Swagger::class);

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedCastResponseBody = $reflectedCaster->getMethod('castResponseBody');
        $reflectedCastResponseBody->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->setMethods([
                'castType',
                'getResponseSchema',
                'hasJsonProduce',
                'serializeType',
            ])
            ->getMock();
        $caster->method('castType')
            ->with($mockBody, $mockSchema)
            ->will($this->returnArgument(0));
        $caster->method('getResponseSchema')
            ->willReturn($mockSchema);
        $caster->method('hasJsonProduce')
            ->willReturn(true);
        $caster->method('serializeType')
            ->with($mockBody, $mockSchema)
            ->willReturn($mockBadBody);

        $reflectedCastResponseBody->invokeArgs(
            $caster,
            [
                $mockResponse,
                $mockSwagger,
            ]
        );
    }

    public function testHasJsonProduceReturnsTrueIfJsonHeader()
    {
        $this->markTestIncomplete();
    }

    public function testHasJsonProduceReturnsFalseIfNoJsonHeader()
    {
        $this->markTestIncomplete();
    }

    public function testGetResponseSchemaReturnsStatusCodeSchema()
    {
        $this->markTestIncomplete();
    }

    public function testGetResponseSchemaReturnsDefaultSchema()
    {
        $this->markTestIncomplete();
    }

    public function testGetResponseSchemaThrowsExceptionIfNoSchemaFound()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeTypeHandlesArray()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeTypeHandlesObject()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeTypeHandlesString()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeTypeIgnoresOtherTypes()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeStringIgnoresIfNoFormat()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeStringHandlesDate()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeStringHandlesDateTime()
    {
        $this->markTestIncomplete();
    }

    public function testSerializeStringIgnoresOtherTypes()
    {
        $this->markTestIncomplete();
    }

    public function testLog()
    {
        $message = 'test debug message';

        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('debug')
            ->with("swagger-caster-middleware: {$message}");

        $reflectedCaster = new ReflectionClass(Caster::class);
        $reflectedLog = $reflectedCaster->getMethod('log');
        $reflectedLog->setAccessible(true);
        $reflectedLogger = $reflectedCaster->getProperty('logger');
        $reflectedLogger->setAccessible(true);

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods(null)
            ->getMock();

        $reflectedLogger->setValue($caster, $mockLogger);
        $reflectedLog->invokeArgs($caster, [
            $message,
        ]);
    }
}
