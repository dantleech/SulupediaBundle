<?php

namespace Sulu\Bundle\SulupediaBundle\Command;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Gedmo\Sluggable\Util\Urlizer;

class ImportCommand extends ContainerAwareCommand
{
    protected $path;
    protected $output;
    protected $mediaManager;
    protected $nodePathCount = array();

    public function configure()
    {
        $this->setName('sulupedia:import');
        $this->setDescription('Import data from the Wikipedia for schools offline project: http://www.sos-schools.org/wikipedia-for-schools');
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to Wikipedia data');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of articles');
        $this->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Offset import', 0);
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $finder = new Finder();
        $this->filesystem = new Filesystem();
        $this->mediaManager = $this->getContainer()->get('sulu_media.media_manager');
        $this->contentMapper = $this->getContainer()->get('sulu.content.mapper');
        $this->em = $this->getContainer()->get('doctrine')->getManager();

        $this->path = $input->getArgument('path');
        $this->output = $output;
        $offset = $input->getOption('offset');
        $limit = $input->getOption('limit') + $offset;

        $finder->files()->name('subject.*.htm')->in($this->path . '/wp/index');

        $i = 0;
        foreach ($finder as $name => $info) {
            if ($i < $offset) {
                $i++;
                continue;
            }

            if ($i == $limit) {
                break;
            }

            $this->importIndex($name);

            $i++;
        }
    }

    private function importIndex($fileName)
    {
        $dom = new \DomDocument(1.0);
        @$dom->loadHtml(file_get_contents($fileName));
        $xpath = new \DomXPath($dom);
        $categories = explode('.', basename(substr($fileName, 0, -4)));
        if ($categories) {
            array_shift($categories);
        } else {
            $categories = array();
        }

        $articleLinks = $xpath->query('//div[@id="bodyContent"]//td/a');

        foreach ($articleLinks as $articleLink) {
            $title = $articleLink->nodeValue;
            $articlePath = substr($articleLink->getAttribute('href'), 6);
            $nodePath = implode('/', $categories) . '/' . $title;

            $this->output->writeln('<info>' . $title . '</info>');
            $this->output->writeln('    Path: ' . $nodePath. '</info>');
            $subCategoryPath = dirname($nodePath);

            if (!isset($this->nodePathCount[$subCategoryPath])) {
                $this->nodePathCount[$subCategoryPath] = 0;
            }

            if ($this->nodePathCount[$subCategoryPath] <= 2) {
                $article = $this->importArticle($this->path . '/' . $articlePath, $nodePath);
                $this->nodePathCount[$subCategoryPath]++;
            }
        }

        return;
    }

    private function importArticle($fileName, $nodePath)
    {
        $dom = new \DomDocument(1.0);
        @$dom->loadHtml(file_get_contents($fileName));
        $xpath = new \DomXPath($dom);

        $text = $this->parseText($xpath);
        $images = $this->importImages($xpath, $nodePath);
        $imageIds = array();
        foreach ($images as $image) {
            $imageIds[] = $image->getId();
        }

        $this->contentMapper->save(array(
            'title' => basename($nodePath),
            'url' => '/' . Urlizer::urlize($nodePath),
            'images' => array('ids' => $imageIds),
            'article' => $text['body']
        ), 'default', 'sulu_io', 'en', 1);

    }

    private function parseText($xpath)
    {
        $relateds = array();
        $text = array();

        $paragraphs = $xpath->query('//p');
        foreach ($paragraphs as $paragraph) {
            $text[] = $paragraph->nodeValue;
        }

        $related = $xpath->query('//div[@id="siteSub"]/a');
        foreach ($related as $related) {
            $relateds[] = $related->getAttribute('href');
        }

        return array(
            'body' => implode("\n", $text),
            'related' => $relateds,
        );
    }

    private function importImages($xpath, $nodePath)
    {
        $category = strstr($nodePath, '/', true);
        $collection = $this->getOrCreateCollection($category);

        $imageTags = $xpath->query('//body//img');
        $images = array();
        $imageEntities = array();

        foreach ($imageTags as $i => $imageTag) {
            if ($i > 2) {
                break;
            }
            $imagePath = $imageTag->getAttribute('src');
            $imagePath = $this->path . '/' . substr($imagePath, 6);
            if (file_exists($imagePath)) {
                $images[] = $imagePath;
            }
        }

        foreach ($images as $imagePath) {
            $uploadedFile = new UploadedFile($imagePath, basename($imagePath));
            try {
                $imageEntities[] = $this->mediaManager->save($uploadedFile, array(
                    'locale' => 'en',
                    'collection' => $collection->getId()
                ), 1);
            } catch (\Exception $e) {
                $this->output->writeln('<error>' . $e->getMessage() . '</error>');
            }
        }

        return $imageEntities;
    }

    public function getOrCreateCollection($category)
    {
        $collectionMetaRepo = $this->em->getRepository('Sulu\Bundle\MediaBundle\Entity\CollectionMeta');
        $collectionManager = $this->getContainer()->get('sulu_media.collection_manager');
        $meta = $collectionMetaRepo->findOneByTitle($category);

        $data = array(
            'title' => $category,
            'locale' => 'en',
        );

        if (!$meta) {
            $type = new CollectionType();
            $type->setName('sulupedia');
            $this->em->persist($type);
            $this->em->flush();
            $data['type']['id'] = $type->getId();

            return $collectionManager->save($data, 1);
        }

        return $meta->getCollection();
    }
}
