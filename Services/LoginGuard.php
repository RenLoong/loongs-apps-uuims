<?php

declare(strict_types=1);

namespace App\Uuims\Services;

/**
 * Login form support (Redis): one-time form nonce bound to a captcha, and per-username failure
 * counter with temporary lock. Keys: uuims:login:nonce:<nonce>, uuims:login:fail:<username>.
 */
final class LoginGuard
{
    private const CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly int $maxFailures = 5,
        private readonly int $lockSeconds = 900,
        private readonly int $captchaTtl = 300,
    ) {
    }

    /**
     * @param bool $png also render a PNG (mini program &lt;image&gt; cannot show SVG data URIs)
     * @return array{nonce: string, svg: string, png?: string}
     */
    public function issue(bool $png = false): array
    {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= self::CHARS[random_int(0, strlen(self::CHARS) - 1)];
        }
        $nonce = bin2hex(random_bytes(16));
        redis()->run(fn (\Redis $r) => $r->setex('uuims:login:nonce:' . $nonce, $this->captchaTtl, $code));

        $out = ['nonce' => $nonce, 'svg' => self::svg($code)];
        if ($png) {
            $out['png'] = self::png($code);
        }

        return $out;
    }

    /** One-time: the nonce is deleted whether or not the captcha matches. */
    public function consume(string $nonce, string $captcha): string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $nonce) !== 1) {
            return 'expired';
        }
        $code = redis()->run(static function (\Redis $r) use ($nonce): mixed {
            $k = 'uuims:login:nonce:' . $nonce;
            $v = $r->get($k);
            $r->del($k);

            return $v;
        });
        if (!is_string($code)) {
            return 'expired';
        }

        return hash_equals(strtoupper($code), strtoupper(trim($captcha))) ? 'ok' : 'captcha';
    }

    public function lockedFor(string $username): int
    {
        $key = self::failKey($username);
        [$count, $ttl] = redis()->run(static fn (\Redis $r): array => [(int) $r->get($key), (int) $r->ttl($key)]);

        return $count >= $this->maxFailures ? max(1, $ttl) : 0;
    }

    /** @return int remaining attempts before lock */
    public function fail(string $username): int
    {
        $key = self::failKey($username);
        $count = redis()->run(function (\Redis $r) use ($key): int {
            $n = (int) $r->incr($key);
            if ($n === 1) {
                $r->expire($key, $this->lockSeconds);
            }

            return $n;
        });

        return max(0, $this->maxFailures - $count);
    }

    public function clear(string $username): void
    {
        $key = self::failKey($username);
        redis()->run(static fn (\Redis $r) => $r->del($key));
    }

    private static function failKey(string $username): string
    {
        return 'uuims:login:fail:' . hash('sha256', strtolower(trim($username)));
    }

    /** 120×40 PNG (GD, built-in font drawn at half size, scaled up, with noise). */
    private static function png(string $code): string
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD extension required for PNG captcha');
        }
        $small = imagecreatetruecolor(60, 20);
        imagefill($small, 0, 0, imagecolorallocate($small, 244, 244, 245));
        foreach (str_split($code) as $i => $ch) {
            $c = imagecolorallocate($small, random_int(20, 110), random_int(20, 110), random_int(60, 140));
            imagestring($small, 5, 5 + $i * 13 + random_int(-1, 1), 2 + random_int(-2, 2), $ch, $c);
        }
        $img = imagecreatetruecolor(120, 40);
        imagecopyresampled($img, $small, 0, 0, 0, 0, 120, 40, 60, 20);
        for ($i = 0; $i < 5; $i++) {
            $c = imagecolorallocate($img, random_int(120, 200), random_int(120, 200), random_int(120, 200));
            imageline($img, random_int(0, 120), random_int(0, 40), random_int(0, 120), random_int(0, 40), $c);
        }
        for ($i = 0; $i < 60; $i++) {
            imagesetpixel($img, random_int(0, 119), random_int(0, 39), imagecolorallocate($img, random_int(100, 220), random_int(100, 220), random_int(100, 220)));
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private static function svg(string $code): string
    {
        $w = 120;
        $h = 40;
        $parts = [];
        for ($i = 0; $i < 5; $i++) {
            $parts[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="hsl(%d,45%%,60%%)" stroke-width="1"/>',
                random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), random_int(0, 359));
        }
        for ($i = 0; $i < 20; $i++) {
            $parts[] = sprintf('<circle cx="%d" cy="%d" r="1" fill="hsl(%d,40%%,55%%)"/>', random_int(0, $w), random_int(0, $h), random_int(0, 359));
        }
        foreach (str_split($code) as $i => $ch) {
            $x = 14 + $i * 26 + random_int(-3, 3);
            $y = 28 + random_int(-4, 4);
            $parts[] = sprintf('<text x="%d" y="%d" font-size="24" font-family="monospace" font-weight="bold" fill="hsl(%d,55%%,35%%)" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, random_int(0, 359), random_int(-25, 25), $x, $y, $ch);
        }

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d"><rect width="100%%" height="100%%" fill="#f4f4f5"/>%s</svg>',
            $w, $h, $w, $h, implode('', $parts));
    }
}
