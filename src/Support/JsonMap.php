<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Support;

/** @internal */
final class JsonMap
{
    /**
     * PHP encodes an array whose keys are 0, 1, 2… as a JSON list, but the API wants a JSON object
     * for maps like `criteria` and `questions`. Only that ambiguous case needs an object; everything
     * else stays a plain array so `Http::assertSent()` callbacks can index into the request data.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>|object
     */
    public static function of(array $map): array|object
    {
        return $map !== [] && array_is_list($map) ? (object) $map : $map;
    }
}
