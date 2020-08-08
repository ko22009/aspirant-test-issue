<?php
/**
 * 2019-06-28.
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\Movie;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class FetchDataCommand.
 */
class FetchDataCommand extends Command
{
    private const SOURCE = 'https://trailers.apple.com/trailers/home/rss/newtrailers.rss';

    /**
     * @var string
     */
    protected static $defaultName = 'fetch:trailers';

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $source;

    /**
     * @var EntityManagerInterface
     */
    private $doctrine;

    /**
     * FetchDataCommand constructor.
     *
     * @param ClientInterface        $httpClient
     * @param LoggerInterface        $logger
     * @param EntityManagerInterface $em
     * @param string|null            $name
     */
    public function __construct(ClientInterface $httpClient, LoggerInterface $logger, EntityManagerInterface $em, string $name = null)
    {
        parent::__construct($name);
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->doctrine = $em;
    }

    public function configure(): void
    {
        $this
            ->setDescription('Fetch data from iTunes Movie Trailers')
            ->addOption('source', 's', InputArgument::OPTIONAL, 'Overwrite source')
            ->addOption('maxCount', 'c', InputArgument::OPTIONAL, 'Overwrite max count');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info(sprintf('Start %s at %s', __CLASS__, (string) date_create()->format(DATE_ATOM)));
        $source = self::SOURCE;
        if ($input->getOption('source')) {
            $source = $input->getOption('source');
        }

        $maxCount = 10;
        if ($input->getOption('maxCount')) {
            $maxCount = intval($input->getOption('maxCount'));
        }

        if ($maxCount < 10) {
            throw new RuntimeException('Max count must be a number and not less 10');
        }

        if (!is_string($source)) {
            throw new RuntimeException('Source must be string');
        }
        $io = new SymfonyStyle($input, $output);
        $io->title(sprintf('Fetch data from %s', $source));

        try {
            $response = $this->httpClient->sendRequest(new Request('GET', $source));
        } catch (ClientExceptionInterface $e) {
            throw new RuntimeException($e->getMessage());
        }
        if (($status = $response->getStatusCode()) !== 200) {
            throw new RuntimeException(sprintf('Response status is %d, expected %d', $status, 200));
        }
        $data = $response->getBody()->getContents();
        $this->processXml($data, $maxCount);

        $this->logger->info(sprintf('End %s at %s', __CLASS__, (string) date_create()->format(DATE_ATOM)));

        return 0;
    }

    /**
     * @param string $data
     * @param int $data
     *
     * @throws \Exception
     */
    protected function processXml(string $data, int $maxCount): void
    {
        $xml = (new \SimpleXMLElement($data))->children();

        if (!property_exists($xml, 'channel')) {
            throw new RuntimeException('Could not find \'channel\' element in feed');
        }

        $items = $xml->channel->item;
        $line = 0;

        foreach ($items as $item) {
            $encodedContent = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded;
            preg_match('/(?:img src=")([^"]+)/', $encodedContent, $matches);

            $trailer = $this->getMovie((string) $item->title)
                ->setTitle((string) $item->title)
                ->setDescription((string) $item->description)
                ->setLink((string) $item->link)
                ->setImage($matches ? $matches[1] : null)
                ->setPubDate($this->parseDate((string) $item->pubDate));

            $this->doctrine->persist($trailer);
            $line++;
            if($line == $maxCount) break;
        }

        $this->doctrine->flush();
    }

    /**
     * @param string $date
     *
     * @return \DateTime
     *
     * @throws \Exception
     */
    protected function parseDate(string $date): \DateTime
    {
        return new \DateTime($date);
    }

    /**
     * @param string $title
     *
     * @return Movie
     */
    protected function getMovie(string $title): Movie
    {
        $item = $this->doctrine->getRepository(Movie::class)->findOneBy(['title' => $title]);

        if ($item === null) {
            $this->logger->info('Create new Movie', ['title' => $title]);
            $item = new Movie();
        } else {
            $this->logger->info('Move found', ['title' => $title]);
        }

        if (!($item instanceof Movie)) {
            throw new RuntimeException('Wrong type!');
        }

        return $item;
    }
}
