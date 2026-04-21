export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_super_admin: boolean;
    last_login_at?: string;
    // Per-view UI state — breakdown mode, sort, filter. Persisted via PATCH /settings/view-preferences.
    // Shape: { [viewKey]: { view?: string, filter?: string, sort_by?: string, sort_dir?: string } }
    view_preferences: Record<string, Record<string, string | undefined>>;
}

export interface Workspace {
    id: number;
    name: string;
    slug: string;
    reporting_currency: string;
    reporting_timezone: string;
    trial_ends_at?: string;
    billing_plan?: string;
    pm_type?: string | null;
    has_store: boolean;
    has_ads: boolean;
    has_gsc: boolean;
    has_psi: boolean;
}

export interface Store {
    id: number;
    slug: string;
    name: string;
    status: string;
    last_synced_at: string | null;
}

/** One integration's sync freshness — used by DataFreshness component. */
export interface IntegrationFreshness {
    label: string;
    type: 'store' | 'ad_account' | 'gsc';
    platform?: string;
    status: string;
    last_synced_at: string | null;
    consecutive_sync_failures: number;
    historical_import_status?: string | null;
}

export interface Alert {
    id: number;
    type: string;
    severity: 'info' | 'warning' | 'critical';
    data?: Record<string, unknown>;
    read_at?: string;
    resolved_at?: string;
    created_at: string;
}

export interface AttributionBackfillProgress {
    status: 'running' | 'completed' | 'failed';
    processed: number;
    total: number;
    started_at: string;
    completed_at: string | null;
    error?: string;
}

export interface AdminWorkspace {
    id: number;
    name: string;
    slug: string;
    billing_plan: string | null;
    trial_ends_at: string | null;
    stores_count: number;
    owner: { id: number; name: string; email: string } | null;
    created_at: string;
    deleted_at: string | null;
    attribution_backfill: AttributionBackfillProgress | null;
}

export interface AdminUser {
    id: number;
    name: string;
    email: string;
    is_super_admin: boolean;
    workspaces_count: number;
    last_login_at: string | null;
    created_at: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    workspace?: Workspace;
    workspaces?: Workspace[];
    stores?: Store[];
    integrations_freshness?: IntegrationFreshness[];
    unread_alerts_count?: number;
    workspace_role?: 'owner' | 'admin' | 'member' | null;
    impersonating?: boolean;
    impersonated_user_name?: string | null;
    earliest_date?: string | null;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
};
