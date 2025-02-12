<?php
/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\Tests\TestFramework\PhpUnit\Config\Builder;

use DOMDocument;
use DOMNodeList;
use DOMXPath;
use Infection\TestFramework\PhpUnit\Config\Builder\InitialConfigBuilder;
use Infection\TestFramework\PhpUnit\Config\InvalidPhpUnitConfiguration;
use Infection\TestFramework\PhpUnit\Config\Path\PathReplacer;
use Infection\TestFramework\PhpUnit\Config\XmlConfigurationManipulator;
use Infection\TestFramework\PhpUnit\Config\XmlConfigurationVersionProvider;
use Infection\Tests\FileSystem\FileSystemTestCase;
use function Infection\Tests\normalizePath as p;
use InvalidArgumentException;
use const PHP_EOL;
use function Safe\file_get_contents;
use function Safe\realpath;
use function Safe\simplexml_load_string;
use function sprintf;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @group integration
 */
final class InitialConfigBuilderTest extends FileSystemTestCase
{
    private const FIXTURES = __DIR__ . '/../../../../Fixtures/Files/phpunit';

    /**
     * @var string
     */
    private $projectPath;

    /**
     * @var InitialConfigBuilder
     */
    private $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = p(realpath(self::FIXTURES . '/project-path'));

        $this->builder = $this->createConfigBuilder();
    }

    public function test_it_builds_and_dump_the_xml_configuration(): void
    {
        $configurationPath = $this->builder->build('6.5');

        $this->assertSame(
            $this->tmp . '/phpunitConfiguration.initial.infection.xml',
            $configurationPath
        );

        $this->assertFileExists($configurationPath);

        $xml = file_get_contents($configurationPath);

        $this->assertNotFalse(
            @simplexml_load_string($xml),
            'Expected dumped configuration content to be a valid XML file.'
        );
    }

    public function test_it_preserves_white_spaces_and_formatting(): void
    {
        if (PHP_EOL === "\r\n") {
            $this->markTestSkipped('Test fixture uses Unix line endings');
        }

        $builder = $this->createConfigBuilder(
            self::FIXTURES . '/format-whitespace/original-phpunit.xml'
        );

        $configurationPath = $builder->build('6.5');

        $this->assertFileEquals(
            self::FIXTURES . '/format-whitespace/expected-phpunit.xml',
            $configurationPath
        );
    }

    public function test_the_original_xml_config_must_be_a_valid_xml_file(): void
    {
        try {
            $this->createConfigBuilder(
                self::FIXTURES . '/invalid/empty-phpunit.xml'
            );

            $this->fail('Expected an exception to be thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The original XML config content cannot be an empty string',
                $exception->getMessage()
            );
        }
    }

    public function test_the_original_xml_config_must_be_a_valid_phpunit_config_file(): void
    {
        $builder = $this->createConfigBuilder(
            self::FIXTURES . '/invalid/invalid-phpunit.xml'
        );

        try {
            $builder->build('x');

            $this->fail('Expected an exception to be thrown.');
        } catch (InvalidPhpUnitConfiguration $exception) {
            $this->assertSame(
                sprintf(
                    'The file "%s/phpunitConfiguration.initial.infection.xml" is not a valid PHPUnit configuration file',
                    $this->tmp
                ),
                $exception->getMessage()
            );
        }
    }

    public function test_it_replaces_relative_path_to_absolute_path(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $directories = $this->queryXpath($xml, '/phpunit/testsuites/testsuite/directory');

        $this->assertInstanceOf(DOMNodeList::class, $directories);

        $this->assertSame(1, $directories->length);
        $this->assertSame($this->projectPath . '/*Bundle', p($directories[0]->nodeValue));
    }

    public function test_it_sets_stops_on_failure(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $stopOnFailure = $this->queryXpath($xml, '/phpunit/@stopOnFailure')[0]->nodeValue;

        $this->assertSame('true', $stopOnFailure);
    }

    public function test_it_deactivates_the_colors(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $colors = $this->queryXpath($xml, '/phpunit/@colors')[0]->nodeValue;

        $this->assertSame('false', $colors);
    }

    public function test_it_disables_caching(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $cacheResult = $this->queryXpath($xml, '/phpunit/@cacheResult')[0]->nodeValue;

        $this->assertSame('false', $cacheResult);
    }

    public function test_it_deactivates_stderr_redirection(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $stdErr = $this->queryXpath($xml, '/phpunit/@stderr')[0]->nodeValue;

        $this->assertSame('false', $stdErr);
    }

    public function test_it_replaces_bootstrap_file(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $bootstrap = p($this->queryXpath($xml, '/phpunit/@bootstrap')[0]->nodeValue);

        $this->assertSame($this->projectPath . '/app/autoload2.php', $bootstrap);
    }

    public function test_it_removes_original_loggers(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $logEntries = $this->queryXpath($xml, '/phpunit/logging/log[@type="coverage-html"]');

        $this->assertSame(0, $logEntries->length);
    }

    public function test_it_does_not_add_coverage_loggers_ever_for_legacy_configuration(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $logEntries = $this->queryXpath($xml, '/phpunit/logging/log');

        $this->assertInstanceOf(DOMNodeList::class, $logEntries);

        $this->assertSame(0, $logEntries->length);
    }

    public function test_it_does_not_add_coverage_loggers_ever_for_latest_configuration(): void
    {
        $builder = $this->createConfigBuilderForPHPUnit93();

        $xml = file_get_contents($builder->build('9.4'));

        $logEntries = $this->queryXpath($xml, '/phpunit/logging');

        $this->assertInstanceOf(DOMNodeList::class, $logEntries);

        $this->assertSame(0, $logEntries->length);
    }

    public function test_it_creates_coverage_filter_whitelist_node_if_does_not_exist(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_without_coverage_whitelist.xml';

        $xml = file_get_contents($this->createConfigBuilder($phpunitXmlPath)->build('6.5'));

        $whitelistedDirectories = $this->queryXpath($xml, '/phpunit/filter/whitelist/directory');

        $this->assertInstanceOf(DOMNodeList::class, $whitelistedDirectories);

        $this->assertSame(2, $whitelistedDirectories->length);
    }

    public function test_it_creates_coverage_filter_whitelist_node_if_does_not_exist_and_version_situation_is_uncertain(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_without_coverage_whitelist.xml';

        $xml = file_get_contents($this->createConfigBuilder($phpunitXmlPath)->build('9.3'));

        $whitelistedDirectories = $this->queryXpath($xml, '/phpunit/filter/whitelist/directory');

        $this->assertInstanceOf(DOMNodeList::class, $whitelistedDirectories);

        $this->assertSame(2, $whitelistedDirectories->length);
    }

    public function test_it_replaces_coverage_filter_include_node_if_exists_but_filtered_source_files_provided(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_with_coverage_include_directories.xml';

        $xml = file_get_contents($this->createConfigBuilder($phpunitXmlPath, ['src/File1.php'])->build('9.3'));

        $coverageIncludeFiles = $this->queryXpath($xml, '/phpunit/coverage/include/file');

        $this->assertInstanceOf(DOMNodeList::class, $coverageIncludeFiles);

        $this->assertSame(1, $coverageIncludeFiles->length);
    }

    public function test_it_creates_coverage_include_node_if_does_not_exist_for_10_0_version_of_phpunit(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_without_coverage_whitelist.xml';

        $xml = file_get_contents($this->createConfigBuilder($phpunitXmlPath)->build('10.0'));

        $includedDirectories = $this->queryXpath($xml, '/phpunit/coverage/include/directory');

        $this->assertInstanceOf(DOMNodeList::class, $includedDirectories);

        $this->assertSame(2, $includedDirectories->length);
    }

    public function test_it_does_not_create_legacy_coverage_filter_whitelist_node_for_10_0_version_of_phpunit(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_without_coverage_whitelist.xml';

        $xml = file_get_contents($this->createConfigBuilder($phpunitXmlPath)->build('10.0'));

        $whitelistedDirectories = $this->queryXpath($xml, '/phpunit/filter/whitelist/directory');

        $this->assertInstanceOf(DOMNodeList::class, $whitelistedDirectories);

        $this->assertSame(0, $whitelistedDirectories->length);
    }

    public function test_it_creates_source_include_node_if_does_not_exist_for_10_1_version_of_phpunit(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_without_coverage_whitelist.xml';

        $xml = file_get_contents($this->createConfigBuilder($phpunitXmlPath)->build('10.1'));

        $includedDirectories = $this->queryXpath($xml, '/phpunit/source/include/directory');

        $this->assertInstanceOf(DOMNodeList::class, $includedDirectories);

        $this->assertSame(2, $includedDirectories->length);
    }

    public function test_it_does_not_create_coverage_filter_whitelist_node_if_already_exist(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $whitelistedDirectories = $this->queryXpath($xml, '/phpunit/filter/whitelist/directory');

        $this->assertInstanceOf(DOMNodeList::class, $whitelistedDirectories);

        $this->assertSame(1, $whitelistedDirectories->length);
    }

    public function test_it_does_not_create_coverage_include_node_if_already_exist(): void
    {
        $builder = $this->createConfigBuilderForPHPUnit93();

        $xml = file_get_contents($builder->build('9.4'));

        $includedDirectories = $this->queryXpath($xml, '/phpunit/coverage/include');

        $this->assertInstanceOf(DOMNodeList::class, $includedDirectories);

        $this->assertSame(1, $includedDirectories->length);
    }

    public function test_it_removes_printer_class(): void
    {
        $xml = file_get_contents($this->builder->build('6.5'));

        $printerClass = $this->queryXpath($xml, '/phpunit/@printerClass');

        $this->assertInstanceOf(DOMNodeList::class, $printerClass);

        $this->assertSame(0, $printerClass->length);
    }

    /**
     * @dataProvider executionOrderProvider
     */
    public function test_it_adds_execution_order_for_proper_phpunit_versions(
        string $version,
        string $attributeName,
        int $expectedNodeCount
    ): void {
        $xml = file_get_contents($this->builder->build($version));

        $nodes = $this->queryXpath($xml, sprintf('/phpunit/@%s', $attributeName));

        $this->assertInstanceOf(DOMNodeList::class, $nodes);

        $this->assertSame($expectedNodeCount, $nodes->length);
    }

    public function test_it_does_not_update_order_if_it_is_already_set(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_with_order_set.xml';

        $builder = $this->createConfigBuilder($phpunitXmlPath);

        $xml = file_get_contents($builder->build('7.2'));

        $executionOrder = $this->queryXpath($xml, sprintf('/phpunit/@%s', 'executionOrder'));

        $this->assertInstanceOf(DOMNodeList::class, $executionOrder);
        $this->assertSame('reverse', $executionOrder[0]->value);

        $resolveDependencies = $this->queryXpath($xml, sprintf('/phpunit/@%s', 'resolveDependencies'));

        $this->assertInstanceOf(DOMNodeList::class, $resolveDependencies);
        $this->assertSame(0, $resolveDependencies->length);
    }

    /**
     * @dataProvider failOnProvider
     */
    public function test_it_adds_fail_on_risky_and_warning_for_proper_phpunit_versions(
        string $version,
        string $attributeName,
        int $expectedNodeCount
    ): void {
        $xml = file_get_contents($this->builder->build($version));

        $nodes = $this->queryXpath($xml, sprintf('/phpunit/@%s', $attributeName));

        $this->assertInstanceOf(DOMNodeList::class, $nodes);

        $this->assertSame($expectedNodeCount, $nodes->length);
    }

    public function test_it_does_not_update_fail_on_risky_attributes_if_it_is_already_set(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_with_fail_on_risky_set.xml';

        $builder = $this->createConfigBuilder($phpunitXmlPath);

        $xml = file_get_contents($builder->build('5.2'));

        $failOnRisky = $this->queryXpath($xml, sprintf('/phpunit/@%s', 'failOnRisky'));

        $this->assertInstanceOf(DOMNodeList::class, $failOnRisky);
        $this->assertSame('false', $failOnRisky[0]->value);
    }

    public function test_it_does_not_update_fail_on_warning_attributes_if_it_is_already_set(): void
    {
        $phpunitXmlPath = self::FIXTURES . '/phpunit_with_fail_on_warning_set.xml';

        $builder = $this->createConfigBuilder($phpunitXmlPath);

        $xml = file_get_contents($builder->build('5.2'));

        $failOnRisky = $this->queryXpath($xml, sprintf('/phpunit/@%s', 'failOnWarning'));

        $this->assertInstanceOf(DOMNodeList::class, $failOnRisky);
        $this->assertSame('false', $failOnRisky[0]->value);
    }

    public function test_it_creates_a_configuration(): void
    {
        $builder = $this->createConfigBuilder(
            self::FIXTURES . '/phpunit.xml'
        );

        $configurationPath = $builder->build('6.5');

        $projectPath = $this->projectPath;

        $this->assertSame(
            <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!--
  ~ Copyright © 2017 Maks Rafalko
  ~
  ~ License: https://opensource.org/licenses/BSD-3-Clause New BSD License
  -->
<phpunit backupGlobals="false" backupStaticAttributes="false" bootstrap="$projectPath/app/autoload2.php" colors="false" convertErrorsToExceptions="true" convertNoticesToExceptions="true" convertWarningsToExceptions="true" processIsolation="false" syntaxCheck="false" defaultTestSuite="unit" failOnRisky="true" failOnWarning="true" stopOnFailure="true" cacheResult="false" stderr="false">
  <testsuites>
    <testsuite name="Application Test Suite">
      <directory>$projectPath/*Bundle</directory>
    </testsuite>
  </testsuites>
  <filter>
    <whitelist>
      <directory>$projectPath/src/</directory>
      <!--<exclude>-->
      <!--<directory>src/*Bundle/Resources</directory>-->
      <!--<directory>src/*/*Bundle/Resources</directory>-->
      <!--<directory>src/*/Bundle/*Bundle/Resources</directory>-->
      <!--</exclude>-->
    </whitelist>
  </filter>
</phpunit>

XML
            ,
            file_get_contents($configurationPath)
        );
    }

    public function executionOrderProvider(): iterable
    {
        yield 'PHPUnit 7.1.99 runs without random test order' => [
            '7.1.99',
            'executionOrder',
            0,
        ];

        yield 'PHPUnit 7.2 runs with random test order' => [
            '7.2',
            'executionOrder',
            1,
        ];

        yield 'PHPUnit 7.3.1 runs with random test order' => [
            '7.3.1',
            'executionOrder',
            1,
        ];

        yield 'PHPUnit 7.1.99 runs without dependency resolver' => [
            '7.1.99',
            'resolveDependencies',
            0,
        ];

        yield 'PHPUnit 7.2 runs with dependency resolver' => [
            '7.2',
            'resolveDependencies',
            1,
        ];

        yield 'PHPUnit 7.3.1 runs dependency resolver' => [
            '7.3.1',
            'resolveDependencies',
            1,
        ];
    }

    public function failOnProvider(): iterable
    {
        yield 'PHPUnit 5.1.99 runs without failOnRisky' => [
            '5.1.99',
            'failOnRisky',
            0,
        ];

        yield 'PHPUnit 5.2 runs with failOnRisky' => [
            '5.2',
            'failOnRisky',
            1,
        ];

        yield 'PHPUnit 5.3.1 runs with failOnRisky' => [
            '5.3.1',
            'failOnRisky',
            1,
        ];

        yield 'PHPUnit 5.1.99 runs without resolveDependencies' => [
            '5.1.99',
            'failOnWarning',
            0,
        ];

        yield 'PHPUnit 5.2 runs with resolveDependencies' => [
            '5.2',
            'failOnWarning',
            1,
        ];

        yield 'PHPUnit 5.3.1 runs resolveDependencies' => [
            '5.3.1',
            'failOnWarning',
            1,
        ];
    }

    private function queryXpath(string $xml, string $query)
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        return (new DOMXPath($dom))->query($query);
    }

    private function createConfigBuilderForPHPUnit93(): InitialConfigBuilder
    {
        return $this->createConfigBuilder(self::FIXTURES . '/phpunit_93.xml');
    }

    private function createConfigBuilder(
        ?string $originalPhpUnitXmlConfigPath = null,
        array $filteredSourceFilesToMutate = []
    ): InitialConfigBuilder {
        $phpunitXmlPath = $originalPhpUnitXmlConfigPath ?: self::FIXTURES . '/phpunit.xml';

        $srcDirs = ['src', 'app'];

        $replacer = new PathReplacer(new Filesystem(), $this->projectPath);

        return new InitialConfigBuilder(
            $this->tmp,
            file_get_contents($phpunitXmlPath),
            new XmlConfigurationManipulator($replacer, ''),
            new XmlConfigurationVersionProvider(),
            $srcDirs,
            $filteredSourceFilesToMutate
        );
    }
}
