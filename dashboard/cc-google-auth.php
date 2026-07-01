<?php
/**
 * Google API auth helper — JWT-based service account token.
 * Forked from VALN dashboard. Shared by GSC and GA4 tabs.
 */

if (!function_exists('cc_get_google_token')) {

function cc_get_google_token(): ?string {
    $cache_file = __DIR__ . '/data/gsc_token_cache.json';
    if (file_exists($cache_file)) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    $creds_file = __DIR__ . '/data/gsc-credentials.json';
    if (!file_exists($creds_file)) return null;
    $creds = json_decode(file_get_contents($creds_file), true);
    if (!$creds) return null;

    $header = cc_base64url_encode(json_encode(['alg'=>'RS256','typ'=>'JWT']));
    $now = time();
    $claim = cc_base64url_encode(json_encode([
        'iss' => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ]));

    $signing_input = "$header.$claim";
    $pk = openssl_pkey_get_private($creds['private_key']);
    if (!$pk) return null;

    openssl_sign($signing_input, $signature, $pk, OPENSSL_ALGO_SHA256);
    $jwt = "$header.$claim." . cc_base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=' . urlencode('urn:ietf:params:oauth:grant-type:jwt-bearer') . '&assertion=' . $jwt,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!isset($data['access_token'])) return null;

    @file_put_contents($cache_file, json_encode([
        'access_token' => $data['access_token'],
        'expires_at' => $now + ($data['expires_in'] ?? 3600)
    ]));

    return $data['access_token'];
}

function cc_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

} // end if !function_exists
