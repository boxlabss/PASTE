<?php
/*
 * Server-side verification for Cloudflare Turnstile.
 */

if (!function_exists('require_human_turnstile')) {
    function require_human_turnstile($action = '') {
        global $error; // For setting global error if verification fails

        $token = $_POST['cf-turnstile-response'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $secret = $_SESSION['turnstile_secretkey'] ?? '';

        if (empty($token)) {
            $error = 'Turnstile token missing. Please try again.';
            error_log("Turnstile: Token missing for action '$action'");
            return false;
        }

        if (empty($secret)) {
            $error = 'Turnstile configuration error.';
            error_log("Turnstile: Secret key missing for action '$action'");
            return false;
        }

        // Verify via Cloudflare API
        $data = [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ];

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Prevent hangs
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $error = 'Turnstile verification failed due to network error.';
            error_log("Turnstile: cURL error for action '$action': $curl_error");
            return false;
        }

        $result = json_decode($response, true);

        if (!$result || !$result['success']) {
            $error_codes = implode(', ', $result['error-codes'] ?? ['unknown']);
            $error = 'Turnstile verification failed.';
            error_log("Turnstile: Verification failed for action '$action'. Errors: $error_codes");
            return false;
        }

        // Check action if provided (Turnstile supports custom actions)
        if ($action && isset($result['action']) && $result['action'] !== $action) {
            $error = 'Turnstile action mismatch.';
            error_log("Turnstile: Action mismatch for '$action'. Received: " . ($result['action'] ?? 'none'));
            return false;
        }

        return true;
    }
}