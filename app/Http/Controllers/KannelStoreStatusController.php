<?php

namespace App\Http\Controllers;

use App\DataObjects\Kannel\StoreMessageData;
use App\Models\KannelEndpoint;
use App\Services\Kannel\MetricsCollector;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KannelStoreStatusController extends Controller
{
    public function __construct(
        protected MetricsCollector $metricsCollector,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint_id' => ['nullable', 'integer', 'exists:kannel_endpoints,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        $endpoints = KannelEndpoint::query()
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'enabled', 'admin_url']);

        $selectedEndpointId = isset($validated['endpoint_id'])
            ? (int) $validated['endpoint_id']
            : $endpoints->where('enabled', true)->first()?->id;

        $messages = [];
        $fetchStatus = null;
        $fetchDuration = null;
        $fetchError = null;
        $usedUrl = null;
        $fetch = null;

        if ($selectedEndpointId !== null) {
            $endpoint = KannelEndpoint::query()->findOrFail($selectedEndpointId);
            $usedUrl = $endpoint->storeStatusUrl();
            $result = $this->metricsCollector->collectStoreStatus($endpoint, true);
            $messages = $result['messages'];
            $fetch = $result['fetch'];
            $fetchStatus = $fetch->status;
            $fetchDuration = $fetch->durationMs;
            $fetchError = $fetch->errorMessage ?? null;
            if ($fetch->success === false) {
                $fetchError = trim(sprintf('HTTP %s · %s (%s)', (string) $fetch->status, $fetchError ?? 'Falha ao consultar store-status.xml', $usedUrl));
            }
            $messages = $this->filterMessages(
                $messages,
                search: $validated['search'] ?? null,
                type: $validated['type'] ?? null,
            );
        }

        $grouped = [];
        foreach ($messages as $msg) {
            $grouped[] = $this->serializeMessage($msg);
        }

        $availableTypes = collect($messages)->map(fn (StoreMessageData $m): string => $m->type)->unique()->sort()->values()->all();

        return Inertia::render('kannel/StoreStatus', [
            'endpoints' => $endpoints->map(fn (KannelEndpoint $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'status' => $e->status instanceof \BackedEnum ? $e->status->value : (is_string($e->status) ? $e->status : null),
                'enabled' => (bool) $e->enabled,
                'url' => $e->admin_url,
            ])->all(),
            'selected_endpoint_id' => $selectedEndpointId,
            'filters' => [
                'search' => $validated['search'] ?? null,
                'type' => $validated['type'] ?? null,
                'endpoint_id' => $selectedEndpointId,
            ],
            'messages' => $grouped,
            'message_count' => count($messages),
            'available_types' => $availableTypes,
            'fetch' => [
                'status' => $fetchStatus,
                'duration_ms' => $fetchDuration,
                'error' => $fetchError,
                'ok' => $fetchError === null,
                'used_url' => $usedUrl,
                'response_preview' => isset($fetch) && $fetch->success && count($messages) === 0
                    ? mb_substr((string) $fetch->body, 0, 400)
                    : null,
            ],
        ]);
    }

    /**
     * @param  list<StoreMessageData>  $messages
     * @return list<StoreMessageData>
     */
    private function filterMessages(array $messages, ?string $search, ?string $type): array
    {
        if ($type !== null && $type !== '') {
            $messages = array_values(array_filter(
                $messages,
                static fn (StoreMessageData $m): bool => strcasecmp($m->type, $type) === 0,
            ));
        }

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $messages = array_values(array_filter(
                $messages,
                static function (StoreMessageData $m) use ($needle): bool {
                    $pieces = array_filter([
                        $m->id,
                        $m->type,
                        $m->sender,
                        $m->receiver,
                        $m->smscId,
                        $m->boxId,
                        $m->msgData,
                        $m->msgDataDecoded() ?? '',
                        $m->flags,
                    ], 'is_string');

                    return str_contains(mb_strtolower(implode(' ', $pieces)), $needle);
                },
            ));
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(StoreMessageData $m): array
    {
        return [
            'id' => $m->id,
            'type' => $m->type,
            'time' => $m->time?->format('Y-m-d H:i:s'),
            'sender' => $m->sender,
            'receiver' => $m->receiver,
            'smsc_id' => $m->smscId,
            'box_id' => $m->boxId,
            'flags' => $m->flags,
            'udh_data' => $m->udhData,
            'msg_data' => $m->msgData,
            'msg_data_decoded' => $m->msgDataDecoded(),
        ];
    }
}
