<?php

namespace TearoomOne\FtpBackup\Tests;

use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;

/**
 * The cron script run.php reads its options and the Kirby root from the command line
 */
class RunScriptTest extends PluginTestCase
{
    private string $scriptDir;

    protected function setUp(): void
    {
        parent::setUp();

        // a Kirby installation in the test root, with this plugin and FTP switched off
        $pluginDir = dirname(__DIR__);
        symlink($pluginDir . '/kirby', $this->root . '/kirby');
        Dir::make($this->root . '/site/plugins');
        symlink($pluginDir, $this->root . '/site/plugins/kirby-ftp-backup');
        F::write(
            $this->root . '/site/config/config.php',
            "<?php return ['tearoom1.kirby-ftp-backup.ftpEnabled' => false];"
        );
        F::write($this->contentDir . '/site.txt', 'Title: Test');

        // a copy of the script outside that installation, so only the explicit path can lead there
        $this->scriptDir = $this->root . '/elsewhere/site/plugins/kirby-ftp-backup';
        Dir::make($this->scriptDir);
        copy($pluginDir . '/run.php', $this->scriptDir . '/run.php');
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runScript(string $phpOptions, string ...$arguments): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . $phpOptions . ' '
            . escapeshellarg($this->scriptDir . '/run.php');

        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        exec($command . ' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    public static function phpOptions(): array
    {
        return [
            'default settings' => [''],
            // $_SERVER is empty then, the arguments are only in $argv
            'variables_order without S' => ['-d variables_order=GPC'],
        ];
    }

    /**
     * @dataProvider phpOptions
     */
    public function testBacksUpTheExplicitKirbyRoot(string $phpOptions): void
    {
        [$exitCode, $output] = $this->runScript($phpOptions, '--verbose', $this->root);

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('Backup created successfully', $output);
        $this->assertNotEmpty(glob($this->backupDir . '/*.zip'));
    }

    /**
     * @dataProvider phpOptions
     */
    public function testRejectsUnknownOptions(string $phpOptions): void
    {
        [$exitCode, $output] = $this->runScript($phpOptions, '--bogus', $this->root);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unknown option: --bogus', $output);
    }
}
