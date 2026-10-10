<script setup lang="ts">
import { reactive } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import Input from '@/components/ui/input/Input.vue';
import Label from '@/components/ui/label/Label.vue';
import Badge from '@/components/ui/badge/Badge.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import endpointsRoutes from '@/routes/kannel/endpoints';
import { index as kannelEndpointsIndex } from '@/routes/kannel/endpoints';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Kannel endpoints',
                href: kannelEndpointsIndex(),
            },
        ],
    },
});

interface Endpoint {
    id: number;
    name: string;
    admin_url: string;
    url: string;
    admin_user: string | null;
    admin_username: string | null;
    enabled: boolean;
    status: string;
    admin_api_key_header: string | null;
    poll_interval_seconds: number | null;
    timeout_seconds: number | null;
    consecutive_failures: number;
    last_error: string | null;
    last_polled_at: string | null;
    last_success_at: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    endpoints: {
        data: Endpoint[];
    };
    defaults: {
        poll_interval_seconds: number;
        timeout_seconds: number;
    };
}

const props = defineProps<Props>();

const page = usePage<{ errors: Record<string, string> }>();

const createForm = useForm({
    name: '',
    admin_url: 'http://127.0.0.1:13000',
    admin_username: '',
    admin_password: '',
    admin_api_key_header: '',
    admin_api_key_value: '',
    poll_interval_seconds: props.defaults.poll_interval_seconds,
    timeout_seconds: props.defaults.timeout_seconds,
    enabled: true,
});

const testStatus = reactive<
    Record<number, { loading: boolean; success: boolean; message: string }>
>({});

function statusClass(s: string) {
    switch (s) {
        case 'healthy':
            return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
        case 'degraded':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
        case 'unreachable':
            return 'bg-rose-500/15 text-rose-700 dark:text-rose-300';
        case 'idle':
            return 'bg-sky-500/15 text-sky-700 dark:text-sky-300';
        default:
            return 'bg-slate-500/15 text-slate-700 dark:text-slate-300';
    }
}

function submitCreate() {
    createForm.post(endpointsRoutes.store.url(), {
        preserveScroll: true,
    });
}

function submitEdit(endpoint: Endpoint, values: Record<string, unknown>) {
    const payload: Record<string, unknown> = {
        _method: 'PUT',
        ...values,
    };
    const form = useForm(payload);
    form.post(endpointsRoutes.update.form(endpoint).action, {
        preserveScroll: true,
    });
}

function removeEndpoint(endpoint: Endpoint) {
    if (!confirm(`Delete endpoint "${endpoint.name}"?`)) {
        return;
    }
    const form = useForm({});
    form.post(endpointsRoutes.destroy.form(endpoint).action, {
        preserveScroll: true,
    });
}

async function testConnection(endpoint: Endpoint) {
    testStatus[endpoint.id] = {
        loading: true,
        success: false,
        message: 'Testing…',
    };
    try {
        const resp = await fetch(endpointsRoutes.test.url(endpoint), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN':
                    (
                        document.querySelector(
                            'meta[name="csrf-token"]',
                        ) as HTMLMetaElement | null
                    )?.content ?? '',
            },
            body: JSON.stringify({}),
        });
        let data: { success?: boolean; message?: string } | null = null;
        try {
            data = (await resp.json()) as {
                success?: boolean;
                message?: string;
            };
        } catch {
            /* ignore JSON parse errors */
        }
        if (!resp.ok) {
            let msg = `HTTP ${resp.status}`;
            if (data?.message) msg = `${msg} · ${data.message}`;
            testStatus[endpoint.id] = {
                loading: false,
                success: false,
                message: msg,
            };
            router.reload({
                only: ['endpoints'],
                preserveScroll: true,
                preserveState: true,
            });
            return;
        }
        testStatus[endpoint.id] = {
            loading: false,
            success: !!data?.success,
            message: data?.message ?? 'OK',
        };
        router.reload({
            only: ['endpoints'],
            preserveScroll: true,
            preserveState: true,
        });
    } catch (err) {
        testStatus[endpoint.id] = {
            loading: false,
            success: false,
            message: err instanceof Error ? err.message : 'Network error',
        };
        router.reload({
            only: ['endpoints'],
            preserveScroll: true,
            preserveState: true,
        });
    }
}
</script>

<template>
    <div class="mx-auto w-full max-w-7xl space-y-8 px-4 py-6 sm:px-6 lg:px-8">
        <Heading
            title="Kannel endpoints"
            description="Configure the Kannel admin endpoints the monitor should poll for metrics."
        />

        <Card>
            <CardHeader>
                <CardTitle>Add endpoint</CardTitle>
                <CardDescription
                    >New endpoints are enabled by default and will be picked up
                    by the next scheduler cycle.</CardDescription
                >
            </CardHeader>
            <CardContent class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-2">
                    <Label for="cr-name">Display name</Label>
                    <Input
                        id="cr-name"
                        v-model="createForm.name"
                        placeholder="Production primary"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.name ?? createForm.errors.name
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="cr-url">Kannel admin URL (base)</Label>
                    <Input
                        id="cr-url"
                        v-model="createForm.admin_url"
                        placeholder="http://10.0.0.2:13000"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.url ??
                            page.props.errors.admin_url ??
                            createForm.errors.admin_url
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="cr-user"
                        >Admin username (HTTP basic auth)</Label
                    >
                    <Input
                        id="cr-user"
                        v-model="createForm.admin_username"
                        placeholder="kannel"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.admin_user ??
                            page.props.errors.admin_username ??
                            createForm.errors.admin_username
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="cr-pass">Admin password</Label>
                    <Input
                        id="cr-pass"
                        type="password"
                        v-model="createForm.admin_password"
                        placeholder="••••••••"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.admin_password ??
                            createForm.errors.admin_password
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="cr-int">Poll interval (seconds)</Label>
                    <Input
                        id="cr-int"
                        type="number"
                        min="5"
                        max="3600"
                        v-model.number="createForm.poll_interval_seconds"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.poll_interval_seconds ??
                            createForm.errors.poll_interval_seconds
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="cr-tm">HTTP timeout (seconds)</Label>
                    <Input
                        id="cr-tm"
                        type="number"
                        min="1"
                        max="120"
                        v-model.number="createForm.timeout_seconds"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.timeout_seconds ??
                            createForm.errors.timeout_seconds
                        "
                    />
                </div>
                <div class="space-y-2 md:col-span-2">
                    <Label for="cr-key-hdr">Optional API key header</Label>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Input
                            id="cr-key-hdr"
                            v-model="createForm.admin_api_key_header"
                            placeholder="X-API-Key"
                        />
                        <Input
                            type="password"
                            v-model="createForm.admin_api_key_value"
                            placeholder="Key value (encrypted at rest)"
                        />
                    </div>
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.admin_api_key_header ??
                            page.props.errors.admin_api_key_value
                        "
                    />
                </div>
                <div class="flex items-center gap-3 md:col-span-2">
                    <input
                        id="cr-enabled"
                        type="checkbox"
                        class="h-4 w-4 rounded border"
                        v-model.checked="createForm.enabled"
                    />
                    <Label for="cr-enabled">Enabled immediately</Label>
                    <Button
                        :disabled="createForm.processing"
                        class="ml-auto"
                        @click="submitCreate"
                    >
                        {{
                            createForm.processing
                                ? 'Saving…'
                                : 'Create endpoint'
                        }}
                    </Button>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Configured endpoints</CardTitle>
                <CardDescription>
                    {{ props.endpoints.data.length }} configured.
                </CardDescription>
            </CardHeader>

            <CardContent>
                <div
                    v-if="props.endpoints.data.length === 0"
                    class="text-muted-foreground py-8 text-center text-sm"
                >
                    No endpoints yet. Add one above to start monitoring.
                </div>

                <div v-else class="-mx-6 -mt-6 divide-y">
                    <div
                        v-for="ep in props.endpoints.data"
                        :key="ep.id"
                        class="space-y-4 p-6"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold">{{ ep.name }}</h3>
                                    <Badge
                                        :class="statusClass(ep.status)"
                                        variant="secondary"
                                        >{{ ep.status }}</Badge
                                    >
                                    <Badge
                                        v-if="!ep.enabled"
                                        variant="outline"
                                        class="opacity-70"
                                        >Disabled</Badge
                                    >
                                    <Badge
                                        v-if="ep.consecutive_failures > 0"
                                        variant="secondary"
                                        class="bg-amber-500/15 text-amber-700 dark:text-amber-300"
                                    >
                                        {{
                                            ep.consecutive_failures
                                        }}
                                        consecutive failures
                                    </Badge>
                                </div>
                                <div class="text-muted-foreground text-sm">
                                    {{ ep.admin_url }}
                                </div>
                                <div
                                    v-if="ep.last_error"
                                    class="text-xs text-rose-600 dark:text-rose-400"
                                >
                                    Last error: {{ ep.last_error }}
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="!!testStatus[ep.id]?.loading"
                                    @click="testConnection(ep)"
                                >
                                    {{
                                        testStatus[ep.id]?.loading
                                            ? 'Testing…'
                                            : 'Test connection'
                                    }}
                                </Button>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    @click="removeEndpoint(ep)"
                                    >Delete</Button
                                >
                            </div>
                        </div>
                        <div
                            v-if="testStatus[ep.id]"
                            class="mt-2 flex items-start gap-2 rounded-md border p-2 text-xs"
                            :class="[
                                testStatus[ep.id].loading
                                    ? 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300'
                                    : testStatus[ep.id].success
                                      ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
                                      : 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300',
                            ]"
                        >
                            <span aria-hidden="true" class="shrink-0 leading-4">
                                <template v-if="testStatus[ep.id].loading"
                                    >⏳</template
                                >
                                <template v-else-if="testStatus[ep.id].success"
                                    >✅</template
                                >
                                <template v-else>❌</template>
                            </span>
                            <span
                                class="leading-4 break-all whitespace-pre-wrap"
                                >{{ testStatus[ep.id].message }}</span
                            >
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
