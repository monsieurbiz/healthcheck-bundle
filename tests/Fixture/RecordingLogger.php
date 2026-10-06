<?php

declare(strict_types=1);

namespace MonsieurBiz\HealthcheckBundle\Tests\Fixture;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (null === $value || is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        $this->records[] = [
            'level' => $level,
            'message' => strtr((string) $message, $replacements),
            'context' => $context,
        ];
    }
}
