<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Endpoint unduh langsung file APK untuk HP Android
Route::get('/download-apk', function () {
    $path = public_path('pkl-monitoring.apk');
    if (file_exists($path)) {
        return response()->download($path, 'pkl-monitoring.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
    return response()->json(['error' => 'File APK belum tersedia di server.'], 404);
});

Route::get('/pkl-monitoring.apk', function () {
    $path = public_path('pkl-monitoring.apk');
    if (file_exists($path)) {
        return response()->download($path, 'pkl-monitoring.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
    return response()->json(['error' => 'File APK belum tersedia di server.'], 404);
});

// Sajikan file media storage publik secara langsung dengan CORS & MIME type yang tepat
// serta aman terhadap environment cPanel yang menonaktifkan symlink/exec.
Route::get('/storage/{path}', function (string $path) {
    if (str_contains($path, '..')) {
        abort(404);
    }

    $corsHeaders = [
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=86400',
    ];

    $storagePublicPath = storage_path('app/public/' . $path);
    if (file_exists($storagePublicPath) && is_file($storagePublicPath)) {
        return response()->file($storagePublicPath, $corsHeaders);
    }

    $publicPath = public_path('storage/' . $path);
    if (file_exists($publicPath) && is_file($publicPath)) {
        return response()->file($publicPath, $corsHeaders);
    }

    $disk = Storage::disk('public');
    if ($disk->exists($path)) {
        return $disk->response($path, null, $corsHeaders);
    }

    abort(404);
})->where('path', '.*');

$serveWebApp = function () {
    $indexPath = public_path('index.html');
    if (file_exists($indexPath)) {
        return response()->file($indexPath);
    }

    return view('welcome');
};

Route::get('/', $serveWebApp);

Route::get('/{any}', $serveWebApp)
    ->where('any', '^(?!api|storage|download-apk|pkl-monitoring\.apk).*$');
