<?php

namespace App\Http\Controllers\POS\Printing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QzCertController extends Controller
{
    public function cert(Request $request)
    {
        return response(Storage::get('qz/qz-root.crt'))->header('Content-Type', 'text/plain');
    }
    public function sign(Request $request)
    {
        $user = auth()->user();
        $privateKey = openssl_pkey_get_private(Storage::get('qz/qz-private.pem'));

        $signature = null;
        openssl_sign($request->get('request'), $signature, $privateKey, "sha512"); // Use "sha1" for QZ Tray 2.0 and older

        if ($signature) {
            return response(base64_encode($signature))->header('Content-Type', 'text/plain');
        }

        return response('Error signing message', 500);
    }
}
