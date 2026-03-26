<?php

namespace OCA\X2Mail;

class ContentSecurityPolicy extends \OCP\AppFramework\Http\ContentSecurityPolicy
{
    /** @var bool Whether inline JS snippets are allowed */
    protected $inlineScriptAllowed = false;
    /** @var bool Whether eval in JS scripts is allowed */
    protected $evalScriptAllowed = true;
    /** @var bool Whether inline CSS is allowed */
    protected $inlineStyleAllowed = true;

    public function __construct()
    {
        $CSP = \RainLoop\Api::getCSP();

        $this->allowedScriptDomains = \array_unique(\array_merge($this->allowedScriptDomains, $CSP->get('script-src')));
        $this->allowedScriptDomains = \array_diff($this->allowedScriptDomains, ["'unsafe-inline'", "'unsafe-eval'"]);

        $this->useStrictDynamic(true);

        $this->allowedImageDomains = \array_unique(\array_merge($this->allowedImageDomains, $CSP->get('img-src')));

        $this->allowedStyleDomains = \array_unique(\array_merge($this->allowedStyleDomains, $CSP->get('style-src')));
        $this->allowedStyleDomains = \array_diff($this->allowedStyleDomains, ["'unsafe-inline'"]);

        $this->allowedFrameDomains = \array_unique(\array_merge($this->allowedFrameDomains, $CSP->get('frame-src')));

        $this->reportTo = \array_unique(\array_merge($this->reportTo, $CSP->report_to));
    }

    public function getSnappyMailNonce(): string
    {
        static $sNonce;
        if (!$sNonce) {
            // No public OCP API for nonce access — using internal class
            $cspManager = \OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class);
            $sNonce = $cspManager->getNonce() ?: \SnappyMail\UUID::generate();
            if (!$cspManager->browserSupportsCspV3()) {
                $this->addAllowedScriptDomain("'nonce-{$sNonce}'");
            }
        }
        return $sNonce;
    }
}
