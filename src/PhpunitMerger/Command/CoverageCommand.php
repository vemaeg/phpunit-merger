<?php

declare(strict_types=1);

namespace Nimut\PhpunitMerger\Command;

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter as CodeCoverageFilter;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Cobertura;
use SebastianBergmann\CodeCoverage\Report\Html\Facade;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class CoverageCommand extends Command
{
    protected function configure()
    {
        $this->setName('coverage')
            ->setDescription('Merges multiple PHPUnit coverage php files into one')
            ->addArgument(
                'directory',
                InputArgument::REQUIRED,
                'The directory containing PHPUnit coverage php files'
            )
            ->addArgument(
                'file',
                InputArgument::OPTIONAL,
                'The file where to write the merged result. Default: Standard output'
            )
            ->addOption(
                'html',
                null,
                InputOption::VALUE_REQUIRED,
                'The directory where to write the code coverage report in HTML format'
            )
            ->addOption(
                'lowUpperBound',
                null,
                InputOption::VALUE_REQUIRED,
                'The lowUpperBound value to be used for HTML format'
            )
            ->addOption(
                'highLowerBound',
                null,
                InputOption::VALUE_REQUIRED,
                'The highLowerBound value to be used for HTML format'
            )
            ->addOption(
                'cobertura',
                null,
                InputOption::VALUE_NONE,
                'Export cobertura instead of clover'
            )
            ->addOption(
                'coverage-cache',
                null,
                InputOption::VALUE_OPTIONAL,
                'The cache directory to be used for the code coverage'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $finder = new Finder();
        $finder->files()
            ->in(realpath($input->getArgument('directory')));

        $codeCoverage = $this->getCodeCoverage($output, $input->getOption('coverage-cache'));

        foreach ($finder as $file) {
            $coverage = require $file->getRealPath();
            if (!$coverage instanceof CodeCoverage) {
                throw new \RuntimeException($file->getRealPath() . ' doesn\'t return a valid ' . CodeCoverage::class . ' object!');
            }
            $codeCoverage->merge($coverage);
        }

        $this->writeCodeCoverage($codeCoverage,$output, $input->getArgument('file'), $input->getOption('cobertura') ?? false);
        $html = $input->getOption('html');
        if ($html !== null) {
            $lowUpperBound = (int)($input->getOption('lowUpperBound') ?: 50);
            $highLowerBound = (int)($input->getOption('highLowerBound') ?: 90);
            $this->writeHtmlReport($codeCoverage, $html, $lowUpperBound, $highLowerBound);
        }

        return 0;
    }

    private function getCodeCoverage(OutputInterface $output, $coverageCache = null)
    {
        $driver = null;
        $filter = null;
        if (method_exists(Driver::class, 'forLineCoverage')) {
            $filter = new CodeCoverageFilter();
            $driver = Driver::forLineCoverage($filter);
        }

        $codeCoverage = new CodeCoverage($driver, $filter);

        if ($coverageCache) {
            $output->writeln('Using directory ' . $coverageCache . ' as coverage cache...');
            $codeCoverage->cacheStaticAnalysis($coverageCache);
        }

        return $codeCoverage;
    }

    private function writeCodeCoverage(CodeCoverage $codeCoverage, OutputInterface $output, $file = null, bool $cobertura = false)
    {
        if ($cobertura) {
            if (!class_exists(Cobertura::class)) {
                $output->writeln('Cobertura writer not found. Are you using a too old phpunit version? You need at least version 9.4.');
                exit(1);
            }
            $writer = new Cobertura();
        } else {
            $writer = new Clover();
        }

        $buffer = $writer->process($codeCoverage, $file);
        if ($file === null) {
            $output->write($buffer);
        }
    }

    private function writeHtmlReport(CodeCoverage $codeCoverage, string $destination, int $lowUpperBound, int $highLowerBound)
    {
        $writer = new Facade($lowUpperBound, $highLowerBound);
        $writer->process($codeCoverage, $destination);
    }
}
