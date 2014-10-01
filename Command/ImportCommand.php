<?php

namespace Sulu\Bundle\SulupediaBundle\Command;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class ImportCommand extends Command
{
    protected $path;
    protected $output;

    public function configure()
    {
        $this->setName('sulupedia:import');
        $this->setDescription('Import data from the Wikipedia for schools offline project: http://www.sos-schools.org/wikipedia-for-schools');
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to Wikipedia data');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of articles');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $finder = new Finder();
        $this->filesystem = new Filesystem();

        $this->path = $input->getArgument('path');
        $this->output = $output;
        $limit = $input->getOption('limit');

        $finder->files()->name('*.htm')->in($this->path);

        $i = 0;
        foreach ($finder as $name => $info) {
            if ($i == $limit) {
                break;
            }

            $this->importData($name);

            $i++;
        }
    }

    private function importData($fileName)
    {
        $dom = new \DomDocument(1.0);
        @$dom->loadHtml(file_get_contents($fileName));
        $xpath = new \DomXPath($dom);

        $text = $this->parseText($xpath);
        $images = $this->parseImages($xpath);
    }

    private function parseText($xpath)
    {
        $paragraphs = $xpath->query('//p');
        $text = array();

        foreach ($paragraphs as $paragraph) {
            $text[] = $paragraph->nodeValue;
        }

        return implode("\n", $text);
    }

    private function parseImages($xpath)
    {
        $imageTags = $xpath->query('//body//img');
        $images = array();

        foreach ($imageTags as $imageTag) {
            $imagePath = $imageTag->getAttribute('src');
            $imagePath = $this->path . '/' . substr($imagePath, 6);
            if (file_exists($imagePath)) {
                $this->output->writeln('Found image! ' . $imagePath);
                $images[] = $imagePath;
            }
        }

        return $images;
    }
}
