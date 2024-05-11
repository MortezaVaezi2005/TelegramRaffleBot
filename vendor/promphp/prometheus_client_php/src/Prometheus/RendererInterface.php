<?php

namespace Prometheus;

interface RendererInterface
{
    /**
     * @param MetricFamilySamples[] $metrics
     *
     * @return string
     */
    public function render(array $metrics): string;
}
