<?php

namespace PrmGoogleDrive;

/**
 * Eklentiye gömülü OAuth istemcisi.
 * Kuran admin Cloud Console açmaz — sadece "Google ile bağlan"a basar.
 *
 * Bu Client ID/Secret'ı eklenti sahibi (sen) BİR KEZ Google Cloud'da oluşturur,
 * buraya (veya panel env'ye) yazar. Her kurulumda aynı kalır.
 *
 * Env öncelikli: PRM_GDRIVE_CLIENT_ID / PRM_GDRIVE_CLIENT_SECRET
 */
class AppCredentials
{
    // --- BİR KEZ DOLDUR (veya docker-compose / panel env) ---
    // Git'e secret koyma. Yerelde PRM_GDRIVE_CLIENT_ID / PRM_GDRIVE_CLIENT_SECRET kullan.
    public const CLIENT_ID = '';
    public const CLIENT_SECRET = '';

    public static function clientId(): string
    {
        $env = trim((string) (getenv('PRM_GDRIVE_CLIENT_ID') ?: ($_ENV['PRM_GDRIVE_CLIENT_ID'] ?? '')));
        if ($env !== '') {
            return $env;
        }

        return trim(self::CLIENT_ID);
    }

    public static function clientSecret(): string
    {
        $env = trim((string) (getenv('PRM_GDRIVE_CLIENT_SECRET') ?: ($_ENV['PRM_GDRIVE_CLIENT_SECRET'] ?? '')));
        if ($env !== '') {
            return $env;
        }

        return trim(self::CLIENT_SECRET);
    }

    public static function ready(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function redirectUri(): string
    {
        return url('/extensions/prmgoogledrive/oauth/callback');
    }

    /**
     * Runtime settings üzerine gömülü credentials uygula.
     */
    public static function apply(array $settings): array
    {
        $settings['client_id'] = self::clientId() ?: ($settings['client_id'] ?? '');
        $settings['client_secret'] = self::clientSecret() ?: ($settings['client_secret'] ?? '');
        $settings['redirect_uri'] = self::redirectUri();

        return $settings;
    }
}
