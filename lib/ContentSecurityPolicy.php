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

    public function getEngineNonce(): string
    {
        static $sNonce;
        if (!$sNonce) {
            // No public OCP API exposes the request CSP nonce, so the internal
            // manager is used (tracked in #181). Internal \OC\ classes carry no BC
            // guarantee — guard against future removal by degrading to a
            // self-generated nonce instead of fataling (the failure mode NC 34
            // caused by dropping allowEvalScript).
            $cls = \OC\Security\CSP\ContentSecurityPolicyNonceManager::class;
            $cspManager = \class_exists($cls) ? \OCP\Server::get($cls) : null;
            $sNonce = ($cspManager?->getNonce() ?: null) ?? \X2Mail\Engine\UUID::generate();
            if ($cspManager !== null && !$cspManager->browserSupportsCspV3()) {
                $this->addAllowedScriptDomain("'nonce-{$sNonce}'");
            }
        }
        return $sNonce;
    }
}
