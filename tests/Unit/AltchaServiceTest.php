<?php

declare(strict_types=1);

namespace Machma\Forms\Tests\Unit;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Hasher\Algorithm;
use Machma\Forms\Security\AltchaService;
use Machma\Forms\Storage\Database;
use PHPUnit\Framework\TestCase;

final class AltchaServiceTest extends TestCase
{
    private function solved(AltchaService $svc, string $site, int $now): string
    {
        $ch = $svc->create($site, $now);
        $sol = (new Altcha('x'))->solveChallenge($ch['challenge'], $ch['salt'], Algorithm::SHA256, $ch['maxnumber']);
        return base64_encode(json_encode(['algorithm' => 'SHA-256', 'challenge' => $ch['challenge'], 'number' => $sol->number, 'salt' => $ch['salt'], 'signature' => $ch['signature']]));
    }

    public function testValidOnceThenReplayRejected(): void
    {
        $svc = new AltchaService('key', new Database(':memory:'), 500);
        $now = time();
        $payload = $this->solved($svc, 'beispielhotel', $now);
        self::assertSame($now, AltchaService::issuedAt($payload));
        self::assertTrue($svc->verify($payload, 'beispielhotel', $now + 5));
        self::assertFalse($svc->verify($payload, 'beispielhotel', $now + 6), 'replay');
    }

    public function testBoundToSiteKeyAndTime(): void
    {
        $db = new Database(':memory:');
        $svc = new AltchaService('key', $db, 500);
        $now = time();
        self::assertFalse($svc->verify($this->solved($svc, 'a', $now), 'b', $now), 'other site');
        self::assertFalse((new AltchaService('other-key', $db, 500))->verify($this->solved($svc, 'a', $now), 'a', $now), 'other key');
        self::assertFalse($svc->verify($this->solved($svc, 'a', $now), 'a', $now + 601), 'expired');
    }

    public function testGarbageIsRejected(): void
    {
        $svc = new AltchaService('key', new Database(':memory:'), 500);
        foreach ([null, '', 'abc', base64_encode('{"x":1}'), ['array'], str_repeat('a', 5000)] as $bad) {
            self::assertFalse($svc->verify($bad, 'a', time()));
        }
    }

    public function testTamperedNumberRejected(): void
    {
        $svc = new AltchaService('key', new Database(':memory:'), 500);
        $data = json_decode(base64_decode($this->solved($svc, 'a', time())), true);
        $data['number'] = ($data['number'] + 1) % 500;
        self::assertFalse($svc->verify(base64_encode(json_encode($data)), 'a', time()));
    }
}
