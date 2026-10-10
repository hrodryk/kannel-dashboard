<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
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
import alertRulesRoutes from '@/routes/kannel/alert-rules';
import { index as kannelAlertRulesIndex } from '@/routes/kannel/alert-rules';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Alert rules',
                href: kannelAlertRulesIndex(),
            },
        ],
    },
});

interface ChannelDef {
    type: 'mail' | 'slack' | 'webhook';
    emails?: string[];
    emailList?: string;
    webhook_url?: string;
    url?: string;
    secret?: string;
    headerName?: string;
    headerValue?: string;
}

interface AlertRuleThreshold {
    outbound_queue?: number;
    fraction?: number;
    consecutive_failures?: number;
}

interface AlertRuleCreateForm {
    name: string;
    endpoint_id: number | null;
    type: string;
    severity: string;
    threshold: AlertRuleThreshold;
    channels: ChannelDef[];
    cooldown_minutes: number;
    enabled: boolean;
    description: string;
}

interface AlertRule {
    id: number;
    name: string;
    type: string;
    severity: string;
    endpoint_id: number | null;
    endpoint?: { name: string } | null;
    threshold: AlertRuleThreshold;
    channels: ChannelDef[];
    cooldown_minutes: number;
    enabled: boolean;
    description: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    rules: {
        data: AlertRule[];
    };
    rule_types: { value: string; label: string }[];
    severities: { value: string; label: string }[];
    channel_types: { value: string; label: string }[];
    defaults: {
        cooldown_minutes: number;
        queue_threshold: number;
        throughput_fraction: number;
    };
}

const props = defineProps<Props>();

const page = usePage<{ errors: Record<string, string> }>();

const initialForm: AlertRuleCreateForm = {
    name: '',
    endpoint_id: null,
    type: props.rule_types[0]?.value ?? 'smsc_down',
    severity: props.severities[0]?.value ?? 'warning',
    threshold: {},
    channels: [
        {
            type: 'mail',
            emails: [],
            emailList: '',
        },
    ],
    cooldown_minutes: props.defaults.cooldown_minutes,
    enabled: true,
    description: '',
};

const createForm = useForm<AlertRuleCreateForm>(initialForm);

function sevClass(s: string): string {
    switch (s) {
        case 'critical':
            return 'bg-rose-500/15 text-rose-700 dark:text-rose-300';
        case 'warning':
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-300';
        default:
            return 'bg-sky-500/15 text-sky-700 dark:text-sky-300';
    }
}

function addChannel(): void {
    createForm.channels.push({ type: 'mail', emails: [], emailList: '' });
}

function removeChannel(idx: number): void {
    createForm.channels.splice(idx, 1);
}

function prepareAndSubmit(): void {
    const normalized = createForm.channels.map((c: ChannelDef) => {
        if (
            c.type === 'mail' &&
            typeof c.emailList === 'string' &&
            c.emailList !== ''
        ) {
            c.emails = c.emailList
                .split(/[\s,;]+/)
                .filter((s: string) => s !== '');
            delete c.emailList;
        }
        if (c.type === 'mail') {
            return { type: 'mail', emails: c.emails ?? [] };
        }
        if (c.type === 'slack') {
            return { type: 'slack', webhook_url: c.webhook_url ?? '' };
        }
        if (c.type === 'webhook') {
            const headers: Record<string, string> = {};
            if (c.headerName && c.headerValue) {
                headers[c.headerName] = c.headerValue;
            }
            return {
                type: 'webhook',
                url: c.url ?? '',
                secret: c.secret ?? '',
                headers,
            };
        }
        return c;
    });

    const threshold: AlertRuleThreshold = { ...createForm.threshold };
    const type = String(createForm.type);
    if (type === 'queue_exceeded') {
        threshold.outbound_queue = Number(
            threshold.outbound_queue ?? props.defaults.queue_threshold,
        );
    }
    if (type === 'throughput_drop') {
        threshold.fraction = Number(
            threshold.fraction ?? props.defaults.throughput_fraction,
        );
        threshold.fraction = Math.max(
            0.05,
            Math.min(0.99, Number(threshold.fraction)),
        );
    }
    if (type === 'endpoint_unreachable') {
        threshold.consecutive_failures = Number(
            threshold.consecutive_failures ?? 3,
        );
    }

    const submit = useForm({
        ...createForm.data(),
        channels: normalized,
        threshold,
    });

    submit.post(alertRulesRoutes.store.url(), { preserveScroll: true });
}

function removeRule(r: AlertRule): void {
    if (!confirm(`Delete rule "${r.name}"?`)) {
        return;
    }
    const form = useForm({ id: r.id });
    form.post(alertRulesRoutes.destroy.form(r.id).action, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="mx-auto w-full max-w-7xl space-y-8 px-4 py-6 sm:px-6 lg:px-8">
        <Heading
            title="Alert rules"
            description="Define thresholds for SMSC, queue, throughput, and unreachable endpoint alerts with notification channels."
        />

        <Card>
            <CardHeader>
                <CardTitle>Create alert rule</CardTitle>
                <CardDescription
                    >Alerts are deduplicated by fingerprint and throttled by
                    cooldown.</CardDescription
                >
            </CardHeader>
            <CardContent class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="space-y-2">
                    <Label for="ar-name">Rule name</Label>
                    <Input
                        id="ar-name"
                        v-model="createForm.name"
                        placeholder="Production SMSC down"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.name ?? createForm.errors.name
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="ar-type">Rule type</Label>
                    <select
                        id="ar-type"
                        v-model="createForm.type"
                        class="bg-background w-full rounded-md border px-3 py-2 text-sm shadow-sm"
                    >
                        <option
                            v-for="t in props.rule_types"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.type ?? createForm.errors.type
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="ar-sev">Severity</Label>
                    <select
                        id="ar-sev"
                        v-model="createForm.severity"
                        class="bg-background w-full rounded-md border px-3 py-2 text-sm shadow-sm"
                    >
                        <option
                            v-for="s in props.severities"
                            :key="s.value"
                            :value="s.value"
                        >
                            {{ s.label }}
                        </option>
                    </select>
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.severity ??
                            createForm.errors.severity
                        "
                    />
                </div>
                <div class="space-y-2">
                    <Label for="ar-cd">Cooldown (minutes)</Label>
                    <Input
                        id="ar-cd"
                        type="number"
                        min="0"
                        max="1440"
                        v-model.number="createForm.cooldown_minutes"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors.cooldown_minutes ??
                            createForm.errors.cooldown_minutes
                        "
                    />
                </div>

                <div
                    v-if="createForm.type === 'queue_exceeded'"
                    class="space-y-2 md:col-span-2"
                >
                    <Label>Outbound queue threshold</Label>
                    <Input
                        type="number"
                        min="0"
                        v-model.number="
                            (createForm.threshold as any).outbound_queue
                        "
                        :placeholder="String(props.defaults.queue_threshold)"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors['threshold.outbound_queue'] ??
                            page.props.errors.threshold
                        "
                    />
                </div>
                <div
                    v-if="createForm.type === 'throughput_drop'"
                    class="space-y-2 md:col-span-2"
                >
                    <Label
                        >MT throughput fraction (0.25 = below 25% of
                        baseline)</Label
                    >
                    <Input
                        type="number"
                        step="0.05"
                        min="0.05"
                        max="0.99"
                        v-model.number="(createForm.threshold as any).fraction"
                        :placeholder="
                            String(props.defaults.throughput_fraction)
                        "
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors['threshold.fraction'] ??
                            page.props.errors.threshold
                        "
                    />
                </div>
                <div
                    v-if="createForm.type === 'endpoint_unreachable'"
                    class="space-y-2 md:col-span-2"
                >
                    <Label>Consecutive failures before firing</Label>
                    <Input
                        type="number"
                        min="1"
                        v-model.number="
                            (createForm.threshold as any).consecutive_failures
                        "
                        placeholder="3"
                    />
                    <InputError
                        class="mt-2"
                        :message="
                            page.props.errors[
                                'threshold.consecutive_failures'
                            ] ?? page.props.errors.threshold
                        "
                    />
                </div>

                <div class="space-y-2 md:col-span-2">
                    <div class="flex items-center justify-between">
                        <Label>Channels</Label>
                        <Button variant="outline" size="sm" @click="addChannel"
                            >+ Add channel</Button
                        >
                    </div>
                    <InputError
                        class="mt-2"
                        :message="page.props.errors.channels"
                    />
                    <div class="space-y-3">
                        <div
                            v-for="(c, idx) in createForm.channels"
                            :key="idx"
                            class="grid grid-cols-1 gap-3 rounded-md border p-3 sm:grid-cols-[auto,1fr,auto] sm:items-start"
                        >
                            <select
                                v-model="c.type"
                                class="bg-background rounded-md border px-3 py-2 text-sm shadow-sm"
                            >
                                <option value="mail">Email</option>
                                <option value="slack">Slack webhook</option>
                                <option value="webhook">HTTP webhook</option>
                            </select>
                            <div class="space-y-2">
                                <template v-if="c.type === 'mail'">
                                    <Label class="text-xs"
                                        >Email recipients (comma
                                        separated)</Label
                                    >
                                    <Input
                                        v-model="c.emailList"
                                        placeholder="ops@example.test, sre@example.test"
                                    />
                                    <InputError
                                        class="mt-2"
                                        :message="
                                            page.props.errors[
                                                `channels.${idx}.emails`
                                            ] ??
                                            page.props.errors[
                                                `channels.${idx}.emailList`
                                            ]
                                        "
                                    />
                                </template>
                                <template v-else-if="c.type === 'slack'">
                                    <Label class="text-xs"
                                        >Slack incoming webhook URL (encrypted
                                        at rest)</Label
                                    >
                                    <Input
                                        type="password"
                                        v-model="c.webhook_url"
                                        placeholder="https://hooks.slack.com/services/T000/…"
                                    />
                                    <InputError
                                        class="mt-2"
                                        :message="
                                            page.props.errors[
                                                `channels.${idx}.webhook_url`
                                            ]
                                        "
                                    />
                                </template>
                                <template v-else>
                                    <Label class="text-xs"
                                        >Webhook URL, signing secret, optional
                                        header</Label
                                    >
                                    <div
                                        class="grid grid-cols-1 gap-2 sm:grid-cols-3"
                                    >
                                        <Input
                                            v-model="c.url"
                                            placeholder="https://example.test/hooks/kannel"
                                        />
                                        <Input
                                            type="password"
                                            v-model="c.secret"
                                            placeholder="HMAC secret"
                                        />
                                        <div class="grid grid-cols-2 gap-2">
                                            <Input
                                                v-model="c.headerName"
                                                placeholder="Header"
                                            />
                                            <Input
                                                type="password"
                                                v-model="c.headerValue"
                                                placeholder="Value"
                                            />
                                        </div>
                                    </div>
                                    <InputError
                                        class="mt-2"
                                        :message="
                                            page.props.errors[
                                                `channels.${idx}.url`
                                            ] ??
                                            page.props.errors[
                                                `channels.${idx}.secret`
                                            ]
                                        "
                                    />
                                </template>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="removeChannel(idx)"
                                >Remove</Button
                            >
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3 md:col-span-2">
                    <input
                        id="ar-enabled"
                        type="checkbox"
                        class="h-4 w-4 rounded border"
                        v-model.checked="createForm.enabled"
                    />
                    <Label for="ar-enabled">Enabled</Label>
                    <Button class="ml-auto" @click="prepareAndSubmit"
                        >Create rule</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Existing rules</CardTitle>
                <CardDescription
                    >{{ rules.data.length }} rules.</CardDescription
                >
            </CardHeader>

            <CardContent>
                <div
                    v-if="rules.data.length === 0"
                    class="text-muted-foreground py-8 text-center text-sm"
                >
                    No alert rules yet.
                </div>

                <div v-else class="-mx-6 -mt-6 divide-y">
                    <div
                        v-for="r in rules.data"
                        :key="r.id"
                        class="space-y-3 p-6"
                    >
                        <div
                            class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold">{{ r.name }}</h3>
                                    <Badge
                                        variant="secondary"
                                        :class="sevClass(r.severity)"
                                        >{{ r.severity }}</Badge
                                    >
                                    <Badge
                                        variant="outline"
                                        class="capitalize"
                                        >{{
                                            r.type?.replaceAll('_', ' ')
                                        }}</Badge
                                    >
                                    <Badge
                                        v-if="r.endpoint?.name"
                                        variant="outline"
                                        >{{ r.endpoint.name }}</Badge
                                    >
                                    <Badge
                                        v-if="!r.enabled"
                                        variant="outline"
                                        class="opacity-70"
                                        >Disabled</Badge
                                    >
                                </div>
                                <div class="text-muted-foreground text-xs">
                                    Cooldown {{ r.cooldown_minutes }}m ·
                                    channels {{ r.channels.length }}
                                </div>
                            </div>
                            <Button
                                variant="destructive"
                                size="sm"
                                @click="removeRule(r)"
                                >Delete</Button
                            >
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
