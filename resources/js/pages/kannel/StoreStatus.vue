<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
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
import { index as kannelStoreStatusIndex } from '@/routes/kannel/store-status';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Store Status',
                href: kannelStoreStatusIndex(),
            },
        ],
    },
});

interface EndpointOption {
    id: number;
    name: string;
    status: string | null;
    enabled: boolean;
    url: string;
}

interface MessageRow {
    id: string;
    type: string;
    time: string | null;
    sender: string | null;
    receiver: string | null;
    smsc_id: string | null;
    box_id: string | null;
    flags: string | null;
    udh_data: string | null;
    msg_data: string | null;
    msg_data_decoded: string | null;
}

interface FetchStatus {
    status: number | null;
    duration_ms: number | null;
    error: string | null;
    ok: boolean;
    used_url: string | null;
    response_preview: string | null;
}

const props = defineProps<{
    endpoints: EndpointOption[];
    selected_endpoint_id: number | null;
    filters: {
        search: string | null;
        type: string | null;
        endpoint_id: number | null;
    };
    messages: MessageRow[];
    message_count: number;
    available_types: string[];
    fetch: FetchStatus;
}>();

const page = usePage<{
    can_manage_kannel: boolean;
}>();

const selectedEndpointId = ref<number | null>(props.selected_endpoint_id);
const search = ref<string>(props.filters.search ?? '');
const typeFilter = ref<string>(props.filters.type ?? '');
const lastRefreshed = ref<Date | null>(
    props.fetch.ok && props.selected_endpoint_id != null ? new Date() : null,
);
const autoRefresh = ref<boolean>(true);
const expandedId = ref<string | null>(null);

let refreshTimer: ReturnType<typeof setInterval> | null = null;

function scheduleAutoRefresh() {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
    if (!autoRefresh.value || selectedEndpointId.value == null) {
        return;
    }
    refreshTimer = setInterval(() => {
        reloadSilent();
    }, 10_000);
}

onMounted(() => {
    scheduleAutoRefresh();
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        clearInterval(refreshTimer);
    }
});

function toggleAuto() {
    autoRefresh.value = !autoRefresh.value;
    scheduleAutoRefresh();
}

function applyFilters() {
    reloadSilent();
}

function resetFilters() {
    search.value = '';
    typeFilter.value = '';
    reloadSilent();
}

function reloadSilent() {
    if (selectedEndpointId.value == null) {
        return;
    }
    router.reload({
        data: {
            endpoint_id: selectedEndpointId.value,
            search: search.value || undefined,
            type: typeFilter.value || undefined,
        },
        only: [
            'endpoints',
            'selected_endpoint_id',
            'filters',
            'messages',
            'message_count',
            'available_types',
            'fetch',
        ],
        onSuccess: () => {
            lastRefreshed.value = new Date();
        },
        preserveScroll: true,
        preserveState: true,
    });
}

function changeEndpoint(value: string) {
    selectedEndpointId.value = value === '' ? null : Number(value);
    reloadSilent();
    scheduleAutoRefresh();
}

const mtCount = computed(
    () => props.messages.filter((m) => m.type.startsWith('MT')).length,
);
const moCount = computed(
    () => props.messages.filter((m) => m.type.startsWith('MO')).length,
);
const dlrCount = computed(
    () =>
        props.messages.filter(
            (m) => m.type.includes('DLR') || m.type.includes('RECEIPT'),
        ).length,
);

function statusBadgeClass(ok: boolean) {
    return ok
        ? 'bg-emerald-500/15 text-emerald-700 ring-1 ring-emerald-500/30'
        : 'bg-rose-500/15 text-rose-700 ring-1 ring-rose-500/30';
}

function typeBadgeClass(type: string) {
    if (type.startsWith('MT'))
        return 'bg-blue-500/15 text-blue-700 ring-1 ring-blue-500/30';
    if (type.startsWith('MO'))
        return 'bg-emerald-500/15 text-emerald-700 ring-1 ring-emerald-500/30';
    if (type.includes('DLR') || type.includes('RECEIPT'))
        return 'bg-violet-500/15 text-violet-700 ring-1 ring-violet-500/30';
    return 'bg-slate-500/15 text-slate-700 ring-1 ring-slate-500/30';
}

function toggleExpand(id: string) {
    expandedId.value = expandedId.value === id ? null : id;
}

function formatTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString();
}
</script>

<template>
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <Heading
            title="Store Status — Fila do Kannel"
            description="Consulta em tempo real o endpoint /store-status.xml do Kannel e lista todas as mensagens pendentes."
        />
        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <CardTitle>Filtros e status da coleta</CardTitle>
                        <CardDescription
                            >Selecione o endpoint, filtre por tipo/texto e use
                            refresh automático para acompanhar a fila
                            LIVE.</CardDescription
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge
                            :class="
                                statusBadgeClass(
                                    fetch.ok && selected_endpoint_id != null,
                                )
                            "
                            variant="muted"
                        >
                            {{
                                fetch.ok && selected_endpoint_id != null
                                    ? 'OK'
                                    : fetch.error
                                      ? 'Falha'
                                      : 'Nenhum endpoint'
                            }}
                        </Badge>
                        <Button
                            :variant="autoRefresh ? 'primary' : 'ghost'"
                            :size="'xs'"
                            type="button"
                            @click="toggleAuto()"
                        >
                            {{ autoRefresh ? 'Auto ON' : 'Auto OFF' }}
                        </Button>
                        <Button
                            variant="secondary"
                            size="xs"
                            type="button"
                            :disabled="selected_endpoint_id == null"
                            @click="reloadSilent()"
                        >
                            Atualizar
                        </Button>
                    </div>
                </div>
            </CardHeader>

            <CardContent class="space-y-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <div class="flex items-center gap-2">
                        <Label class="whitespace-nowrap">Endpoint</Label>
                        <select
                            class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900"
                            :value="String(selected_endpoint_id ?? '')"
                            @change="
                                (e) =>
                                    changeEndpoint(
                                        (e.target as HTMLSelectElement).value,
                                    )
                            "
                        >
                            <option value="" disabled>
                                Selecione um endpoint
                            </option>
                            <option
                                v-for="ep in endpoints"
                                :key="ep.id"
                                :value="String(ep.id)"
                            >
                                {{ ep.name }} ({{
                                    ep.enabled ? 'ativo' : 'desativado'
                                }})
                            </option>
                        </select>
                    </div>

                    <div class="flex items-center gap-2">
                        <Label>Buscar</Label>
                        <Input
                            v-model="search"
                            placeholder="sender, receiver, msg..."
                            @keydown.enter="applyFilters()"
                        />
                    </div>

                    <div class="flex items-center gap-2">
                        <Label>Tipo</Label>
                        <select
                            class="h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-900"
                            :value="typeFilter"
                            @change="
                                (e) => {
                                    typeFilter = (e.target as HTMLSelectElement)
                                        .value;
                                    applyFilters();
                                }
                            "
                        >
                            <option value="">
                                Todos ({{ message_count }})
                            </option>
                            <option
                                v-for="t in available_types"
                                :key="t"
                                :value="t"
                            >
                                {{ t }}
                            </option>
                        </select>
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="xs"
                            @click="resetFilters()"
                        >
                            Limpar
                        </Button>
                    </div>
                </div>

                <div v-if="selected_endpoint_id != null && !fetch.ok">
                    <div
                        class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300"
                    >
                        <div class="font-semibold">
                            Não foi possível consultar store-status.xml
                        </div>
                        <div class="mt-1 whitespace-pre-wrap">
                            {{ fetch.error ?? 'Erro desconhecido' }}
                        </div>
                    </div>
                </div>

                <div
                    v-if="selected_endpoint_id != null && fetch.used_url"
                    class="text-xs break-all text-slate-500 dark:text-slate-400"
                >
                    <span
                        class="font-semibold text-slate-700 dark:text-slate-300"
                        >URL consultada:</span
                    >
                    <code
                        class="rounded bg-slate-100 px-1.5 py-0.5 dark:bg-slate-800"
                        >{{ fetch.used_url }}</code
                    >
                </div>

                <div
                    v-if="selected_endpoint_id != null"
                    class="grid grid-cols-2 gap-2 text-sm text-slate-600 sm:grid-cols-4 dark:text-slate-400"
                >
                    <div>
                        Total:
                        <span
                            class="font-semibold text-slate-900 dark:text-slate-100"
                            >{{ message_count }}</span
                        >
                    </div>
                    <div>
                        MT:
                        <span
                            class="font-semibold text-slate-900 dark:text-slate-100"
                            >{{ mtCount }}</span
                        >
                    </div>
                    <div>
                        MO:
                        <span
                            class="font-semibold text-slate-900 dark:text-slate-100"
                            >{{ moCount }}</span
                        >
                    </div>
                    <div>
                        DLR:
                        <span
                            class="font-semibold text-slate-900 dark:text-slate-100"
                            >{{ dlrCount }}</span
                        >
                    </div>
                </div>

                <div class="text-xs text-slate-500 dark:text-slate-400">
                    <template v-if="lastRefreshed"
                        >Última atualização:
                        {{ lastRefreshed.toLocaleString() }}</template
                    >
                    <template
                        v-if="
                            fetch.duration_ms != null &&
                            selected_endpoint_id != null
                        "
                    >
                        · Latência: {{ fetch.duration_ms }}ms · HTTP
                        {{ fetch.status }}</template
                    >
                </div>

                <div
                    v-if="selected_endpoint_id == null"
                    class="flex min-h-[240px] flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50/40 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-400"
                >
                    Selecione um endpoint Kannel acima para consultar a fila de
                    mensagens pendentes.
                </div>

                <div v-else-if="messages.length === 0" class="space-y-3">
                    <div
                        class="flex min-h-[200px] flex-col items-center justify-center rounded-lg border border-dashed border-emerald-300 bg-emerald-50/40 text-center text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300"
                    >
                        <div class="text-lg font-semibold">Fila vazia ✅</div>
                        <div>
                            Não há mensagens pendentes no store do Kannel.
                        </div>
                    </div>

                    <div
                        v-if="fetch.response_preview"
                        class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900/60"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <div
                                class="text-xs font-semibold tracking-wider text-slate-500 uppercase dark:text-slate-400"
                            >
                                Resposta bruta (preview — parser retornou 0
                                mensagens)
                            </div>
                            <Badge variant="outline" size="xs"
                                >{{ fetch.status }} bytes:
                                {{ fetch.response_preview.length }}</Badge
                            >
                        </div>
                        <pre
                            class="max-h-80 overflow-auto rounded bg-slate-950 p-3 text-xs break-all whitespace-pre-wrap text-slate-100 dark:bg-black/60"
                            >{{ fetch.response_preview }}</pre>
                    </div>
                </div>

                <div v-else class="overflow-x-auto">
                    <table
                        class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800"
                    >
                        <thead
                            class="bg-slate-50/60 text-left text-xs tracking-wider text-slate-500 uppercase dark:bg-slate-900/60 dark:text-slate-400"
                        >
                            <tr>
                                <th class="w-10 px-3 py-2"></th>
                                <th class="px-3 py-2">Tempo</th>
                                <th class="px-3 py-2">Tipo</th>
                                <th class="px-3 py-2">Sender</th>
                                <th class="px-3 py-2">Receiver</th>
                                <th class="px-3 py-2">SMSC</th>
                                <th class="px-3 py-2">Mensagem</th>
                                <th class="px-3 py-2">Flags</th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-slate-100 dark:divide-slate-800/60"
                        >
                            <tr
                                v-for="m in messages"
                                :key="m.id"
                                class="align-top hover:bg-slate-50/60 dark:hover:bg-slate-900/40"
                            >
                                <td class="px-3 py-2">
                                    <Button
                                        variant="ghost"
                                        size="xs"
                                        type="button"
                                        @click="toggleExpand(m.id)"
                                        class="font-mono"
                                    >
                                        {{ expandedId === m.id ? '–' : '+' }}
                                    </Button>
                                </td>
                                <td
                                    class="px-3 py-2 whitespace-nowrap text-slate-600 dark:text-slate-400"
                                >
                                    {{ formatTime(m.time) }}
                                </td>
                                <td class="px-3 py-2">
                                    <Badge
                                        :class="typeBadgeClass(m.type)"
                                        variant="muted"
                                        size="sm"
                                    >
                                        {{ m.type }}
                                    </Badge>
                                </td>
                                <td
                                    class="px-3 py-2 font-mono whitespace-nowrap text-slate-700 dark:text-slate-300"
                                >
                                    {{ m.sender ?? '—' }}
                                </td>
                                <td
                                    class="px-3 py-2 font-mono whitespace-nowrap text-slate-700 dark:text-slate-300"
                                >
                                    {{ m.receiver ?? '—' }}
                                </td>
                                <td
                                    class="px-3 py-2 whitespace-nowrap text-slate-500 dark:text-slate-400"
                                >
                                    {{ m.smsc_id ?? '—' }}
                                </td>
                                <td
                                    class="px-3 py-2 font-mono text-xs text-slate-700 dark:text-slate-300"
                                >
                                    <div class="line-clamp-2">
                                        {{
                                            m.msg_data_decoded ??
                                            m.msg_data ??
                                            '—'
                                        }}
                                    </div>
                                </td>
                                <td
                                    class="px-3 py-2 font-mono text-xs whitespace-nowrap text-slate-500 dark:text-slate-400"
                                >
                                    {{ m.flags ?? '—' }}
                                </td>
                            </tr>
                            <tr
                                v-for="m in messages.filter(
                                    (x) => expandedId === x.id,
                                )"
                                :key="'exp-' + m.id"
                                class="bg-slate-50/80 dark:bg-slate-900/60"
                            >
                                <td colspan="8" class="px-4 py-3 text-xs">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <div
                                                class="font-semibold text-slate-700 dark:text-slate-300"
                                            >
                                                ID da mensagem
                                            </div>
                                            <div
                                                class="font-mono break-all text-slate-600 dark:text-slate-400"
                                            >
                                                {{ m.id }}
                                            </div>
                                        </div>
                                        <div>
                                            <div
                                                class="font-semibold text-slate-700 dark:text-slate-300"
                                            >
                                                Box ID
                                            </div>
                                            <div
                                                class="font-mono text-slate-600 dark:text-slate-400"
                                            >
                                                {{ m.box_id ?? '—' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div
                                                class="font-semibold text-slate-700 dark:text-slate-300"
                                            >
                                                UDH Data (hex)
                                            </div>
                                            <div
                                                class="font-mono break-all text-slate-600 dark:text-slate-400"
                                            >
                                                {{ m.udh_data ?? '—' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div
                                                class="font-semibold text-slate-700 dark:text-slate-300"
                                            >
                                                Raw msg-data (URL-encoded)
                                            </div>
                                            <div
                                                class="font-mono break-all text-slate-600 dark:text-slate-400"
                                            >
                                                {{ m.msg_data ?? '—' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
