<?php

namespace AvalancheDevelopment\SwaggerCasterMiddleware;

use AvalancheDevelopment\SwaggerRouterMiddleware\ParsedSwaggerInterface;
use DateTime;
use Exception;
use PHPUnit_Framework_TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;

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
        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->expects($this->once())
            ->method('getAttribute')
            ->with('swagger')
            ->willReturn(null);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockCallable = function ($request, $response) {
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
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
        $mockSwagger = $this->createMock(ParsedSwaggerInterface::class);

        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->method('getAttribute')
            ->willReturn($mockSwagger);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockCallable = function ($request, $response) {
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
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
        $mockSwagger = $this->createMock(ParsedSwaggerInterface::class);

        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->method('getAttribute')
            ->willReturn($mockSwagger);
        $mockRequest->expects($this->once())
            ->method('withAttribute')
            ->with('swagger', $mockSwagger)
            ->willReturn($mockRequest);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockCallable = function ($request, $response) use ($mockRequest) {
            $this->assertSame($mockRequest, $request);
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('log');
        $caster->method('updateSwaggerParams')
            ->willReturn($mockSwagger);

        $caster->__invoke($mockRequest, $mockResponse, $mockCallable);
    }

    public function testInvokePassesAlongResponseFromCallStack()
    {
        $mockSwagger = $this->createMock(ParsedSwaggerInterface::class);

        $mockRequest = $this->createMock(ServerRequestInterface::class);
        $mockRequest->method('getAttribute')
            ->willReturn($mockSwagger);
        $mockRequest->method('withAttribute')
            ->willReturn($mockRequest);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockCallable = function ($request, $response) use ($mockRequest) {
            return $response;
        };

        $caster = $this->getMockBuilder(Caster::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'log',
                'updateSwaggerParams',
            ])
            ->getMock();
        $caster->expects($this->never())
            ->method('log');
        $caster->method('updateSwaggerParams')
            ->willReturn($mockSwagger);

        $result = $caster->__invoke($mockRequest, $mockResponse, $mockCallable);

        $this->assertSame($mockResponse, $result);
    }

    public function testUpdateSwaggerWalksSwaggerParamsThroughCast()
    {
        $mockParams = [
            [ 'first call' ],
            [ 'second call' ],
        ];

        $mockSwagger = $this->createMock(ParsedSwaggerInterface::class);
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
                [ $mockParams[0] ],
                [ $mockParams[1] ]
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
            [ 'parameter' ],
        ];

        $mockSwagger = $this->createMock(ParsedSwaggerInterface::class);
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
            [ 'parameter' ],
        ];

        $mockSwagger = $this->createMock(ParsedSwaggerInterface::class);
        $mockSwagger->method('getParams')
            ->willReturn($mockParams);
        $mockSwagger->expects($this->once())
            ->method('setParams')
            ->with($mockParams);

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

        $reflectedUpdateSwagger->invokeArgs($caster, [
            $mockSwagger,
        ]);
    }

    public function testUpdateSwaggerReturnsModifiedSwagger()
    {
        $mockParams = [
            [ 'parameter' ],
        ];

        $mockSwagger = $this->createMock(ParsedSwaggerInterface::class);
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
        $this->markTestIncomplete();

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

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->exactly(3))
            ->method('getParameterType')
            ->with($this->isType('array'))
            ->will($this->onConsecutiveCalls('array', 'string', 'string'));

        $result = $reflectedCastType->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame($expectedValue, $result);
    }

    public function testCastTypeHandlesBoolean()
    {
        $this->markTestIncomplete();

        $parameter = [
            'some value'
        ];
        $value = 'false';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('boolean');

        $result = $reflectedCastType->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame((boolean) $value, $result);
    }

    public function testCastTypeHandlesFile()
    {
        $this->markTestIncomplete();

        $parameter = [
            'some value'
        ];
        $value = $this->createMock(UploadedFileInterface::class);

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('file');

        $result = $reflectedCastType->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame($value, $result);
    }

    public function testCastTypeHandlesInteger()
    {
        $this->markTestIncomplete();

        $parameter = [
            'some value'
        ];
        $value = '245';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('integer');

        $result = $reflectedCastType->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame((int) $value, $result);
    }

    public function testCastTypeHandlesNumber()
    {
        $this->markTestIncomplete();

        $parameter = [
            'some value',
        ];
        $value = '3.141592';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('number');

        $result = $reflectedCastType->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertSame((float) $value, $result);
    }

    public function testCastTypeHandlesObject()
    {
        $this->markTestIncomplete();

        $parameter = [
            'some value',
        ];
        $value = (object) [
            'key' => 'value',
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'formatObject', 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('formatObject')
            ->with(json_encode($value), $parameter)
            ->willReturn($value);
        $parameterParser->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('object');

        $result = $reflectedCastType->invokeArgs(
            $parameterParser,
            [
                json_encode($value),
                $parameter,
            ]
        );

        $this->assertEquals($value, $result);
    }

    public function testCastTypeHandlesString()
    {
        $this->markTestIncomplete();

        $parameter = [
            'some value',
        ];
        $value = 1337;

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'formatString', 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('formatString')
            ->with($value, $parameter)
            ->will($this->returnArgument(0));
        $parameterParser->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('string');

        $result = $reflectedCastType->invokeArgs(
            $parameterParser,
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
        $this->markTestIncomplete();

        $parameter = [
            'some value',
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedCastType = $reflectedParameterParser->getMethod('castType');
        $reflectedCastType->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'getParameterType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('getParameterType')
            ->with($parameter)
            ->willReturn('invalid');

        $reflectedCastType->invokeArgs(
            $parameterParser,
            [
                '',
                $parameter,
            ]
        );
    }

    public function testGetParameterTypeDefaultsToType()
    {
        $this->markTestIncomplete();

        $parameter = [
            'in' => 'path',
            'type' => 'good type',
            'schema' => [
                'type' => 'bad type',
            ],
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedGetParameterType = $reflectedParameterParser->getMethod('getParameterType');
        $reflectedGetParameterType->setAccessible(true);

        $parameterParser = new ParameterParser;
        $result = $reflectedGetParameterType->invokeArgs(
            $parameterParser,
            [
                $parameter,
            ]
        );

        $this->assertEquals('good type', $result);
    }

    public function testGetParameterTypeBodyUsesSchemaType()
    {
        $this->markTestIncomplete();

        $parameter = [
            'in' => 'body',
            'type' => 'bad type',
            'schema' => [
                'type' => 'good type',
            ],
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedGetParameterType = $reflectedParameterParser->getMethod('getParameterType');
        $reflectedGetParameterType->setAccessible(true);

        $parameterParser = new ParameterParser;
        $result = $reflectedGetParameterType->invokeArgs(
            $parameterParser,
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
        $this->markTestIncomplete();

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedGetParameterType = $reflectedParameterParser->getMethod('getParameterType');
        $reflectedGetParameterType->setAccessible(true);

        $parameterParser = new ParameterParser;
        $reflectedGetParameterType->invokeArgs(
            $parameterParser,
            [[]]
        );
    }

    public function testFormatObjectHandlesObject()
    {
        $this->markTestIncomplete();

        $parameter = [
            'schema' => [
                'properties' => [
                    'key' => [
                        'some value',
                    ],
                ],
            ],
        ];

        $value = (object) [
            'key' => 'value',
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatObject = $reflectedParameterParser->getMethod('formatObject');
        $reflectedFormatObject->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'castType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('castType')
            ->with($value->key, $parameter['schema']['properties']['key'])
            ->willReturn('value');

        $result = $reflectedFormatObject->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertEquals($value, $result);
    }

    public function testFormatObjectHandlesEncodedObject()
    {
        $this->markTestIncomplete();

        $parameter = [
            'schema' => [
                'properties' => [
                    'key' => [
                        'some value',
                    ],
                ],
            ],
        ];

        $value = (object) [
            'key' => 'value',
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatObject = $reflectedParameterParser->getMethod('formatObject');
        $reflectedFormatObject->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'castType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('castType')
            ->with($value->key, $parameter['schema']['properties']['key'])
            ->willReturn('value');

        $result = $reflectedFormatObject->invokeArgs(
            $parameterParser,
            [
                json_encode($value),
                $parameter,
            ]
        );

        $this->assertEquals($value, $result);
    }

    public function testFormatObjectHandlesPartiallyDefinedParameter()
    {
        $this->markTestIncomplete();

        $parameter = [
            'properties' => [
                'key' => [
                    'some value',
                ],
            ],
        ];

        $value = (object) [
            'key' => 'value',
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatObject = $reflectedParameterParser->getMethod('formatObject');
        $reflectedFormatObject->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'castType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('castType')
            ->with($value->key, $parameter['properties']['key'])
            ->willReturn('value');

        $result = $reflectedFormatObject->invokeArgs(
            $parameterParser,
            [
                json_encode($value),
                $parameter,
            ]
        );

        $this->assertEquals($value, $result);
    }

    public function testFormatObjectHandlesUndefinedParameterObject()
    {
        $this->markTestIncomplete();

        $parameter = [];

        $value = (object) [
            'key' => 'value',
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatObject = $reflectedParameterParser->getMethod('formatObject');
        $reflectedFormatObject->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'castType' ])
            ->getMock();
        $parameterParser->expects($this->never())
            ->method('castType');

        $result = $reflectedFormatObject->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertEquals($value, $result);
    }

    /**
     * @expectedException AvalancheDevelopment\Peel\HttpError\BadRequest
     * @expectedExceptionMessage Bad json object passed in as parameter
     */
    public function testFormatObjectBailsOnBadObject()
    {
        $this->markTestIncomplete();

        $value = 'some string';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatObject = $reflectedParameterParser->getMethod('formatObject');
        $reflectedFormatObject->setAccessible(true);

        $parameterParser = new ParameterParser;
        $reflectedFormatObject->invokeArgs(
            $parameterParser,
            [
                $value,
                [],
            ]
        );
    }

    public function testFormatObjectHandlesPartialDefinition()
    {
        $this->markTestIncomplete();

        $parameter = [
            'properties' => [
                'key' => [
                    'some value',
                ],
            ],
        ];

        $value = (object) [
            'key' => 'value',
        ];

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatObject = $reflectedParameterParser->getMethod('formatObject');
        $reflectedFormatObject->setAccessible(true);

        $parameterParser = $this->getMockBuilder(ParameterParser::class)
            ->setMethods([ 'castType' ])
            ->getMock();
        $parameterParser->expects($this->once())
            ->method('castType')
            ->with($value->key, $parameter['properties']['key'])
            ->willReturn('value');

        $result = $reflectedFormatObject->invokeArgs(
            $parameterParser,
            [
                $value,
                $parameter,
            ]
        );

        $this->assertEquals($value, $result);
    }

    public function testFormatStringIgnoresFormatlessParameter()
    {
        $this->markTestIncomplete();

        $value = 'some string';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatString = $reflectedParameterParser->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $parameterParser = new ParameterParser;
        $result = $reflectedFormatString->invokeArgs(
            $parameterParser,
            [
                $value,
                []
            ]
        );

        $this->assertSame($value, $result);
    }

    public function testFormatStringHandlesDate()
    {
        $this->markTestIncomplete();

        $value = '2016-10-18';
        $expectedValue = DateTime::createFromFormat('Y-m-d', $value);

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatString = $reflectedParameterParser->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $parameterParser = new ParameterParser;
        $result = $reflectedFormatString->invokeArgs(
            $parameterParser,
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
        $this->markTestIncomplete();

        $value = 'invalid date';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatString = $reflectedParameterParser->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $parameterParser = new ParameterParser;
        $reflectedFormatString->invokeArgs(
            $parameterParser,
            [
                $value,
                [ 'format' => 'date' ],
            ]
        );
    }

    public function testFormatStringHandlesDateTime()
    {
        $this->markTestIncomplete();

        $value = '2016-10-18T+07:00';
        $expectedValue = new DateTime($value);

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatString = $reflectedParameterParser->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $parameterParser = new ParameterParser;
        $result = $reflectedFormatString->invokeArgs(
            $parameterParser,
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
        $this->markTestIncomplete();

        $value = 'invalid date';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatString = $reflectedParameterParser->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $parameterParser = new ParameterParser;
        $reflectedFormatString->invokeArgs(
            $parameterParser,
            [
                $value,
                [ 'format' => 'date-time' ],
            ]
        );
    }

    public function testFormatStringIgnoresOnUnmatchedFormat()
    {
        $this->markTestIncomplete();

        $value = 'some value';

        $reflectedParameterParser = new ReflectionClass(ParameterParser::class);
        $reflectedFormatString = $reflectedParameterParser->getMethod('formatString');
        $reflectedFormatString->setAccessible(true);

        $parameterParser = new ParameterParser;
        $result = $reflectedFormatString->invokeArgs(
            $parameterParser,
            [
                $value,
                [ 'format' => 'random' ],
            ]
        );

        $this->assertSame($value, $result);
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
