<?php

declare(strict_types=1);

namespace X2Mail\Standalone\Dav;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Minimal CardDAV client for Nextcloud: discovers the addressbook home,
 * lists addressbooks, searches and reads/writes/deletes vCards.
 *
 * XML is parsed with DOMDocument (LIBXML_NONET, no LIBXML_NOENT) and any
 * response carrying a DOCTYPE is rejected outright. The bearer token only
 * goes to URLs under the configured DAV root (same origin, path prefix, no
 * dot segments); anything else is dropped (list contexts) or rejected
 * (single-resource contexts).
 */
final class CardDavClient implements CardDavApi
{
    private const NS_DAV = 'DAV:';
    private const NS_CARD = 'urn:ietf:params:xml:ns:carddav';
    private const NS_CS = 'http://calendarserver.org/ns/';

    /** Only properties from a propstat block whose status is 200 are trusted. */
    private const PROPSTAT_OK = 'd:propstat[contains(concat(" ", normalize-space(d:status), " "), " 200 ")]/d:prop';

    private const FILENAME_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,200}\.vcf\z/';

    private const PRINCIPAL_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:">
          <d:prop>
            <d:current-user-principal/>
          </d:prop>
        </d:propfind>
        XML;

    private const HOME_SET_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
          <d:prop>
            <card:addressbook-home-set/>
          </d:prop>
        </d:propfind>
        XML;

    private const BOOKS_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">
          <d:prop>
            <d:resourcetype/>
            <d:displayname/>
            <d:current-user-privilege-set/>
          </d:prop>
        </d:propfind>
        XML;

    private readonly string $davRoot;

    private readonly string $origin;

    /** Path of the DAV root, always ending in '/'. */
    private readonly string $rootPath;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        string $davRoot,
        private readonly string $accessToken,
        private readonly int $timeoutS,
    ) {
        $this->davRoot = $davRoot;
        $parts = \parse_url($davRoot) ?: [];
        $this->origin = $this->originOf($parts);
        $this->rootPath = \rtrim(\is_string($parts['path'] ?? null) ? $parts['path'] : '', '/') . '/';
    }

    /** Advisory only: the App configures the Guzzle client's actual HTTP timeout; this mirrors that value for callers that need it. */
    public function timeoutSeconds(): int
    {
        return $this->timeoutS;
    }

    /** @return list<AddressBookRef> */
    public function addressBooks(): array
    {
        $principalDoc = $this->propfind($this->davRoot, self::PRINCIPAL_BODY, 0);
        $principalHref = $this->firstText($principalDoc, '//d:response/' . self::PROPSTAT_OK . '/d:current-user-principal/d:href');
        if ($principalHref === null) {
            throw DavException::invalidResponse('missing current-user-principal');
        }
        $principalUrl = $this->resolveHref($principalHref);
        if ($principalUrl === null) {
            throw DavException::invalidResponse('current-user-principal outside dav root');
        }

        $homeDoc = $this->propfind($principalUrl, self::HOME_SET_BODY, 0);
        $homeHref = $this->firstText($homeDoc, '//d:response/' . self::PROPSTAT_OK . '/card:addressbook-home-set/d:href');
        if ($homeHref === null) {
            throw DavException::invalidResponse('missing addressbook-home-set');
        }
        $homeUrl = $this->resolveHref($homeHref);
        if ($homeUrl === null) {
            throw DavException::invalidResponse('addressbook-home-set outside dav root');
        }

        $booksDoc = $this->propfind($homeUrl, self::BOOKS_BODY, 1);
        return $this->parseBooks($booksDoc, $homeUrl);
    }

    /** @return list<CardRef> */
    public function search(AddressBookRef $book, string $text, int $limit): array
    {
        $limit = \max(1, $limit);
        return $this->addressbookQuery(
            $book,
            '<card:address-data/>',
            ($text === '' ? '' : $this->anyOfFilter($text))
                . '<card:limit><card:nresults>' . $limit . '</card:nresults></card:limit>',
        );
    }

    /** @return list<CardRef> */
    public function index(AddressBookRef $book): array
    {
        return $this->addressbookQuery($book, '<card:address-data><card:prop name="UID"/></card:address-data>', '');
    }

    /** @return list<CardRef> */
    private function addressbookQuery(AddressBookRef $book, string $addressData, string $tail): array
    {
        $bookUrl = $this->resolveHref($book->href);
        if ($bookUrl === null) {
            throw DavException::invalidResponse('addressbook href outside dav root');
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<card:addressbook-query xmlns:d="DAV:" xmlns:card="urn:ietf:params:xml:ns:carddav">'
            . '<d:prop><d:getetag/>' . $addressData . '</d:prop>'
            . $tail
            . '</card:addressbook-query>';

        $request = $this->requests->createRequest('REPORT', $bookUrl)
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withHeader('Depth', '1')
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withBody($this->streams->createStream($body));

        return $this->parseCards($this->send($request));
    }

    public function get(string $href): ?CardRef
    {
        $url = $this->resolveHref($href);
        if ($url === null) {
            throw DavException::invalidResponse('href outside dav root');
        }

        $request = $this->requests->createRequest('GET', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken);
        $response = $this->rawSend($request);
        if ($response->getStatusCode() === 404) {
            return null;
        }
        $this->requireStatus($response, [200]);

        return new CardRef($url, $this->trimEtag($response->getHeaderLine('ETag')), (string) $response->getBody());
    }

    public function put(AddressBookRef $book, string $filename, string $vcard, ?string $ifMatchEtag): CardRef
    {
        if (\preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            throw new DavException('invalid card filename');
        }
        $bookUrl = $this->resolveHref($book->href);
        if ($bookUrl === null) {
            throw DavException::invalidResponse('addressbook href outside dav root');
        }
        $url = $bookUrl . $filename;

        $request = $this->requests->createRequest('PUT', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withHeader('Content-Type', 'text/vcard; charset=utf-8')
            ->withBody($this->streams->createStream($vcard));
        $request = $ifMatchEtag === null
            ? $request->withHeader('If-None-Match', '*')
            : $request->withHeader('If-Match', $ifMatchEtag);

        $response = $this->rawSend($request);
        if ($response->getStatusCode() === 412) {
            throw DavException::preconditionFailed();
        }
        $this->requireStatus($response, [201, 204]);

        $etagHeader = $response->getHeaderLine('ETag');
        if ($etagHeader === '') {
            $card = $this->get($url);
            if ($card === null) {
                throw DavException::invalidResponse('put succeeded but card is missing');
            }
            return $card;
        }

        return new CardRef($url, $this->trimEtag($etagHeader), $vcard);
    }

    public function delete(string $href, string $etag): void
    {
        $url = $this->resolveHref($href);
        if ($url === null) {
            throw DavException::invalidResponse('href outside dav root');
        }

        $request = $this->requests->createRequest('DELETE', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withHeader('If-Match', $etag);
        $this->requireStatus($this->rawSend($request), [200, 204]);
    }

    private function anyOfFilter(string $text): string
    {
        $escaped = \htmlspecialchars($text, \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE);
        $filters = '';
        foreach (['FN', 'EMAIL', 'NICKNAME'] as $prop) {
            $filters .= '<card:prop-filter name="' . $prop . '">'
                . '<card:text-match collation="i;unicode-casemap" match-type="contains">' . $escaped . '</card:text-match>'
                . '</card:prop-filter>';
        }
        return '<card:filter test="anyof">' . $filters . '</card:filter>';
    }

    private function propfind(string $url, string $body, int $depth): \DOMDocument
    {
        $request = $this->requests->createRequest('PROPFIND', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken)
            ->withHeader('Depth', (string) $depth)
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withBody($this->streams->createStream($body));

        return $this->send($request);
    }

    private function send(RequestInterface $request): \DOMDocument
    {
        $response = $this->rawSend($request);
        $this->requireStatus($response, [207]);
        return $this->parseXml((string) $response->getBody());
    }

    private function rawSend(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (\Throwable) {
            throw DavException::requestFailed();
        }
    }

    /** @param list<int> $allowed */
    private function requireStatus(ResponseInterface $response, array $allowed): void
    {
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw DavException::authFailure();
        }
        if (!\in_array($status, $allowed, true)) {
            throw DavException::httpStatus($status);
        }
    }

    private function parseXml(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $ok = @$doc->loadXML($xml, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        if (!$ok) {
            throw DavException::invalidResponse('not well-formed xml');
        }
        if ($doc->doctype !== null) {
            throw DavException::invalidResponse('doctype not allowed');
        }
        return $doc;
    }

    private function xpath(\DOMDocument $doc): \DOMXPath
    {
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('d', self::NS_DAV);
        $xpath->registerNamespace('card', self::NS_CARD);
        $xpath->registerNamespace('cs', self::NS_CS);
        return $xpath;
    }

    private function firstText(\DOMDocument $doc, string $query): ?string
    {
        $node = $this->queryOne($this->xpath($doc), $query, $doc);
        return $node !== null ? \trim($node->textContent) : null;
    }

    /** Runs an XPath query and returns the first result as an element, narrowed of the DOMNameSpaceNode union PHPStan infers for DOMXPath::query(). */
    private function queryOne(\DOMXPath $xpath, string $query, \DOMNode $context): ?\DOMElement
    {
        $list = $xpath->query($query, $context);
        if ($list === false) {
            return null;
        }
        $node = $list->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    private function queryCount(\DOMXPath $xpath, string $query, \DOMNode $context): int
    {
        $list = $xpath->query($query, $context);
        return $list === false ? 0 : $list->length;
    }

    /** @return list<AddressBookRef> */
    private function parseBooks(\DOMDocument $doc, string $homeUrl): array
    {
        $xpath = $this->xpath($doc);
        $responses = $xpath->query('//d:response');
        $books = [];
        if ($responses === false) {
            return $books;
        }

        foreach ($responses as $response) {
            if (!$response instanceof \DOMElement) {
                continue;
            }

            $hrefNode = $this->queryOne($xpath, 'd:href', $response);
            if ($hrefNode === null) {
                continue;
            }
            $href = $this->resolveHref(\trim($hrefNode->textContent));
            if ($href === null || \rtrim($href, '/') === \rtrim($homeUrl, '/')) {
                continue;
            }

            $isAddressbook = $this->queryCount($xpath, './/' . self::PROPSTAT_OK . '/d:resourcetype/card:addressbook', $response) > 0;
            if (!$isAddressbook) {
                continue;
            }

            $displayNameNode = $this->queryOne($xpath, './/' . self::PROPSTAT_OK . '/d:displayname', $response);
            $displayName = $displayNameNode !== null ? \trim($displayNameNode->textContent) : '';
            $writable = $this->queryCount($xpath, './/' . self::PROPSTAT_OK . '/d:current-user-privilege-set/d:privilege/d:write', $response) > 0;

            $name = $this->lastSegment($href);
            $books[] = new AddressBookRef(
                $href,
                $name,
                $displayName,
                $writable,
                \str_starts_with($name, 'z-server-generated--'),
                \str_starts_with($name, 'z-app-generated--'),
            );
        }

        return $books;
    }

    /** @return list<CardRef> */
    private function parseCards(\DOMDocument $doc): array
    {
        $xpath = $this->xpath($doc);
        $responses = $xpath->query('//d:response');
        $cards = [];
        if ($responses === false) {
            return $cards;
        }

        foreach ($responses as $response) {
            if (!$response instanceof \DOMElement) {
                continue;
            }

            $hrefNode = $this->queryOne($xpath, 'd:href', $response);
            $etagNode = $this->queryOne($xpath, './/' . self::PROPSTAT_OK . '/d:getetag', $response);
            $dataNode = $this->queryOne($xpath, './/' . self::PROPSTAT_OK . '/card:address-data', $response);
            if ($hrefNode === null || $etagNode === null || $dataNode === null) {
                continue;
            }
            $href = $this->resolveHref(\trim($hrefNode->textContent));
            if ($href === null) {
                continue;
            }
            $cards[] = new CardRef($href, $this->trimEtag($etagNode->textContent), $dataNode->textContent);
        }

        return $cards;
    }

    /** ETags are opaque per RFC 7232: only whitespace around them is ours to remove, quotes (weak or strong) are part of the value. */
    private function trimEtag(string $raw): string
    {
        return \trim($raw);
    }

    private function lastSegment(string $href): string
    {
        $trimmed = \rtrim($href, '/');
        $pos = \strrpos($trimmed, '/');
        return $pos === false ? $trimmed : \substr($trimmed, $pos + 1);
    }

    /** Resolves a DAV href to an absolute URL under the configured DAV root; null if it points elsewhere. */
    private function resolveHref(string $href): ?string
    {
        $parts = \parse_url($href);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme'])) {
            if ($this->originOf($parts) !== $this->origin) {
                return null;
            }
            $url = $href;
        } elseif (\str_starts_with($href, '/')) {
            $url = $this->origin . $href;
        } else {
            return null;
        }

        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) ? $path : '';
        foreach (\explode('/', $path) as $segment) {
            $decoded = \rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..') {
                return null;
            }
        }
        return \str_starts_with($path . '/', $this->rootPath) ? $url : null;
    }

    /** @param array<string, mixed> $parts */
    private function originOf(array $parts): string
    {
        $scheme = \is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '';
        $host = \is_string($parts['host'] ?? null) ? $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }
}
