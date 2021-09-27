<?php

declare(strict_types=1);

namespace Nimut\PhpunitMerger\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class LogCommand extends Command
{
    /**
     * @var \DOMDocument
     */
    private $document;

    /**
     * @var \DOMElement[]
     */
    private $domElements = [];

    protected function configure()
    {
        $this->setName('log')
            ->setDescription('Merges multiple PHPUnit JUnit xml files into one')
            ->addArgument(
                'directory',
                InputArgument::REQUIRED,
                'The directory containing PHPUnit JUnit xml files'
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'The file where to write the merged result'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $finder = new Finder();
        $finder->files()
            ->in(realpath($input->getArgument('directory')));

        $this->document = new \DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;

        $root = $this->document->createElement('testsuites');
        $this->document->appendChild($root);

        foreach ($finder as $file) {
            $output->writeln('Processing file ' . $file . '...');
            try {
                $xml = new \SimpleXMLElement(file_get_contents($file->getRealPath()));
                $xmlArray = json_decode(json_encode($xml), true);
                if (!empty($xmlArray)) {
                    $this->addTestSuites($root, $xmlArray);
                }
            } catch (\Exception $exception) {
                // Initial fallthrough
            }
        }

        foreach ($this->domElements as $domElement) {
            if ($domElement->hasAttribute('parent')) {
                $domElement->removeAttribute('parent');
            }
        }

        $file = $input->getArgument('file');
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0777, true);
        }
        $this->document->save($input->getArgument('file'));

        return 0;
    }

    private function addTestSuites(\DOMElement $parent, array $testSuites)
    {
        foreach ($testSuites as $testSuite) {
            if (empty($testSuite['@attributes']['name'])) {
                if (!empty($testSuite['testsuite'])) {
                    $children = isset($testSuite['testsuite']['@attributes']) ? [$testSuite['testsuite']] : $testSuite['testsuite'];
                    $this->addTestSuites($parent, $children);
                }
                continue;
            }
            $name = $testSuite['@attributes']['name'];

            if (isset($this->domElements[$name])) {
                $element = $this->domElements[$name];
            } else {
                $element = $this->document->createElement('testsuite');
                $element->setAttribute('parent', $parent->getAttribute('name'));
                $attributes = $testSuite['@attributes'] ?? [];
                foreach ($attributes as $key => $value) {
                    $value = $key === 'name' ? $value : 0;
                    $element->setAttribute($key, (string)$value);
                }
                $parent->appendChild($element);
                $this->domElements[$name] = $element;
            }

            if (!empty($testSuite['testsuite'])) {
                $children = isset($testSuite['testsuite']['@attributes']) ? [$testSuite['testsuite']] : $testSuite['testsuite'];
                $this->addTestSuites($element, $children);
            }

            if (!empty($testSuite['testcase'])) {
                $attributes = $testSuite['@attributes'] ?? [];
                foreach ($attributes as $key => $value) {
                    if (!is_numeric($value)) {
                        continue;
                    }

                    $this->addAttributeValueToTestSuite($element, $key, $value);
                }

                $children = isset($testSuite['testcase']['@attributes']) ? [$testSuite['testcase']] : $testSuite['testcase'];
                $this->addTestCases($element, $children);
            }
        }
    }

    private function addTestCases(\DOMElement $parent, array $testCases)
    {
        foreach ($testCases as $testCase) {
            $attributes = $testCase['@attributes'] ?? [];
            if (empty($testCase['@attributes']['name'])) {
                continue;
            }
            $name = $testCase['@attributes']['name'];

            if (isset($this->domElements[$name])) {
                continue;
            }

            $element = $this->document->createElement('testcase');
            foreach ($attributes as $key => $value) {
                $element->setAttribute($key, (string)$value);
                if (!is_numeric($value)) {
                    continue;
                }
                $this->addAttributeValueToTestSuite($parent, $key, $value);
            }
            $this->addChildElements($element, $testCase);
            $parent->appendChild($element);
            $this->domElements[$name] = $element;
        }
    }

    private function addAttributeValueToTestSuite(\DOMElement $element, $key, $value)
    {
        // attribute line is invalid on testsuite elements.
        if ($key === 'line') {
            return;
        }

        $currentValue = $element->hasAttribute($key) ? $element->getAttribute($key) : 0;
        $element->setAttribute($key, (string)($currentValue + $value));

        if ($element->hasAttribute('parent')) {
            $parent = $element->getAttribute('parent');
            if (isset($this->domElements[$parent])) {
                $this->addAttributeValueToTestSuite($this->domElements[$parent], $key, $value);
            }
        }
    }

    private function addChildElements(\DOMElement $element, array $testCase)
    {
        // A testcase might contain further nodes, an error for example. But the json_encode/decode-stuff does
        // not contain both, attributes and values of the nodes. Example:
        //
        // <testcase name="testMyTest" class="TestClass" classname="Tests.TestClass" file="src/Tests/TestClass.php" line="26" assertions="0" time="0.151037">
        //   <error type="TypeError">error with stack trace...</error>
        // </testcase>
        //
        // Remove the @attributes collection (name, class, classname, file, ...)
        unset($testCase['@attributes']);

        // Iterate over any child elements.
        foreach ($testCase as $key => $value) {
            // Unfortunately, the json_encode/decode used with simplexml does not contain both, attributes and values
            // of child elements.
            $childNode = $element->ownerDocument->createElement($key);

            // Skipped tests contain an empty tag <skipped />, but the son_encode/decode produces an empty array.
            if ($value) {
                $childNode->nodeValue = is_array($value) ? implode(', ', $value) : $value;
            }

            $element->appendChild($childNode);
        }
    }
}
