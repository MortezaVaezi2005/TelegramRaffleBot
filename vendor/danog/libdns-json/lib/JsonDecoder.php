<?php

namespace danog\LibDNSJson;

use LibDNS\Decoder\DecodingContextFactory;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Packets\PacketFactory;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;
use LibDNS\Records\Resource;
use LibDNS\Records\ResourceBuilder;
use LibDNS\Records\Types\Anything;
use LibDNS\Records\Types\BitMap;
use LibDNS\Records\Types\Char;
use LibDNS\Records\Types\CharacterString;
use LibDNS\Records\Types\DomainName;
use LibDNS\Records\Types\IPv4Address;
use LibDNS\Records\Types\IPv6Address;
use LibDNS\Records\Types\Long;
use LibDNS\Records\Types\Short;
use LibDNS\Records\Types\Type;
use LibDNS\Records\Types\TypeBuilder;
use LibDNS\Records\Types\Types;

/**
 * Decodes JSON DNS strings to Message objects.
 *
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class JsonDecoder
{
    /**
     * @var \LibDNS\Packets\PacketFactory
     */
    private $packetFactory;

    /**
     * @var \LibDNS\Messages\MessageFactory
     */
    private $messageFactory;

    /**
     * @var \LibDNS\Records\QuestionFactory
     */
    private $questionFactory;

    /**
     * @var \LibDNS\Records\ResourceBuilder
     */
    private $resourceBuilder;

    /**
     * @var \LibDNS\Records\Types\TypeBuilder
     */
    private $typeBuilder;

    /**
     * @var \LibDNS\Decoder\DecodingContextFactory
     */
    private $decodingContextFactory;

    /**
     * Constructor.
     *
     */
    public function __construct(
        PacketFactory $packetFactory,
        MessageFactory $messageFactory,
        QuestionFactory $questionFactory,
        ResourceBuilder $resourceBuilder,
        TypeBuilder $typeBuilder,
        DecodingContextFactory $decodingContextFactory
    ) {
        $this->packetFactory = $packetFactory;
        $this->messageFactory = $messageFactory;
        $this->questionFactory = $questionFactory;
        $this->resourceBuilder = $resourceBuilder;
        $this->typeBuilder = $typeBuilder;
        $this->decodingContextFactory = $decodingContextFactory;
    }
    /**
     * Decode a question record.
     *
     *
     * @throws \UnexpectedValueException When the record is invalid
     */
    private function decodeQuestionRecord(array $record): Question
    {
        /** @var \LibDNS\Records\Types\DomainName $domainName */
        $domainName = $this->typeBuilder->build(Types::DOMAIN_NAME);
        $labels = \explode('.', $record['name']);
        if (!empty($last = \array_pop($labels))) {
            $labels[] = $last;
        }
        $domainName->setLabels($labels);

        $question = $this->questionFactory->create($record['type']);
        $question->setName($domainName);
        //$question->setClass($meta['class']);
        return $question;
    }

    /**
     * Decode a resource record.
     *
     *
     * @throws \UnexpectedValueException When the record is invalid
     * @throws \InvalidArgumentException When a type subtype is unknown
     */
    private function decodeResourceRecord(array $record): Resource
    {
        /** @var \LibDNS\Records\Types\DomainName $domainName */
        $domainName = $this->typeBuilder->build(Types::DOMAIN_NAME);
        $labels = \explode('.', $record['name']);
        if (!empty($last = \array_pop($labels))) {
            $labels[] = $last;
        }
        $domainName->setLabels($labels);
        /* @var \LibDNS\Records\Resource $resource */
        $resource = $this->resourceBuilder->build($record['type']);
        $resource->setName($domainName);
        //$resource->setClass($meta['class']);
        $resource->setTTL($record['TTL']);

        $data = $resource->getData();

        $typeDef = $data->getTypeDefinition();
        $record['data'] = \explode(' ', $record['data'], $typeDef->count());

        $fieldDef = $index = null;
        foreach ($data->getTypeDefinition() as $index => $fieldDef) {
            $field = $this->typeBuilder->build($fieldDef->getType());
            $this->decodeType($field, $record['data'][$index]);
            $data->setField($index, $field);
        }

        return $resource;
    }
    /**
     * Decode a Type field.
     *
     *
     * @param \LibDNS\Records\Types\Type $type The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     * @throws \InvalidArgumentException When the Type subtype is unknown
     */
    private function decodeType(Type $type, string $data): void
    {
        if ($type instanceof Anything) {
            $this->decodeAnything($type, $data);
        } elseif ($type instanceof BitMap) {
            $this->decodeBitMap($type, $data);
        } elseif ($type instanceof Char) {
            $this->decodeChar($type, $data);
        } elseif ($type instanceof CharacterString) {
            $this->decodeCharacterString($type, $data);
        } elseif ($type instanceof DomainName) {
            $this->decodeDomainName($type, $data);
        } elseif ($type instanceof IPv4Address) {
            $this->decodeIPv4Address($type, $data);
        } elseif ($type instanceof IPv6Address) {
            $this->decodeIPv6Address($type, $data);
        } elseif ($type instanceof Long) {
            $this->decodeLong($type, $data);
        } elseif ($type instanceof Short) {
            $this->decodeShort($type, $data);
        } else {
            throw new \InvalidArgumentException('Unknown Type '.\get_class($type));
        }
    }
    /**
     * Decode an Anything field.
     *
     *
     * @param \LibDNS\Records\Types\Anything $anything The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeAnything(Anything $anything, string $data): void
    {
        $anything->setValue(\hex2bin($data));
    }

    /**
     * Decode a BitMap field.
     *
     *
     * @param \LibDNS\Records\Types\BitMap $bitMap The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeBitMap(BitMap $bitMap, string $data): void
    {
        $bitMap->setValue(\hex2bin($data));
    }

    /**
     * Decode a Char field.
     *
     *
     * @param \LibDNS\Records\Types\Char $char The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeChar(Char $char, string $result): void
    {
        $value = \unpack('C', $result)[1];
        $char->setValue($value);
    }

    /**
     * Decode a CharacterString field.
     *
     *
     * @param \LibDNS\Records\Types\CharacterString $characterString The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeCharacterString(CharacterString $characterString, string $result): void
    {
        $characterString->setValue($result);
    }

    /**
     * Decode a DomainName field.
     *
     *
     * @param \LibDNS\Records\Types\DomainName $domainName The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeDomainName(DomainName $domainName, string $result): void
    {
        $labels = \explode('.', $result);
        if (!empty($last = \array_pop($labels))) {
            $labels[] = $last;
        }

        $domainName->setLabels($labels);
    }

    /**
     * Decode an IPv4Address field.
     *
     *
     * @param \LibDNS\Records\Types\IPv4Address $ipv4Address The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeIPv4Address(IPv4Address $ipv4Address, string $result): void
    {
        $octets = \unpack('C4', \inet_pton($result));
        $ipv4Address->setOctets($octets);
    }

    /**
     * Decode an IPv6Address field.
     *
     *
     * @param \LibDNS\Records\Types\IPv6Address $ipv6Address The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeIPv6Address(IPv6Address $ipv6Address, string $result): void
    {
        $shorts = \unpack('n8', \inet_pton($result));
        $ipv6Address->setShorts($shorts);
    }

    /**
     * Decode a Long field.
     *
     *
     * @param \LibDNS\Records\Types\Long $long The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeLong(Long $long, string $result): void
    {
        $long->setValue($result);
    }

    /**
     * Decode a Short field.
     *
     *
     * @param \LibDNS\Records\Types\Short $short The object to populate with the result
     * @throws \UnexpectedValueException When the packet data is invalid
     */
    private function decodeShort(Short $short, string $result): void
    {
        $short->setValue($result);
    }

    /**
     * Decode a Message from JSON-encoded string.
     *
     * @param int $requestId The message ID to set
     * @throws \UnexpectedValueException When the packet data is invalid
     * @throws \InvalidArgumentException When a type subtype is unknown
     */
    public function decode(string $result, int $requestId): Message
    {
        $result = \json_decode($result, true);
        if ($result === false) {
            $error = \json_last_error_msg();
            throw new \InvalidArgumentException("Could not decode JSON DNS payload ($error)");
        }
        if (!isset($result['Status'], $result['TC'], $result['RD'], $result['RA'])) {
            throw new \InvalidArgumentException('Wrong reply from server, missing required fields');
        }

        $message = $this->messageFactory->create();
        $decodingContext = $this->decodingContextFactory->create($this->packetFactory->create());

        //$message->isAuthoritative(true);
        $message->setType(MessageTypes::RESPONSE);
        $message->setID($requestId);
        $message->setResponseCode($result['Status']);
        $message->isTruncated($result['TC']);
        $message->isRecursionDesired($result['RD']);
        $message->isRecursionAvailable($result['RA']);

        $decodingContext->setExpectedQuestionRecords(isset($result['Question']) ? \count($result['Question']) : 0);
        $decodingContext->setExpectedAnswerRecords(isset($result['Answer']) ? \count($result['Answer']) : 0);
        $decodingContext->setExpectedAuthorityRecords(0);
        $decodingContext->setExpectedAdditionalRecords(isset($result['Additional']) ? \count($result['Additional']) : 0);

        $questionRecords = $message->getQuestionRecords();
        $expected = $decodingContext->getExpectedQuestionRecords();
        for ($i = 0; $i < $expected; $i++) {
            $questionRecords->add($this->decodeQuestionRecord($result['Question'][$i]));
        }

        $answerRecords = $message->getAnswerRecords();
        $expected = $decodingContext->getExpectedAnswerRecords();
        for ($i = 0; $i < $expected; $i++) {
            $answerRecords->add($this->decodeResourceRecord($result['Answer'][$i]));
        }

        $authorityRecords = $message->getAuthorityRecords();
        $expected = $decodingContext->getExpectedAuthorityRecords();
        for ($i = 0; $i < $expected; $i++) {
            $authorityRecords->add($this->decodeResourceRecord($result['Authority'][$i]));
        }

        $additionalRecords = $message->getAdditionalRecords();
        $expected = $decodingContext->getExpectedAdditionalRecords();
        for ($i = 0; $i < $expected; $i++) {
            $additionalRecords->add($this->decodeResourceRecord($result['Additional'][$i]));
        }
        return $message;
    }
}
