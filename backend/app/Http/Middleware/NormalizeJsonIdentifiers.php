<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeJsonIdentifiers
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($response instanceof JsonResponse) {
            $response->setData($this->normalize($response->getData(true)));
        }

        return $response;
    }

    private function normalize(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            foreach ($value as $field => $item) {
                $value[$field] = $this->normalize($item, is_string($field) ? $field : (str_ends_with($key, '_ids') ? 'id' : ($key === 'years' ? 'year' : '')));
            }

            return $value;
        }
        if (($key === 'id' || $key === 'year' || str_ends_with($key, '_id')) && is_string($value) && ctype_digit($value) && strlen($value) <= 16 && (float) $value <= 9007199254740991) {
            return (int) $value;
        }

        return $value;
    }
}
