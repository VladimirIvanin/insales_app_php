<?php

declare(strict_types=1);

namespace InSales;

/**
 * InSales protocol formulas.
 *
 * Ported from the verified protocol described in LiquidHub docs and
 * cross-validated with Python demo.
 *
 * @see https://github.com/insales/insales_app (rev 78593a4)
 * @see https://github.com/VladimirIvanin/insales_app_python (rev c152f4f)
 */
final readonly class Protocol
{
    /**
     * Compute a store password from the install token and app secret.
     *
     * formula: password = MD5(token + secret)
     * Note: concatenation WITHOUT separator, lowercase hex.
     */
    public static function installPassword(string $token, string $appSecret): string
    {
        return md5($token . $appSecret);
    }

    /**
     * Compute token3 for autologin verification.
     *
     * formula: token3 = MD5(nonce + user_email + user_name + user_id + email_confirmed + password)
     *
     * IMPORTANT: email_confirmed is the literal string "true" or "false",
     * not PHP boolean. user_name may be empty string. UTF-8 matters.
     * All fields concatenated WITHOUT separator, lowercase hex.
     */
    public static function token3(
        string $nonce,
        string $userEmail,
        string $userName,
        string $userId,
        string $emailConfirmed,
        string $password,
    ): string {
        return md5($nonce . $userEmail . $userName . $userId . $emailConfirmed . $password);
    }

    /**
     * Compute token2 (available for compatibility checks).
     * formula: token2 = MD5(nonce + user_email + user_name + user_id + password)
     */
    public static function token2(
        string $nonce,
        string $userEmail,
        string $userName,
        string $userId,
        string $password,
    ): string {
        return md5($nonce . $userEmail . $userName . $userId . $password);
    }

    /**
     * Compute token (basic login token).
     * formula: token = MD5(nonce + password)
     */
    public static function token(
        string $nonce,
        string $password,
    ): string {
        return md5($nonce . $password);
    }
}