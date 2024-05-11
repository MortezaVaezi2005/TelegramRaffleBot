<?php
/**
 * Creates JsonDecoder objects.
 *
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 * @copyright Copyright (c) Chris Wright <https://github.com/DaveRandom>,
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 */

namespace danog\LibDNSJson;

use \LibDNS\Messages\MessageFactory;
use \LibDNS\Packets\PacketFactory;
use \LibDNS\Records\QuestionFactory;
use \LibDNS\Records\RDataBuilder;
use \LibDNS\Records\RDataFactory;
use \LibDNS\Records\RecordCollectionFactory;
use \LibDNS\Records\ResourceBuilder;
use \LibDNS\Records\ResourceFactory;
use \LibDNS\Records\TypeDefinitions\FieldDefinitionFactory;
use \LibDNS\Records\TypeDefinitions\TypeDefinitionFactory;
use \LibDNS\Records\TypeDefinitions\TypeDefinitionManager;
use \LibDNS\Records\Types\TypeBuilder;
use \LibDNS\Records\Types\TypeFactory;
use LibDNS\Decoder\DecodingContextFactory;

/**
 * Creates JsonDecoder objects.
 *
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class JsonDecoderFactory
{
    /**
     * Create a new JsonDecoder object.
     *
     * @param \LibDNS\Records\TypeDefinitions\TypeDefinitionManager $typeDefinitionManager
     */
    public function create(TypeDefinitionManager $typeDefinitionManager = null): JsonDecoder
    {
        $typeBuilder = new TypeBuilder(new TypeFactory);

        return new JsonDecoder(
            new PacketFactory,
            new MessageFactory(new RecordCollectionFactory),
            new QuestionFactory,
            new ResourceBuilder(
                new ResourceFactory,
                new RDataBuilder(
                    new RDataFactory,
                    $typeBuilder
                ),
                $typeDefinitionManager ?: new TypeDefinitionManager(
                    new TypeDefinitionFactory,
                    new FieldDefinitionFactory
                )
            ),
            $typeBuilder,
            new DecodingContextFactory
        );
    }
}
