<?php declare(strict_types=1);

namespace Amp\DoH;

use Amp\Cache\Cache;
use Amp\Cancellation;
use Amp\CompositeException;
use Amp\Dns\DnsConfig;
use Amp\Dns\DnsConfigException;
use Amp\Dns\DnsConfigLoader;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\Dns\DnsTimeoutException;
use Amp\Dns\MissingDnsRecordException;
use Amp\Dns\Rfc1035StubDnsResolver;
use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\NullCancellation;
use danog\LibDNSJson\JsonDecoder;
use danog\LibDNSJson\JsonDecoderFactory;
use danog\LibDNSJson\QueryEncoder;
use danog\LibDNSJson\QueryEncoderFactory;
use LibDNS\Decoder\Decoder;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;

use function Amp\async;
use function Amp\Dns\normalizeName;

final class Rfc8484StubDoHResolver implements DnsResolver
{
    const CACHE_PREFIX = "amphp.doh.";

    private DnsConfigLoader $configLoader;
    private QuestionFactory $questionFactory;
    private ?DnsConfig $config = null;

    private ?Future $pendingConfig = null;

    private Cache $cache;

    /** @var Future[] */
    private array $pendingQueries = [];

    private DnsResolver $subResolver;
    private Encoder $encoder;
    private Decoder $decoder;
    private QueryEncoder $encoderJson;
    private JsonDecoder $decoderJson;
    private MessageFactory $messageFactory;
    private HttpClient $httpClient;

    public function __construct(private DoHConfig $dohConfig)
    {
        $this->cache = $dohConfig->getCache();
        $this->configLoader = $dohConfig->getConfigLoader();
        $this->subResolver = $dohConfig->getSubResolver();
        $this->questionFactory = new QuestionFactory;
        $this->encoder = (new EncoderFactory)->create();
        $this->decoder = (new DecoderFactory)->create();
        $this->encoderJson = (new QueryEncoderFactory)->create();
        $this->decoderJson = (new JsonDecoderFactory)->create();
        $this->httpClient = $dohConfig->getHttpClient();
        $this->messageFactory = new MessageFactory;
    }

    /** @inheritdoc */
    public function resolve(string $name, int $typeRestriction = null, ?Cancellation $cancellation = null): array
    {
        if ($typeRestriction !== null && $typeRestriction !== DnsRecord::A && $typeRestriction !== DnsRecord::AAAA) {
            throw new \Error("Invalid value for parameter 2: null|DnsRecord::A|DnsRecord::AAAA expected");
        }

        if (!$this->config) {
            try {
                $this->reloadConfig();
            } catch (DnsConfigException $e) {
                $this->config = new DnsConfig(['0.0.0.0'], []);
            }
        }

        switch ($typeRestriction) {
            case DnsRecord::A:
                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return [new DnsRecord($name, DnsRecord::A, null)];
                }

                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new DnsException("Got an IPv6 address, but type is restricted to IPv4");
                }
                break;
            case DnsRecord::AAAA:
                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return [new DnsRecord($name, DnsRecord::AAAA, null)];
                }

                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new DnsException("Got an IPv4 address, but type is restricted to IPv6");
                }
                break;
            default:
                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return [new DnsRecord($name, DnsRecord::A, null)];
                }

                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return [new DnsRecord($name, DnsRecord::AAAA, null)];
                }
                break;
        }

        $dots = \substr_count($name, ".");
        $trailingDot = $name[-1] === ".";
        $name = normalizeName($name);

        if ($records = $this->queryHosts($name, $typeRestriction)) {
            return $records;
        }

        // Follow RFC 6761 and never send queries for localhost to the caching DNS server
        // Usually, these queries are already resolved via queryHosts()
        if ($name === 'localhost') {
            return $typeRestriction === DnsRecord::AAAA
                ? [new DnsRecord('::1', DnsRecord::AAAA, null)]
                : [new DnsRecord('127.0.0.1', DnsRecord::A, null)];
        }

        if ($this->dohConfig->isDoHNameserver($name)) {
            return $this->subResolver->resolve($name, $typeRestriction, $cancellation);
        }
        \assert($this->config !== null);

        $searchList = [null];
        if (!$trailingDot && $dots < $this->config->getNdots()) {
            $searchList = \array_merge($this->config->getSearchList(), $searchList);
        }

        foreach ($searchList as $searchIndex => $search) {
            for ($redirects = 0; $redirects < 5; $redirects++) {
                $searchName = $name;

                if ($search !== null) {
                    $searchName = $name . "." . $search;
                }

                try {
                    if ($typeRestriction) {
                        return $this->query($searchName, $typeRestriction, $cancellation);
                    }

                    [$exceptions, $records] = Future\awaitAll([
                        async(fn () => $this->query($searchName, DnsRecord::A, $cancellation)),
                        async(fn () => $this->query($searchName, DnsRecord::AAAA, $cancellation)),
                    ]);

                    if (\count($exceptions) === 2) {
                        $errors = [];

                        foreach ($exceptions as $reason) {
                            if ($reason instanceof MissingDnsRecordException) {
                                throw $reason;
                            }

                            if ($searchIndex < \count($searchList) - 1 && \in_array($reason->getCode(), [2, 3], true)) {
                                continue 2;
                            }

                            $errors[] = $reason->getMessage();
                        }

                        throw new DnsException(
                            "All query attempts failed for {$searchName}: " . \implode(", ", $errors),
                            0,
                            new CompositeException($exceptions)
                        );
                    }

                    return \array_merge(...$records);
                } catch (MissingDnsRecordException) {
                    try {
                        $cnameRecords = $this->query($searchName, DnsRecord::CNAME, $cancellation);
                        $name = $cnameRecords[0]->getValue();
                        continue;
                    } catch (MissingDnsRecordException) {
                        $dnameRecords = $this->query($searchName, DnsRecord::DNAME, $cancellation);
                        $name = $dnameRecords[0]->getValue();
                        continue;
                    }
                } catch (DnsException $e) {
                    if ($searchIndex < \count($searchList) - 1 && \in_array($e->getCode(), [2, 3], true)) {
                        continue 2;
                    }

                    throw $e;
                }
            }
        }

        \assert(isset($searchName));

        throw new DnsException("Giving up resolution of '{$searchName}', too many redirects");
    }

    /**
     * Reloads the configuration in the background.
     *
     * Once it's finished, the configuration will be used for new requests.
     */
    public function reloadConfig(): void
    {
        if (!$this->pendingConfig) {
            $promise = async(function () {
                try {
                    if ($this->subResolver instanceof Rfc1035StubDnsResolver) {
                        $this->subResolver->reloadConfig();
                    }
                    $this->config = $this->configLoader->loadConfig();
                } finally {
                    $this->pendingConfig = null;
                }
            });
            $this->pendingConfig = $promise;
        }

        $this->pendingConfig->await();
    }

    private function queryHosts(string $name, int $typeRestriction = null): array
    {
        \assert($this->config !== null);
        $hosts = $this->config->getKnownHosts();
        $records = [];

        $returnIPv4 = $typeRestriction === null || $typeRestriction === DnsRecord::A;
        $returnIPv6 = $typeRestriction === null || $typeRestriction === DnsRecord::AAAA;

        if ($returnIPv4 && isset($hosts[DnsRecord::A][$name])) {
            $records[] = new DnsRecord($hosts[DnsRecord::A][$name], DnsRecord::A, null);
        }

        if ($returnIPv6 && isset($hosts[DnsRecord::AAAA][$name])) {
            $records[] = new DnsRecord($hosts[DnsRecord::AAAA][$name], DnsRecord::AAAA, null);
        }

        return $records;
    }

    /** @inheritdoc */
    public function query(string $name, int $type, ?Cancellation $cancellation = null): array
    {
        $cancellation ??= new NullCancellation;
        $pendingQueryKey = $type." ".$name;

        if (isset($this->pendingQueries[$pendingQueryKey])) {
            return $this->pendingQueries[$pendingQueryKey]->await($cancellation);
        }

        $promise = async(function () use ($name, $type, $cancellation, $pendingQueryKey) {
            try {
                if (!$this->config) {
                    try {
                        $this->reloadConfig();
                    } catch (DnsConfigException $e) {
                        $this->config = new DnsConfig(['0.0.0.0'], []);
                    }
                }

                \assert($this->config !== null);

                $name = $this->normalizeName($name, $type);
                $question = $this->createQuestion($name, $type);

                if (null !== $cachedValue = $this->cache->get($this->getCacheKey($name, $type))) {
                    return $this->decodeCachedResult($name, $type, $cachedValue);
                }

                $nameservers = $this->dohConfig->getDoHNameservers();
                $attempts = $this->config->getAttempts() * \count($nameservers);
                $attempt = 0;

                $nameserver = $nameservers[0];

                $attemptDescription = [];

                while ($attempt < $attempts) {
                    try {
                        $attemptDescription[] = $nameserver;

                        $response = $this->ask($nameserver, $question, $cancellation);
                        $this->assertAcceptableResponse($response);

                        if ($response->isTruncated()) {
                            throw new DnsException("Server returned a truncated response for '{$name}' (".DnsRecord::getName($type).")");
                        }

                        $answers = $response->getAnswerRecords();
                        $result = [];
                        $ttls = [];

                        foreach ($answers as $record) {
                            $recordType = $record->getType();
                            $result[$recordType][] = (string) $record->getData();

                            // Cache for max one day
                            $ttls[$recordType] = \min($ttls[$recordType] ?? 86400, $record->getTTL());
                        }

                        foreach ($result as $recordType => $records) {
                            // We don't care here whether storing in the cache fails
                            $this->cache->set($this->getCacheKey($name, $recordType), \json_encode($records), $ttls[$recordType]);
                        }

                        if (!isset($result[$type])) {
                            // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
                            $this->cache->set($this->getCacheKey($name, $type), \json_encode([]), 300);
                            throw new MissingDnsRecordException("No records returned for '{$name}' (".DnsRecord::getName($type).")");
                        }

                        return \array_map(function ($data) use ($type, $ttls) {
                            return new DnsRecord($data, $type, $ttls[$type]);
                        }, $result[$type]);
                    } catch (DnsTimeoutException) {
                        $i = ++$attempt % \count($nameservers);
                        $nameserver = $nameservers[$i];
                    }
                }

                throw new DnsTimeoutException(\sprintf(
                    "No response for '%s' (%s) from any nameserver within %d ms after %d attempts, tried %s",
                    $name,
                    DnsRecord::getName($type),
                    $this->config->getTimeout(),
                    $attempts,
                    \implode(", ", $attemptDescription)
                ));
            } finally {
                unset($this->pendingQueries[$pendingQueryKey]);
            }
        });

        $this->pendingQueries[$pendingQueryKey] = $promise;

        return $promise->await($cancellation);
    }
    /**
     * Base64URL encode.
     *
     * @param string $data Data to encode
     */
    private static function base64urlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    private function ask(DoHNameserver $nameserver, Question $question, Cancellation $cancellation): Message
    {
        $message = $this->createMessage($question, \random_int(0, 0xffff));
        $request = null;
        switch ($nameserver->getType()) {
            case DoHNameserverType::RFC8484_GET:
                $data = $this->encoder->encode($message);
                $request = new Request($nameserver->getUri().'?'.\http_build_query(['dns' => self::base64urlEncode($data), 'ct' => 'application/dns-message']), "GET");
                $request->setHeaders($nameserver->getHeaders());
                $request->setHeader('accept', 'application/dns-message');
                break;
            case DoHNameserverType::RFC8484_POST:
                $data = $this->encoder->encode($message);
                $request = new Request($nameserver->getUri(), "POST");
                $request->setBody($data);
                $request->setHeaders($nameserver->getHeaders());
                $request->setHeader('content-type', 'application/dns-message');
                $request->setHeader('accept', 'application/dns-message');
                $request->setHeader('content-length', (string) \strlen($data));
                break;
            case DoHNameserverType::GOOGLE_JSON:
                $data = $this->encoderJson->encode($message);
                $request = new Request($nameserver->getUri().'?'.$data, "GET");
                $request->setHeaders($nameserver->getHeaders());
                $request->setHeader('accept', 'application/dns-json');
                break;
        }

        $response = $this->httpClient->request($request, $cancellation);
        if ($response->getStatus() !== 200) {
            throw new DoHException("HTTP result !== 200: ".$response->getStatus()." ".$response->getReason(), $response->getStatus());
        }
        $response = $response->getBody()->buffer();

        switch ($nameserver->getType()) {
            case DoHNameserverType::RFC8484_GET:
            case DoHNameserverType::RFC8484_POST:
                return $this->decoder->decode($response);
            case DoHNameserverType::GOOGLE_JSON:
                return $this->decoderJson->decode($response, $message->getID());
        }
    }

    private function normalizeName(string $name, int $type): string
    {
        if ($type === DnsRecord::PTR) {
            if (($packedIp = @\inet_pton($name)) !== false) {
                if (isset($packedIp[4])) { // IPv6
                    $name = \wordwrap(\strrev(\bin2hex($packedIp)), 1, ".", true).".ip6.arpa";
                } else { // IPv4
                    $name = \inet_ntop(\strrev($packedIp)).".in-addr.arpa";
                }
            }
        } elseif (\in_array($type, [DnsRecord::A, DnsRecord::AAAA])) {
            $name = normalizeName($name);
        }

        return $name;
    }

    private function createQuestion(string $name, int $type): Question
    {
        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }

    private function createMessage(Question $question, int $id): Message
    {
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);
        $request->setID($id);
        return $request;
    }

    private function getCacheKey(string $name, int $type): string
    {
        return self::CACHE_PREFIX.$name."#".$type;
    }

    /**
     * @return list<DnsRecord>
     */
    private function decodeCachedResult(string $name, int $type, string $encoded): array
    {
        $decoded = \json_decode($encoded, true);

        if (!$decoded) {
            throw new MissingDnsRecordException("No records returned for {$name} (cached result)");
        }

        $result = [];

        foreach ($decoded as $data) {
            $result[] = new DnsRecord($data, $type);
        }

        return $result;
    }

    private function assertAcceptableResponse(Message $response): void
    {
        if ($response->getResponseCode() !== 0) {
            throw new DnsException(\sprintf("Server returned error code: %d", $response->getResponseCode()));
        }
    }
}
