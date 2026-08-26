<?php
// jwtparser.php -- Peteramati class for creating JSON Web Tokens
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

// Partial port of HotCRP's lib/jwtparser.php. HotCRP's version parses and
// verifies JWTs and can create them signed with `none` or HMAC; it has no RSA
// signer, which is what GitHub App authentication needs. `make_rsa` below is
// written to drop into HotCRP's class unchanged -- it uses the same
// `$openssl_alg_map` that class already carries for verification. Peteramati
// uses no namespace, so this copy has none.

class JWTParser {
    static private $openssl_alg_map = [
        "RS256" => "sha256WithRSAEncryption",
        "RS384" => "sha384WithRSAEncryption",
        "RS512" => "sha512WithRSAEncryption"
    ];

    /** @param object $payload
     * @param string|resource|OpenSSLAsymmetricKey $key
     * @param 'RS256'|'RS384'|'RS512' $alg
     * @return ?string */
    static function make_rsa($payload, $key, $alg = "RS256") {
        assert(isset(self::$openssl_alg_map[$alg]));
        if (is_string($key)
            && !($key = openssl_pkey_get_private($key))) {
            return null;
        }
        $jose = '{"alg":"' . $alg . '","typ":"JWT"}';
        $s = base64url_encode($jose) . "." . base64url_encode(json_encode_db($payload));
        if (!openssl_sign($s, $signature, $key, self::$openssl_alg_map[$alg])) {
            return null;
        }
        return $s . "." . base64url_encode($signature);
    }
}
