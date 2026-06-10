<?php

declare(strict_types=1);

namespace OCA\X2Mail;

class ContentSecurityPolicy extends \OCP\AppFramework\Http\ContentSecurityPolicy
{
    public function __construct()
    {
        $CSP = \X2Mail\Engine\Api::getCSP();

        $this->allowedScriptDomains = \array_unique(\array_merge($this->allowedScriptDomains, $CSP->get('script-src')));
        $this->allowedScriptDomains = \array_diff($this->allowedScriptDomains, ["'unsafe-inline'", "'unsafe-eval'"]);

        // SnappyMail's Knockout.js compiles its template bindings with new Function(),
        // which the CSP only permits via 'unsafe-eval'. NC <=33 exposed this through
        // allowEvalScript(), removed in NC 34 along with its backing
        // $evalScriptAllowed property (a subclass redeclaring that property also
        // crashes NC 34's reflection-based policy merge -> HTTP 500). Appending the
        // keyword to script-src is the version-independent equivalent.
        $this->allowedScriptDomains[] = "'unsafe-eval'";
        $this->allowInlineStyle(true);

        $this->useStrictDynamic(true);

        $this->allowedImageDomains = \array_unique(\array_merge($this->allowedImageDomains, $CSP->get('img-src')));

        $this->allowedStyleDomains = \array_unique(\array_merge($this->allowedStyleDomains, $CSP->get('style-src')));
        $this->allowedStyleDomains = \array_diff($this->allowedStyleDomains, ["'unsafe-inline'"]);

        $this->allowedFrameDomains = \array_unique(\array_merge($this->allowedFrameDomains, $CSP->get('frame-src')));

        $this->reportTo = \array_unique(\array_merge($this->reportTo, $CSP->report_to));
    }

    private ?string $engineNonce = null;

    public function getEngineNonce(): string
    {
        if ($this->engineNonce !== null) {
            return $this->engineNonce;
        }

        $manager = $this->resolveNonceManager();
        $managerNonce = null;
        if ($manager !== null && \method_exists($manager, 'getNonce')) {
            $nonce = $manager->getNonce();
            $managerNonce = \is_string($nonce) && $nonce !== '' ? $nonce : null;
        }

        if ($managerNonce !== null) {
            $this->engineNonce = $managerNonce;
            // NC core already references this nonce in the CSP header on CSPv3
            // browsers; only legacy browsers need it in our own policy.
            if (
                \method_exists($manager, 'browserSupportsCspV3')
                && !$manager->browserSupportsCspV3()
            ) {
                $this->addAllowedScriptDomain("'nonce-{$this->engineNonce}'");
            }
        } else {
            // Fallback (manager gone or nonce empty): NC's header knows nothing
            // about our self-generated nonce, so it MUST be allowed via this
            // policy or the inline boot script gets blocked on CSPv3 browsers.
            $this->engineNonce = \X2Mail\Engine\UUID::generate();
            $this->addAllowedScriptDomain("'nonce-{$this->engineNonce}'");
        }

        return $this->engineNonce;
    }

    /**
     * No public OCP API exposes the request CSP nonce, so the internal manager
     * is used (tracked in #181). Internal \OC\ classes carry no BC guarantee —
     * guard against removal by degrading to the self-generated-nonce fallback
     * instead of fataling (the failure mode NC 34 caused by dropping
     * allowEvalScript). Overridable for tests.
     */
    protected function resolveNonceManager(): ?object
    {
        $cls = '\\OC\\Security\\CSP\\ContentSecurityPolicyNonceManager';
        return \class_exists($cls) ? \OCP\Server::get($cls) : null;
    }
}
