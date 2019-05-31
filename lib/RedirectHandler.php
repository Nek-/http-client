<?php

namespace Amp\Http\Client;

use Amp\CancellationToken;
use Amp\Promise;
use League\Uri;
use League\Uri\UriException;
use Psr\Http\Message\UriInterface;
use function Amp\call;

final class RedirectHandler implements ApplicationInterceptor
{
    private $maxRedirects;
    private $autoReferrer;

    public function __construct(int $maxRedirects = 10, bool $autoReferrer = true)
    {
        if ($maxRedirects < 1) {
            /** @noinspection PhpUndefinedClassInspection */
            throw new \Error("Invalid redirection limit: " . $maxRedirects);
        }

        $this->maxRedirects = $maxRedirects;
        $this->autoReferrer = $autoReferrer;
    }

    public function interceptApplicationRequest(
        Request $request,
        CancellationToken $cancellationToken,
        Client $next
    ): Promise {
        return call(function () use ($request, $cancellationToken, $next) {
            $originalUri = $request->getUri();
            $previousResponse = null;

            $maxRedirects = $this->maxRedirects;
            $requestNr = 1;

            do {
                /** @var Response $response */
                $response = yield $next->request($request, $cancellationToken);
                if ($previousResponse !== null) {
                    $response = $response->withPreviousResponse($previousResponse);
                }

                if ($redirectUri = $this->getRedirectUri($response)) {
                    // Discard response body of redirect responses
                    $body = $response->getBody();

                    /** @noinspection PhpStatementHasEmptyBodyInspection */
                    /** @noinspection LoopWhichDoesNotLoopInspection */
                    /** @noinspection MissingOrEmptyGroupStatementInspection */
                    while (null !== yield $body->read()) {
                        // discard
                    }

                    /**
                     * If this is a 302/303 we need to follow the location with a GET if the original request wasn't
                     * GET. Otherwise we need to send the body again.
                     *
                     * We won't resend the body nor any headers on redirects to other hosts for security reasons.
                     *
                     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.3.3
                     */
                    $method = $request->getMethod();
                    $status = $response->getStatus();
                    $isSameHost = $redirectUri->getAuthority() === $originalUri->getAuthority();

                    if ($isSameHost) {
                        $request = $request->withUri($redirectUri);

                        if ($status >= 300 && $status <= 303 && $method !== 'GET') {
                            $request = $request->withMethod('GET');
                            $request = $request->withoutHeader('Transfer-Encoding');
                            $request = $request->withoutHeader('Content-Length');
                            $request = $request->withoutHeader('Content-Type');
                            $request = $request->withBody(null);
                        }
                    } else {
                        // We ALWAYS follow with a GET and without any set headers or body for redirects to other hosts.
                        $request = new Request($redirectUri);
                    }

                    if ($this->autoReferrer) {
                        $request = $this->assignRedirectRefererHeader($request, $originalUri, $redirectUri);
                    }

                    $previousResponse = $response;
                    $originalUri = $redirectUri;
                } else {
                    break;
                }
            } while (++$requestNr <= $maxRedirects + 1);

            if ($maxRedirects !== 0 && $redirectUri = $this->getRedirectUri($response)) {
                throw new TooManyRedirectsException($response);
            }

            return $response;
        });
    }

    /**
     * Clients must not add a Referer header when leaving an unencrypted resource and redirecting to an encrypted
     * resource.
     *
     * @param Request      $request
     * @param UriInterface $referrerUri
     * @param UriInterface $followUri
     *
     * @return Request
     *
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec15.html#sec15.1.3
     */
    private function assignRedirectRefererHeader(
        Request $request,
        UriInterface $referrerUri,
        UriInterface $followUri
    ): Request {
        $referrerIsEncrypted = $referrerUri->getScheme() === 'https';
        $destinationIsEncrypted = $followUri->getScheme() === 'https';

        if (!$referrerIsEncrypted || $destinationIsEncrypted) {
            return $request->withHeader('Referer', $referrerUri);
        }

        return $request->withoutHeader('Referer');
    }

    private function getRedirectUri(Response $response): ?UriInterface
    {
        if (!$response->hasHeader('Location')) {
            return null;
        }

        $request = $response->getRequest();
        $method = $request->getMethod();

        $status = $response->getStatus();

        if ($status < 300 || $status > 399 || $method === 'HEAD') {
            return null;
        }

        try {
            $requestUri = Uri\Http::createFromString($request->getUri());
            $redirectLocation = $response->getHeader('Location');

            $redirectUri = Uri\Http::createFromString($redirectLocation);

            return $this->resolveRedirect($requestUri, $redirectUri);
        } catch (UriException $e) {
            return null;
        }
    }

    private function resolveRedirect(UriInterface $requestUri, UriInterface $redirectUri): UriInterface
    {
        if ($redirectUri->getAuthority() === '') {
            $redirectUri = $redirectUri->withHost($requestUri->getHost());

            if ($redirectUri->getPort() === null && $requestUri->getPort() !== null) {
                $redirectUri = $redirectUri->withPort($requestUri->getPort());
            }
        }

        if ($redirectUri->getScheme() === '') {
            $redirectUri = $redirectUri->withScheme($requestUri->getScheme());
        }

        if ('' !== $query = $requestUri->getQuery()) {
            $redirectUri = $redirectUri->withQuery($query);
        }

        return $redirectUri;
    }
}