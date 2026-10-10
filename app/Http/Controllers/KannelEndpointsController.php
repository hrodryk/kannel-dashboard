<?php

namespace App\Http\Controllers;

use App\Enums\EndpointStatus;
use App\Models\KannelEndpoint;
use App\Services\Kannel\MetricsCollector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KannelEndpointsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('kannel/Endpoints', [
            'endpoints' => KannelEndpoint::query()
                ->orderBy('name')
                ->paginate($request->integer('per_page', 25)),
            'defaults' => [
                'poll_interval_seconds' => config('kannel.poll_interval_seconds', 30),
                'timeout_seconds' => (int) config('kannel.http.timeout_seconds', 10),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:kannel_endpoints,name'],
            'admin_url' => ['required', 'string', 'url', 'max:511', 'regex:~^https?://~i'],
            'admin_username' => ['nullable', 'string', 'max:127'],
            'admin_password' => ['nullable', 'string', 'max:255'],
            'admin_api_key_header' => ['nullable', 'string', 'max:127'],
            'admin_api_key_value' => ['nullable', 'string', 'max:255'],
            'poll_interval_seconds' => ['nullable', 'integer', 'min:5', 'max:3600'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'enabled' => ['boolean'],
        ]);

        $endpoint = new KannelEndpoint;
        $endpoint->fill($valid);
        $endpoint->status = EndpointStatus::Idle;
        $endpoint->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kannel endpoint created.')]);

        return to_route('kannel.endpoints.index');
    }

    public function update(Request $request, KannelEndpoint $endpoint): RedirectResponse
    {
        $valid = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:kannel_endpoints,name,'.$endpoint->id],
            'admin_url' => ['required', 'string', 'url', 'max:511', 'regex:~^https?://~i'],
            'admin_username' => ['nullable', 'string', 'max:127'],
            'admin_password' => ['nullable', 'string', 'max:255'],
            'admin_api_key_header' => ['nullable', 'string', 'max:127'],
            'admin_api_key_value' => ['nullable', 'string', 'max:255'],
            'poll_interval_seconds' => ['nullable', 'integer', 'min:5', 'max:3600'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'enabled' => ['boolean'],
        ]);

        if (isset($valid['admin_password']) && $valid['admin_password'] === '') {
            unset($valid['admin_password']);
        }
        if (isset($valid['admin_api_key_value']) && $valid['admin_api_key_value'] === '') {
            unset($valid['admin_api_key_value']);
        }

        $endpoint->update($valid);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kannel endpoint updated.')]);

        return to_route('kannel.endpoints.index');
    }

    public function destroy(KannelEndpoint $endpoint): RedirectResponse
    {
        $endpoint->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kannel endpoint deleted.')]);

        return to_route('kannel.endpoints.index');
    }

    /**
     * @return RedirectResponse|array{success:bool,message:string,metrics?:array<string, mixed>}
     *
     * @throws AuthorizationException
     */
    public function test(Request $request, KannelEndpoint $endpoint, MetricsCollector $collector)
    {
        if (! $request->user()->can('manage_kannel')) {
            throw new AuthorizationException;
        }

        try {
            $result = $collector->collect($endpoint);
        } catch (\Throwable $e) {
            $endpoint->markUnhealthy($e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $fetch = $result['fetch'] ?? null;

        if (! ($fetch?->success ?? false)) {
            $endpoint->markUnhealthy((string) ($fetch?->errorMessage ?? 'Unknown failure'));

            return [
                'success' => false,
                'message' => $fetch?->errorMessage ?? 'Unknown failure',
                'http_status' => $fetch?->status ?? 0,
            ];
        }

        $endpoint->markHealthy();

        return [
            'success' => true,
            'message' => sprintf(
                'Connection OK in %dms: collected %d SMSCs, %d errors.',
                (int) ($fetch?->durationMs ?? 0),
                count($result['smscs'] ?? []),
                count($result['snapshot']?->errors ?? []),
            ),
            'http_status' => $fetch?->status ?? 0,
            'duration_ms' => (int) ($fetch?->durationMs ?? 0),
            'format' => $result['format'] ?? null,
            'uptime_seconds' => $result['snapshot']?->uptimeSeconds ?? null,
        ];
    }
}
