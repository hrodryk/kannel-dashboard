<?php

namespace App\Services\Kannel;

use App\Contracts\KannelMetricsParser;
use App\DataObjects\Kannel\HttpFetchResult;
use App\DataObjects\Kannel\KannelSnapshotData;
use App\DataObjects\Kannel\SmscMetricsData;
use App\DataObjects\Kannel\StoreMessageData;
use App\Models\KannelEndpoint;
use RuntimeException;

class MetricsCollector
{
    public function __construct(
        protected HttpClient $httpClient,
        protected XmlStatusParser $xmlParser,
        protected JsonStatusParser $jsonParser,
        protected StoreStatusXmlParser $storeParser,
    ) {}

    /**
     * @return array{
     *     snapshot: KannelSnapshotData,
     *     smscs: list<SmscMetricsData>,
     *     fetch: HttpFetchResult,
     *     format: string,
     * }
     */
    public function collect(KannelEndpoint $endpoint, bool $isWebContext = false): array
    {
        $format = $this->detectPreferredFormat($endpoint);
        $fetch = $this->httpClient->fetchStatus($endpoint, $format, $isWebContext);

        if (! $fetch->success) {
            return [
                'snapshot' => new KannelSnapshotData(collectedAt: new \DateTimeImmutable),
                'smscs' => [],
                'fetch' => $fetch,
                'format' => $format,
            ];
        }

        $parser = $this->resolveParser($fetch, $format);
        [$snapshot, $smscs] = $parser->parse($fetch->body);

        return [
            'snapshot' => $snapshot,
            'smscs' => $smscs,
            'fetch' => $fetch,
            'format' => $format,
        ];
    }

    /**
     * @return array{
     *     messages: list<StoreMessageData>,
     *     fetch: HttpFetchResult,
     * }
     */
    public function collectStoreStatus(KannelEndpoint $endpoint, bool $isWebContext = false): array
    {
        $fetch = $this->httpClient->fetchStoreStatus($endpoint, $isWebContext);

        if (! $fetch->success) {
            return [
                'messages' => [],
                'fetch' => $fetch,
            ];
        }

        return [
            'messages' => $this->storeParser->parse($fetch->body),
            'fetch' => $fetch,
        ];
    }

    private function resolveParser(HttpFetchResult $fetch, string $format): KannelMetricsParser
    {
        $payloadTrim = ltrim($fetch->body);
        if ($format === 'json' || str_starts_with($payloadTrim, '{') || str_starts_with($payloadTrim, '[')) {
            return $this->jsonParser;
        }

        if ($format === 'xml' || str_starts_with($payloadTrim, '<')) {
            return $this->xmlParser;
        }

        throw new RuntimeException('Unsupported Kannel status payload format.');
    }

    private function detectPreferredFormat(KannelEndpoint $endpoint): string
    {
        $url = strtolower(rtrim($endpoint->admin_url ?? '', '/'));
        if (str_ends_with($url, '.json')) {
            return 'json';
        }

        return 'xml';
    }
}
