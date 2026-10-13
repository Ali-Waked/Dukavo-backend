<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HTTP status codes used across the API.
 *
 * Named constants keep intent readable at call sites (`Response::UNPROCESSABLE`)
 * without scattering magic numbers through controllers.
 */
final class Response
{
    public const OK = 200;

    public const CREATED = 201;

    public const ACCEPTED = 202;

    public const NO_CONTENT = 204;

    public const BAD_REQUEST = 400;

    public const UNAUTHORIZED = 401;

    public const PAYMENT_REQUIRED = 402;

    public const FORBIDDEN = 403;

    public const NOT_FOUND = 404;

    public const METHOD_NOT_ALLOWED = 405;

    public const CONFLICT = 409;

    public const UNPROCESSABLE = 422;

    public const TOO_MANY_REQUESTS = 429;

    public const SERVER_ERROR = 500;
}
