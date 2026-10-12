<?php

namespace Tests\Unit;

use App\Support\AwsCredentials;
use Tests\TestCase;

class AwsCredentialsTest extends TestCase
{
    private const KEY_ID = 'AKIAIOSFODNN7EXAMPLE';

    private const SECRET = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

    protected function tearDown(): void
    {
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    private function setEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    public function test_clean_removes_whitespace_quotes_and_pasted_variable_names(): void
    {
        $this->assertSame(self::KEY_ID, AwsCredentials::clean(' '.self::KEY_ID."\n"));
        $this->assertSame(self::KEY_ID, AwsCredentials::clean('"'.self::KEY_ID.'"'));
        $this->assertSame(self::KEY_ID, AwsCredentials::clean("'".self::KEY_ID."'"));
        $this->assertSame(self::KEY_ID, AwsCredentials::clean('AWS_ACCESS_KEY_ID='.self::KEY_ID));
        $this->assertSame(self::SECRET, AwsCredentials::clean(self::SECRET.'  '));
        $this->assertNull(AwsCredentials::clean('  '));
        $this->assertNull(AwsCredentials::clean(null));
    }

    public function test_diagnose_accepts_valid_values(): void
    {
        $this->setEnv([
            'AWS_ACCESS_KEY_ID' => self::KEY_ID,
            'AWS_SECRET_ACCESS_KEY' => self::SECRET,
            'AWS_DEFAULT_REGION' => 'us-east-2',
            'AWS_BUCKET' => 'snapmanager-prod-files-rafael-990204465512-us-east-2-an',
        ]);

        $this->assertSame(array_fill_keys(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET'], null), AwsCredentials::diagnose());
    }

    public function test_diagnose_detects_swapped_keys_without_showing_values(): void
    {
        $this->setEnv([
            'AWS_ACCESS_KEY_ID' => self::SECRET,
            'AWS_SECRET_ACCESS_KEY' => self::KEY_ID,
            'AWS_DEFAULT_REGION' => 'us-east-2',
            'AWS_BUCKET' => 'meu-bucket',
        ]);

        $result = AwsCredentials::diagnose();

        $this->assertStringContainsString('trocadas', $result['AWS_ACCESS_KEY_ID']);
        $this->assertStringContainsString('trocadas', $result['AWS_SECRET_ACCESS_KEY']);
        $this->assertStringNotContainsString(self::SECRET, implode(' ', array_filter($result)));
        $this->assertStringNotContainsString(self::KEY_ID, implode(' ', array_filter($result)));
    }

    public function test_diagnose_flags_pasted_quotes_and_bad_formats(): void
    {
        $this->setEnv([
            'AWS_ACCESS_KEY_ID' => self::KEY_ID.' ',
            'AWS_SECRET_ACCESS_KEY' => 'curta',
            'AWS_DEFAULT_REGION' => 'Ohio',
            'AWS_BUCKET' => 'Meu Bucket',
        ]);

        $result = AwsCredentials::diagnose();

        $this->assertStringContainsString('espaço', $result['AWS_ACCESS_KEY_ID']);
        $this->assertStringContainsString('5 caracteres', $result['AWS_SECRET_ACCESS_KEY']);
        $this->assertNotNull($result['AWS_DEFAULT_REGION']);
        $this->assertNotNull($result['AWS_BUCKET']);
    }
}
