<?php

namespace App\Support;

use App\Models\SchoolClass;

class CurrentClass
{
    public const SESSION_KEY = 'classpulse.current_class_id';

    public static function resolve(?int $requested): ?SchoolClass
    {
        if ($requested !== null) {
            $class = SchoolClass::find($requested);
            if ($class !== null) {
                session([self::SESSION_KEY => $class->id]);

                return $class;
            }
        }

        $sessionId = session(self::SESSION_KEY);
        if ($sessionId !== null) {
            $class = SchoolClass::find($sessionId);
            if ($class !== null) {
                return $class;
            }
        }

        return SchoolClass::query()->orderBy('name')->first();
    }
}
