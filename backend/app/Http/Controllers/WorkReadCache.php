<?php

namespace App\Http\Controllers;

class WorkReadCache
{
    public static function remember(string $key, callable $read): mixed
    {
        $request = request();
        if (! $request->isMethod('GET')) {
            return $read();
        }
        $key = 'work-read.'.$key;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $read());
        }

        return $request->attributes->get($key);
    }
}
