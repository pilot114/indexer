<?php

declare(strict_types=1);

class Database
{
    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['dbname']);
        $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function savePage(string $url, string $html): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO indexed_pages (url, html) VALUES (:url, :html) ON CONFLICT (url) DO NOTHING');
        $stmt->execute(['url' => $url, 'html' => $html]);
    }

    public function saveProgress(int $parsedCount): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO index_progress (id, parsed_count) VALUES (1, :parsed_count) ON CONFLICT (id) DO UPDATE SET parsed_count = :parsed_count');
        $stmt->execute(['parsed_count' => $parsedCount]);
    }

    public function restoreProgress(): ?int
    {
        $stmt = $this->pdo->query('SELECT parsed_count FROM index_progress WHERE id = 1');
        $progress = $stmt->fetch();
        return $progress ? (int) $progress['parsed_count'] : null;
    }

    public function isUrlVisited(string $url): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM visited_urls WHERE url = :url');
        $stmt->execute(['url' => $url]);
        return (bool) $stmt->fetchColumn();
    }

    public function isUrlToVisit(string $url): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM urls_to_visit WHERE url = :url');
        $stmt->execute(['url' => $url]);
        return (bool) $stmt->fetchColumn();
    }

    public function addUrlToVisit(string $url): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO urls_to_visit (url) VALUES (:url) ON CONFLICT DO NOTHING');
        $stmt->execute(['url' => $url]);
    }

    public function addUrlVisited(string $url): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO visited_urls (url) VALUES (:url) ON CONFLICT DO NOTHING');
        $stmt->execute(['url' => $url]);
    }

    public function getNextUrlToVisit(): ?string
    {
        $stmt = $this->pdo->query('SELECT url FROM urls_to_visit LIMIT 1');
        $url = $stmt->fetchColumn();
        if ($url) {
            $this->removeUrlToVisit($url);
            return $url;
        }
        return null;
    }

    public function removeUrlToVisit(string $url): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM urls_to_visit WHERE url = :url');
        $stmt->execute(['url' => $url]);
    }
}

class UrlFetcher
{
    private int $maxRedirects = 5;

    public function getPage(string $url): ?string
    {
        $redirects = 0;

        while ($redirects < $this->maxRedirects) {
            $result = $this->fetchPage($url, 512);

            if ($result['status'] === 'redirect') {
                $url = $result['location'];
                $redirects++;
                continue;
            }

            if (!is_string($result['content']) || $result['content'] === '' || !$this->isHtml($result['content'])) {
                return null;
            }
            return $result['content'];
        }

        return null;
    }

    private function isHtml(string $content): bool
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($content, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $restore = $dom->saveHTML();
        return substr($content, 0, 15) === substr($restore, 0, 15);
    }

    private function fetchPage(string $url, int $kbLimit = 1024): array
    {
        $urlParts = parse_url($url);

        if (!isset($urlParts['scheme'], $urlParts['host'])) {
            return ['status' => 'error', 'content' => null];
        }

        $scheme = $urlParts['scheme'];
        $host = $urlParts['host'];
        $port = $scheme === 'https' ? 443 : 80;
        $path = $urlParts['path'] ?? '/';
        $query = isset($urlParts['query']) ? '?' . $urlParts['query'] : '';
        $requestUri = $path . $query;

        $socket = fsockopen(($scheme === 'https' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 10);

        if (!$socket) {
            echo "Socket error: $errstr ($errno)\n";
            return ['status' => 'error', 'content' => null];
        }

        $request = "GET $requestUri HTTP/1.1\r\n";
        $request .= "Host: $host\r\n";
        $request .= "Connection: close\r\n\r\n";

        fwrite($socket, $request);

        $response = '';
        $maxSize = 1 * 1024 * $kbLimit;
        $currentSize = 0;

        while (!feof($socket)) {
            $chunk = fread($socket, 1024);
            if ($chunk === false) {
                return ['status' => 'error', 'content' => null];
            }
            $currentSize += strlen($chunk);

            if ($currentSize > $maxSize) {
                fclose($socket);
                return ['status' => 'error', 'content' => null];
            }

            $response .= $chunk;
        }

        fclose($socket);

        $headerEndPos = strpos($response, "\r\n\r\n");
        $header = substr($response, 0, $headerEndPos);
        $body = substr($response, $headerEndPos + 4);

        // Обрабатываем заголовки для проверки редиректов
        if (preg_match('/^HTTP\/\d\.\d\s+30[1278]/', $header)) {
            if (preg_match('/Location:\s*(.*)/i', $header, $matches)) {
                $location = trim($matches[1]);

                // Если Location не является абсолютным URL, преобразуем его
                if (parse_url($location, PHP_URL_SCHEME) === null) {
                    $location = $this->urlToAbsolute($url, $location);
                }

                return ['status' => 'redirect', 'location' => $location];
            }
        }

        return ['status' => 'success', 'content' => $body];
    }

    private function urlToAbsolute(string $base, string $relative): string
    {
        if (parse_url($relative, PHP_URL_SCHEME) !== null) {
            return $relative;
        }

        if ($relative[0] === '/') {
            $parts = parse_url($base);
            return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $relative);
        }

        $base = rtrim($base, '/');
        while (str_starts_with($relative, '../')) {
            $base = dirname($base);
            $relative = substr($relative, 3);
        }
        $relative = ltrim($relative, './');

        return sprintf('%s/%s', $base, $relative);
    }
}

class LinkParser
{
    public function parseLinks(string $html, string $baseUrl): array
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html); // incorrect html

        $links = [];
        foreach ($dom->getElementsByTagName('a') as $aTag) {
            $href = $aTag->getAttribute('href');
            $fullUrl = $this->urlToAbsolute($baseUrl, $href);

            if (filter_var($fullUrl, FILTER_VALIDATE_URL) && in_array(parse_url($fullUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $links[] = $this->removeFragment($fullUrl);
            }
        }
        return array_unique($links);
    }

    private function removeFragment(string $url): string
    {
        $urlParts = parse_url($url);
        unset($urlParts['fragment']);
        return (isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '')
            . (isset($urlParts['user']) ? $urlParts['user'] . (isset($urlParts['pass']) ? ':' . $urlParts['pass'] : '') . '@' : '')
            . ($urlParts['host'] ?? '')
            . (isset($urlParts['port']) ? ':' . $urlParts['port'] : '')
            . ($urlParts['path'] ?? '')
            . (isset($urlParts['query']) ? '?' . $urlParts['query'] : '');
    }

    private function urlToAbsolute(string $base, string $relative): string
    {
        // Если URL уже абсолютный, просто вернуть его
        if (parse_url($relative, PHP_URL_SCHEME) !== null) {
            return $relative;
        }

        // Если URL начинается с '/', нужно использовать корень базового URL
        if ($relative && $relative[0] === '/') {
            $parts = parse_url($base);
            return sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $relative);
        }

        // Удалить все './' и '../' из пути
        $base = rtrim($base, '/');
        while (str_starts_with($relative, '../')) {
            $base = dirname($base);
            $relative = substr($relative, 3);
        }
        $relative = ltrim($relative, './');

        // Объединить базовый путь и относительный путь
        return sprintf('%s/%s', $base, $relative);
    }
}

class SiteIndexer
{
    private int $parsedCount = 0;

    public function __construct(
        readonly private Database $db,
        readonly private UrlFetcher $fetcher,
        readonly private LinkParser $parser,
    ) {
    }

    public function run(string $startUrl): void
    {
        $progressData = $this->db->restoreProgress();

        if ($progressData !== null) {
            $this->parsedCount = $progressData;
            echo "Resuming from last saved progress.\n";
        } else {
            $this->db->addUrlToVisit($startUrl);
        }

        while ($url = $this->db->getNextUrlToVisit()) {
            $this->indexSite($url);
        }
    }

    private function indexSite(string $url, int $depth = 2): void
    {
        if ($depth === 0 || $this->db->isUrlVisited($url)) {
            return;
        }

        $this->db->addUrlVisited($url);
        $this->parsedCount++;
        echo "Indexing: $url\n";
        echo "Parsed URLs: $this->parsedCount\n";

        $html = $this->fetcher->getPage($url);
        if ($html === null) {
            return;
        }

        $this->db->savePage($url, $html);
        $this->db->saveProgress($this->parsedCount);

        $links = $this->parser->parseLinks($html, $url);
        foreach ($links as $link) {
            if (!$this->db->isUrlVisited($link) && !$this->db->isUrlToVisit($link)) {
                $this->db->addUrlToVisit($link);
            }
        }

        foreach ($links as $link) {
            $this->indexSite($link, $depth - 1);
        }

        usleep(100_000);
    }
}

$dbConfig = [
    'dbname' => 'indexer',
    'user' => 'test',
    'password' => 'test_pass',
    'host' => 'postgres',
    'port' => 5432,
];
$startUrl = "https://habr.com";

$db = new Database($dbConfig);
$fetcher = new UrlFetcher();
$parser = new LinkParser();
$indexer = new SiteIndexer($db, $fetcher, $parser);
$indexer->run($startUrl);
