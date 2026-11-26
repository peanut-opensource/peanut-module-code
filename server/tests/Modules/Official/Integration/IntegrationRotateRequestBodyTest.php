<?php

declare(strict_types=1);

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\Modules\Integration\Controller\IntegrationAdminController;
use PHPUnit\Framework\TestCase;
use think\Request;

/**
 * The tenant's real route is POST /machine-identities/:identityKey/rotate.
 * A strict JSON body must not be conflated with route or parser state.
 */
final class IntegrationRotateRequestBodyTest extends TestCase
{
    private function request(array $body, string $json = '', string $contentType = 'application/json'): IntegrationBodyProbe
    {
        $request = new Request();
        $request->setMethod('POST');
        $request->withRoute(['identityKey' => 'machine_' . str_repeat('a', 32)]);
        $request->withHeader(['content-type' => $contentType]);
        $request->withInput($json);
        // Simulate a route/middleware parser cache that differs from the
        // actual JSON body; the native controller must read the HTTP contract.
        $request->withPost($body);

        return new IntegrationBodyProbe($request);
    }

    public function testNativeRotateReadsRevisionFromJsonNotDynamicRouteState(): void
    {
        $body = $this->request(
            ['identityKey' => 'machine_' . str_repeat('a', 32), 'revision' => 1],
            '{"revision":1}',
        )->revisionBody();
        self::assertSame(['revision' => 1], $body);
    }

    public function testNativeRotateDoesNotRequireParsedPostCacheToHaveBeenPopulated(): void
    {
        $body = $this->request([], '{"revision":1}')->revisionBody();
        self::assertSame(['revision' => 1], $body);
    }

    public function testStrictJsonRejectsUnknownFieldsEvenIfPostCacheOmitsThem(): void
    {
        $request = $this->request(['revision' => 1], '{"revision":1,"operator":"forged"}');
        $this->expectException(IntegrationSecurityException::class);
        $request->revisionBody();
    }

    public function testStrictJsonRejectsArrayAndEmptyBody(): void
    {
        foreach (['[{"revision":1}]', '', '{broken'] as $invalid) {
            try {
                $this->request([], $invalid)->revisionBody();
                self::fail('Malformed JSON accepted');
            } catch (IntegrationSecurityException $e) {
                self::assertSame('INTEGRATION_INPUT_INVALID', $e->problemCode);
            }
        }
    }

    public function testValidJsonAndLegacyFormBodyPreservePositiveRevisionContract(): void
    {
        self::assertSame(1, $this->request(['revision' => 1], '{"revision":1}')->revisionBody()['revision']);
        self::assertSame(2, $this->request(['revision' => '2'], '', 'application/x-www-form-urlencoded')->revisionBody()['revision']);
    }

    public function testZeroOrFractionalRevisionsRemainRejected(): void
    {
        foreach (['{"revision":0}', '{"revision":1.5}', '{"revision":true}'] as $body) {
            try {
                $this->request([], $body)->revisionBody();
                self::fail('Invalid revision accepted');
            } catch (IntegrationSecurityException $e) {
                self::assertSame('INTEGRATION_INPUT_INVALID', $e->problemCode);
            }
        }
    }
}

final class IntegrationBodyProbe extends IntegrationAdminController
{
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function revisionBody(): array
    {
        $body = $this->body(['revision']);
        $body['revision'] = $this->positiveInteger($body['revision'] ?? null);
        return $body;
    }
}
