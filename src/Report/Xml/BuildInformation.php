<?php declare(strict_types=1);
/*
 * This file is part of phpunit/php-code-coverage.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace SebastianBergmann\CodeCoverage\Report\Xml;

use XMLWriter;
use function phpversion;
use DateTimeImmutable;
use SebastianBergmann\Environment\Runtime;

/**
 * @internal This class is not covered by the backward compatibility promise for phpunit/php-code-coverage
 */
final readonly class BuildInformation
{
    private Runtime $runtime;
    private DateTimeImmutable $buildTime;
    private string $phpunitVersion;
    private string $coverageVersion;

    public function setRuntimeInformation(Runtime $runtime): void
    {
        $this->runtime = $runtime;
    }

    public function setBuildTime(DateTimeImmutable $date): void
    {
        $this->buildTime = $date;
    }

    public function setGeneratorVersions(string $phpUnitVersion, string $coverageVersion): void
    {
        $this->phpunitVersion = $phpUnitVersion;
        $this->coverageVersion = $coverageVersion;
    }

    public function write(XMLWriter $writer): void
    {
        $writer->startElement('build');
        $writer->writeAttribute('time', $this->buildTime->format('D M j G:i:s T Y'));
        $writer->writeAttribute('phpunit', $this->phpunitVersion);
        $writer->writeAttribute('coverage', $this->coverageVersion);

        $writer->startElement('runtime');
        $writer->writeAttribute('name', $this->runtime->getName());
        $writer->writeAttribute('version', $this->runtime->getVersion());
        $writer->writeAttribute('url', $this->runtime->getVendorUrl());
        $writer->endElement();

        $writer->startElement('driver');
        if ($this->runtime->hasXdebug()) {
            $writer->writeAttribute('name', 'xdebug');
            $writer->writeAttribute('version', phpversion('xdebug'));
        }
        if ($this->runtime->hasPCOV()) {
            $writer->writeAttribute('name', 'pcov');
            $writer->writeAttribute('version', phpversion('pcov'));
        }
        $writer->endElement();

        $writer->endElement();
    }

}
