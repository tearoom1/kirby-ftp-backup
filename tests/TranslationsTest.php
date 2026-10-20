<?php

namespace TearoomOne\FtpBackup\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every Panel text exists in every language
 */
class TranslationsTest extends TestCase
{
    public function testAllLanguagesHaveTheSameKeys(): void
    {
        $translations = require dirname(__DIR__) . '/src/translations.php';

        $this->assertEqualsCanonicalizing(array_keys($translations['en']), array_keys($translations['de']));
    }

    public function testEveryKeyUsedInThePanelViewExists(): void
    {
        $translations = require dirname(__DIR__) . '/src/translations.php';
        $view = file_get_contents(dirname(__DIR__) . '/js/components/FtpBackupView.vue');

        preg_match_all("/(?<![\\$\\w])t\\('([a-zA-Z.]+)'/", $view, $matches);
        // Keys built at runtime
        $dynamic = [
            'test.step.settings', 'test.step.connect', 'test.step.write', 'test.step.list',
            'test.ok.settings', 'test.ok.connect', 'test.ok.write', 'test.ok.list',
            'lastRun.warning', 'lastRun.error', 'lastRun.cancelled',
        ];

        $used = array_unique([...$matches[1], ...$dynamic]);
        $this->assertNotEmpty($matches[1]);
        foreach ($used as $key) {
            if (str_ends_with($key, '.')) {
                continue;
            }
            $this->assertArrayHasKey('tearoom1.ftp-backup.' . $key, $translations['en'], "Missing translation: {$key}");
        }
    }
}
