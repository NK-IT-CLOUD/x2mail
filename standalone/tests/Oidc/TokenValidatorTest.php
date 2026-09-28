<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Tests\Oidc;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use X2Mail\Standalone\Oidc\OidcException;
use X2Mail\Standalone\Oidc\TokenValidator;
use X2Mail\Standalone\Tests\Support\TestKeys;

class TokenValidatorTest extends TestCase
{
    private const ISS = 'https://id.example.org/realms/central';

    private function validator(): TokenValidator
    {
        return new TokenValidator(TestKeys::keySet(), self::ISS);
    }

    /** @return array<string, mixed> */
    private function idClaims(array $override = []): array
    {
        return $override + [
            'iss' => self::ISS, 'aud' => 'webmail', 'exp' => \time() + 300, 'iat' => \time(),
            'nonce' => 'n-1', 'sub' => 'u-1', 'email' => 'a@example.org',
        ];
    }

    private function expectRejected(callable $fn, string $messagePart): void
    {
        try {
            $fn();
            self::fail('expected OidcException');
        } catch (OidcException $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    public function testValidIdToken(): void
    {
        $c = $this->validator()->validateIdToken(TestKeys::issue($this->idClaims()), 'webmail', 'n-1');
        self::assertSame('a@example.org', $c['email']);
    }

    public function testIdTokenAudienceListWithAzp(): void
    {
        $jwt = TestKeys::issue($this->idClaims(['aud' => ['webmail', 'other'], 'azp' => 'webmail']));
        self::assertSame('u-1', $this->validator()->validateIdToken($jwt, 'webmail', 'n-1')['sub']);
    }

    public function testIdTokenAudienceListWithForeignAzpRejected(): void
    {
        $jwt = TestKeys::issue($this->idClaims(['aud' => ['webmail', 'other'], 'azp' => 'other']));
        $this->expectRejected(fn () => $this->validator()->validateIdToken($jwt, 'webmail', 'n-1'), 'azp');
    }

    public function testWrongAudienceRejected(): void
    {
        $jwt = TestKeys::issue($this->idClaims(['aud' => 'mail-desktop']));
        $this->expectRejected(fn () => $this->validator()->validateIdToken($jwt, 'webmail', 'n-1'), 'aud');
    }

    public function testWrongNonceRejected(): void
    {
        $jwt = TestKeys::issue($this->idClaims());
        $this->expectRejected(fn () => $this->validator()->validateIdToken($jwt, 'webmail', 'other'), 'nonce');
    }

    public function testMissingNonceRejected(): void
    {
        $claims = $this->idClaims();
        unset($claims['nonce']);
        $this->expectRejected(fn () => $this->validator()->validateIdToken(TestKeys::issue($claims), 'webmail', 'n-1'), 'nonce');
    }

    public function testWrongIssuerRejected(): void
    {
        $jwt = TestKeys::issue($this->idClaims(['iss' => 'https://id.example.org/realms/other']));
        $this->expectRejected(fn () => $this->validator()->validateIdToken($jwt, 'webmail', 'n-1'), 'issuer');
    }

    public function testExpiredRejected(): void
    {
        $jwt = TestKeys::issue($this->idClaims(['exp' => \time() - 120]));
        $this->expectRejected(fn () => $this->validator()->validateIdToken($jwt, 'webmail', 'n-1'), 'rejected');
    }

    public function testMissingExpRejected(): void
    {
        $claims = $this->idClaims();
        unset($claims['exp']);
        $this->expectRejected(fn () => $this->validator()->validateAccessToken(TestKeys::issue($claims)), 'exp');
    }

    public function testHs256WithForeignSecretRejected(): void
    {
        $jwt = JWT::encode($this->idClaims(), \str_repeat('k', 32), 'HS256', 'k1');
        $this->expectRejected(fn () => $this->validator()->validateIdToken($jwt, 'webmail', 'n-1'), 'rejected');
    }

    public function testUnknownKidRejected(): void
    {
        $jwt = TestKeys::issue($this->idClaims(), 'k-unknown');
        $this->expectRejected(fn () => $this->validator()->validateIdToken($jwt, 'webmail', 'n-1'), 'rejected');
    }

    public function testAccessTokenReturnsNestedClaimsAsArrays(): void
    {
        $jwt = TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + 60, 'realm_access' => ['roles' => ['mailxyz']], 'organization' => ['nkit']]);
        $c = $this->validator()->validateAccessToken($jwt);
        self::assertSame(['mailxyz'], $c['realm_access']['roles']);
        self::assertSame(['nkit'], $c['organization']);
    }

    public function testLeewayIsRestoredAfterDecode(): void
    {
        JWT::$leeway = 7;

        // Test successful decode restores leeway
        $jwt = TestKeys::issue(['iss' => self::ISS, 'exp' => \time() + 60]);
        $this->validator()->validateAccessToken($jwt);
        self::assertSame(7, JWT::$leeway);

        // Test failed decode also restores leeway
        $expiredJwt = TestKeys::issue($this->idClaims(['exp' => \time() - 120]));
        $this->expectRejected(fn () => $this->validator()->validateIdToken($expiredJwt, 'webmail', 'n-1'), 'rejected');
        self::assertSame(7, JWT::$leeway);

        // Cleanup
        JWT::$leeway = 0;
    }
}
