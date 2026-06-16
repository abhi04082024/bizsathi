<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLanguage
{
    public function handle(Request $request, Closure $next)
    {
        $language = $request->header('Accept-Language', 'hi');

        $supportedLanguages = [
            'en', 'hi', 'bn', 'mr', 'or', 'as', 'te', 'ta', 
            'pa', 'gu', 'kn', 'ml', 'ur'
        ];

        if (in_array($language, $supportedLanguages)) {
            App::setLocale($language);
        } else {
            App::setLocale('hi'); // Default to Hindi
        }

        // If user is authenticated, use their preference
        if ($request->user() && $request->user()->language) {
            App::setLocale($request->user()->language);
        }

        return $next($request);
    }
}
