<?php

declare(strict_types=1);

namespace Prometheus;

class MetricFamilySamples
{
    /**
     * @var mixed
     */
    private $name;

    /**
     * @var string
     */
    private $type;

    /**
     * @var string
     */
    private $help;

    /**
     * @var string[]
     */
    private $labelNames;

    /**
     * @var Sample[]
     */
    private $samples = [];

    /**
     * @param mixed[] $data
     */
    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->type = $data['type'];
        $this->help = $data['help'];
        $this->labelNames = $data['labelNames'];
        if (isset($data['samples'])) {
            foreach ($data['samples'] as $sampleData) {
                $this->samples[] = new Sample($sampleData);
            }
        }
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return $this->help;
    }

    /**
     * @return Sample[]
     */
    public function getSamples(): array
    {
        return $this->samples;
    }

    /**
     * @return string[]
     */
    public function getLabelNames(): array
    {
        return $this->labelNames;
    }

    /**
     * @return bool
     */
    public function hasLabelNames(): bool
    {
        return $this->labelNames !== [];
    }
}
