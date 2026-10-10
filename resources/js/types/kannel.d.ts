declare module '@/types/kannel' {
    export interface KpiRow {
        total_endpoints: number;
        active_endpoints: number;
        unhealthy_endpoints: number;
        total_smscs: number;
        smsc_down_count: number;
        mo_total: number;
        mt_total: number;
        mo_rate: number;
        mt_rate: number;
        failed_total: number;
        queue_total: number;
        last_polled_at: string | null;
    }

    export interface SmscRow {
        id: number;
        endpoint_id: number;
        endpoint_name: string;
        smsc_name: string;
        smsc_id?: string | null;
        state: string | null;
        state_label?: string | null;
        operational?: boolean | null;
        mo_total: number;
        mt_total: number;
        mo_rate: number;
        mt_rate: number;
        failed: number;
        queued: number;
        latency_ms: number | null;
        last_seen_at: string | null;
        collected_at: string | null;
    }

    export interface KpiSeriesPoint {
        bucket: string;
        mo_rate: number;
        mt_rate: number;
        mo_delta: number;
        mt_delta: number;
        peak_mt_rate?: number;
        samples?: number;
    }

    export interface QueuesRow {
        endpoint_id: number;
        endpoint_name: string;
        inbound_queue: number;
        outbound_queue: number;
        dlr_queue: number;
        buffered: number;
        stored: number;
        peak_outbound_queue: number;
        updated_at: string | null;
    }

    export interface RecentAlert {
        id: number;
        fingerprint: string;
        state: 'firing' | 'resolved' | string;
        severity: 'critical' | 'warning' | 'info' | string | null;
        title: string;
        message: string;
        endpoint_id: number | null;
        smsc_name: string | null;
        fired_at: string | null;
        resolved_at: string | null;
        last_notified_at: string | null;
        notify_attempts: number;
        rule_name?: string | null;
        rule_type?: string | null;
    }
}

export {};
