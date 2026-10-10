<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import Badge from '@/components/ui/badge/Badge.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { KpiRow, KpiSeriesPoint, QueuesRow, RecentAlert, SmscRow } from '@/types/kannel';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

interface Props {
    can_manage_kannel: boolean;
    poll_interval_seconds: number;
    kpis?: KpiRow | null;
    smsc_grid?: SmscRow[] | null;
    throughput?: KpiSeriesPoint[] | null;
    queues?: QueuesRow[] | null;
    recent_alerts?: RecentAlert[] | null;
}

const props = defineProps<Props>();

const rangeHours = ref<number>(24);
const lastRefreshed = ref<Date>(new Date());
const isRefreshing = ref(false);
let reloadTimer: number | null = null;

const throughput = computed(() => props.throughput ?? []);
const smscs = computed(() => props.smsc_grid ?? []);
const queues = computed(() => props.queues ?? []);
const alerts = computed(() => props.recent_alerts ?? []);
const kpis = computed(
    () =>
        props.kpis ?? {
            total_endpoints: 0,
            active_endpoints: 0,
            unhealthy_endpoints: 0,
            total_smscs: 0,
            smsc_down_count: 0,
            mo_total: 0,
            mt_total: 0,
            mo_rate: 0,
            mt_rate: 0,
            failed_total: 0,
            queue_total: 0,
            last_polled_at: null,
        },
);

const systemHealth = computed<'healthy' | 'degraded' | 'critical' | 'unknown'>(() => {
    if (kpis.value.total_endpoints === 0) {
        return 'unknown';
    }
    if (kpis.value.unhealthy_endpoints > 0 || kpis.value.smsc_down_count > 0) {
        return kpis.value.unhealthy_endpoints > 0 ? 'critical' : 'degraded';
    }
    return 'healthy';
});

const systemHealthLabel = computed(() => {
    switch (systemHealth.value) {
        case 'healthy':
            return 'All systems operational';
        case 'degraded':
            return 'Minor issues detected';
        case 'critical':
            return 'Critical issues detected';
        default:
            return 'Waiting for first data';
    }
});

const healthBadgeClass = computed(() => {
    switch (systemHealth.value) {
        case 'healthy':
            return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
        case 'degraded':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
        case 'critical':
            return 'bg-rose-500/15 text-rose-700 dark:text-rose-300';
        default:
            return 'bg-slate-500/15 text-slate-700 dark:text-slate-300';
    }
});

function refreshNow() {
    if (isRefreshing.value) {
        return;
    }
    isRefreshing.value = true;
    router.reload({
        only: ['kpis', 'smsc_grid', 'throughput', 'queues', 'recent_alerts'],
        data: { range_hours: rangeHours.value },
        onFinish: () => {
            isRefreshing.value = false;
            lastRefreshed.value = new Date();
        },
    });
}

watch(rangeHours, () => refreshNow());

onMounted(() => {
    const intervalMs = Math.max(10_000, props.poll_interval_seconds * 1000);
    reloadTimer = window.setInterval(() => {
        refreshNow();
    }, intervalMs);
});

onBeforeUnmount(() => {
    if (reloadTimer !== null) {
        window.clearInterval(reloadTimer);
    }
});

function formatNumber(v: number, digits = 0): string {
    return Number(v || 0).toLocaleString(undefined, {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });
}

function formatTime(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function stateClass(state: string | null | undefined): string {
    switch (state) {
        case 'connected':
            return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
        case 'binding':
        case 'reconnecting':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
        case 'disconnected':
        case 'unknown':
            return 'bg-rose-500/15 text-rose-700 dark:text-rose-300';
        case 'idle':
            return 'bg-slate-500/15 text-slate-700 dark:text-slate-300';
        default:
            return 'bg-slate-500/15 text-slate-700 dark:text-slate-300';
    }
}

function severityClass(sev: string | null | undefined): string {
    switch (sev) {
        case 'critical':
            return 'bg-rose-500/15 text-rose-700 dark:text-rose-300';
        case 'warning':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
        case 'info':
            return 'bg-sky-500/15 text-sky-700 dark:text-sky-300';
        default:
            return 'bg-slate-500/15 text-slate-700 dark:text-slate-300';
    }
}

const chart = computed(() => {
    const W = 720;
    const H = 256;
    const PAD_L = 52;
    const PAD_R = 12;
    const PAD_T = 18;
    const PAD_B = 30;
    const innerW = W - PAD_L - PAD_R;
    const innerH = H - PAD_T - PAD_B;

    const series = throughput.value ?? [];
    const rates = series.flatMap((p) => [p.mo_rate, p.mt_rate]);
    const maxValRaw = Math.max(1, ...rates);
    const maxVal = Math.ceil(maxValRaw * 1.1);

    const xStep = Math.max(1, series.length - 1);
    const xOf = (i: number) => PAD_L + (i * innerW) / xStep;
    const yOf = (v: number) => PAD_T + innerH - (v / maxVal) * innerH;

    const toPath = (items: KpiSeriesPoint[], key: 'mo_rate' | 'mt_rate'): string => {
        return items.map((p, i) => `${i === 0 ? 'M' : 'L'} ${xOf(i)} ${yOf(p[key])}`).join(' ');
    };

    const toArea = (items: KpiSeriesPoint[], key: 'mo_rate' | 'mt_rate'): string => {
        if (items.length === 0) {
            return '';
        }
        const line = items.map((p, i) => `${i === 0 ? 'M' : 'L'} ${xOf(i)} ${yOf(p[key])}`).join(' ');
        return `${line} L ${xOf(items.length - 1)} ${PAD_T + innerH} L ${xOf(0)} ${PAD_T + innerH} Z`;
    };

    const ticks: number[] = Array.from({ length: 5 }, (_, i) => (maxVal * i) / 4);

    return {
        W,
        H,
        PAD_L,
        PAD_R,
        PAD_T,
        PAD_B,
        maxVal,
        ticks,
        yTicks: ticks.map((v, i) => ({
            label: formatNumber(v, maxVal < 10 ? 1 : 0),
            y: PAD_T + innerH - (i * innerH) / 4,
        })),
        moLine: toPath(series, 'mo_rate'),
        mtLine: toPath(series, 'mt_rate'),
        moArea: toArea(series, 'mo_rate'),
        mtArea: toArea(series, 'mt_rate'),
        bucketFirst: series[0]?.bucket ?? null,
        bucketLast: series[series.length - 1]?.bucket ?? null,
    };
});
</script>

<template>
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8 space-y-6">
        <Head title="Dashboard — Kannel Monitor" />

        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-semibold tracking-tight">Kannel SMPP Monitor</h1>
                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-medium" :class="healthBadgeClass">
                        <span class="relative inline-flex h-2 w-2 rounded-full" :class="[systemHealth === 'healthy' ? 'bg-emerald-500' : systemHealth === 'critical' ? 'bg-rose-500 animate-pulse' : systemHealth === 'degraded' ? 'bg-amber-500' : 'bg-slate-500']" />
                        {{ systemHealthLabel }}
                    </span>
                </div>
                <p class="text-sm text-muted-foreground">
                    Live metrics and alerting for your Kannel SMS gateways.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div role="status" aria-live="polite" class="text-xs text-muted-foreground">
                    Last refreshed {{ lastRefreshed.toLocaleTimeString() }}
                </div>
                <Button size="sm" variant="outline" :disabled="isRefreshing" @click="refreshNow">
                    {{ isRefreshing ? 'Refreshing…' : 'Refresh now' }}
                </Button>
            </div>
        </header>

        <section aria-label="Key metrics" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>Endpoints</CardDescription>
                    <CardTitle class="text-3xl">
                        {{ kpis.active_endpoints }}<span class="text-base font-normal text-muted-foreground"> / {{ kpis.total_endpoints }}</span>
                    </CardTitle>
                </CardHeader>
                <CardContent class="pt-0">
                    <div v-if="kpis.unhealthy_endpoints > 0" class="text-xs text-rose-600 dark:text-rose-400">
                        {{ kpis.unhealthy_endpoints }} unhealthy
                    </div>
                    <div v-else class="text-xs text-emerald-600 dark:text-emerald-400">
                        All endpoints healthy
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>SMSC connections</CardDescription>
                    <CardTitle class="text-3xl">
                        {{ kpis.total_smscs - kpis.smsc_down_count }}<span class="text-base font-normal text-muted-foreground"> / {{ kpis.total_smscs }}</span>
                    </CardTitle>
                </CardHeader>
                <CardContent class="pt-0">
                    <div v-if="kpis.smsc_down_count > 0" class="text-xs text-rose-600 dark:text-rose-400">
                        {{ kpis.smsc_down_count }} down
                    </div>
                    <div v-else class="text-xs text-emerald-600 dark:text-emerald-400">
                        All SMSCs connected
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>MO / MT rates (msg/s)</CardDescription>
                    <CardTitle class="text-3xl">
                        <span>{{ formatNumber(kpis.mo_rate, 1) }}</span>
                        <span class="text-base font-normal text-muted-foreground">MO · </span>
                        <span>{{ formatNumber(kpis.mt_rate, 1) }}</span>
                        <span class="text-base font-normal text-muted-foreground">MT</span>
                    </CardTitle>
                </CardHeader>
                <CardContent class="pt-0 text-xs text-muted-foreground">
                    Lifetime: {{ formatNumber(kpis.mo_total) }} MO · {{ formatNumber(kpis.mt_total) }} MT · {{ formatNumber(kpis.failed_total) }} failed
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-2">
                    <CardDescription>Queued messages</CardDescription>
                    <CardTitle class="text-3xl">{{ formatNumber(kpis.queue_total) }}</CardTitle>
                </CardHeader>
                <CardContent class="pt-0 text-xs text-muted-foreground">
                    Updated {{ formatTime(kpis.last_polled_at) }}
                </CardContent>
            </Card>
        </section>

        <section aria-label="Throughput" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2">
                <CardHeader class="flex flex-row flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle>Throughput (msg/s)</CardTitle>
                        <CardDescription>MO (teal) and MT (indigo) rates. Averages per rolling hour.</CardDescription>
                    </div>
                    <div class="inline-flex rounded-md border" role="tablist" aria-label="Time range">
                        <Button size="sm" :variant="rangeHours === 1 ? 'default' : 'ghost'" @click="rangeHours = 1">1h</Button>
                        <Button size="sm" :variant="rangeHours === 24 ? 'default' : 'ghost'" @click="rangeHours = 24">24h</Button>
                        <Button size="sm" :variant="rangeHours === 24 * 7 ? 'default' : 'ghost'" @click="rangeHours = 24 * 7">7d</Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="throughput.length === 0" class="flex h-64 items-center justify-center text-sm text-muted-foreground">
                        No throughput data — wait for the first kannel:poll run.
                    </div>
                    <div v-else class="w-full overflow-x-auto">
                        <svg :viewBox="`0 0 ${chart.W} ${chart.H}`" class="h-64 w-full min-w-[640px]">
                            <defs>
                                <linearGradient id="moGradient" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" stop-color="#0d9488" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="#0d9488" stop-opacity="0" />
                                </linearGradient>
                                <linearGradient id="mtGradient" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="0%" stop-color="#4f46e5" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="#4f46e5" stop-opacity="0" />
                                </linearGradient>
                            </defs>

                            <g class="text-slate-200/60 dark:text-white/10">
                                <line
                                    v-for="(tick, i) in chart.yTicks"
                                    :key="'grid-'+i"
                                    :x1="chart.PAD_L"
                                    :y1="tick.y"
                                    :x2="chart.W - chart.PAD_R"
                                    :y2="tick.y"
                                    stroke="currentColor"
                                    stroke-dasharray="3 3"
                                />
                            </g>

                            <g class="fill-muted-foreground text-[10px]" font-size="10">
                                <text
                                    v-for="(tick, i) in chart.yTicks"
                                    :key="'yt-'+i"
                                    :x="chart.PAD_L - 8"
                                    :y="tick.y + 3"
                                    text-anchor="end"
                                    fill="currentColor"
                                >
                                    {{ tick.label }}
                                </text>
                            </g>

                            <path :d="chart.mtArea" fill="url(#mtGradient)" />
                            <path :d="chart.moArea" fill="url(#moGradient)" />
                            <path :d="chart.mtLine" fill="none" stroke="#4f46e5" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                            <path :d="chart.moLine" fill="none" stroke="#0d9488" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />

                            <g font-size="10" class="fill-muted-foreground">
                                <text :x="chart.PAD_L" :y="chart.H - 10" text-anchor="start" fill="currentColor">{{ formatTime(chart.bucketFirst) }}</text>
                                <text :x="chart.W - chart.PAD_R" :y="chart.H - 10" text-anchor="end" fill="currentColor">{{ formatTime(chart.bucketLast) }}</text>
                            </g>
                        </svg>
                        <div class="mt-2 flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                            <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-sm bg-teal-600"></span> MO rate</span>
                            <span class="inline-flex items-center gap-2"><span class="h-3 w-3 rounded-sm bg-indigo-600"></span> MT rate</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Queue depth</CardTitle>
                    <CardDescription>Inbound, outbound, and DLR per endpoint.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="queues.length === 0" class="flex h-64 items-center justify-center text-sm text-muted-foreground">
                        No queue data yet.
                    </div>
                    <div v-else class="space-y-3">
                        <div v-for="row in queues" :key="'q-'+row.endpoint_id" class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span class="truncate font-medium">{{ row.endpoint_name }}</span>
                                <span class="text-muted-foreground">
                                    {{ formatNumber(row.inbound_queue + row.outbound_queue + row.dlr_queue) }}
                                </span>
                            </div>
                            <div class="relative h-2.5 w-full overflow-hidden rounded-full bg-slate-200/60 dark:bg-white/10">
                                <div
                                    class="absolute inset-y-0 left-0 h-full bg-sky-500"
                                    :style="{
                                        width: `${(row.inbound_queue) / Math.max(1, row.inbound_queue + row.outbound_queue + row.dlr_queue) * 100}%`,
                                    }"
                                    :title="'In: ' + formatNumber(row.inbound_queue)"
                                />
                                <div
                                    class="absolute inset-y-0 h-full bg-indigo-500"
                                    :style="{
                                        left: `${row.inbound_queue / Math.max(1, row.inbound_queue + row.outbound_queue + row.dlr_queue) * 100}%`,
                                        width: `${row.outbound_queue / Math.max(1, row.inbound_queue + row.outbound_queue + row.dlr_queue) * 100}%`,
                                    }"
                                    :title="'Out: ' + formatNumber(row.outbound_queue)"
                                />
                                <div
                                    class="absolute inset-y-0 h-full bg-amber-500 opacity-75"
                                    :style="{
                                        left: `${(row.inbound_queue + row.outbound_queue) / Math.max(1, row.inbound_queue + row.outbound_queue + row.dlr_queue) * 100}%`,
                                        width: `${row.dlr_queue / Math.max(1, row.inbound_queue + row.outbound_queue + row.dlr_queue) * 100}%`,
                                    }"
                                    :title="'DLR: ' + formatNumber(row.dlr_queue)"
                                />
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </section>

        <Card>
            <CardHeader>
                <CardTitle>SMSC connections</CardTitle>
                <CardDescription>Last known state for every configured SMSC link.</CardDescription>
            </CardHeader>
            <CardContent class="!px-0 !pt-0">
                <div class="w-full overflow-x-auto">
                    <table class="min-w-full divide-y text-sm">
                        <thead class="bg-muted/40">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left font-medium text-muted-foreground">Endpoint</th>
                                <th scope="col" class="px-6 py-3 text-left font-medium text-muted-foreground">SMSC</th>
                                <th scope="col" class="px-6 py-3 text-left font-medium text-muted-foreground">State</th>
                                <th scope="col" class="px-6 py-3 text-right font-medium text-muted-foreground">MO</th>
                                <th scope="col" class="px-6 py-3 text-right font-medium text-muted-foreground">MT</th>
                                <th scope="col" class="px-6 py-3 text-right font-medium text-muted-foreground">MO rate</th>
                                <th scope="col" class="px-6 py-3 text-right font-medium text-muted-foreground">MT rate</th>
                                <th scope="col" class="px-6 py-3 text-right font-medium text-muted-foreground">Latency</th>
                                <th scope="col" class="px-6 py-3 text-right font-medium text-muted-foreground">Last seen</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-if="smscs.length === 0">
                                <td colspan="9" class="px-6 py-8 text-center text-sm text-muted-foreground">No SMSC data yet — run `kannel:poll`</td>
                            </tr>
                            <tr v-for="row in smscs" :key="row.id" class="hover:bg-muted/30">
                                <td class="whitespace-nowrap px-6 py-3 font-medium">{{ row.endpoint_name }}</td>
                                <td class="whitespace-nowrap px-6 py-3">
                                    <div>{{ row.smsc_name }}</div>
                                    <div v-if="row.smsc_id" class="text-xs text-muted-foreground">{{ row.smsc_id }}</div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-3">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium" :class="stateClass(row.state)">
                                        {{ row.state_label ?? row.state ?? 'unknown' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums">{{ formatNumber(row.mo_total) }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums">{{ formatNumber(row.mt_total) }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums">{{ formatNumber(row.mo_rate, 2) }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums">{{ formatNumber(row.mt_rate, 2) }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right tabular-nums">{{ row.latency_ms ?? '—' }}{{ row.latency_ms != null ? ' ms' : '' }}</td>
                                <td class="whitespace-nowrap px-6 py-3 text-right text-xs text-muted-foreground">{{ formatTime(row.last_seen_at ?? row.collected_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Recent alerts</CardTitle>
                <CardDescription>Firing and resolved events with severity.</CardDescription>
            </CardHeader>
            <CardContent class="!px-0 !pt-0">
                <ul v-if="alerts.length === 0" class="px-6 py-8 text-center text-sm text-muted-foreground">
                    No alerts recorded yet.
                </ul>
                <ul v-else class="divide-y">
                    <li v-for="event in alerts" :key="'alert-'+event.id" class="flex flex-col gap-1 px-6 py-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <Badge :class="severityClass(event.severity)" variant="secondary">{{ (event.severity ?? 'info').toUpperCase() }}</Badge>
                            <div>
                                <div class="font-medium">{{ event.title }}</div>
                                <div class="text-xs text-muted-foreground">{{ event.message }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 pl-9 text-xs text-muted-foreground sm:pl-0">
                            <span v-if="event.rule_type" class="truncate">{{ event.rule_type }}</span>
                            <span>·</span>
                            <span>{{ formatTime(event.fired_at) }}</span>
                            <span v-if="event.state === 'resolved'" class="text-emerald-600 dark:text-emerald-400">resolved</span>
                            <span v-else class="text-rose-600 dark:text-rose-400">{{ event.state }}</span>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
