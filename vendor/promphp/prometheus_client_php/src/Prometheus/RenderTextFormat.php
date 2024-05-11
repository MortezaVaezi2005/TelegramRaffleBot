<?php

declare(strict_types=1);

namespace Prometheus;

use RuntimeException;
use Throwable;

class RenderTextFormat implements RendererInterface
{
    const MIME_TYPE = 'text/plain; version=0.0.4';

    /**
     * @param MetricFamilySamples[] $metrics
     * @param bool $silent If true, render value errors as comments instead of throwing them.
     * @return string
     */
    public function render(array $metrics, bool $silent = false): string
    {
        usort($metrics, function (MetricFamilySamples $a, MetricFamilySamples $b): int {
            return strcmp($a->getName(), $b->getName());
        });

        $lines = [];
        foreach ($metrics as $metric) {
            $lines[] = "# HELP " . $metric->getName() . " {$metric->getHelp()}";
            $lines[] = "# TYPE " . $metric->getName() . " {$metric->getType()}";
            foreach ($metric->getSamples() as $sample) {
                try {
                    $lines[] = $this->renderSample($metric, $sample);
                } catch (Throwable $e) {
                    // Redis and RedisNg allow samples with mismatching labels to be stored, which could cause ValueError
                    // to be thrown when rendering. If this happens, users can decide whether to ignore the error or not.
                    // These errors will normally disappear after the storage is flushed.
                    if (!$silent) {
                        throw $e;
                    }

                    $lines[] = "# Error: {$e->getMessage()}";
                    $lines[] = "#   Labels: " . json_encode(array_merge($metric->getLabelNames(), $sample->getLabelNames()));
                    $lines[] = "#   Values: " . json_encode(array_merge($sample->getLabelValues()));
                }
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param MetricFamilySamples $metric
     * @param Sample $sample
     * @return string
     */
    private function renderSample(MetricFamilySamples $metric, Sample $sample): string
    {
        $labelNames = $metric->getLabelNames();
        if ($metric->hasLabelNames() || $sample->hasLabelNames()) {
            $escapedLabels = $this->escapeAllLabels($metric, $labelNames, $sample);
            return $sample->getName() . '{' . implode(',', $escapedLabels) . '} ' . $sample->getValue();
        }
        return $sample->getName() . ' ' . $sample->getValue();
    }

    /**
     * @param string $v
     * @return string
     */
    private function escapeLabelValue(string $v): string
    {
        return str_replace(["\\", "\n", "\""], ["\\\\", "\\n", "\\\""], $v);
    }

    /**
     * @param MetricFamilySamples $metric
     * @param string[] $labelNames
     * @param Sample $sample
     *
     * @return string[]
     */
    private function escapeAllLabels(MetricFamilySamples $metric, array $labelNames, Sample $sample): array
    {
        $escapedLabels = [];

        $labels = array_combine(array_merge($labelNames, $sample->getLabelNames()), $sample->getLabelValues());

        if ($labels === false) {
            throw new RuntimeException('Unable to combine labels for metric named ' . $metric->getName());
        }

        foreach ($labels as $labelName => $labelValue) {
            $escapedLabels[] = $labelName . '="' . $this->escapeLabelValue((string)$labelValue) . '"';
        }

        return $escapedLabels;
    }
}
