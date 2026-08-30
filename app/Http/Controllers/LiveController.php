<?php

namespace App\Http\Controllers;

use App\Support\LiveStamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /live/version — штамп «что изменилось у меня»: 1 чтение кеша, 0 SQL. */
class LiveController extends Controller
{
    public function version(Request $request): JsonResponse
    {
        $stamp = LiveStamp::get($request->user()->id);
        $etag = '"'.md5(json_encode($stamp)).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->json(null, 304);
        }

        return response()->json($stamp)
            ->setEtag($etag)
            ->header('Cache-Control', 'no-cache, private');
    }
}
