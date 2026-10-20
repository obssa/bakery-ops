<?php

declare(strict_types=1);

namespace App\Shared\Live;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Cheap polls (ADR 0009 decision 3). A polled feed computes its ETag from a few cheap numbers (a max cursor, a count:
 * OverlapCursor::fingerprint, or a max movement id for the menu) and hands respond() the closure that builds the body.
 * When the request's If-None-Match names the current ETag, the answer is 304 with no body and the closure never runs,
 * so an idle KDS costs one indexed query per poll. Every answer carries the ETag and Cache-Control: no-cache, private:
 * the browser and Cloudflare must revalidate, never serve a stored copy, and no shared cache may keep one.
 *
 * Comparison is weak (RFC 9110 section 13.1.2, as If-None-Match requires): Cloudflare turns a strong ETag into W/"..."
 * when it compresses a response, and the client sends back what it received. The scope and the parts must cover
 * everything the body depends on (the station, the user for a "mine" feed, the cursor the client sent).
 */
final class ConditionalPoll
{
    public const string CACHE_CONTROL = 'no-cache, private';

    /**
     * A strong ETag over a scope ("tickets", "orders.mine") and the parts the feed's state is read from.
     *
     * @param  array<array-key, int|string|bool|null>  $parts
     */
    public static function etag(string $scope, array $parts): string
    {
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/', $scope) !== 1) {
            throw new InvalidArgumentException("An ETag scope is lower-case letters, digits, '.', '_' and '-': {$scope}");
        }

        return '"'.$scope.'-'.substr(hash('sha256', json_encode([$scope, $parts], JSON_THROW_ON_ERROR)), 0, 20).'"';
    }

    /** Does the request's If-None-Match name $etag (weakly), list it among others, or say "*"? */
    public static function matches(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');
        if (! is_string($header) || trim($header) === '') {
            return false;
        }
        $wanted = self::opaque($etag);
        foreach (preg_split('/\s*,\s*/', trim($header), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if ($candidate === '*' || self::opaque($candidate) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * 304 with no body when the client's copy is current (GET and HEAD only); otherwise what $body builds (an array as
     * JSON, a resource, or a response), stamped with the ETag and Cache-Control.
     *
     * @param  Closure(): (array<array-key, mixed>|Responsable|SymfonyResponse)  $body
     */
    public static function respond(Request $request, string $etag, Closure $body): SymfonyResponse
    {
        if ($request->isMethodCacheable() && self::matches($request, $etag)) {
            return self::stamp(new Response('', SymfonyResponse::HTTP_NOT_MODIFIED), $etag);
        }

        $result = $body();
        $response = match (true) {
            $result instanceof SymfonyResponse => $result,
            $result instanceof Responsable => $result->toResponse($request),
            default => new JsonResponse($result),
        };

        return self::stamp($response, $etag);
    }

    private static function stamp(SymfonyResponse $response, string $etag): SymfonyResponse
    {
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);

        return $response;
    }

    /** The opaque tag without its weak marker: If-None-Match compares weakly. */
    private static function opaque(string $tag): string
    {
        return str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag;
    }
}
